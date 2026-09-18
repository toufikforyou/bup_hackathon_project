<?php

declare(strict_types=1);

namespace App\GridWise\Dto;

final readonly class HourPlan
{
    public const CHARGE = 'charge';

    public const DISCHARGE = 'discharge';

    public const IDLE = 'idle';

    public function __construct(
        public int $hour,
        public float $gridKwh,
        public float $solarUsedKwh,
        public string $batteryAction,
        public float $batteryKwh,
        public float $batteryEnergyAfterKwh,
    ) {}

    public function chargeKwh(): float
    {
        return $this->batteryAction === self::CHARGE ? $this->batteryKwh : 0.0;
    }

    public function dischargeKwh(): float
    {
        return $this->batteryAction === self::DISCHARGE ? $this->batteryKwh : 0.0;
    }

    public function toArray(): array
    {
        return [
            'hour' => $this->hour,
            'grid_kwh' => $this->gridKwh,
            'solar_used_kwh' => $this->solarUsedKwh,
            'battery_action' => $this->batteryAction,
            'battery_kwh' => $this->batteryKwh,
            'battery_energy_after_kwh' => $this->batteryEnergyAfterKwh,
        ];
    }
}
