<?php namespace Lee2son\Swoolaravel;

use Illuminate\Support\ServiceProvider;

class SwoolaravelServiceProvider extends ServiceProvider
{
    protected $commands = [
        \Lee2son\Swoolaravel\Console\Commands\Server::class
    ];

    /**
     * Bootstrap any application services.
     * @return void
     */
    public function boot()
    {
        $this->loadRoute();
    }

    /**
     * Register any application services.
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/swoolaravel.php', 'swoolaravel');
        $this->commands($this->commands);
        $this->registerPublishes();
    }

    /**
     * 加载路由
     * @return void
     */
    protected function loadRoute()
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/swoolaravel.php');
    }

    /**
     * 注册 publishes
     * 通过“php artisan vendor:publish --tag=swoolaravel”命令把默认配置复制到相应目录去
     * @return void
     */
    protected function registerPublishes()
    {
        $this->publishes([
            __DIR__ . '/../config/swoolaravel.php' => base_path('config/swoolaravel.php'),
            __DIR__ . '/../routes/swoolaravel.php' => base_path('routes/swoolaravel.php')
        ], 'swoolaravel');
    }
}