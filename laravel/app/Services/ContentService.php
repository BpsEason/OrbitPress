<?php

namespace App\Services;

use App\Models\Tenant\Article;
use App\Models\Tenant\ArticleMongo; # 導入 MongoDB 模型
use Elasticsearch\ClientBuilder; # 導入 Elasticsearch 客戶端
use Illuminate\Support\Facades\Log;
use Spatie\EloquentSnapshot\Snapshot; # 導入 Snapshot 模型

class ContentService
{
    protected $elasticsearch;

    public function __construct()
    {
        $this->elasticsearch = ClientBuilder::create()
            ->setHosts([env('ELASTICSEARCH_HOSTS', 'http://elasticsearch:9200')])
            ->build();
    }

    /**
     * 為當前租戶創建一篇新文章。
     *
     * @param array $data
     * @return Article
     */
    public function createArticle(array $data): Article
    {
        # 存儲到 PostgreSQL
        $article = Article::create($data);
        
        # 存儲到 MongoDB
        $mongoData = $data;
        $mongoData['tenant_id'] = tenancy()->tenant->id;
        $mongoData['_id'] = $article->id; # 使用 PostgreSQL 的 ID 作為 MongoDB 的 ID
        ArticleMongo::create($mongoData);
        
        # 索引到 Elasticsearch
        $this->indexToElasticsearch($article);
        
        Log::info("文章創建並索引：{$article->id} 租戶：{$article->tenant_id}");
        return $article;
    }

    /**
     * 更新現有文章。
     *
     * @param Article $article
     * @param array $data
     * @return Article
     */
    public function updateArticle(Article $article, array $data): Article
    {
        $article->update($data);
        $article->saveSnapshot(); # 每次更新時保存快照

        # 更新 MongoDB
        ArticleMongo::where('_id', $article->id)->update($data);
        
        # 重新索引到 Elasticsearch
        $this->indexToElasticsearch($article);
        
        Log::info("文章更新並重新索引：{$article->id} 租戶：{$article->tenant_id}");
        return $article;
    }

    /**
     * 獲取文章的版本歷史。
     *
     * @param Article $article
     * @return \Illuminate\Support\Collection
     */
    public function getArticleHistory(Article $article)
    {
        return $article->snapshots;
    }

    /**
     * 恢復文章到特定版本。
     *
     * @param Article $article
     * @param Snapshot $snapshot
     * @return Article
     */
    public function restoreArticleVersion(Article $article, Snapshot $snapshot): Article
    {
        $snapshot->restore();
        $article->refresh(); # 重新載入模型以反映恢復的數據
        Log::info("文章 {$article->id} 已恢復到快照 {$snapshot->id}。");
        return $article;
    }

    /**
     * 透過 ID 獲取文章。
     *
     * @param int $id
     * @return Article|null
     */
    public function getArticleById(int $id): ?Article
    {
        return Article::find($id);
    }

    /**
     * 刪除文章。
     *
     * @param Article $article
     * @return bool|null
     */
    public function deleteArticle(Article $article): ?bool
    {
        # 從 MongoDB 刪除
        ArticleMongo::where('_id', $article->id)->delete();
        # 從 Elasticsearch 刪除
        $this->deleteFromElasticsearch($article);
        
        Log::info("文章刪除：{$article->id} 租戶：{$article->tenant_id}");
        return $article->delete();
    }

