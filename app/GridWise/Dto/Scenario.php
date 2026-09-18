<?php

declare(strict_types=1);

namespace App\GridWise\Dto;

final readonly class Scenario
{
    public const HORIZON = 24;

    /**
     * @param  list<string>  $operatorNotes
     * @param  list<float>  $demandKwh
     * @param  list<float>  $solarKwh
     * @param  list<float>  $tariff
     */
    public function __construct(
        public string $scenarioId,
        public array $operatorNotes,
        public array $demandKwh,
        public array $solarKwh,
        public array $tariff,
        public Battery $battery,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $demand = array_fill(0, self::HORIZON, 0.0);
        $solar = array_fill(0, self::HORIZON, 0.0);
        $tariff = array_fill(0, self::HORIZON, 0.0);

        foreach ($payload['hours'] as $entry) {
            $hour = (int) $entry['hour'];
            $demand[$hour] = (float) $entry['demand_kwh'];
            $solar[$hour] = (float) $entry['solar_kwh'];
            $tariff[$hour] = (float) $entry['tariff_bdt_per_kwh'];
        }

        return new self(
            (string) $payload['scenario_id'],
            array_values(array_map(
                static fn (mixed $note): string => (string) $note,
                $payload['operator_notes'],
            )),
            $demand,
            $solar,
            $tariff,
            Battery::fromArray($payload['battery']),
        );
    }

    public function noteCount(): int
    {
        return count($this->operatorNotes);
    }

    /**
     * @return list<array{hour: int, demand_kwh: float, solar_kwh: float, tariff_bdt_per_kwh: float}>
     */
    public function hourTable(): array
    {
        $rows = [];

        for ($hour = 0; $hour < self::HORIZON; $hour++) {
            $rows[] = [
                'hour' => $hour,
                'demand_kwh' => $this->demandKwh[$hour],
                'solar_kwh' => $this->solarKwh[$hour],
                'tariff_bdt_per_kwh' => $this->tariff[$hour],
            ];
        }

        return $rows;
    }
}
