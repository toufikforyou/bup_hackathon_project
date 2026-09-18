<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\GridWise\Optimizer\LinearProgram;
use App\GridWise\Optimizer\Simplex;
use App\GridWise\Optimizer\SimplexResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SimplexTest extends TestCase
{
    #[Test]
    public function it_solves_a_bounded_minimisation(): void
    {
        $program = new LinearProgram;
        $x = $program->addVariable(2.0);
        $y = $program->addVariable(3.0);

        $program->addConstraint([$x => 1.0, $y => 1.0], LinearProgram::GE, 10.0);
        $program->addConstraint([$x => 1.0], LinearProgram::LE, 6.0);

        $result = (new Simplex)->solve($program);

        $this->assertSame(SimplexResult::OPTIMAL, $result->status);
        $this->assertEqualsWithDelta(6.0, $result->value($x), 1e-7);
        $this->assertEqualsWithDelta(4.0, $result->value($y), 1e-7);
        $this->assertEqualsWithDelta(24.0, $result->objectiveValue, 1e-7);
    }

    #[Test]
    public function it_honours_equality_constraints(): void
    {
        $program = new LinearProgram;
        $a = $program->addVariable(1.0);
        $b = $program->addVariable(4.0);

        $program->addConstraint([$a => 1.0, $b => 1.0], LinearProgram::EQ, 12.0);
        $program->addConstraint([$a => 1.0], LinearProgram::LE, 5.0);

        $result = (new Simplex)->solve($program);

        $this->assertSame(SimplexResult::OPTIMAL, $result->status);
        $this->assertEqualsWithDelta(5.0, $result->value($a), 1e-7);
        $this->assertEqualsWithDelta(7.0, $result->value($b), 1e-7);
    }

    #[Test]
    public function it_detects_infeasible_programs(): void
    {
        $program = new LinearProgram;
        $x = $program->addVariable(1.0);

        $program->addConstraint([$x => 1.0], LinearProgram::GE, 10.0);
        $program->addConstraint([$x => 1.0], LinearProgram::LE, 4.0);

        $this->assertSame(SimplexResult::INFEASIBLE, (new Simplex)->solve($program)->status);
    }

    #[Test]
    public function it_detects_unbounded_programs(): void
    {
        $program = new LinearProgram;
        $x = $program->addVariable(-1.0);

        $program->addConstraint([$x => 1.0], LinearProgram::GE, 1.0);

        $this->assertSame(SimplexResult::UNBOUNDED, (new Simplex)->solve($program)->status);
    }

    #[Test]
    public function it_applies_the_objective_offset(): void
    {
        $program = new LinearProgram;
        $x = $program->addVariable(1.0);
        $program->addObjectiveOffset(100.0);
        $program->addConstraint([$x => 1.0], LinearProgram::GE, 5.0);

        $this->assertEqualsWithDelta(105.0, (new Simplex)->solve($program)->objectiveValue, 1e-7);
    }
}
