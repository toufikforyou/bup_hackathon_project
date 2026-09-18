<?php

declare(strict_types=1);

namespace App\GridWise\Llm\Drivers;

class OpenAiDriver extends HttpDriver
{
    public function name(): string
    {
        return 'openai';
    }

    public function structuredJson(string $system, string $user, array $schema): array
    {
        $response = $this->post($this->baseUrl().'/chat/completions', [
            'model' => $this->model(),
            'temperature' => 0.0,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'response_format' => $this->responseFormat($schema),
        ], [
            'Authorization' => 'Bearer '.$this->config['key'],
        ]);

        return $this->decode($response->json('choices.0.message.content'));
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    protected function responseFormat(array $schema): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'directive_interpretation',
                'strict' => true,
                'schema' => $this->toStrictJsonSchema($schema),
            ],
        ];
    }
}
