<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\GridWise\GridWiseService;
use App\Http\Requests\OptimizeEnergyRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

final class OptimizeEnergyController extends Controller
{
    public function __construct(private readonly GridWiseService $gridwise) {}

    public function __invoke(OptimizeEnergyRequest $request): JsonResponse
    {
        try {
            $outcome = $this->gridwise->optimize($request->toScenario());
        } catch (Throwable $exception) {
            Log::error('GridWise optimisation failed', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return new JsonResponse([
                'error' => 'internal_error',
                'message' => 'The optimisation service could not complete this scenario.',
            ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse($outcome->toResponseArray());
    }
}
