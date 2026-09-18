<?php

declare(strict_types=1);

namespace App\GridWise\Llm;

use RuntimeException;

final class LlmException extends RuntimeException
{
    public static function notConfigured(string $driver): self
    {
        return new self("LLM driver [{$driver}] is missing its credentials.");
    }

    public static function transport(string $driver, string $reason): self
    {
        return new self("LLM driver [{$driver}] request failed: {$reason}");
    }

    public static function emptyResponse(string $driver): self
    {
        return new self("LLM driver [{$driver}] returned no content.");
    }

    public static function undecodable(string $driver): self
    {
        return new self("LLM driver [{$driver}] returned content that is not a JSON object.");
    }
}
