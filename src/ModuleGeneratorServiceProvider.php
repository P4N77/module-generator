<?php

declare(strict_types=1);

namespace Sodeker\ModuleGenerator;

use Illuminate\Support\ServiceProvider;
use Sodeker\ModuleGenerator\Commands\MakeModuleCommand;

final class ModuleGeneratorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/module-generator.php', 'module-generator');
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            MakeModuleCommand::class,
        ]);

        $this->publishes([
            __DIR__.'/../config/module-generator.php' => config_path('module-generator.php'),
        ], 'module-generator-config');
    }
}
