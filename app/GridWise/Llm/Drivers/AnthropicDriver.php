<?php

declare(strict_types=1);

namespace App\GridWise\Llm\Drivers;

use App\GridWise\Llm\LlmException;

final class AnthropicDriver extends HttpDriver
{
    private const TOOL_NAME = 'emit_directive_interpretation';

    public function name(): string
    {
        return 'anthropic';
    }

    public function structuredJson(string $system, string $user, array $schema): array
    {
        $response = $this->post($this->baseUrl().'/messages', [
            'model' => $this->model(),
            'max_tokens' => 2048,
            'temperature' => 0.0,
            'system' => $system,
            'messages' => [
                ['role' => 'user', 'content' => $user],
            ],
            'tools' => [[
                'name' => self::TOOL_NAME,
                'description' => 'Return the structured interpretation of every operator note.',
                'input_schema' => $this->toStrictJsonSchema($schema),
            ]],
            'tool_choice' => ['type' => 'tool', 'name' => self::TOOL_NAME],
        ], [
            'x-api-key' => (string) $this->config['key'],
            'anthropic-version' => (string) ($this->config['version'] ?? '2023-06-01'),
        ]);

        foreach ($response->json('content', []) as $block) {
            if (($block['type'] ?? null) === 'tool_use' && is_array($block['input'] ?? null)) {
                return $block['input'];
            }
        }

        throw LlmException::emptyResponse($this->name());
    }
}
