<?php

namespace App\Providers;

use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;
use App\Events\ArticlePublished;
use App\Listeners\ArticlePublishedListener;
use App\Events\UserSubscribed; # 假設此事件也可能有監聽器

class EventServiceProvider extends ServiceProvider
{
    /**
     * 應用程式的事件到監聽器映射。
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
        ArticlePublished::class => [
            ArticlePublishedListener::class,
        ],
        # UserSubscribed::class => [
        #     UserSubscribedListener::class, # 如果您為此創建監聽器
        # ],
    ];

    /**
     * 註冊應用程式的任何事件。
     */
    public function boot(): void
    {
        parent::boot(); # 調用父類別的 boot 方法
    }

    /**
     * 判斷是否應自動發現事件和監聽器。
     */
    public function shouldDiscoverEvents(): bool
    {
        return false; # 如果您喜歡自動發現，請設定為 true
    }
}
