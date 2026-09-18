<?php

declare(strict_types=1);

namespace App\GridWise\Dto;

final readonly class Directive
{
    /**
     * @param  list<int>  $hours
     */
    public function __construct(
        public int $noteIndex,
        public DirectiveType $type,
        public array $hours = [],
        public ?float $value = null,
        public string $explanation = '',
    ) {}

    public static function noOp(int $noteIndex, string $explanation): self
    {
        return new self($noteIndex, DirectiveType::NoOp, [], null, $explanation);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromResponseArray(array $payload): self
    {
        $type = DirectiveType::from((string) $payload['directive_type']);
        $adjustment = $payload['structured_adjustment'] ?? null;

        $hours = [];
        $value = null;

        if (is_array($adjustment)) {
            $hours = array_map(intval(...), $adjustment['hours'] ?? []);

            $numericKey = $type->numericKey();

            if ($numericKey !== null && isset($adjustment[$numericKey])) {
                $value = (float) $adjustment[$numericKey];
            }
        }

        return new self(
            (int) $payload['note_index'],
            $type,
            $hours,
            $value,
            (string) ($payload['explanation'] ?? ''),
        );
    }

    public function applies(): bool
    {
        return ! $this->type->isNoOp();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function structuredAdjustment(): ?array
    {
        if ($this->type->isNoOp()) {
            return null;
        }

        $adjustment = ['hours' => $this->hours];

        $numericKey = $this->type->numericKey();

        if ($numericKey !== null) {
            $adjustment[$numericKey] = $this->value;
        }

        return $adjustment;
    }

    public function toResponseArray(): array
    {
        return [
            'note_index' => $this->noteIndex,
            'applies' => $this->applies(),
            'directive_type' => $this->type->value,
            'structured_adjustment' => $this->structuredAdjustment(),
            'explanation' => $this->explanation,
        ];
    }
}
