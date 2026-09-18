<?php

declare(strict_types=1);

namespace App\Providers;

use App\GridWise\GridWiseService;
use App\GridWise\Interpretation\DirectiveGuard;
use App\GridWise\Interpretation\DirectiveInterpreter;
use App\GridWise\Interpretation\FallbackInterpreter;
use App\GridWise\Llm\LlmManager;
use App\GridWise\Optimizer\EnergyOptimizer;
use App\GridWise\Validation\ScheduleReplayer;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\ServiceProvider;

final class GridWiseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LlmManager::class, static fn (): LlmManager => new LlmManager(config('gridwise.llm')));

        $this->app->singleton(DirectiveGuard::class);
        $this->app->singleton(FallbackInterpreter::class);

        $this->app->singleton(EnergyOptimizer::class, static fn (): EnergyOptimizer => new EnergyOptimizer(
            (int) config('gridwise.optimizer.max_iterations'),
            (int) config('gridwise.optimizer.bland_after'),
            (int) config('gridwise.tolerance.output_decimals'),
        ));

        $this->app->singleton(ScheduleReplayer::class, static fn (): ScheduleReplayer => new ScheduleReplayer(
            (float) config('gridwise.tolerance.judge'),
        ));

        $this->app->singleton(DirectiveInterpreter::class, static fn ($app): DirectiveInterpreter => new DirectiveInterpreter(
            $app->make(LlmManager::class),
            $app->make(DirectiveGuard::class),
            $app->make(FallbackInterpreter::class),
            $app->make(CacheRepository::class),
            (bool) config('gridwise.llm.cache.enabled'),
            (int) config('gridwise.llm.cache.ttl'),
            (bool) config('gridwise.fallback.enabled'),
        ));

        $this->app->singleton(GridWiseService::class);
    }
}
