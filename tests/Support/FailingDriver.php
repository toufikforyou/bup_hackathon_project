<?php

declare(strict_types=1);

namespace Tests\Support;

use App\GridWise\Llm\Contracts\LlmDriver;
use App\GridWise\Llm\LlmException;

final class FailingDriver implements LlmDriver
{
    public function name(): string
    {
        return 'broken';
    }

    public function model(): string
    {
        return 'unavailable';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function structuredJson(string $system, string $user, array $schema): array
    {
        throw LlmException::transport('broken', 'HTTP 503');
    }
}
