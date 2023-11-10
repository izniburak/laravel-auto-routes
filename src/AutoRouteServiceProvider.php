<?php

namespace Buki\AutoRoute;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

/**
 * Class AutoRouteServiceProvider
 *
 * @package Buki\AutoRoute
 * @author  İzni Burak Demirtaş <info@burakdemirtas.org>
 */
class AutoRouteServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the services.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->publishes([
            $this->configPath() => config_path('auto-route.php'),
        ], 'auto-route');
    }

    /**
     * Register the services.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom($this->configPath(), 'auto-route');
        $this->app->singleton(AutoRoute::class, function ($app) {
            $autoRouter = new AutoRoute($app);
            $autoRouter->setConfigurations($app['config']->get('auto-route'));
            return $autoRouter;
        });

        /** @var Router $router */
        $router = $this->app['router'];
        $autoRoute = $this->app[AutoRoute::class];
        $router->macro('auto', function (string $prefix, string $controller, array $options = []) use ($autoRoute) {
            return $autoRoute->auto($prefix, $controller, $options);
        });
    }

    /**
     * @return string
     */
    protected function configPath(): string
    {
        return __DIR__ . '/../config/auto-route.php';
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides(): array
    {
        return [AutoRoute::class];
    }
}
