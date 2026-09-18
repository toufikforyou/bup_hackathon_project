<?php

declare(strict_types=1);

namespace App\GridWise\Validation;

use App\GridWise\Dto\ConstraintModel;
use App\GridWise\Dto\HourPlan;
use App\GridWise\Dto\Scenario;
use App\GridWise\Dto\Schedule;

final class ScheduleReplayer
{
    public function __construct(private readonly float $tolerance = 0.01) {}

    public function verify(Scenario $scenario, ConstraintModel $model, Schedule $schedule): ReplayReport
    {
        $violations = [];
        $battery = $scenario->battery;

        $seen = [];

        foreach ($schedule->hours as $plan) {
            if ($plan->hour < 0 || $plan->hour > 23) {
                $violations[] = $this->violation($plan->hour, 'hour_range', 'hour must be an integer 0..23');

                continue;
            }

            if (isset($seen[$plan->hour])) {
                $violations[] = $this->violation($plan->hour, 'hour_uniqueness', 'duplicate hour in hourly_plan');
            }

            $seen[$plan->hour] = true;
        }

        if (count($schedule->hours) !== Scenario::HORIZON || count($seen) !== Scenario::HORIZON) {
            $violations[] = $this->violation(null, 'horizon', 'hourly_plan must contain exactly 24 unique hours 0..23');

            return new ReplayReport($violations);
        }

        $ordered = [];

        foreach ($schedule->hours as $plan) {
            $ordered[$plan->hour] = $plan;
        }

        ksort($ordered);

        $energy = $battery->initialEnergyKwh;
        $totalGrid = 0.0;
        $totalCost = 0.0;
        $peak = 0.0;

        foreach ($ordered as $hour => $plan) {
            $violations = [
                ...$violations,
                ...$this->verifyHour($scenario, $model, $plan, $energy),
            ];

            $energy = round($energy + $plan->chargeKwh() - $plan->dischargeKwh(), 9);

            $totalGrid += $plan->gridKwh;
            $totalCost += $plan->gridKwh * $scenario->tariff[$hour];
            $peak = max($peak, $plan->gridKwh);
        }

        if (abs($energy - $battery->initialEnergyKwh) > $this->tolerance) {
            $violations[] = $this->violation(null, 'battery_neutrality', sprintf(
                'final battery energy %.4f must equal initial energy %.4f',
                $energy,
                $battery->initialEnergyKwh,
            ));
        }

        foreach ([
            ['total_grid_kwh', $schedule->totalGridKwh, $totalGrid],
            ['total_cost_bdt', $schedule->totalCostBdt, $totalCost],
            ['peak_grid_kwh', $schedule->peakGridKwh, $peak],
        ] as [$field, $reported, $recalculated]) {
            if (abs($reported - $recalculated) > $this->tolerance) {
                $violations[] = $this->violation(null, 'totals', sprintf(
                    '%s reported %.4f but recalculates to %.4f',
                    $field,
                    $reported,
                    $recalculated,
                ));
            }
        }

        return new ReplayReport($violations);
    }

