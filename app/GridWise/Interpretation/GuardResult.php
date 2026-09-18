<?php

declare(strict_types=1);

namespace App\GridWise\Interpretation;

use App\GridWise\Dto\Directive;

final readonly class GuardResult
{
    /**
     * @param  array<int, Directive>  $directives
     * @param  list<int>  $rejectedNoteIndexes
     * @param  list<string>  $issues
     */
    public function __construct(
        public array $directives,
        public array $rejectedNoteIndexes,
        public array $issues,
    ) {}

    public function isComplete(): bool
    {
        return $this->rejectedNoteIndexes === [];
    }
}
