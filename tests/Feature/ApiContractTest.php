<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\GridWise\Llm\Contracts\LlmDriver;
use App\GridWise\Llm\LlmManager;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FailingDriver;
use Tests\Support\SampleCases;
use Tests\TestCase;

final class ApiContractTest extends TestCase
{
    #[Test]
    public function health_reports_readiness(): void
    {
        $this->getJson('/health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);
    }

    #[Test]
    public function it_returns_every_required_response_field(): void
    {
        $case = SampleCases::first();

        $this->postJson('/optimize-energy', $case['input'])
            ->assertOk()
            ->assertJsonStructure([
                'scenario_id',
                'directive_interpretation' => [
                    ['note_index', 'applies', 'directive_type', 'structured_adjustment', 'explanation'],
                ],
                'hourly_plan' => [
                    ['hour', 'grid_kwh', 'solar_used_kwh', 'battery_action', 'battery_kwh', 'battery_energy_after_kwh'],
                ],
                'total_grid_kwh',
                'total_cost_bdt',
                'peak_grid_kwh',
                'plan_summary',
            ])
            ->assertJsonPath('scenario_id', $case['input']['scenario_id'])
            ->assertJsonCount(24, 'hourly_plan');
    }

    #[Test]
    public function reported_totals_match_the_returned_plan(): void
    {
        $case = SampleCases::first();
        $body = $this->postJson('/optimize-energy', $case['input'])->assertOk()->json();

        $tariff = [];

        foreach ($case['input']['hours'] as $hour) {
            $tariff[$hour['hour']] = (float) $hour['tariff_bdt_per_kwh'];
        }

        $grid = 0.0;
        $cost = 0.0;
        $peak = 0.0;

        foreach ($body['hourly_plan'] as $entry) {
            $grid += $entry['grid_kwh'];
            $cost += $entry['grid_kwh'] * $tariff[$entry['hour']];
            $peak = max($peak, $entry['grid_kwh']);
        }

        $this->assertEqualsWithDelta($grid, $body['total_grid_kwh'], 0.01);
        $this->assertEqualsWithDelta($cost, $body['total_cost_bdt'], 0.01);
        $this->assertEqualsWithDelta($peak, $body['peak_grid_kwh'], 0.01);
    }

    #[Test]
    public function malformed_json_is_rejected_with_400(): void
    {
        $this->call('POST', '/optimize-energy', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: '{"scenario_id": ')
            ->assertStatus(400)
            ->assertJsonPath('error', 'bad_request');
    }

    #[Test]
    public function a_structurally_invalid_request_is_rejected_with_400(): void
    {
        $this->postJson('/optimize-energy', ['scenario_id' => 'X'])
            ->assertStatus(400)
            ->assertJsonPath('error', 'bad_request');
    }

    #[Test]
    public function too_many_operator_notes_are_rejected(): void
    {
        $input = SampleCases::first()['input'];
        $input['operator_notes'] = ['a', 'b', 'c', 'd'];

        $this->postJson('/optimize-energy', $input)->assertStatus(400);
    }

    #[Test]
    public function an_impossible_battery_is_rejected_with_422(): void
    {
        $input = SampleCases::first()['input'];
        $input['battery']['initial_energy_kwh'] = $input['battery']['capacity_kwh'] + 50;

        $this->postJson('/optimize-energy', $input)
            ->assertStatus(422)
            ->assertJsonPath('error', 'unprocessable_entity');
    }

    #[Test]
    public function a_failing_model_provider_still_returns_a_valid_plan(): void
    {
        $manager = app(LlmManager::class);
        $manager->extend('broken', static fn (): LlmDriver => new FailingDriver);
        $manager->useDriver('broken');

        $this->postJson('/optimize-energy', SampleCases::first()['input'])
            ->assertOk()
            ->assertJsonCount(24, 'hourly_plan')
            ->assertJsonCount(count(SampleCases::first()['input']['operator_notes']), 'directive_interpretation');
    }

    #[Test]
    public function error_responses_never_leak_internals(): void
    {
        $body = $this->postJson('/optimize-energy', ['scenario_id' => 'X'])->json();

        $encoded = json_encode($body);

        $this->assertStringNotContainsString('vendor/', (string) $encoded);
        $this->assertStringNotContainsString('Stack trace', (string) $encoded);
        $this->assertArrayNotHasKey('trace', $body);
        $this->assertArrayNotHasKey('exception', $body);
    }

    #[Test]
    public function a_wrong_http_method_returns_a_clean_405(): void
    {
        $this->getJson('/optimize-energy')
            ->assertStatus(405)
            ->assertJsonPath('error', 'method_not_allowed');
    }

    #[Test]
    public function browsing_to_the_console_endpoint_redirects_to_the_dashboard(): void
    {
        $this->get('/console/optimize')->assertRedirect('/');
    }

    #[Test]
    public function errors_never_leak_a_stack_trace_even_with_debug_enabled(): void
    {
        config(['app.debug' => true]);

        foreach ([
            $this->getJson('/optimize-energy'),
            $this->getJson('/does-not-exist'),
            $this->postJson('/optimize-energy', ['scenario_id' => 'X']),
        ] as $response) {
            $body = (string) $response->getContent();

            $this->assertStringNotContainsString('vendor/', $body);
            $this->assertStringNotContainsString('Symfony\\Component', $body);
            $this->assertStringNotContainsString('AbstractRouteCollection', $body);
            $this->assertStringNotContainsString('trace', $body);
        }
    }

    #[Test]
    public function an_unknown_endpoint_returns_a_clean_404(): void
    {
        $this->getJson('/optimise-energy')
            ->assertStatus(404)
            ->assertJsonPath('error', 'not_found');
    }
}
