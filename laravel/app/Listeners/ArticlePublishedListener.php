<?php

namespace App\Listeners;

use App\Events\ArticlePublished;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class ArticlePublishedListener implements ShouldQueue
{
    use InteractsWithQueue;

    public $notificationService;

    /**
     * 創建事件監聽器。
     */
    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * 處理事件。
     */
    public function handle(ArticlePublished $event): void
    {
        $article = $event->article;

        Log::info("文章發布事件已處理，文章 ID: {$article->id}，租戶: {$article->tenant_id}");

        # 範例：向此租戶的總編輯發送電子郵件通知
        # 在實際應用程式中，您會獲取總編輯的實際電子郵件地址
        $this->notificationService->sendEmail(
            'chief_editor_' . $article->tenant_id . '@example.com',
            '新文章發布: ' . $article->title['zh_TW'], # 使用特定語言或調整郵件模板
            "您的團隊已發布文章 '{$article->title['zh_TW']}'。您可以在此處查看: [文章連結]"
        );

        # 範例：發送推送通知 (如果設備 token 可用)
        # $this->notificationService->sendFirebasePushNotification(
        #     ['some_device_token'],
        #     '新文章！',
        #     $article->title['zh_TW'] . ' 已發布。'
        # );

        # 您也可以在此處追蹤此事件到分析系統
        # app(\Analytics\AnalyticsModule::class)->track('article_published', [
        #     'article_id' => $article->id,
        #     'tenant_id' => $article->tenant_id,
        # ]);
    }
}
