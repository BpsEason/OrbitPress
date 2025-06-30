<?php

namespace Analytics;

use Illuminate\Support\ServiceProvider;
use Analytics\AnalyticsModule;

class AnalyticsServiceProvider extends ServiceProvider
{
    /**
     * 註冊服務。
     */
    public function register(): void
    {
        $this->app->singleton('analytics.module', function ($app) {
            return new AnalyticsModule();
        });
    }

    /**
     * 啟動服務。
     */
    public function boot(): void
    {
        # 可選地從此套件加載路由、遷移、視圖
        # $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        # $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
