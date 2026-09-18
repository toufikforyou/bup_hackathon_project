<?php

declare(strict_types=1);

namespace App\GridWise\Interpretation;

use App\GridWise\Dto\Directive;

final readonly class InterpretationOutcome
{
    public const SOURCE_MODEL = 'model';

    public const SOURCE_MODEL_REPAIRED = 'model_repaired';

    public const SOURCE_FALLBACK = 'deterministic_fallback';

    /**
     * @param  list<Directive>  $directives
     * @param  list<string>  $guardIssues
     * @param  list<int>  $fallbackNoteIndexes
     */
    public function __construct(
        public array $directives,
        public string $source,
        public string $driver,
        public string $model,
        public array $guardIssues = [],
        public array $fallbackNoteIndexes = [],
        public float $latencyMs = 0.0,
        public bool $cached = false,
    ) {}

    public function usedModel(): bool
    {
        return $this->source !== self::SOURCE_FALLBACK;
    }
}
