<?php

declare(strict_types=1);

namespace App\GridWise\Dto;

enum DirectiveType: string
{
    case SolarReduction = 'solar_reduction';
    case MinimumBatteryReserve = 'minimum_battery_reserve';
    case NoChargeWindow = 'no_charge_window';
    case NoDischargeWindow = 'no_discharge_window';
    case MaxGridWindow = 'max_grid_window';
    case NoOp = 'no_op';

    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function isNoOp(): bool
    {
        return $this === self::NoOp;
    }

    public function numericKey(): ?string
    {
        return match ($this) {
            self::SolarReduction => 'factor',
            self::MinimumBatteryReserve => 'minimum_energy_kwh',
            self::MaxGridWindow => 'max_grid_kwh',
            default => null,
        };
    }
}
