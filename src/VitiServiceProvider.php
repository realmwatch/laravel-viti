<?php

namespace Realmwatch\Viti;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\ServiceProvider;
use Realmwatch\Viti\Console\InstallCommand;
use Realmwatch\Viti\Console\ListCommand;
use Realmwatch\Viti\Console\TestCommand;
use Realmwatch\Viti\Http\DetectDecoys;
use Realmwatch\Viti\Reporting\Reporter;

final class VitiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/viti.php', 'viti');
        $this->app->singleton(Reporter::class);
        $this->app->singleton(Viti::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/viti.php' => config_path('viti.php')], 'viti-config');
            $this->commands([InstallCommand::class, TestCommand::class, ListCommand::class]);
        }

        // @viti renders nothing unless the breadcrumbs tripwire replaces it,
        // so a disabled Viti never prints the directive's name into a page.
        $this->callAfterResolving('blade.compiler', fn ($blade) => $blade->directive('viti', fn () => ''));

        if (! $this->app['config']->get('viti.enabled')) {
            return;
        }

        $kernel = $this->app->make(Kernel::class);
        if (method_exists($kernel, 'pushMiddleware')) {
            $kernel->pushMiddleware(DetectDecoys::class);
        }

        $viti = $this->app->make(Viti::class);
        foreach ((array) $this->app['config']->get('viti.tripwires', []) as $name => $options) {
            if (is_array($options) && ($options['enabled'] ?? true) && isset($options['class'])) {
                $viti->extend((string) $name, $options['class'], $options);
            }
        }
    }
}
