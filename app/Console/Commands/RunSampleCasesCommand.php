<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\GridWise\Dto\ConstraintModel;
use App\GridWise\Dto\Directive;
use App\GridWise\Dto\HourPlan;
use App\GridWise\Dto\Scenario;
use App\GridWise\Dto\Schedule;
use App\GridWise\GridWiseService;
use App\GridWise\Validation\ScheduleReplayer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use JsonException;

use function Laravel\Prompts\table;

final class RunSampleCasesCommand extends Command
{
    protected $signature = 'gridwise:samples
        {--file= : Path to the public sample cases JSON}
        {--url= : Run against a deployed base URL instead of the local service}
        {--case=* : Only run the given case ids}
        {--json : Print the raw response for every case}';

    protected $description = 'Replay the public GridWise sample cases and score interpretation, schedule validity and cost';

    public function __construct(
        private readonly GridWiseService $gridwise,
        private readonly ScheduleReplayer $replayer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $path = (string) ($this->option('file') ?: storage_path('app/gridwise/public_sample_cases.json'));

        if (! is_file($path)) {
            $this->components->error("Sample case file not found at {$path}");

            return self::FAILURE;
        }

        try {
            $pack = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->components->error('Sample case file is not valid JSON.');

            return self::FAILURE;
        }

        $only = array_values(array_filter((array) $this->option('case')));
        $rows = [];
        $failures = 0;
        $qualityTotal = 0.0;
        $interpretationHits = 0;
        $counted = 0;

        foreach ($pack['cases'] ?? [] as $case) {
            if ($only !== [] && ! in_array($case['id'], $only, true)) {
                continue;
            }

            $startedAt = microtime(true);
            $response = $this->runCase($case['input']);
            $elapsed = (microtime(true) - $startedAt) * 1000;

            if ($response === null) {
                $rows[] = [$case['id'], 'request failed', '-', '-', '-', '-'];
                $failures++;
                $counted++;

                continue;
            }

            if ($this->option('json')) {
                $this->line((string) json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }

            $scenario = Scenario::fromArray($case['input']);
            $expected = $case['expected_output'];

            $interpretation = $this->compareInterpretation($expected, $response);
            $verdict = $this->replayAgainstGroundTruth($scenario, $expected, $response);

            $expectedCost = (float) $expected['total_cost_bdt'];
            $actualCost = (float) ($response['total_cost_bdt'] ?? 0.0);

            $quality = match (true) {
                $verdict !== 'valid' => 0.0,
                abs($expectedCost) <= 0.01 && abs($actualCost) <= 0.01 => 1.0,
                $actualCost <= 0.01 => 1.0,
                default => min(1.0, $expectedCost / $actualCost),
            };

            $qualityTotal += $quality;
            $interpretationHits += $interpretation['ok'] ? 1 : 0;
            $counted++;

            $passed = $interpretation['ok'] && $verdict === 'valid' && abs($quality - 1.0) < 1e-6;

            if (! $passed) {
                $failures++;
            }

            $rows[] = [
                $case['id'],
                $interpretation['label'],
                $verdict,
                sprintf('%.2f', $actualCost),
                sprintf('%.3f', $quality),
                sprintf('%.0f ms', $elapsed),
            ];
        }

        table(['case', 'interpretation', 'schedule', 'cost', 'quality', 'latency'], $rows);

        $this->newLine();
        $this->components->twoColumnDetail('Cases run', (string) $counted);
        $this->components->twoColumnDetail('Failures', (string) $failures);
        $this->components->twoColumnDetail(
            'Interpretation match',
            $counted > 0 ? sprintf('%d / %d', $interpretationHits, $counted) : 'n/a',
        );
        $this->components->twoColumnDetail(
            'Optimization quality',
            $counted > 0 ? sprintf('%.2f / 10', 10 * $qualityTotal / $counted) : 'n/a',
        );

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|null
     */
    private function runCase(array $input): ?array
    {
        $baseUrl = $this->option('url');

        if (! $baseUrl) {
            return $this->gridwise->optimize(Scenario::fromArray($input))->toResponseArray();
        }

        $response = Http::timeout(30)
            ->acceptJson()
            ->post(rtrim((string) $baseUrl, '/').'/optimize-energy', $input);

        return $response->successful() ? $response->json() : null;
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     * @return array{ok: bool, label: string}
     */
    private function compareInterpretation(array $expected, array $actual): array
    {
        $want = $expected['directive_interpretation'] ?? [];
        $got = $actual['directive_interpretation'] ?? [];

        if (count($want) !== count($got)) {
            return ['ok' => false, 'label' => 'entry count mismatch'];
        }

        $problems = [];

        foreach ($want as $position => $reference) {
            $candidate = $got[$position] ?? null;

            if (! is_array($candidate) || ($candidate['note_index'] ?? null) !== $reference['note_index']) {
                $problems[] = 'note order';

                continue;
            }

            $index = $reference['note_index'];

            if (($candidate['directive_type'] ?? null) !== $reference['directive_type']) {
                $problems[] = "note {$index} type";

                continue;
            }

            if (($candidate['applies'] ?? null) !== $reference['applies']) {
                $problems[] = "note {$index} applies";

                continue;
            }

            if (! $this->adjustmentMatches($reference['structured_adjustment'], $candidate['structured_adjustment'] ?? null)) {
                $problems[] = "note {$index} adjustment";
            }
        }

        return [
            'ok' => $problems === [],
            'label' => $problems === []
                ? sprintf('%d/%d exact', count($want), count($want))
                : implode(', ', array_unique($problems)),
        ];
    }

    private function adjustmentMatches(mixed $want, mixed $got): bool
    {
        if ($want === null || $got === null) {
            return $want === $got;
        }

        if (! is_array($want) || ! is_array($got)) {
            return false;
        }

        $wantHours = array_map(intval(...), $want['hours'] ?? []);
        $gotHours = array_map(intval(...), $got['hours'] ?? []);

        if ($wantHours !== $gotHours) {
            return false;
        }

        foreach (['factor', 'minimum_energy_kwh', 'max_grid_kwh'] as $key) {
            if (array_key_exists($key, $want) !== array_key_exists($key, $got)) {
                return false;
            }

            if (array_key_exists($key, $want) && abs((float) $want[$key] - (float) $got[$key]) > 0.01) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $response
     */
    private function replayAgainstGroundTruth(Scenario $scenario, array $expected, array $response): string
    {
        $groundTruth = array_map(
            Directive::fromResponseArray(...),
            $expected['directive_interpretation'] ?? [],
        );

        $report = $this->replayer->verify(
            $scenario,
            ConstraintModel::build($scenario, $groundTruth),
            $this->toSchedule($scenario, $response),
        );

        return $report->isValid() ? 'valid' : $report->messages()[0];
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function toSchedule(Scenario $scenario, array $response): Schedule
    {
        $hours = [];

        foreach ($response['hourly_plan'] ?? [] as $entry) {
            $hours[] = new HourPlan(
                (int) ($entry['hour'] ?? -1),
                (float) ($entry['grid_kwh'] ?? 0),
                (float) ($entry['solar_used_kwh'] ?? 0),
                (string) ($entry['battery_action'] ?? ''),
                (float) ($entry['battery_kwh'] ?? 0),
                (float) ($entry['battery_energy_after_kwh'] ?? 0),
            );
        }

        $schedule = Schedule::fromHours($hours, $scenario->tariff);

        return Schedule::fromReported(
            $hours,
            (float) ($response['total_grid_kwh'] ?? $schedule->totalGridKwh),
            (float) ($response['total_cost_bdt'] ?? $schedule->totalCostBdt),
            (float) ($response['peak_grid_kwh'] ?? $schedule->peakGridKwh),
        );
    }
}
