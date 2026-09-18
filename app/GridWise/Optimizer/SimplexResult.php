<?php

declare(strict_types=1);

namespace App\GridWise\Optimizer;

final readonly class SimplexResult
{
    public const OPTIMAL = 'optimal';

    public const INFEASIBLE = 'infeasible';

    public const UNBOUNDED = 'unbounded';

    public const ITERATION_LIMIT = 'iteration_limit';

    /**
     * @param  list<float>  $values
     */
    public function __construct(
        public string $status,
        public array $values = [],
        public float $objectiveValue = 0.0,
        public int $iterations = 0,
    ) {}

    public function isOptimal(): bool
    {
        return $this->status === self::OPTIMAL;
    }

    public function value(int $variable): float
    {
        return $this->values[$variable] ?? 0.0;
    }
}
