<?php

declare(strict_types=1);

namespace App\GridWise\Llm\Drivers;

final class GeminiDriver extends HttpDriver
{
    public function name(): string
    {
        return 'gemini';
    }

    public function structuredJson(string $system, string $user, array $schema): array
    {
        $url = sprintf('%s/models/%s:generateContent', $this->baseUrl(), $this->model());

        $response = $this->post($url, [
            'systemInstruction' => [
                'parts' => [['text' => $system]],
            ],
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => $user]],
            ]],
            'generationConfig' => [
                'temperature' => 0.0,
                'topP' => 0.1,
                'candidateCount' => 1,
                'responseMimeType' => 'application/json',
                'responseSchema' => $this->toGeminiSchema($schema),
                'thinkingConfig' => [
                    'thinkingBudget' => (int) ($this->config['thinking_budget'] ?? 0),
                ],
            ],
        ], [
            'x-goog-api-key' => (string) $this->config['key'],
        ]);

        return $this->decode($response->json('candidates.0.content.parts.0.text'));
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function toGeminiSchema(array $schema): array
    {
        $converted = [];

        foreach ($schema as $key => $value) {
            if ($key === 'additionalProperties') {
                continue;
            }

            $converted[$key] = match (true) {
                $key === 'type' && is_string($value) => strtoupper($value),
                $key === 'properties' && is_array($value) => array_map(
                    fn (array $property): array => $this->toGeminiSchema($property),
                    $value,
                ),
                $key === 'items' && is_array($value) => $this->toGeminiSchema($value),
                default => $value,
            };
        }

        if (($converted['type'] ?? null) === 'OBJECT' && isset($converted['properties'])) {
            $converted['propertyOrdering'] = array_keys($converted['properties']);
        }

        return $converted;
    }
}
