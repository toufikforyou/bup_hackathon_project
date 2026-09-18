<?php

declare(strict_types=1);

namespace App\GridWise\Optimizer;

final class Simplex
{
    private const EPS = 1.0e-9;

    private const PIVOT_EPS = 1.0e-7;

    /** @var list<list<float>> */
    private array $tableau = [];

    /** @var list<float> */
    private array $costRow = [];

    /** @var list<int> */
    private array $basis = [];

    /** @var array<int, bool> */
    private array $excluded = [];

    private int $width = 0;

    private int $rhsIndex = 0;

    private int $artificialStart = 0;

    private int $iterations = 0;

    public function __construct(
        private readonly int $maxIterations = 20000,
        private readonly int $blandAfter = 4000,
    ) {}

    public function solve(LinearProgram $program): SimplexResult
    {
        $this->build($program);

        if ($this->tableau === []) {
            foreach ($program->objective() as $coefficient) {
                if ($coefficient < -self::EPS) {
                    return new SimplexResult(SimplexResult::UNBOUNDED);
                }
            }

            return new SimplexResult(
                SimplexResult::OPTIMAL,
                array_fill(0, $program->variableCount(), 0.0),
                $program->objectiveOffset(),
            );
        }

        $phaseOne = $this->runPhaseOne();

        if ($phaseOne !== null) {
            return $phaseOne;
        }

        $status = $this->runPhaseTwo($program);

        if ($status !== SimplexResult::OPTIMAL) {
            return new SimplexResult($status, iterations: $this->iterations);
        }

        $values = $this->extractValues($program->variableCount());

        $objective = $program->objectiveOffset();

        foreach ($program->objective() as $index => $coefficient) {
            $objective += $coefficient * $values[$index];
        }

        return new SimplexResult(SimplexResult::OPTIMAL, $values, $objective, $this->iterations);
    }

    private function build(LinearProgram $program): void
    {
        $rows = $program->rows();

        if ($rows === []) {
            $this->tableau = [];

            return;
        }

        $variableCount = $program->variableCount();

        $normalised = [];
        $slackCount = 0;
        $artificialCount = 0;

        foreach ($rows as $row) {
            $coefficients = $row['coefficients'];
            $relation = $row['relation'];
            $rhs = $row['rhs'];

            if ($rhs < 0.0) {
                foreach ($coefficients as $index => $coefficient) {
                    $coefficients[$index] = -$coefficient;
                }

                $rhs = -$rhs;

                $relation = match ($relation) {
                    LinearProgram::LE => LinearProgram::GE,
                    LinearProgram::GE => LinearProgram::LE,
                    default => LinearProgram::EQ,
                };
            }

            if ($relation !== LinearProgram::EQ) {
                $slackCount++;
            }

            if ($relation !== LinearProgram::LE) {
                $artificialCount++;
            }

            $normalised[] = [$coefficients, $relation, $rhs];
        }

        $this->artificialStart = $variableCount + $slackCount;
        $total = $this->artificialStart + $artificialCount;

        $this->width = $total + 1;
        $this->rhsIndex = $total;

        $empty = array_fill(0, $this->width, 0.0);

        $this->tableau = [];
        $this->basis = [];
        $this->excluded = [];

        $nextSlack = $variableCount;
        $nextArtificial = $this->artificialStart;

        foreach ($normalised as [$coefficients, $relation, $rhs]) {
            $line = $empty;

            foreach ($coefficients as $index => $coefficient) {
                $line[$index] = $coefficient;
            }

            $line[$this->rhsIndex] = $rhs;

            if ($relation === LinearProgram::LE) {
                $line[$nextSlack] = 1.0;
                $this->basis[] = $nextSlack;
                $nextSlack++;
            } elseif ($relation === LinearProgram::GE) {
                $line[$nextSlack] = -1.0;
                $nextSlack++;
                $line[$nextArtificial] = 1.0;
                $this->basis[] = $nextArtificial;
                $this->excluded[$nextArtificial] = true;
                $nextArtificial++;
            } else {
                $line[$nextArtificial] = 1.0;
                $this->basis[] = $nextArtificial;
                $this->excluded[$nextArtificial] = true;
                $nextArtificial++;
            }

            $this->tableau[] = $line;
        }
    }

    private function runPhaseOne(): ?SimplexResult
    {
        if ($this->artificialStart >= $this->rhsIndex) {
            return null;
        }

        $costs = array_fill(0, $this->width, 0.0);

        for ($column = $this->artificialStart; $column < $this->rhsIndex; $column++) {
            $costs[$column] = 1.0;
        }

        $artificialColumns = $this->excluded;
        $this->excluded = [];

        $this->buildCostRow($costs);

        if ($this->pivotToOptimality() === SimplexResult::ITERATION_LIMIT) {
            return new SimplexResult(SimplexResult::ITERATION_LIMIT, iterations: $this->iterations);
        }

        if (-$this->costRow[$this->rhsIndex] > 1.0e-7) {
            return new SimplexResult(SimplexResult::INFEASIBLE, iterations: $this->iterations);
        }

        $this->excluded = $artificialColumns;
        $this->driveOutArtificials();

        return null;
    }

