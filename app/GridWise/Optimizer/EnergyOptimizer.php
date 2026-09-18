<?php

declare(strict_types=1);

namespace App\GridWise\Optimizer;

use App\GridWise\Dto\ConstraintModel;
use App\GridWise\Dto\Directive;
use App\GridWise\Dto\DirectiveType;
use App\GridWise\Dto\HourPlan;
use App\GridWise\Dto\Scenario;
use App\GridWise\Dto\Schedule;

final class EnergyOptimizer
{
    private const RELAXATION_ORDER = [
        DirectiveType::MaxGridWindow,
        DirectiveType::MinimumBatteryReserve,
        DirectiveType::NoDischargeWindow,
        DirectiveType::NoChargeWindow,
        DirectiveType::SolarReduction,
    ];

    public function __construct(
        private readonly int $maxIterations = 20000,
        private readonly int $blandAfter = 4000,
        private readonly int $decimals = 6,
    ) {}

    /**
     * @param  list<Directive>  $directives
     */
    public function optimize(Scenario $scenario, array $directives): OptimizationResult
    {
        $active = $directives;
        $relaxed = [];

        foreach ([null, ...self::RELAXATION_ORDER] as $drop) {
            if ($drop !== null) {
                $active = array_values(array_filter(
                    $active,
                    static fn (Directive $directive): bool => $directive->type !== $drop,
                ));
                $relaxed[] = $drop->value;
            }

            $model = ConstraintModel::build($scenario, $active);
            $schedule = $this->solveModel($scenario, $model);

            if ($schedule !== null) {
                return new OptimizationResult($schedule, $model, $relaxed);
            }
        }

        $model = ConstraintModel::baseline($scenario);

        return new OptimizationResult($this->idleSchedule($scenario, $model), $model, $relaxed);
    }

    private function solveModel(Scenario $scenario, ConstraintModel $model): ?Schedule
    {
        $battery = $scenario->battery;
        $program = new LinearProgram;

        /** @var array<int, int|null> $solarVar */
        $solarVar = [];
        /** @var array<int, int|null> $chargeVar */
        $chargeVar = [];
        /** @var array<int, int|null> $dischargeVar */
        $dischargeVar = [];

        $maxCharge = max(0.0, $battery->maxChargeKwhPerHour);
        $maxDischarge = max(0.0, $battery->maxDischargeKwhPerHour);

        for ($hour = 0; $hour < Scenario::HORIZON; $hour++) {
            $tariff = $scenario->tariff[$hour];

            $solarVar[$hour] = max(0.0, $model->effectiveSolarKwh[$hour]) > 0.0
                ? $program->addVariable(-$tariff, "solar_used[$hour]")
                : null;

            $chargeVar[$hour] = (! $model->chargeBlocked[$hour] && $maxCharge > 0.0)
                ? $program->addVariable($tariff, "charge[$hour]")
                : null;

            $dischargeVar[$hour] = (! $model->dischargeBlocked[$hour] && $maxDischarge > 0.0)
                ? $program->addVariable(-$tariff, "discharge[$hour]")
                : null;

            $program->addObjectiveOffset($tariff * $scenario->demandKwh[$hour]);
        }

        for ($hour = 0; $hour < Scenario::HORIZON; $hour++) {
            $demand = $scenario->demandKwh[$hour];

            $flow = [];

            if ($chargeVar[$hour] !== null) {
                $flow[$chargeVar[$hour]] = 1.0;
            }

            if ($dischargeVar[$hour] !== null) {
                $flow[$dischargeVar[$hour]] = -1.0;
            }

            if ($solarVar[$hour] !== null) {
                $flow[$solarVar[$hour]] = -1.0;
            }

            $program->addConstraint($flow, LinearProgram::GE, -$demand);

            if ($model->maxGridKwh[$hour] !== null) {
                $program->addConstraint($flow, LinearProgram::LE, $model->maxGridKwh[$hour] - $demand);
            }

            if ($solarVar[$hour] !== null) {
                $program->addConstraint(
                    [$solarVar[$hour] => 1.0],
                    LinearProgram::LE,
                    max(0.0, $model->effectiveSolarKwh[$hour]),
                );
            }

            if ($chargeVar[$hour] !== null) {
                $program->addConstraint([$chargeVar[$hour] => 1.0], LinearProgram::LE, $maxCharge);
            }

            if ($dischargeVar[$hour] !== null) {
                $program->addConstraint([$dischargeVar[$hour] => 1.0], LinearProgram::LE, $maxDischarge);
            }
        }

        $cumulative = [];

        for ($hour = 0; $hour < Scenario::HORIZON; $hour++) {
            if ($chargeVar[$hour] !== null) {
                $cumulative[$chargeVar[$hour]] = 1.0;
            }

            if ($dischargeVar[$hour] !== null) {
                $cumulative[$dischargeVar[$hour]] = -1.0;
            }

            $program->addConstraint(
                $cumulative,
                LinearProgram::GE,
                $model->minimumEnergyKwh[$hour] - $battery->initialEnergyKwh,
            );

            $program->addConstraint(
                $cumulative,
                LinearProgram::LE,
                $battery->capacityKwh - $battery->initialEnergyKwh,
            );
        }

        $program->addConstraint($cumulative, LinearProgram::EQ, 0.0);

        $result = (new Simplex($this->maxIterations, $this->blandAfter))->solve($program);

        if (! $result->isOptimal()) {
            return null;
        }

        return $this->buildSchedule($scenario, $model, $result, $solarVar, $chargeVar, $dischargeVar);
    }

