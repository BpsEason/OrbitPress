<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Tenant\ContentController;
use App\Http\Controllers\Tenant\UserController;
use App\Http\Controllers\Tenant\ReportController; # 新增 ReportController
// 中間件 'InitializeTenancy' 在 API 閘道層應用，
// 或透過這些路由的自訂 RouteServiceProvider。

/*
|--------------------------------------------------------------------------
| 租戶 API 路由
|--------------------------------------------------------------------------
|
| 這些路由專門用於租戶範圍的操作。它們通常由 API 閘道或自訂
| 路由服務提供者進行前綴 (例如 /tenant-routes)，並在已初始化
| 的租戶上下文中運行。
|
*/

Route::middleware(['auth:api'])->group(function () {
    # 文章管理
    Route::apiResource('articles', ContentController::class);
    Route::post('articles/{article}/publish', [ContentController::class, 'publish'])->middleware('throttle:tenant-publish'); # 應用租戶發布速率限制
    Route::post('articles/{article}/submit-for-review', [ContentController::class, 'submitForReview']); # 提交審核
    Route::post('articles/{article}/approve', [ContentController::class, 'approve']); # 批准審核
    Route::post('articles/{article}/reject', [ContentController::class, 'reject']); # 拒絕審核

    # 文章搜尋 (應用自定義搜尋速率限制)
    Route::get('articles/search', [ContentController::class, 'search'])->middleware('throttle:search');
    
    # 文章版本控制
    Route::get('articles/{article}/history', [ContentController::class, 'history']); # 獲取歷史
    Route::post('articles/{article}/restore/{snapshot}', [ContentController::class, 'restore']); # 恢復版本


    # 用戶和角色管理 (在租戶內部)
    Route::get('users', [UserController::class, 'index']);
    Route::post('users/{user}/assign-role', [UserController::class, 'assignRole']);
    Route::post('users/{user}/revoke-role', [UserController::class, 'revokeRole']);

    # 租戶報表
    Route::get('reports/articles-by-status', [ReportController::class, 'articlesByStatus']);
    Route::get('reports/user-activity', [ReportController::class, 'userActivity']);
    Route::get('reports/{reportType}/export', [ReportController::class, 'exportReport']); // 新增報表導出路由
});
