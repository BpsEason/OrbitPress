<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\System\SystemTenantController; # 導入新控制器
use App\Http\Controllers\Tenant\TokenController; # 導入 TokenController
use App\Http\Controllers\Auth\SocialiteController; # 導入 SocialiteController
use App\Http\Middleware\InitializeTenancy; // 導入租戶初始化中間件

/*
|--------------------------------------------------------------------------
| API 路由
|--------------------------------------------------------------------------
|
| 您可以在此處註冊應用程式的 API 路由。這些
| 路由由 RouteServiceProvider 加載，並且它們都將
| 分配給 "api" 中間件組。開始創造吧！
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

# 系統級別的租戶管理路由 (中央資料庫)
Route::prefix('system')->group(function () {
    Route::apiResource('tenants', SystemTenantController::class);
    # 您稍後可能會為這些路由添加特定的策略以進行適當的授權
});

# 用於生成 Sanctum API Token 的路由
Route::post('/auth/token', [TokenController::class, 'createToken'])->middleware(InitializeTenancy::class); // Token generation needs tenant context

# 社群登入路由 (不需要 tenant-id 中間件，因為 tenant-id 會在 redirect 前或 callback 中處理)
Route::get('/auth/{provider}/redirect', [SocialiteController::class, 'redirectToProvider']);
Route::get('/auth/{provider}/callback', [SocialiteController::class, 'handleProviderCallback']);


# Prometheus 指標端點 (概念性)
Route::get('/metrics', function () {
    # 這是一個佔位符。在實際應用程式中，您會使用一個指標庫
    # 例如 [https://github.com/promphp/laravel-exporter](https://github.com/promphp/laravel-exporter) 或手動收集數據。
    return response("
# HELP laravel_http_requests_total HTTP 請求總數。
# TYPE laravel_http_requests_total counter
laravel_http_requests_total{method=\"GET\",path=\"/metrics\"} 1

# HELP articles_created_total 已創建文章總數
# TYPE articles_created_total counter
articles_created_total{tenant_id=\"default\"} 0

# HELP articles_published_total 已發布文章總數
# TYPE articles_published_total counter
articles_published_total{tenant_id=\"default\"} 0
", 200, ['Content-Type' => 'text/plain']);
})->withoutMiddleware('api'); # 如果需要，從 API 中間件中排除
