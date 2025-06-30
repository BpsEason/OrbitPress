<?php

namespace Analytics;

use Elasticsearch\ClientBuilder;
use Illuminate\Support\Facades\Log;

class AnalyticsModule
{
    protected $elasticsearch;

    public function __construct()
    {
        # 初始化 Elasticsearch 客戶端
        $this->elasticsearch = ClientBuilder::create()
            ->setHosts([env('ELASTICSEARCH_HOSTS', 'http://elasticsearch:9200')])
            ->build();
    }

    /**
     * 追蹤文章瀏覽。
     *
     * @param string $articleId
     * @param string $tenantId
     * @param array $metadata
     * @return void
     */
    public function trackView(string $articleId, string $tenantId, array $metadata = []): void
    {
        $params = [
            'index' => 'analytics_' . $tenantId, # 租戶專屬分析索引
            'body' => [
                'article_id' => $articleId,
                'event_type' => 'view',
                'timestamp' => now()->toIso8601String(),
                'metadata' => $metadata,
            ],
        ];
        try {
            $this->elasticsearch->index($params);
            Log::info("追蹤了文章瀏覽: {$articleId} 於租戶: {$tenantId}");
        } catch (\Exception $e) {
            Log::error("追蹤文章瀏覽失敗 {$articleId}： " . $e->getMessage());
        }
    }

    /**
     * 追蹤自定義事件。
     *
     * @param string $eventType
     * @param string $tenantId
     * @param array $data
     * @return void
     */
    public function trackEvent(string $eventType, string $tenantId, array $data): void
    {
        $params = [
            'index' => 'analytics_' . $tenantId, # 租戶專屬分析索引
            'body' => [
                'event_type' => $eventType,
                'timestamp' => now()->toIso8601String(),
                'data' => $data,
            ],
        ];
        try {
            $this->elasticsearch->index($params);
            Log::info("追蹤了事件: {$eventType} 於租戶: {$tenantId}");
        } catch (\Exception $e) {
            Log::error("追蹤事件失敗 {$eventType}： " . $e->getMessage());
        }
    }

    /**
     * 獲取文章瀏覽量。
     *
     * @param string $articleId
     * @param string $tenantId
     * @return int
     */
    public function getArticleViews(string $articleId, string $tenantId): int
    {
        $params = [
            'index' => 'analytics_' . $tenantId,
            'body' => [
                'query' => [
                    'bool' => [
                        'filter' => [
                            ['term' => ['article_id' => $articleId]],
                            ['term' => ['event_type' => 'view']],
                        ],
                    ],
                ],
            ],
        ];
        try {
            $response = $this->elasticsearch->count($params);
            return $response['count'];
        } catch (\Exception $e) {
            Log::error("獲取文章瀏覽量失敗 {$articleId}： " . $e->getMessage());
            return 0;
        }
    }
}
