<?php

declare(strict_types=1);

namespace App\GridWise\Llm\Drivers;

use App\GridWise\Llm\Contracts\LlmDriver;
use App\GridWise\Llm\LlmException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

abstract class HttpDriver implements LlmDriver
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected readonly array $config,
        protected readonly float $timeout = 12.0,
        protected readonly float $connectTimeout = 4.0,
        protected readonly int $retries = 2,
        protected readonly int $retryDelayMs = 250,
    ) {}

    public function model(): string
    {
        return (string) ($this->config['model'] ?? 'unknown');
    }

    public function isConfigured(): bool
    {
        return ! empty($this->config['key']);
    }

    protected function baseUrl(): string
    {
        return rtrim((string) $this->config['base_url'], '/');
    }

    protected function client(): PendingRequest
    {
        return Http::timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->retry($this->retries, $this->retryDelayMs, throw: false)
            ->acceptJson()
            ->asJson();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    protected function post(string $url, array $payload, array $headers = []): Response
    {
        try {
            $response = $this->client()->withHeaders($headers)->post($url, $payload);
        } catch (Throwable $exception) {
            throw LlmException::transport($this->name(), $exception->getMessage());
        }

        if ($response->failed()) {
            throw LlmException::transport($this->name(), 'HTTP '.$response->status());
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    protected function decode(?string $content): array
    {
        if ($content === null || trim($content) === '') {
            throw LlmException::emptyResponse($this->name());
        }

        $decoded = json_decode($this->stripCodeFence($content), true);

        if (! is_array($decoded)) {
            throw LlmException::undecodable($this->name());
        }

        return $decoded;
    }

    private function stripCodeFence(string $content): string
    {
        $trimmed = trim($content);

        if (! str_starts_with($trimmed, '```')) {
            return $trimmed;
        }

        $trimmed = preg_replace('/^```[a-zA-Z]*\s*/', '', $trimmed) ?? $trimmed;

        return trim(preg_replace('/```\s*$/', '', $trimmed) ?? $trimmed);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    protected function toStrictJsonSchema(array $schema): array
    {
        if (($schema['nullable'] ?? false) === true && is_string($schema['type'] ?? null)) {
            $schema['type'] = [$schema['type'], 'null'];
            unset($schema['nullable']);
        }

        if (isset($schema['type']) && $schema['type'] === 'object') {
            $schema['additionalProperties'] = false;
            $schema['required'] = array_keys($schema['properties'] ?? []);

            foreach ($schema['properties'] ?? [] as $name => $property) {
                $schema['properties'][$name] = $this->toStrictJsonSchema($property);
            }
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = $this->toStrictJsonSchema($schema['items']);
        }

        return $schema;
    }
}