    private function driveOutArtificials(): void
    {
        foreach ($this->basis as $row => $variable) {
            if ($variable < $this->artificialStart) {
                continue;
            }

            $line = $this->tableau[$row];
            $pivotColumn = null;

            for ($column = 0; $column < $this->artificialStart; $column++) {
                if (abs($line[$column]) > self::PIVOT_EPS) {
                    $pivotColumn = $column;
                    break;
                }
            }

            if ($pivotColumn === null) {
                $this->tableau[$row] = array_fill(0, $this->width, 0.0);

                continue;
            }

            $this->pivot($row, $pivotColumn);
        }
    }

    private function runPhaseTwo(LinearProgram $program): string
    {
        $costs = array_fill(0, $this->width, 0.0);

        foreach ($program->objective() as $index => $coefficient) {
            $costs[$index] = $coefficient;
        }

        $this->buildCostRow($costs);

        return $this->pivotToOptimality();
    }

    /**
     * @param  list<float>  $costs
     */
    private function buildCostRow(array $costs): void
    {
        $this->costRow = $costs;
        $this->costRow[$this->rhsIndex] = 0.0;

        foreach ($this->basis as $row => $variable) {
            $basicCost = $costs[$variable];

            if ($basicCost === 0.0) {
                continue;
            }

            $line = $this->tableau[$row];

            for ($column = 0; $column <= $this->rhsIndex; $column++) {
                if ($line[$column] !== 0.0) {
                    $this->costRow[$column] -= $basicCost * $line[$column];
                }
            }
        }
    }

    private function pivotToOptimality(): string
    {
        $rowCount = count($this->tableau);

        while (true) {
            if ($this->iterations >= $this->maxIterations) {
                return SimplexResult::ITERATION_LIMIT;
            }

            $entering = $this->chooseEnteringColumn($this->iterations >= $this->blandAfter);

            if ($entering === null) {
                return SimplexResult::OPTIMAL;
            }

            $leaving = null;
            $bestRatio = INF;
            $bestBasis = PHP_INT_MAX;

            for ($row = 0; $row < $rowCount; $row++) {
                $coefficient = $this->tableau[$row][$entering];

                if ($coefficient <= self::PIVOT_EPS) {
                    continue;
                }

                $rhs = $this->tableau[$row][$this->rhsIndex];
                $ratio = ($rhs <= 0.0 ? 0.0 : $rhs) / $coefficient;

                if ($ratio < $bestRatio - self::EPS) {
                    $bestRatio = $ratio;
                    $leaving = $row;
                    $bestBasis = $this->basis[$row];

                    continue;
                }

                if ($ratio <= $bestRatio + self::EPS && $this->basis[$row] < $bestBasis) {
                    $bestRatio = min($bestRatio, $ratio);
                    $leaving = $row;
                    $bestBasis = $this->basis[$row];
                }
            }

            if ($leaving === null) {
                return SimplexResult::UNBOUNDED;
            }

            $this->pivot($leaving, $entering);
            $this->iterations++;
        }
    }

    private function chooseEnteringColumn(bool $useBland): ?int
    {
        $best = null;
        $bestValue = -self::EPS;

        for ($column = 0; $column < $this->rhsIndex; $column++) {
            if (isset($this->excluded[$column])) {
                continue;
            }

            $reduced = $this->costRow[$column];

            if ($reduced >= -self::EPS) {
                continue;
            }

            if ($useBland) {
                return $column;
            }

            if ($reduced < $bestValue) {
                $bestValue = $reduced;
                $best = $column;
            }
        }

        return $best;
    }

    private function pivot(int $pivotRow, int $pivotColumn): void
    {
        $line = $this->tableau[$pivotRow];
        $pivotValue = $line[$pivotColumn];

        if ($pivotValue !== 1.0) {
            $inverse = 1.0 / $pivotValue;

            for ($column = 0; $column <= $this->rhsIndex; $column++) {
                if ($line[$column] !== 0.0) {
                    $line[$column] *= $inverse;
                }
            }

            $line[$pivotColumn] = 1.0;
            $this->tableau[$pivotRow] = $line;
        }

        $rowCount = count($this->tableau);

        for ($row = 0; $row < $rowCount; $row++) {
            if ($row === $pivotRow) {
                continue;
            }

            $factor = $this->tableau[$row][$pivotColumn];

            if ($factor === 0.0) {
                continue;
            }

            $target = $this->tableau[$row];

            for ($column = 0; $column <= $this->rhsIndex; $column++) {
                if ($line[$column] !== 0.0) {
                    $target[$column] -= $factor * $line[$column];
                }
            }

            $target[$pivotColumn] = 0.0;
            $this->tableau[$row] = $target;
        }

        $factor = $this->costRow[$pivotColumn];

        if ($factor !== 0.0) {
            for ($column = 0; $column <= $this->rhsIndex; $column++) {
                if ($line[$column] !== 0.0) {
                    $this->costRow[$column] -= $factor * $line[$column];
                }
            }

            $this->costRow[$pivotColumn] = 0.0;
        }

        $this->basis[$pivotRow] = $pivotColumn;
    }

    /** @return list<float> */
    private function extractValues(int $variableCount): array
    {
        $values = array_fill(0, $variableCount, 0.0);

        foreach ($this->basis as $row => $variable) {
            if ($variable >= $variableCount) {
                continue;
            }

            $value = $this->tableau[$row][$this->rhsIndex];

            $values[$variable] = abs($value) < self::EPS ? 0.0 : $value;
        }

        return $values;
    }
}
