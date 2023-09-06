<?php

namespace Morbihanet\RedisSQL;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class RedisSQLServiceProvider extends ServiceProvider
{
    const DS = DIRECTORY_SEPARATOR;

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/resources/views', 'rsql');
        $this->loadTranslationsFrom(__DIR__ . '/resources/lang', 'rsql');
    }

    public function register()
    {
        require_once __DIR__ . static::DS . 'RedisSQLHelper.php';

        $this->publishes([
            __DIR__ . static::DS . 'RedisSQLConfig.php' => config_path('redissql.php'),
        ], 'redissql-config');

        RedisSQLCollection::macro('isAssoc', function () {
            /** @phpstan-ignore-next-line */
            return Arr::isAssoc($this->toBase()->all());
        });

        /** @var \Illuminate\View\Compilers\BladeCompiler $blade */
        $blade = $this->app['blade.compiler'];

        $blade->directive('lang', fn ($expression) => "<?php echo __($expression); ?>");

        Route::middleware(RedisSQLCrudMiddleware::class)->any('/redismyadmin', [RedisSQLCrud::class, 'router'])
            ->name('redis-sql-admin.home');

        Route::middleware(RedisSQLCrudMiddleware::class)->any('/redismyadmin/{path}', [RedisSQLCrud::class, 'router'])
            ->name('redis-sql-admin.crud')
            ->where('path', '.*');
    }
}
