<?php

declare(strict_types=1);

namespace App\GridWise\Interpretation;

use App\GridWise\Dto\DirectiveType;
use App\GridWise\Dto\Scenario;

final class InterpretationPrompt
{
    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'interpretations' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'note_index' => ['type' => 'integer'],
                            'directive_type' => [
                                'type' => 'string',
                                'enum' => DirectiveType::values(),
                            ],
                            'windows' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'start_hour' => ['type' => 'integer'],
                                        'end_hour' => ['type' => 'integer'],
                                    ],
                                    'required' => ['start_hour', 'end_hour'],
                                ],
                            ],
                            'value' => ['type' => 'number', 'nullable' => true],
                            'explanation' => ['type' => 'string'],
                        ],
                        'required' => ['note_index', 'directive_type', 'windows', 'value', 'explanation'],
                    ],
                ],
            ],
            'required' => ['interpretations'],
        ];
    }

    public static function system(): string
    {
        return <<<'PROMPT'
        You are the operator-note interpreter for GridWise, a campus energy scheduling service.
        You convert each campus operator note into exactly one structured directive.
        You never invent demand, solar, tariff or battery numbers, and you never invent directive types.

        DIRECTIVE TYPES
        solar_reduction          Usable rooftop solar is reduced during a window. value = the fraction of normal solar that REMAINS, between 0 and 1.
        minimum_battery_reserve  Stored battery energy must stay at or above a level during a window. value = that level in kWh.
        no_charge_window         The battery may not be charged during a window. value = null.
        no_discharge_window      The battery may not be discharged during a window. value = null.
        max_grid_window          Grid import in any single hour may not exceed a limit during a window. value = that limit in kWh.
        no_op                    The note does not affect today's 24-hour electricity schedule. windows = [] and value = null.

        TIME WINDOWS
        Report every window as start_hour and end_hour on a 24-hour clock, exactly as the note states them.
        Do not adjust, shift or subtract anything: the service applies the whole-hour convention itself.
        "1 PM to 3 PM" -> start_hour 13, end_hour 15.
        "2 AM until 5 AM" -> start_hour 2, end_hour 5.
        "noon until 2 PM" -> start_hour 12, end_hour 14.
        "from 6 PM until 10 PM" -> start_hour 18, end_hour 22.
        "between 11 AM and 2 PM" -> start_hour 11, end_hour 14.
        "13:00 to 15:00" -> start_hour 13, end_hour 15.
        Midnight at the end of a day is 24. If a note names several separate periods, return one window for each.

        VALUES
        solar_reduction: "drops to 25%" -> 0.25. "an 80% reduction" -> 0.2. "about half" -> 0.5. "one fifth of normal" -> 0.2. "roughly a third" -> 0.33.
        minimum_battery_reserve: "at least 120 kWh" -> 120. A percentage always refers to the battery capacity given in the scenario facts, so "50% of capacity" with a 200 kWh battery -> 100.
        max_grid_window: "must not exceed 155 kWh in any hour" -> 155. "stay at or below 190 kWh" -> 190.

        RELEVANCE
        Mark a note no_op when it is about anything other than today's electricity schedule: catering, room or seminar bookings, registration deadlines, notices, staffing, library hours, sports or general announcements.
        A note describing solar output, battery charging, battery discharging, reserve levels or grid import limits for the coming day is never a no_op.

        OUTPUT
        Return exactly one entry per operator note, ordered by note_index starting at 0.
        Never merge two notes into one entry and never split one note into two entries.
        PROMPT;
    }

    /**
     * @param  list<int>  $noteIndexes
     */
    public static function user(Scenario $scenario, array $noteIndexes): string
    {
        $battery = $scenario->battery;

        $facts = sprintf(
            "Scenario facts\n".
            "- Battery capacity: %s kWh\n".
            "- Battery energy at the start of the day: %s kWh\n".
            "- Battery base minimum energy: %s kWh\n".
            "- Maximum charge per hour: %s kWh\n".
            "- Maximum discharge per hour: %s kWh\n".
            '- The schedule covers hours 0 to 23 of a single day.',
            self::number($battery->capacityKwh),
            self::number($battery->initialEnergyKwh),
            self::number($battery->minimumEnergyKwh),
            self::number($battery->maxChargeKwhPerHour),
            self::number($battery->maxDischargeKwhPerHour),
        );

        $notes = [];

        foreach ($noteIndexes as $index) {
            $notes[] = sprintf('[%d] %s', $index, $scenario->operatorNotes[$index]);
        }

        return sprintf(
            "%s\n\n%s\n\nOperator notes\n%s\n\nReturn exactly %d interpretation %s, one for each note index listed above.",
            $facts,
            self::hourlyTable($scenario),
            implode("\n", $notes),
            count($noteIndexes),
            count($noteIndexes) === 1 ? 'entry' : 'entries',
        );
    }

    private static function hourlyTable(Scenario $scenario): string
    {
        $rows = ['Hourly forecast (hour | demand kWh | solar kWh | tariff BDT/kWh)'];

        foreach ($scenario->hourTable() as $row) {
            $rows[] = sprintf(
                '%2d | %s | %s | %s',
                $row['hour'],
                self::number($row['demand_kwh']),
                self::number($row['solar_kwh']),
                self::number($row['tariff_bdt_per_kwh']),
            );
        }

        return implode("\n", $rows);
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
