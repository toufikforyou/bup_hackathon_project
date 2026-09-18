<?php

declare(strict_types=1);

namespace App\GridWise\Dto;

final readonly class Battery
{
    public function __construct(
        public float $capacityKwh,
        public float $initialEnergyKwh,
        public float $minimumEnergyKwh,
        public float $maxChargeKwhPerHour,
        public float $maxDischargeKwhPerHour,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            (float) $payload['capacity_kwh'],
            (float) $payload['initial_energy_kwh'],
            (float) $payload['minimum_energy_kwh'],
            (float) $payload['max_charge_kwh_per_hour'],
            (float) $payload['max_discharge_kwh_per_hour'],
        );
    }

    /** @return array<string, float> */
    public function toArray(): array
    {
        return [
            'capacity_kwh' => $this->capacityKwh,
            'initial_energy_kwh' => $this->initialEnergyKwh,
            'minimum_energy_kwh' => $this->minimumEnergyKwh,
            'max_charge_kwh_per_hour' => $this->maxChargeKwhPerHour,
            'max_discharge_kwh_per_hour' => $this->maxDischargeKwhPerHour,
        ];
    }
}
