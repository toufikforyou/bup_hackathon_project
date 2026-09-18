<?php

declare(strict_types=1);

namespace App\GridWise\Llm\Contracts;

use App\GridWise\Llm\LlmException;

interface LlmDriver
{
    public function name(): string;

    public function model(): string;

    public function isConfigured(): bool;

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     *
     * @throws LlmException
     */
    public function structuredJson(string $system, string $user, array $schema): array;
}
