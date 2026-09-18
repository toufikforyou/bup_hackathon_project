<?php

declare(strict_types=1);

namespace Tests\Support;

final class SampleCases
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        $path = dirname(__DIR__, 2).'/storage/app/gridwise/public_sample_cases.json';

        $pack = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $pack['cases'];
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function provider(): array
    {
        $cases = [];

        foreach (self::all() as $case) {
            $cases[$case['id']] = [$case];
        }

        return $cases;
    }

    /**
     * @return array<string, mixed>
     */
    public static function first(): array
    {
        return self::all()[0];
    }
}
