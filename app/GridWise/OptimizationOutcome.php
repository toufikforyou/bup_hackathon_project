<?php

declare(strict_types=1);

namespace App\GridWise;

use App\GridWise\Dto\Directive;
use App\GridWise\Dto\Scenario;
use App\GridWise\Interpretation\InterpretationOutcome;
use App\GridWise\Optimizer\OptimizationResult;
use App\GridWise\Validation\ReplayReport;

final readonly class OptimizationOutcome
{
    public function __construct(
        public Scenario $scenario,
        public InterpretationOutcome $interpretation,
        public OptimizationResult $optimization,
        public ReplayReport $replay,
        public string $planSummary,
        public float $totalLatencyMs,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toResponseArray(): array
    {
        $schedule = $this->optimization->schedule;

        return [
            'scenario_id' => $this->scenario->scenarioId,
            'directive_interpretation' => array_map(
                static fn (Directive $directive): array => $directive->toResponseArray(),
                $this->interpretation->directives,
            ),
            'hourly_plan' => $schedule->toArray(),
            'total_grid_kwh' => $schedule->totalGridKwh,
            'total_cost_bdt' => $schedule->totalCostBdt,
            'peak_grid_kwh' => $schedule->peakGridKwh,
            'plan_summary' => $this->planSummary,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDiagnosticsArray(): array
    {
        $model = $this->optimization->model;

        return [
            'interpretation' => [
                'source' => $this->interpretation->source,
                'driver' => $this->interpretation->driver,
                'model' => $this->interpretation->model,
                'cached' => $this->interpretation->cached,
                'latency_ms' => $this->interpretation->latencyMs,
                'guard_issues' => $this->interpretation->guardIssues,
                'fallback_note_indexes' => $this->interpretation->fallbackNoteIndexes,
            ],
            'constraints' => [
                'effective_solar_kwh' => $model->effectiveSolarKwh,
                'minimum_energy_kwh' => $model->minimumEnergyKwh,
                'charge_blocked_hours' => $this->activeHours($model->chargeBlocked),
                'discharge_blocked_hours' => $this->activeHours($model->dischargeBlocked),
                'max_grid_kwh' => $model->maxGridKwh,
                'relaxed_directive_types' => $this->optimization->relaxedDirectiveTypes,
            ],
            'replay' => [
                'valid' => $this->replay->isValid(),
                'violations' => $this->replay->messages(),
            ],
            'total_latency_ms' => $this->totalLatencyMs,
        ];
    }

    /**
     * @param  list<bool>  $flags
     * @return list<int>
     */
    private function activeHours(array $flags): array
    {
        return array_values(array_keys(array_filter($flags)));
    }
}
