<?php

declare(strict_types=1);

namespace App\GridWise\Optimizer;

use App\GridWise\Dto\ConstraintModel;
use App\GridWise\Dto\Schedule;

final readonly class OptimizationResult
{
    /**
     * @param  list<string>  $relaxedDirectiveTypes
     */
    public function __construct(
        public Schedule $schedule,
        public ConstraintModel $model,
        public array $relaxedDirectiveTypes = [],
    ) {}

    public function wasRelaxed(): bool
    {
        return $this->relaxedDirectiveTypes !== [];
    }
}
