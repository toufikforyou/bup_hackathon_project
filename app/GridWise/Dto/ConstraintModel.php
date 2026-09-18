<?php

declare(strict_types=1);

namespace App\GridWise\Dto;

final readonly class ConstraintModel
{
    /**
     * @param  list<float>  $effectiveSolarKwh
     * @param  list<float>  $minimumEnergyKwh
     * @param  list<bool>  $chargeBlocked
     * @param  list<bool>  $dischargeBlocked
     * @param  list<float|null>  $maxGridKwh
     */
    public function __construct(
        public array $effectiveSolarKwh,
        public array $minimumEnergyKwh,
        public array $chargeBlocked,
        public array $dischargeBlocked,
        public array $maxGridKwh,
    ) {}

    /**
     * @param  list<Directive>  $directives
     */
    public static function build(Scenario $scenario, array $directives): self
    {
        $battery = $scenario->battery;

        $effectiveSolar = $scenario->solarKwh;
        $minimumEnergy = array_fill(0, Scenario::HORIZON, $battery->minimumEnergyKwh);
        $chargeBlocked = array_fill(0, Scenario::HORIZON, false);
        $dischargeBlocked = array_fill(0, Scenario::HORIZON, false);
        $maxGrid = array_fill(0, Scenario::HORIZON, null);

        foreach ($directives as $directive) {
            if (! $directive->applies()) {
                continue;
            }

            foreach ($directive->hours as $hour) {
                match ($directive->type) {
                    DirectiveType::SolarReduction => $effectiveSolar[$hour] = min(
                        $effectiveSolar[$hour],
                        $scenario->solarKwh[$hour] * (float) $directive->value,
                    ),
                    DirectiveType::MinimumBatteryReserve => $minimumEnergy[$hour] = max(
                        $minimumEnergy[$hour],
                        (float) $directive->value,
                    ),
                    DirectiveType::NoChargeWindow => $chargeBlocked[$hour] = true,
                    DirectiveType::NoDischargeWindow => $dischargeBlocked[$hour] = true,
                    DirectiveType::MaxGridWindow => $maxGrid[$hour] = $maxGrid[$hour] === null
                        ? (float) $directive->value
                        : min($maxGrid[$hour], (float) $directive->value),
                    DirectiveType::NoOp => null,
                };
            }
        }

        return new self($effectiveSolar, $minimumEnergy, $chargeBlocked, $dischargeBlocked, $maxGrid);
    }

    public static function baseline(Scenario $scenario): self
    {
        return self::build($scenario, []);
    }
}
