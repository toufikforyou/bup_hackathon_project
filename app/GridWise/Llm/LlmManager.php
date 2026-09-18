<?php

declare(strict_types=1);

namespace App\GridWise\Llm;

use App\GridWise\Llm\Contracts\LlmDriver;
use App\GridWise\Llm\Drivers\AnthropicDriver;
use App\GridWise\Llm\Drivers\GeminiDriver;
use App\GridWise\Llm\Drivers\GroqDriver;
use App\GridWise\Llm\Drivers\MockDriver;
use App\GridWise\Llm\Drivers\OllamaDriver;
use App\GridWise\Llm\Drivers\OpenAiDriver;
use Closure;
use InvalidArgumentException;

final class LlmManager
{
    /** @var array<string, LlmDriver> */
    private array $resolved = [];

    /** @var array<string, Closure(): LlmDriver> */
    private array $custom = [];

    private ?string $defaultDriver = null;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    public function driver(?string $name = null): LlmDriver
    {
        $name ??= $this->defaultDriverName();

        return $this->resolved[$name] ??= $this->make($name);
    }

    public function defaultDriverName(): string
    {
        return $this->defaultDriver ?? (string) $this->config['driver'];
    }

    public function useDriver(string $name): void
    {
        $this->defaultDriver = $name;
    }

    /**
     * @param  Closure(): LlmDriver  $factory
     */
    public function extend(string $name, Closure $factory): void
    {
        $this->custom[$name] = $factory;
        unset($this->resolved[$name]);
    }

    private function make(string $name): LlmDriver
    {
        if (isset($this->custom[$name])) {
            return ($this->custom[$name])();
        }

        if ($name === 'mock') {
            return new MockDriver;
        }

        $settings = $this->config['drivers'][$name] ?? null;

        if (! is_array($settings)) {
            throw new InvalidArgumentException("Unknown GridWise LLM driver [{$name}].");
        }

        $arguments = [
            $settings,
            (float) $this->config['timeout'],
            (float) $this->config['connect_timeout'],
            (int) $this->config['retries'],
            (int) $this->config['retry_delay_ms'],
        ];

        return match ($name) {
            'gemini' => new GeminiDriver(...$arguments),
            'openai' => new OpenAiDriver(...$arguments),
            'groq' => new GroqDriver(...$arguments),
            'anthropic' => new AnthropicDriver(...$arguments),
            'ollama' => new OllamaDriver(...$arguments),
            default => throw new InvalidArgumentException("Unknown GridWise LLM driver [{$name}]."),
        };
    }
}
