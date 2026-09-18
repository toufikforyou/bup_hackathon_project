<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\GridWise\Dto\DirectiveType;
use App\GridWise\Dto\Scenario;
use App\GridWise\Interpretation\DirectiveGuard;
use App\GridWise\Interpretation\GuardResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DirectiveGuardTest extends TestCase
{
    private DirectiveGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new DirectiveGuard;
    }

    #[Test]
    public function it_expands_windows_with_an_exclusive_end_hour(): void
    {
        $result = $this->inspect([[
            'note_index' => 0,
            'directive_type' => 'solar_reduction',
            'windows' => [['start_hour' => 13, 'end_hour' => 15]],
            'value' => 0.2,
            'explanation' => 'panel work',
        ]]);

        $directive = $result->directives[0];

        $this->assertSame([13, 14], $directive->hours);
        $this->assertSame(['hours' => [13, 14], 'factor' => 0.2], $directive->structuredAdjustment());
    }

    #[Test]
    public function it_merges_multiple_windows_into_unique_ascending_hours(): void
    {
        $result = $this->inspect([[
            'note_index' => 0,
            'directive_type' => 'no_charge_window',
            'windows' => [
                ['start_hour' => 18, 'end_hour' => 20],
                ['start_hour' => 6, 'end_hour' => 8],
                ['start_hour' => 19, 'end_hour' => 21],
            ],
            'value' => null,
            'explanation' => '',
        ]]);

        $this->assertSame([6, 7, 18, 19, 20], $result->directives[0]->hours);
    }

    #[Test]
    public function it_wraps_windows_that_cross_midnight(): void
    {
        $result = $this->inspect([[
            'note_index' => 0,
            'directive_type' => 'no_discharge_window',
            'windows' => [['start_hour' => 22, 'end_hour' => 2]],
            'value' => null,
            'explanation' => '',
        ]]);

        $this->assertSame([0, 1, 22, 23], $result->directives[0]->hours);
    }

    #[Test]
    public function it_rewrites_a_percentage_factor_into_a_fraction(): void
    {
        $result = $this->inspect([[
            'note_index' => 0,
            'directive_type' => 'solar_reduction',
            'windows' => [['start_hour' => 10, 'end_hour' => 12]],
            'value' => 25,
            'explanation' => '',
        ]]);

        $this->assertSame(0.25, $result->directives[0]->value);
    }

    #[Test]
    public function it_resolves_a_reserve_expressed_as_a_share_of_capacity(): void
    {
        $result = $this->inspect(
            [[
                'note_index' => 0,
                'directive_type' => 'minimum_battery_reserve',
                'windows' => [['start_hour' => 18, 'end_hour' => 21]],
                'value' => 0.5,
                'explanation' => '',
            ]],
            ['Keep at least 50% of the battery capacity stored from 6 PM until 9 PM.'],
        );

        $this->assertSame(100.0, $result->directives[0]->value);
    }

    #[Test]
    public function it_caps_a_reserve_at_battery_capacity(): void
    {
        $result = $this->inspect([[
            'note_index' => 0,
            'directive_type' => 'minimum_battery_reserve',
            'windows' => [['start_hour' => 1, 'end_hour' => 2]],
            'value' => 9000,
            'explanation' => '',
        ]]);

        $this->assertSame(200.0, $result->directives[0]->value);
    }

    #[Test]
    public function it_rejects_an_unsupported_directive_type(): void
    {
        $result = $this->inspect([[
            'note_index' => 0,
            'directive_type' => 'shed_load',
            'windows' => [['start_hour' => 1, 'end_hour' => 2]],
            'value' => 5,
            'explanation' => '',
        ]]);

        $this->assertSame([], $result->directives);
        $this->assertSame([0], $result->rejectedNoteIndexes);
    }

    #[Test]
    public function it_rejects_a_directive_that_needs_a_number_but_has_none(): void
    {
        $result = $this->inspect([[
            'note_index' => 0,
            'directive_type' => 'max_grid_window',
            'windows' => [['start_hour' => 19, 'end_hour' => 21]],
            'value' => null,
            'explanation' => '',
        ]]);

        $this->assertSame([0], $result->rejectedNoteIndexes);
    }

    #[Test]
    public function it_rejects_duplicate_and_unknown_note_indexes(): void
    {
        $result = $this->inspect([
            ['note_index' => 0, 'directive_type' => 'no_op', 'windows' => [], 'value' => null, 'explanation' => ''],
            ['note_index' => 0, 'directive_type' => 'no_op', 'windows' => [], 'value' => null, 'explanation' => ''],
            ['note_index' => 7, 'directive_type' => 'no_op', 'windows' => [], 'value' => null, 'explanation' => ''],
        ]);

        $this->assertCount(1, $result->directives);
        $this->assertNotEmpty($result->issues);
    }

    #[Test]
    public function a_no_op_never_applies_and_has_a_null_adjustment(): void
    {
        $result = $this->inspect([[
            'note_index' => 0,
            'directive_type' => 'no_op',
            'windows' => [['start_hour' => 4, 'end_hour' => 9]],
            'value' => 42,
            'explanation' => 'unrelated',
        ]]);

        $directive = $result->directives[0];

        $this->assertSame(DirectiveType::NoOp, $directive->type);
        $this->assertFalse($directive->applies());
        $this->assertNull($directive->structuredAdjustment());
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @param  list<string>|null  $notes
     */
    private function inspect(array $entries, ?array $notes = null): GuardResult
    {
        $scenario = Scenario::fromArray([
            'scenario_id' => 'T-1',
            'operator_notes' => $notes ?? ['note'],
            'hours' => array_map(static fn (int $hour): array => [
                'hour' => $hour,
                'demand_kwh' => 100,
                'solar_kwh' => 0,
                'tariff_bdt_per_kwh' => 10,
            ], range(0, 23)),
            'battery' => [
                'capacity_kwh' => 200,
                'initial_energy_kwh' => 100,
                'minimum_energy_kwh' => 40,
                'max_charge_kwh_per_hour' => 50,
                'max_discharge_kwh_per_hour' => 50,
            ],
        ]);

        return $this->guard->inspect($scenario, ['interpretations' => $entries], [0]);
    }
}
