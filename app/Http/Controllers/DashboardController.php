<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\GridWise\GridWiseService;
use App\GridWise\Llm\LlmManager;
use App\Http\Requests\OptimizeEnergyRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use JsonException;
use Throwable;

final class DashboardController extends Controller
{
    public function __construct(
        private readonly GridWiseService $gridwise,
        private readonly LlmManager $manager,
    ) {}

    public function index(): View
    {
        $driver = $this->manager->driver();

        return view('dashboard', [
            'samples' => $this->samples(),
            'provider' => [
                'driver' => $driver->name(),
                'model' => $driver->model(),
                'configured' => $driver->isConfigured(),
                'fallback' => (bool) config('gridwise.fallback.enabled'),
            ],
        ]);
    }

    public function optimize(OptimizeEnergyRequest $request): JsonResponse
    {
        try {
            $outcome = $this->gridwise->optimize($request->toScenario());
        } catch (Throwable) {
            return new JsonResponse([
                'error' => 'internal_error',
                'message' => 'The optimisation service could not complete this scenario.',
            ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse([
            'response' => $outcome->toResponseArray(),
            'diagnostics' => $outcome->toDiagnosticsArray(),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function samples(): array
    {
        $path = storage_path('app/gridwise/public_sample_cases.json');

        if (! is_file($path)) {
            return [];
        }

        try {
            $pack = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return array_map(static fn (array $case): array => [
            'id' => $case['id'],
            'label' => $case['label'] ?? $case['id'],
            'input' => $case['input'],
            'expected' => $case['expected_output']['directive_interpretation'] ?? [],
            'expected_cost' => $case['expected_output']['total_cost_bdt'] ?? null,
        ], $pack['cases'] ?? []);
    }
}
