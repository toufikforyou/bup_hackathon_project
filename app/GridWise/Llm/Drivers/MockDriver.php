<?php

declare(strict_types=1);

namespace App\GridWise\Llm\Drivers;

use App\GridWise\Llm\Contracts\LlmDriver;

final class MockDriver implements LlmDriver
{
    public function name(): string
    {
        return 'mock';
    }

    public function model(): string
    {
        return 'offline-no-model';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function structuredJson(string $system, string $user, array $schema): array
    {
        return ['interpretations' => []];
    }
}
