<?php

declare(strict_types=1);

namespace App\GridWise\Interpretation;

use App\GridWise\Dto\DirectiveType;
use App\GridWise\Dto\Scenario;

final class FallbackInterpreter
{
    private const WORD_FRACTIONS = [
        'half' => 0.5,
        'a third' => 0.33,
        'one third' => 0.33,
        'a quarter' => 0.25,
        'one quarter' => 0.25,
        'a fifth' => 0.2,
        'one fifth' => 0.2,
        'one-fifth' => 0.2,
        'one-third' => 0.33,
        'one-quarter' => 0.25,
    ];

    /**
     * @return array<string, mixed>
     */
    public function payload(Scenario $scenario, array $noteIndexes): array
    {
        $interpretations = [];

        foreach ($noteIndexes as $index) {
            $interpretations[] = $this->interpretNote($scenario, $index);
        }

        return ['interpretations' => $interpretations];
    }

    /**
     * @return array<string, mixed>
     */
    private function interpretNote(Scenario $scenario, int $index): array
    {
        $note = strtolower($scenario->operatorNotes[$index] ?? '');
        $type = $this->classify($note);

        if ($type === DirectiveType::NoOp) {
            return [
                'note_index' => $index,
                'directive_type' => DirectiveType::NoOp->value,
                'windows' => [],
                'value' => null,
                'explanation' => 'Deterministic fallback found no energy directive in this note.',
            ];
        }

        $window = $this->parseWindow($note);

        if ($window === null) {
            return [
                'note_index' => $index,
                'directive_type' => DirectiveType::NoOp->value,
                'windows' => [],
                'value' => null,
                'explanation' => 'Deterministic fallback could not resolve a time window for this note.',
            ];
        }

        return [
            'note_index' => $index,
            'directive_type' => $type->value,
            'windows' => [['start_hour' => $window[0], 'end_hour' => $window[1]]],
            'value' => $this->parseValue($note, $type, $scenario),
            'explanation' => 'Deterministic fallback interpretation.',
        ];
    }

    private function classify(string $note): DirectiveType
    {
        $outage = '(disabled|unavailable|not available|isolated|offline|out of service|suspended|blocked|prohibited|locked out)';

        $blocksDischarging = preg_match('/(do not|don\'?t|must not|cannot|can\'?t|no|stop|avoid)\s+discharg/', $note) === 1
            || preg_match('/discharg\w*[^.;]{0,60}?'.$outage.'/', $note) === 1;

        $blocksCharging = preg_match('/(do not|don\'?t|must not|cannot|can\'?t|no|stop|avoid)\s+charg/', $note) === 1
            || preg_match('/(?<!dis)charg\w*[^.;]{0,60}?'.$outage.'/', $note) === 1;

        if ($blocksDischarging) {
            return DirectiveType::NoDischargeWindow;
        }

        if ($blocksCharging) {
            return DirectiveType::NoChargeWindow;
        }

        if (preg_match('/(solar|pv|panel|photovoltaic|inverter)/', $note) === 1
            && preg_match('/(reduc|drop|down to|cloud|wash|clean|maintenance|inspect|only|about|roughly|half|fifth|third|quarter|%)/', $note) === 1) {
            return DirectiveType::SolarReduction;
        }

        if (preg_match('/(grid|import|intake|feeder|substation|transformer)/', $note) === 1
            && preg_match('/(not exceed|no more than|at or below|below|cap|limit|max)/', $note) === 1) {
            return DirectiveType::MaxGridWindow;
        }

        if (preg_match('/(reserve|at least|minimum|keep|remain|retain)/', $note) === 1
            && preg_match('/(batter|storage|stored|kwh)/', $note) === 1) {
            return DirectiveType::MinimumBatteryReserve;
        }

        return DirectiveType::NoOp;
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private function parseWindow(string $note): ?array
    {
        $note = str_replace(
            ['noon', 'midday', 'midnight'],
            ['12 pm', '12 pm', '12 am'],
            $note,
        );

        $pattern = '/(\d{1,2})(?::(\d{2}))?\s*(am|pm)?\s*(?:-|–|—|to|until|till|through|and)\s*(\d{1,2})(?::(\d{2}))?\s*(am|pm)?/';

        if (preg_match($pattern, $note, $matches) !== 1) {
            return null;
        }

        $startMeridiem = $matches[3] ?: null;
        $endMeridiem = $matches[6] ?: null;

        $start = $this->toHour((int) $matches[1], $startMeridiem ?? $endMeridiem);
        $end = $this->toHour((int) $matches[4], $endMeridiem ?? $startMeridiem);

        if ($start === null || $end === null) {
            return null;
        }

        if ($end === 0) {
            $end = 24;
        }

        return [$start, $end];
    }

    private function toHour(int $clock, ?string $meridiem): ?int
    {
        if ($clock > 24 || $clock < 0) {
            return null;
        }

        if ($meridiem === null) {
            return $clock;
        }

        $hour = $clock % 12;

        return $meridiem === 'pm' ? $hour + 12 : $hour;
    }

    private function parseValue(string $note, DirectiveType $type, Scenario $scenario): ?float
    {
        return match ($type) {
            DirectiveType::SolarReduction => $this->parseFactor($note),
            DirectiveType::MinimumBatteryReserve => $this->parseReserve($note, $scenario),
            DirectiveType::MaxGridWindow => $this->parseEnergy($note),
            default => null,
        };
    }

    private function parseFactor(string $note): float
    {
        if (preg_match('/(\d{1,3}(?:\.\d+)?)\s*%\s*(reduction|less|lower|drop|decrease)/', $note, $matches) === 1) {
            return max(0.0, min(1.0, 1.0 - ((float) $matches[1] / 100.0)));
        }

        if (preg_match('/(reduction|reduce\w*|drop\w*|decrease\w*|cut)\D{0,30}?(\d{1,3}(?:\.\d+)?)\s*%/', $note, $matches) === 1) {
            return max(0.0, min(1.0, 1.0 - ((float) $matches[2] / 100.0)));
        }

        if (preg_match('/(\d{1,3}(?:\.\d+)?)\s*%/', $note, $matches) === 1) {
            return max(0.0, min(1.0, (float) $matches[1] / 100.0));
        }

        foreach (self::WORD_FRACTIONS as $word => $fraction) {
            if (str_contains($note, $word)) {
                return $fraction;
            }
        }

        return 0.0;
    }

    private function parseReserve(string $note, Scenario $scenario): ?float
    {
        $energy = $this->parseEnergy($note);

        if ($energy !== null) {
            return $energy;
        }

        if (preg_match('/(\d{1,3}(?:\.\d+)?)\s*%/', $note, $matches) === 1) {
            return $scenario->battery->capacityKwh * ((float) $matches[1] / 100.0);
        }

        foreach (self::WORD_FRACTIONS as $word => $fraction) {
            if (str_contains($note, $word)) {
                return $scenario->battery->capacityKwh * $fraction;
            }
        }

        return null;
    }

    private function parseEnergy(string $note): ?float
    {
        if (preg_match('/(\d+(?:\.\d+)?)\s*kwh/', $note, $matches) === 1) {
            return (float) $matches[1];
        }

        return null;
    }
}