    /**
     * 使用 Elasticsearch 搜尋文章。
     *
     * @param string $query 搜尋查詢
     * @param string $locale 語言環境 (例如 'zh_TW', 'en')
     * @return array 匹配的文章列表
     */
    public function searchArticles(string $query, string $locale = 'zh_TW'): array
    {
        $params = [
            'index' => 'articles_' . tenancy()->tenant->id, # 租戶專屬索引
            'body' => [
                'query' => [
                    'bool' => [
                        'must' => [
                            [
                                'multi_match' => [
                                    'query' => $query,
                                    'fields' => ["title.{$locale}^3", "content.{$locale}^1", "tags.{$locale}"], # 標題權重更高，支援多語系欄位
                                    'fuzziness' => 'AUTO', # 允許一些拼寫錯誤
                                ]
                            ],
                            ['term' => ['locale' => $locale]], # 根據語言環境過濾
                        ],
                    ],
                ],
                'suggest' => [ # 搜尋提示 (自動補全)
                    'title-suggest' => [
                        'prefix' => $query,
                        'completion' => [
                            'field' => "title.{$locale}.completion",
                            'size' => 5,
                        ]
                    ]
                ],
                # 相似度匹配 (使用基於內容的向量，假設這些向量已預先計算)
                // 'knn' => [
                //     'field' => 'content_vector', // 假設有個向量欄位
                //     'query_vector' => $this->getVectorForQuery($query), // 需要一個函數來將查詢轉換為向量
                //     'k' => 5,
                //     'num_candidates' => 10
                // ]
            ],
        ];

        # 設定自訂分析器 (假設在 Elasticsearch 中已配置)
        // $params['body']['settings'] = [
        //     'analysis' => [
        //         'analyzer' => [
        //             'my_analyzer' => [
        //                 'tokenizer' => 'ik_smart', // 例如，用於中文分詞
        //                 'filter' => ['lowercase']
        //             ]
        //         ]
        //     ]
        // ];

        try {
            $response = $this->elasticsearch->search($params);
            
            $articles = array_map(function ($hit) {
                return $hit['_source'];
            }, $response['hits']['hits']);

            $suggestions = [];
            if (isset($response['suggest']['title-suggest'])) {
                foreach ($response['suggest']['title-suggest'] as $suggest_option) {
                    foreach ($suggest_option['options'] as $option) {
                        $suggestions[] = $option['text'];
                    }
                }
            }

            return [
                'articles' => $articles,
                'suggestions' => array_unique($suggestions),
            ];
        } catch (\Exception $e) {
            Log::error("Elasticsearch 搜尋失敗： " . $e->getMessage());
            return ['articles' => [], 'suggestions' => []];
        }
    }

    /**
     * 索引文章到 Elasticsearch。
     *
     * @param Article $article
     */
    protected function indexToElasticsearch(Article $article): void
    {
        $body = [
            'status' => $article->status->name,
            'published_at' => $article->published_at ? $article->published_at->toIso8601String() : null,
            'metadata' => $article->metadata,
            'tenant_id' => tenancy()->tenant->id,
            'locale' => $article->locale, # Add locale to index
        ];

        # 為每個語言環境添加可翻譯欄位
        foreach ($article->getTranslatableAttributes() as $attribute) {
            foreach ($article->getTranslations($attribute) as $locale => $translation) {
                $body["{$attribute}.{$locale}"] = $translation;
                # 如果要為自動補全提供單獨的完成器欄位
                if ($attribute === 'title') {
                    $body["{$attribute}.{$locale}.completion"] = [
                        'input' => $translation,
                        'weight' => 1, # 可選的權重
                    ];
                }
            }
        }

        $params = [
            'index' => 'articles_' . tenancy()->tenant->id,
            'id' => $article->id,
            'body' => $body,
        ];
        try {
            $this->elasticsearch->index($params);
            # 如果需要，在此處為新索引的文檔執行相似度計算
        } catch (\Exception $e) {
            Log::error("Elasticsearch 索引文章失敗 {$article->id}： " . $e->getMessage());
        }
    }

    /**
     * 從 Elasticsearch 刪除文章。
     *
     * @param Article $article
     */
    protected function deleteFromElasticsearch(Article $article): void
    {
        $params = [
            'index' => 'articles_' . tenancy()->tenant->id,
            'id' => $article->id,
        ];
        try {
            $this->elasticsearch->delete($params);
        } catch (\Exception $e) {
            Log::warning("無法從 Elasticsearch 刪除文章 {$article->id}： " . $e->getMessage());
        }
    }
}
