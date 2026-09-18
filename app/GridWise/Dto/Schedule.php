<?php

declare(strict_types=1);

namespace App\GridWise\Dto;

final readonly class Schedule
{
    /**
     * @param  list<HourPlan>  $hours
     */
    private function __construct(
        public array $hours,
        public float $totalGridKwh,
        public float $totalCostBdt,
        public float $peakGridKwh,
    ) {}

    /**
     * @param  list<HourPlan>  $hours
     */
    public static function fromReported(
        array $hours,
        float $totalGridKwh,
        float $totalCostBdt,
        float $peakGridKwh,
    ): self {
        return new self($hours, $totalGridKwh, $totalCostBdt, $peakGridKwh);
    }

    /**
     * @param  list<HourPlan>  $hours
     * @param  list<float>  $tariff
     */
    public static function fromHours(array $hours, array $tariff, int $decimals = 6): self
    {
        $totalGrid = 0.0;
        $totalCost = 0.0;
        $peak = 0.0;

        foreach ($hours as $plan) {
            $totalGrid += $plan->gridKwh;
            $totalCost += $plan->gridKwh * $tariff[$plan->hour];
            $peak = max($peak, $plan->gridKwh);
        }

        return new self(
            $hours,
            round($totalGrid, $decimals),
            round($totalCost, $decimals),
            round($peak, $decimals),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(static fn (HourPlan $plan): array => $plan->toArray(), $this->hours);
    }
}
