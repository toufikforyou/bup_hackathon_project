<?php

declare(strict_types=1);

namespace App\GridWise\Interpretation;

use App\GridWise\Dto\Directive;
use App\GridWise\Dto\Scenario;
use App\GridWise\Llm\Contracts\LlmDriver;
use App\GridWise\Llm\LlmException;
use App\GridWise\Llm\LlmManager;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;
use Throwable;

final class DirectiveInterpreter
{
    public function __construct(
        private readonly LlmManager $manager,
        private readonly DirectiveGuard $guard,
        private readonly FallbackInterpreter $fallback,
        private readonly CacheRepository $cache,
        private readonly bool $cacheEnabled = true,
        private readonly int $cacheTtl = 3600,
        private readonly bool $fallbackEnabled = true,
    ) {}

    public function interpret(Scenario $scenario): InterpretationOutcome
    {
        $driver = $this->manager->driver();
        $cacheKey = $this->cacheKey($scenario, $driver);

        if ($this->cacheEnabled) {
            $cached = $this->cache->get($cacheKey);

            if (is_array($cached)) {
                return $this->rehydrate($cached, $driver);
            }
        }

        $startedAt = microtime(true);
        $outcome = $this->run($scenario, $driver, $startedAt);

        if ($this->cacheEnabled && $outcome->usedModel()) {
            $this->cache->put($cacheKey, $this->dehydrate($outcome), $this->cacheTtl);
        }

        return $outcome;
    }

    private function run(Scenario $scenario, LlmDriver $driver, float $startedAt): InterpretationOutcome
    {
        $noteIndexes = range(0, $scenario->noteCount() - 1);

        $issues = [];
        $accepted = [];
        $source = InterpretationOutcome::SOURCE_MODEL;

        $payload = $this->ask($driver, $scenario, $noteIndexes, $issues);

        if ($payload !== null) {
            $result = $this->guard->inspect($scenario, $payload, $noteIndexes);
            $accepted = $result->directives;
            $issues = [...$issues, ...$result->issues];
        }

        $missing = $this->missingIndexes($noteIndexes, $accepted);

        if ($missing !== [] && $payload !== null) {
            $repair = $this->ask($driver, $scenario, $missing, $issues);

            if ($repair !== null) {
                $result = $this->guard->inspect($scenario, $repair, $missing);
                $accepted += $result->directives;
                $issues = [...$issues, ...$result->issues];

                if ($result->directives !== []) {
                    $source = InterpretationOutcome::SOURCE_MODEL_REPAIRED;
                }
            }
        }

        $missing = $this->missingIndexes($noteIndexes, $accepted);
        $fellBack = [];

        if ($missing !== [] && $this->fallbackEnabled) {
            $result = $this->guard->inspect($scenario, $this->fallback->payload($scenario, $missing), $missing);
            $accepted += $result->directives;
            $fellBack = array_keys($result->directives);
        }

        foreach ($this->missingIndexes($noteIndexes, $accepted) as $index) {
            $accepted[$index] = Directive::noOp($index, 'No supported directive could be extracted from this note.');
            $fellBack[] = $index;
        }

        ksort($accepted);

        if ($accepted === [] || count($fellBack) === count($noteIndexes)) {
            $source = InterpretationOutcome::SOURCE_FALLBACK;
        }

        return new InterpretationOutcome(
            array_values($accepted),
            $source,
            $driver->name(),
            $driver->model(),
            array_values(array_unique($issues)),
            array_values(array_unique($fellBack)),
            round((microtime(true) - $startedAt) * 1000, 2),
        );
    }

    /**
     * @param  list<int>  $noteIndexes
     * @param  list<string>  $issues
     * @return array<string, mixed>|null
     */
    private function ask(LlmDriver $driver, Scenario $scenario, array $noteIndexes, array &$issues): ?array
    {
        if (! $driver->isConfigured()) {
            $issues[] = 'model provider is not configured';

            return null;
        }

        try {
            return $driver->structuredJson(
                InterpretationPrompt::system(),
                InterpretationPrompt::user($scenario, $noteIndexes),
                InterpretationPrompt::schema(),
            );
        } catch (LlmException $exception) {
            $issues[] = $exception->getMessage();
        } catch (Throwable $exception) {
            $issues[] = 'model provider call failed';

            Log::warning('GridWise interpretation call failed', [
                'driver' => $driver->name(),
                'exception' => $exception::class,
            ]);
        }

        return null;
    }

    /**
     * @param  list<int>  $noteIndexes
     * @param  array<int, Directive>  $accepted
     * @return list<int>
     */
    private function missingIndexes(array $noteIndexes, array $accepted): array
    {
        return array_values(array_filter(
            $noteIndexes,
            static fn (int $index): bool => ! isset($accepted[$index]),
        ));
    }

    private function cacheKey(Scenario $scenario, LlmDriver $driver): string
    {
        return 'gridwise:interpretation:'.hash('xxh128', json_encode([
            $driver->name(),
            $driver->model(),
            $scenario->operatorNotes,
            $scenario->battery->toArray(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function dehydrate(InterpretationOutcome $outcome): array
    {
        return [
            'directives' => array_map(
                static fn (Directive $directive): array => $directive->toResponseArray(),
                $outcome->directives,
            ),
            'source' => $outcome->source,
            'issues' => $outcome->guardIssues,
            'fallback' => $outcome->fallbackNoteIndexes,
            'latency' => $outcome->latencyMs,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function rehydrate(array $payload, LlmDriver $driver): InterpretationOutcome
    {
        return new InterpretationOutcome(
            array_map(Directive::fromResponseArray(...), $payload['directives']),
            (string) $payload['source'],
            $driver->name(),
            $driver->model(),
            $payload['issues'] ?? [],
            $payload['fallback'] ?? [],
            (float) ($payload['latency'] ?? 0.0),
            true,
        );
    }
}
