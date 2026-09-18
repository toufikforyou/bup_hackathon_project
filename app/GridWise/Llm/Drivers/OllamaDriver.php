<?php

declare(strict_types=1);

namespace App\GridWise\Llm\Drivers;

final class OllamaDriver extends HttpDriver
{
    public function name(): string
    {
        return 'ollama';
    }

    public function isConfigured(): bool
    {
        return ! empty($this->config['base_url']) && ! empty($this->config['model']);
    }

    public function structuredJson(string $system, string $user, array $schema): array
    {
        $response = $this->post($this->baseUrl().'/api/chat', [
            'model' => $this->model(),
            'stream' => false,
            'format' => $this->toStrictJsonSchema($schema),
            'options' => ['temperature' => 0.0],
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
        ]);

        return $this->decode($response->json('message.content'));
    }
}
