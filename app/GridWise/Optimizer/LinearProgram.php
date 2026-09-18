<?php

declare(strict_types=1);

namespace App\GridWise\Optimizer;

final class LinearProgram
{
    public const LE = '<=';

    public const EQ = '=';

    public const GE = '>=';

    /** @var list<float> */
    private array $objective = [];

    /** @var list<string> */
    private array $names = [];

    /** @var list<array{coefficients: array<int, float>, relation: string, rhs: float}> */
    private array $rows = [];

    private float $objectiveOffset = 0.0;

    public function addVariable(float $objectiveCoefficient = 0.0, string $name = ''): int
    {
        $this->objective[] = $objectiveCoefficient;
        $this->names[] = $name;

        return count($this->objective) - 1;
    }

    public function addObjectiveOffset(float $constant): void
    {
        $this->objectiveOffset += $constant;
    }

    /**
     * @param  array<int, float>  $coefficients
     */
    public function addConstraint(array $coefficients, string $relation, float $rhs): void
    {
        $clean = [];

        foreach ($coefficients as $index => $coefficient) {
            if ($coefficient === 0.0) {
                continue;
            }

            $clean[$index] = ($clean[$index] ?? 0.0) + $coefficient;
        }

        if ($clean === []) {
            $violated = match ($relation) {
                self::LE => $rhs < -1e-9,
                self::GE => $rhs > 1e-9,
                default => abs($rhs) > 1e-9,
            };

            if (! $violated) {
                return;
            }
        }

        $this->rows[] = [
            'coefficients' => $clean,
            'relation' => $relation,
            'rhs' => $rhs,
        ];
    }

    public function variableCount(): int
    {
        return count($this->objective);
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }

    /** @return list<float> */
    public function objective(): array
    {
        return $this->objective;
    }

    public function objectiveOffset(): float
    {
        return $this->objectiveOffset;
    }

    /** @return list<array{coefficients: array<int, float>, relation: string, rhs: float}> */
    public function rows(): array
    {
        return $this->rows;
    }

    /** @return list<string> */
    public function names(): array
    {
        return $this->names;
    }
}
