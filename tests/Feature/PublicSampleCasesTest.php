<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\GridWise\Dto\ConstraintModel;
use App\GridWise\Dto\Directive;
use App\GridWise\Dto\HourPlan;
use App\GridWise\Dto\Scenario;
use App\GridWise\Dto\Schedule;
use App\GridWise\Optimizer\EnergyOptimizer;
use App\GridWise\Validation\ScheduleReplayer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SampleCases;
use Tests\TestCase;

final class PublicSampleCasesTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function cases(): array
    {
        return SampleCases::provider();
    }

    /**
     * @param  array<string, mixed>  $case
     */
    #[Test]
    #[DataProvider('cases')]
    public function the_optimizer_reaches_the_organizer_optimal_cost(array $case): void
    {
        $scenario = Scenario::fromArray($case['input']);
        $groundTruth = $this->groundTruth($case);

        $result = app(EnergyOptimizer::class)->optimize($scenario, $groundTruth);

        $this->assertSame([], $result->relaxedDirectiveTypes, 'Judge scenarios must stay feasible.');
        $this->assertEqualsWithDelta(
            (float) $case['expected_output']['total_cost_bdt'],
            $result->schedule->totalCostBdt,
            0.01,
        );
    }

    /**
     * @param  array<string, mixed>  $case
     */
    #[Test]
    #[DataProvider('cases')]
    public function the_endpoint_returns_a_schedule_that_survives_a_ground_truth_replay(array $case): void
    {
        $response = $this->postJson('/optimize-energy', $case['input']);

        $response->assertOk();

        $body = $response->json();
        $scenario = Scenario::fromArray($case['input']);

        $hours = array_map(
            static fn (array $entry): HourPlan => new HourPlan(
                (int) $entry['hour'],
                (float) $entry['grid_kwh'],
                (float) $entry['solar_used_kwh'],
                (string) $entry['battery_action'],
                (float) $entry['battery_kwh'],
                (float) $entry['battery_energy_after_kwh'],
            ),
            $body['hourly_plan'],
        );

        $report = app(ScheduleReplayer::class)->verify(
            $scenario,
            ConstraintModel::build($scenario, $this->groundTruth($case)),
            Schedule::fromReported(
                $hours,
                (float) $body['total_grid_kwh'],
                (float) $body['total_cost_bdt'],
                (float) $body['peak_grid_kwh'],
            ),
        );

        $this->assertTrue($report->isValid(), implode(PHP_EOL, $report->messages()));
    }

    /**
     * @param  array<string, mixed>  $case
     */
    #[Test]
    #[DataProvider('cases')]
    public function every_note_produces_one_interpretation_entry_in_order(array $case): void
    {
        $body = $this->postJson('/optimize-energy', $case['input'])->assertOk()->json();

        $entries = $body['directive_interpretation'];

        $this->assertCount(count($case['input']['operator_notes']), $entries);

        foreach ($entries as $position => $entry) {
            $this->assertSame($position, $entry['note_index']);
            $this->assertContains($entry['directive_type'], [
                'solar_reduction',
                'minimum_battery_reserve',
                'no_charge_window',
                'no_discharge_window',
                'max_grid_window',
                'no_op',
            ]);

            if ($entry['directive_type'] === 'no_op') {
                $this->assertFalse($entry['applies']);
                $this->assertNull($entry['structured_adjustment']);

                continue;
            }

            $this->assertTrue($entry['applies']);
            $this->assertIsArray($entry['structured_adjustment']);

            $hours = $entry['structured_adjustment']['hours'];

            $this->assertSame(array_values(array_unique($hours)), $hours);

            $sorted = $hours;
            sort($sorted);

            $this->assertSame($sorted, $hours);

            foreach ($hours as $hour) {
                $this->assertIsInt($hour);
                $this->assertGreaterThanOrEqual(0, $hour);
                $this->assertLessThanOrEqual(23, $hour);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $case
     * @return list<Directive>
     */
    private function groundTruth(array $case): array
    {
        return array_map(
            Directive::fromResponseArray(...),
            $case['expected_output']['directive_interpretation'],
        );
    }
}