    /**
     * @return list<array{hour: int|null, rule: string, detail: string}>
     */
    private function verifyHour(
        Scenario $scenario,
        ConstraintModel $model,
        HourPlan $plan,
        float $energyBefore,
    ): array {
        $violations = [];
        $hour = $plan->hour;
        $battery = $scenario->battery;

        foreach ([
            'grid_kwh' => $plan->gridKwh,
            'solar_used_kwh' => $plan->solarUsedKwh,
            'battery_kwh' => $plan->batteryKwh,
            'battery_energy_after_kwh' => $plan->batteryEnergyAfterKwh,
        ] as $field => $value) {
            if (! is_finite($value)) {
                $violations[] = $this->violation($hour, 'finite_values', "$field is not a finite number");
            } elseif ($value < -$this->tolerance) {
                $violations[] = $this->violation($hour, 'non_negative', "$field must not be negative");
            }
        }

        if (! in_array($plan->batteryAction, [HourPlan::CHARGE, HourPlan::DISCHARGE, HourPlan::IDLE], true)) {
            $violations[] = $this->violation($hour, 'battery_action', 'battery_action must be charge, discharge or idle');

            return $violations;
        }

        if ($plan->batteryAction === HourPlan::IDLE && abs($plan->batteryKwh) > $this->tolerance) {
            $violations[] = $this->violation($hour, 'idle_consistency', 'battery_kwh must be 0 when the action is idle');
        }

        $charge = $plan->chargeKwh();
        $discharge = $plan->dischargeKwh();

        if ($charge > $battery->maxChargeKwhPerHour + $this->tolerance) {
            $violations[] = $this->violation($hour, 'charge_rate', sprintf(
                'charge %.4f exceeds max_charge_kwh_per_hour %.4f',
                $charge,
                $battery->maxChargeKwhPerHour,
            ));
        }

        if ($discharge > $battery->maxDischargeKwhPerHour + $this->tolerance) {
            $violations[] = $this->violation($hour, 'discharge_rate', sprintf(
                'discharge %.4f exceeds max_discharge_kwh_per_hour %.4f',
                $discharge,
                $battery->maxDischargeKwhPerHour,
            ));
        }

        if ($model->chargeBlocked[$hour] && $charge > $this->tolerance) {
            $violations[] = $this->violation($hour, 'no_charge_window', 'charging is forbidden in this hour');
        }

        if ($model->dischargeBlocked[$hour] && $discharge > $this->tolerance) {
            $violations[] = $this->violation($hour, 'no_discharge_window', 'discharging is forbidden in this hour');
        }

        $expectedEnergy = $energyBefore + $charge - $discharge;

        if (abs($plan->batteryEnergyAfterKwh - $expectedEnergy) > $this->tolerance) {
            $violations[] = $this->violation($hour, 'battery_transition', sprintf(
                'battery_energy_after_kwh %.4f does not follow from %.4f and the declared action',
                $plan->batteryEnergyAfterKwh,
                $energyBefore,
            ));
        }

        $floor = $model->minimumEnergyKwh[$hour];

        if ($plan->batteryEnergyAfterKwh < $floor - $this->tolerance) {
            $violations[] = $this->violation($hour, 'battery_minimum', sprintf(
                'battery energy %.4f is below the active minimum %.4f',
                $plan->batteryEnergyAfterKwh,
                $floor,
            ));
        }

        if ($plan->batteryEnergyAfterKwh > $battery->capacityKwh + $this->tolerance) {
            $violations[] = $this->violation($hour, 'battery_capacity', sprintf(
                'battery energy %.4f exceeds capacity %.4f',
                $plan->batteryEnergyAfterKwh,
                $battery->capacityKwh,
            ));
        }

        if ($plan->solarUsedKwh > $model->effectiveSolarKwh[$hour] + $this->tolerance) {
            $violations[] = $this->violation($hour, 'effective_solar', sprintf(
                'solar_used_kwh %.4f exceeds effective solar %.4f',
                $plan->solarUsedKwh,
                $model->effectiveSolarKwh[$hour],
            ));
        }

        if ($model->maxGridKwh[$hour] !== null && $plan->gridKwh > $model->maxGridKwh[$hour] + $this->tolerance) {
            $violations[] = $this->violation($hour, 'max_grid_window', sprintf(
                'grid_kwh %.4f exceeds the hourly cap %.4f',
                $plan->gridKwh,
                $model->maxGridKwh[$hour],
            ));
        }

        $supply = $plan->gridKwh + $plan->solarUsedKwh + $discharge;
        $draw = $scenario->demandKwh[$hour] + $charge;

        if (abs($supply - $draw) > $this->tolerance) {
            $violations[] = $this->violation($hour, 'energy_balance', sprintf(
                'supply %.4f does not equal demand plus charging %.4f',
                $supply,
                $draw,
            ));
        }

        return $violations;
    }

    /**
     * @return array{hour: int|null, rule: string, detail: string}
     */
    private function violation(?int $hour, string $rule, string $detail): array
    {
        return ['hour' => $hour, 'rule' => $rule, 'detail' => $detail];
    }
}
