<?php

namespace SUBandL;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use SUBandL\Contracts\AccessResolver;
use SUBandL\Http\Middleware\EnsureLicenseValid;
use SUBandL\Http\Middleware\RunScheduledTasks;

class SUBandLServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/subandl.php', 'subandl');

        $this->app->singleton(License\ServerHealth::class);
        $this->app->singleton(SUBandL::class);

        $this->app->bind(AccessResolver::class, function ($app) {
            return $app->make(config('subandl.access.resolver') ?: Support\DefaultAccessResolver::class);
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'subandl');

        $this->registerRoutes();
        $this->registerMiddleware();

        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\InstallCommand::class,
                Console\StatusCommand::class,
                Console\LicenseCheckCommand::class,
                Console\UpdateCheckCommand::class,
                Console\BackupCommand::class,
            ]);

            $this->publishes([
                __DIR__ . '/../config/subandl.php' => config_path('subandl.php'),
            ], 'subandl-config');

            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/subandl'),
            ], 'subandl-views');

            // Vue / React pages share one core (resources/js/subandl) with the Blade UI.
            // Pages import it as ../../subandl/, so both keep that relative layout.
            foreach (['vue' => 'vue', 'react' => 'jsx'] as $ui => $extension) {
                $files = [__DIR__ . '/../resources/js/subandl' => resource_path('js/subandl')];
                foreach (glob(__DIR__ . "/../resources/js/Pages/SUBandL/*.{$extension}") ?: [] as $page) {
                    $files[$page] = resource_path('js/Pages/SUBandL/' . basename($page));
                }
                $this->publishes($files, "subandl-{$ui}");
            }

            $this->registerSchedule();
        }
    }

    private function registerRoutes(): void
    {
        if (! config('subandl.routes.enabled', true) || $this->app->routesAreCached()) {
            return;
        }

        Route::middleware((array) config('subandl.routes.middleware', ['web']))
            ->prefix((string) config('subandl.routes.prefix', ''))
            ->group(__DIR__ . '/../routes/subandl.php');
    }

    private function registerMiddleware(): void
    {
        $router = $this->app['router'];
        $router->aliasMiddleware('subandl.license', EnsureLicenseValid::class);
        $router->aliasMiddleware('subandl.scheduler', RunScheduledTasks::class);

        if (! config('subandl.middleware.auto_register', true)) {
            return;
        }

        // Appended through the HTTP kernel (not just the router) so it also works on
        // Laravel 11+ apps configured via bootstrap/app.php. Both calls skip a class
        // that is already in the group, so a manual registration is never doubled.
        $group = (string) config('subandl.middleware.group', 'web');
        $kernel = $this->app->make(HttpKernel::class);

        foreach ([EnsureLicenseValid::class, RunScheduledTasks::class] as $middleware) {
            try {
                method_exists($kernel, 'appendMiddlewareToGroup')
                    ? $kernel->appendMiddlewareToGroup($group, $middleware)
                    : $router->pushMiddlewareToGroup($group, $middleware);
            } catch (\InvalidArgumentException $e) {
                // The group doesn't exist in this app — use the aliases manually instead.
                return;
            }
        }
    }

    private function registerSchedule(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            if (! config('subandl.schedule.enabled', true)) {
                return;
            }

            $schedule->command('subandl:license-check')->everyThirtyMinutes()->withoutOverlapping();
            $schedule->command('subandl:update-check')->hourly()->withoutOverlapping();
            $schedule->command('subandl:backup')->everyFifteenMinutes()->withoutOverlapping();
        });
    }
}
