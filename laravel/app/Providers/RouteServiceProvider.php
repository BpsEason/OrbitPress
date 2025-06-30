<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * 應用程式路由定義檔案的路徑。
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * 定義應用程式的路由模型綁定、模式過濾器等。
     */
    public function boot(): void
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));

            # 租戶特定路由，應用 InitializeTenancy 中間件
            Route::middleware(['api', \App\Http\Middleware\InitializeTenancy::class])
                ->prefix('tenant-routes')
                ->group(base_path('routes/tenant.php'));
        });
    }

    /**
     * 為應用程式配置速率限制器。
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            # 對於每個經過身份驗證的用戶，每分鐘最多 60 次請求
            # 如果未經過身份驗證，則根據 IP 地址每分鐘最多 60 次請求
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        # 為搜尋 API 定義更細粒度的速率限制
        RateLimiter::for('search', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip())->response(function () {
                return response('搜尋請求過於頻繁，請稍後重試。', 429);
            });
        });

        # 您可以在此處定義更多針對特定租戶或 API 行為的速率限制器
        # 例如，一個租戶可以有不同的發布頻率限制
        RateLimiter::for('tenant-publish', function (Request $request) {
            # 從租戶設定中獲取限制，如果不存在則使用預設值
            $limit = tenancy()->tenant->data['publish_rate_limit'] ?? 5; # 假設每分鐘 5 次發布
            return Limit::perMinute($limit)->by($request->user()?->id ?: $request->ip())->response(function () {
                return response('發布文章請求過於頻繁，請稍後重試。', 429);
            });
        });
    }
}

