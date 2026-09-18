<?php

declare(strict_types=1);

namespace App\GridWise\Validation;

final readonly class ReplayReport
{
    /**
     * @param  list<array{hour: int|null, rule: string, detail: string}>  $violations
     */
    public function __construct(public array $violations) {}

    public function isValid(): bool
    {
        return $this->violations === [];
    }

    public function messages(): array
    {
        return array_map(
            static fn (array $violation): string => $violation['hour'] === null
                ? sprintf('%s: %s', $violation['rule'], $violation['detail'])
                : sprintf('hour %d - %s: %s', $violation['hour'], $violation['rule'], $violation['detail']),
            $this->violations,
        );
    }
}
