<?php

declare(strict_types=1);

namespace App\GridWise;

use App\GridWise\Dto\Directive;
use App\GridWise\Dto\HourPlan;
use App\GridWise\Dto\Scenario;
use App\GridWise\Dto\Schedule;
use App\GridWise\Interpretation\DirectiveInterpreter;
use App\GridWise\Optimizer\EnergyOptimizer;
use App\GridWise\Validation\ScheduleReplayer;
use Illuminate\Support\Facades\Log;

final class GridWiseService
{
    public function __construct(
        private readonly DirectiveInterpreter $interpreter,
        private readonly EnergyOptimizer $optimizer,
        private readonly ScheduleReplayer $replayer,
    ) {}

    public function optimize(Scenario $scenario): OptimizationOutcome
    {
        $startedAt = microtime(true);

        $interpretation = $this->interpreter->interpret($scenario);
        $optimization = $this->optimizer->optimize($scenario, $interpretation->directives);
        $replay = $this->replayer->verify($scenario, $optimization->model, $optimization->schedule);

        if (! $replay->isValid()) {
            Log::error('GridWise produced a schedule that failed its own replay', [
                'scenario_id' => $scenario->scenarioId,
                'violations' => $replay->messages(),
            ]);
        }

        return new OptimizationOutcome(
            $scenario,
            $interpretation,
            $optimization,
            $replay,
            $this->summarise($scenario, $interpretation->directives, $optimization->schedule),
            round((microtime(true) - $startedAt) * 1000, 2),
        );
    }

    /**
     * @param  list<Directive>  $directives
     */
    private function summarise(Scenario $scenario, array $directives, Schedule $schedule): string
    {
        $applied = array_values(array_filter($directives, static fn (Directive $d): bool => $d->applies()));

        $charged = 0.0;
        $discharged = 0.0;
        $solar = 0.0;
        $chargeHours = [];
        $dischargeHours = [];

        foreach ($schedule->hours as $plan) {
            $charged += $plan->chargeKwh();
            $discharged += $plan->dischargeKwh();
            $solar += $plan->solarUsedKwh;

            if ($plan->batteryAction === HourPlan::CHARGE) {
                $chargeHours[] = $plan->hour;
            }

            if ($plan->batteryAction === HourPlan::DISCHARGE) {
                $dischargeHours[] = $plan->hour;
            }
        }

        $parts = [];

        $parts[] = sprintf(
            '%d operator %s interpreted, %d applied as %s.',
            count($directives),
            count($directives) === 1 ? 'note' : 'notes',
            count($applied),
            $applied === []
                ? 'no directive'
                : implode(', ', array_unique(array_map(
                    static fn (Directive $d): string => $d->type->value,
                    $applied,
                ))),
        );

        $parts[] = sprintf(
            'Used %s kWh of available solar, charged %s kWh in %s and discharged %s kWh in %s so the battery ends the day at its starting level.',
            $this->number($solar),
            $this->number($charged),
            $this->hourList($chargeHours),
            $this->number($discharged),
            $this->hourList($dischargeHours),
        );

        $parts[] = sprintf(
            'Total grid import %s kWh at %s BDT, peaking at %s kWh in a single hour.',
            $this->number($schedule->totalGridKwh),
            $this->number($schedule->totalCostBdt),
            $this->number($schedule->peakGridKwh),
        );

        return implode(' ', $parts);
    }

    /**
     * @param  list<int>  $hours
     */
    private function hourList(array $hours): string
    {
        if ($hours === []) {
            return 'no hours';
        }

        return 'hours '.implode(', ', $hours);
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
