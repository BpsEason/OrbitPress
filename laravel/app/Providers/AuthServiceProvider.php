<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;
use App\Models\Tenant\Article;
use App\Policies\ArticlePolicy;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * 應用程式的模型到策略映射。
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Article::class => ArticlePolicy::class,
        // 'App\Models\Model' => 'App\Policies\ModelPolicy',
    ];

    /**
     * 註冊任何身份驗證 / 授權服務。
     */
    public function boot(): void
    {
        // $this->registerPolicies(); // Laravel 10 自動發現策略，但手動註冊更清晰

        // 定義其他 Gate，如果它們不是通過策略實現的
        // Gate::define('manage-roles', function ($user) {
        //     return $user->hasPermissionTo('manage_roles');
        // });
    }
}
