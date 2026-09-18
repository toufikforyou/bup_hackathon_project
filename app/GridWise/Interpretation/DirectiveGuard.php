<?php

declare(strict_types=1);

namespace App\GridWise\Interpretation;

use App\GridWise\Dto\Directive;
use App\GridWise\Dto\DirectiveType;
use App\GridWise\Dto\Scenario;

final class DirectiveGuard
{
    private const PERCENT_HINTS = ['%', 'percent', 'per cent', 'half', 'quarter', 'third', 'fifth'];

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<int>  $expectedNoteIndexes
     */
    public function inspect(Scenario $scenario, array $payload, array $expectedNoteIndexes): GuardResult
    {
        $entries = $payload['interpretations'] ?? $payload['directive_interpretation'] ?? null;

        if (! is_array($entries)) {
            return new GuardResult([], $expectedNoteIndexes, ['model output had no interpretations array']);
        }

        $accepted = [];
        $issues = [];
        $expected = array_flip($expectedNoteIndexes);

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                $issues[] = 'entry was not an object';

                continue;
            }

            $noteIndex = $this->readNoteIndex($entry);

            if ($noteIndex === null || ! isset($expected[$noteIndex])) {
                $issues[] = 'entry referenced an unknown note index';

                continue;
            }

            if (isset($accepted[$noteIndex])) {
                $issues[] = "note {$noteIndex} was interpreted more than once";

                continue;
            }

            $directive = $this->buildDirective($scenario, $noteIndex, $entry, $issues);

