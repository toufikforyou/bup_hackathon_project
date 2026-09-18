<?php

declare(strict_types=1);

namespace App\GridWise\Llm\Drivers;

final class GroqDriver extends OpenAiDriver
{
    public function name(): string
    {
        return 'groq';
    }

    protected function responseFormat(array $schema): array
    {
        return ['type' => 'json_object'];
    }
}