    /**
     * @param  array<int, int|null>  $solarVar
     * @param  array<int, int|null>  $chargeVar
     * @param  array<int, int|null>  $dischargeVar
     */
    private function buildSchedule(
        Scenario $scenario,
        ConstraintModel $model,
        SimplexResult $result,
        array $solarVar,
        array $chargeVar,
        array $dischargeVar,
    ): Schedule {
        $battery = $scenario->battery;

        $net = [];
        $solar = [];

        for ($hour = 0; $hour < Scenario::HORIZON; $hour++) {
            $charge = $chargeVar[$hour] !== null ? max(0.0, $result->value($chargeVar[$hour])) : 0.0;
            $discharge = $dischargeVar[$hour] !== null ? max(0.0, $result->value($dischargeVar[$hour])) : 0.0;

            $net[$hour] = round($charge - $discharge, $this->decimals);

            $solarCap = max(0.0, $model->effectiveSolarKwh[$hour]);
            $used = $solarVar[$hour] !== null ? $result->value($solarVar[$hour]) : 0.0;
            $solar[$hour] = min($solarCap, max(0.0, round($used, $this->decimals)));
        }

        $this->forceBatteryNeutrality($net, $model, $battery->maxChargeKwhPerHour, $battery->maxDischargeKwhPerHour);

        $hours = [];
        $energy = $battery->initialEnergyKwh;

        for ($hour = 0; $hour < Scenario::HORIZON; $hour++) {
            $movement = $net[$hour];
            $energy = round($energy + $movement, $this->decimals);

            $grid = round($scenario->demandKwh[$hour] + $movement - $solar[$hour], $this->decimals);

            if ($grid < 0.0) {
                $solar[$hour] = round($solar[$hour] + $grid, $this->decimals);
                $grid = 0.0;
            }

            $action = match (true) {
                $movement > 0.0 => HourPlan::CHARGE,
                $movement < 0.0 => HourPlan::DISCHARGE,
                default => HourPlan::IDLE,
            };

            $hours[] = new HourPlan($hour, $grid, $solar[$hour], $action, abs($movement), $energy);
        }

        return Schedule::fromHours($hours, $scenario->tariff, $this->decimals);
    }

    /**
     * @param  array<int, float>  $net
     */
    private function forceBatteryNeutrality(
        array &$net,
        ConstraintModel $model,
        float $maxCharge,
        float $maxDischarge,
    ): void {
        $residue = round(array_sum($net), $this->decimals);

        if ($residue === 0.0) {
            return;
        }

        for ($hour = Scenario::HORIZON - 1; $hour >= 0; $hour--) {
            $candidate = round($net[$hour] - $residue, $this->decimals);

            if ($candidate > 0.0 && ($model->chargeBlocked[$hour] || $candidate > $maxCharge)) {
                continue;
            }

            if ($candidate < 0.0 && ($model->dischargeBlocked[$hour] || -$candidate > $maxDischarge)) {
                continue;
            }

            $net[$hour] = $candidate;

            return;
        }
    }

    private function idleSchedule(Scenario $scenario, ConstraintModel $model): Schedule
    {
        $hours = [];

        for ($hour = 0; $hour < Scenario::HORIZON; $hour++) {
            $solar = min(
                max(0.0, $model->effectiveSolarKwh[$hour]),
                $scenario->demandKwh[$hour],
            );

            $hours[] = new HourPlan(
                $hour,
                round($scenario->demandKwh[$hour] - $solar, $this->decimals),
                round($solar, $this->decimals),
                HourPlan::IDLE,
                0.0,
                $scenario->battery->initialEnergyKwh,
            );
        }

        return Schedule::fromHours($hours, $scenario->tariff, $this->decimals);
    }
}