            if ($directive !== null) {
                $accepted[$noteIndex] = $directive;
            }
        }

        $rejected = array_values(array_filter(
            $expectedNoteIndexes,
            static fn (int $index): bool => ! isset($accepted[$index]),
        ));

        ksort($accepted);

        return new GuardResult($accepted, $rejected, $issues);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  list<string>  $issues
     */
    private function buildDirective(Scenario $scenario, int $noteIndex, array $entry, array &$issues): ?Directive
    {
        $type = DirectiveType::tryFrom((string) ($entry['directive_type'] ?? ''));

        if ($type === null) {
            $issues[] = "note {$noteIndex} used an unsupported directive type";

            return null;
        }

        $explanation = trim((string) ($entry['explanation'] ?? ''));

        if ($type->isNoOp()) {
            return Directive::noOp(
                $noteIndex,
                $explanation !== '' ? $explanation : 'This note does not affect the 24-hour energy schedule.',
            );
        }

        $hours = $this->resolveHours($entry);

        if ($hours === []) {
            $issues[] = "note {$noteIndex} produced no valid hours";

            return null;
        }

        $value = $this->resolveValue($scenario, $noteIndex, $type, $entry, $issues);

        if ($type->numericKey() !== null && $value === null) {
            return null;
        }

        return new Directive(
            $noteIndex,
            $type,
            $hours,
            $value,
            $explanation !== '' ? $explanation : $this->describe($type, $hours, $value),
        );
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return list<int>
     */
    private function resolveHours(array $entry): array
    {
        $hours = [];

        foreach ($this->readWindows($entry) as [$start, $end]) {
            foreach ($this->expandWindow($start, $end) as $hour) {
                $hours[$hour] = true;
            }
        }

        foreach ($this->readExplicitHours($entry) as $hour) {
            $hours[$hour] = true;
        }

        $hours = array_keys($hours);
        sort($hours);

        return array_values(array_filter($hours, static fn (int $hour): bool => $hour >= 0 && $hour <= 23));
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return list<array{0: int, 1: int}>
     */
    private function readWindows(array $entry): array
    {
        $raw = $entry['windows'] ?? null;

        if (! is_array($raw)) {
            return [];
        }

        $windows = [];

        foreach ($raw as $window) {
            if (! is_array($window)) {
                continue;
            }

            $start = $this->readInt($window['start_hour'] ?? null);
            $end = $this->readInt($window['end_hour'] ?? null);

            if ($start === null || $end === null) {
                continue;
            }

            $windows[] = [$start, $end];
        }

        return $windows;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return list<int>
     */
    private function readExplicitHours(array $entry): array
    {
        $source = $entry['hours'] ?? ($entry['structured_adjustment']['hours'] ?? null);

        if (! is_array($source)) {
            return [];
        }

        $hours = [];

        foreach ($source as $candidate) {
            $hour = $this->readInt($candidate);

            if ($hour !== null && $hour >= 0 && $hour <= 23) {
                $hours[] = $hour;
            }
        }

        return $hours;
    }

    /**
     * @return list<int>
     */
    private function expandWindow(int $start, int $end): array
    {
        if ($start < 0 || $start > 24 || $end < 0 || $end > 24) {
            return [];
        }

        $start %= 24;

        if ($end === 0) {
            $end = 24;
        }

        $span = $end - $start;

        if ($span <= 0) {
            $span += 24;
        }

        $span = min($span, 24);

        $hours = [];

        for ($offset = 0; $offset < $span; $offset++) {
            $hours[] = ($start + $offset) % 24;
        }

        return $hours;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  list<string>  $issues
     */
    private function resolveValue(
        Scenario $scenario,
        int $noteIndex,
        DirectiveType $type,
        array $entry,
        array &$issues,
    ): ?float {
        $numericKey = $type->numericKey();

        if ($numericKey === null) {
            return null;
        }

        $raw = $entry['value']
            ?? $entry[$numericKey]
            ?? ($entry['structured_adjustment'][$numericKey] ?? null);

        if (! is_numeric($raw)) {
            $issues[] = "note {$noteIndex} is missing the numeric value required by {$type->value}";

            return null;
        }

        $value = (float) $raw;

        if (! is_finite($value)) {
            $issues[] = "note {$noteIndex} produced a non-finite numeric value";

            return null;
        }

        return match ($type) {
            DirectiveType::SolarReduction => $this->normaliseFactor($value),
            DirectiveType::MinimumBatteryReserve => $this->normaliseReserve($scenario, $noteIndex, $value),
            DirectiveType::MaxGridWindow => max(0.0, $value),
            default => $value,
        };
    }

    private function normaliseFactor(float $value): float
    {
        if ($value > 1.0 && $value <= 100.0) {
            $value /= 100.0;
        }

        return min(1.0, max(0.0, $value));
    }

    private function normaliseReserve(Scenario $scenario, int $noteIndex, float $value): float
    {
        $capacity = $scenario->battery->capacityKwh;

        if ($value > 0.0 && $value <= 1.0 && $capacity > 1.0 && $this->mentionsProportion($scenario, $noteIndex)) {
            $value *= $capacity;
        }

        return min($capacity, max(0.0, $value));
    }

    private function mentionsProportion(Scenario $scenario, int $noteIndex): bool
    {
        $note = strtolower($scenario->operatorNotes[$noteIndex] ?? '');

        foreach (self::PERCENT_HINTS as $hint) {
            if (str_contains($note, $hint)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function readNoteIndex(array $entry): ?int
    {
        return $this->readInt($entry['note_index'] ?? null);
    }

    private function readInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return null;
    }

    /**
     * @param  list<int>  $hours
     */
    private function describe(DirectiveType $type, array $hours, ?float $value): string
    {
        $window = sprintf('hours %s', implode(', ', $hours));

        return match ($type) {
            DirectiveType::SolarReduction => sprintf('Usable solar is limited to %s of the forecast during %s.', $value, $window),
            DirectiveType::MinimumBatteryReserve => sprintf('Battery energy must stay at or above %s kWh during %s.', $value, $window),
            DirectiveType::NoChargeWindow => sprintf('Battery charging is unavailable during %s.', $window),
            DirectiveType::NoDischargeWindow => sprintf('Battery discharging is unavailable during %s.', $window),
            DirectiveType::MaxGridWindow => sprintf('Grid import must not exceed %s kWh during %s.', $value, $window),
            DirectiveType::NoOp => 'This note does not affect the 24-hour energy schedule.',
        };
    }
}
