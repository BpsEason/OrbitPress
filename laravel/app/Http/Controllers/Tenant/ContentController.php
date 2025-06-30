<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\ContentRequest;
use App\Models\Tenant\Article;
use App\Models\Tenant\ArticleMongo; // 導入 MongoDB 模型
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log; // 用於日誌指標
use Elasticsearch\ClientBuilder; // 導入 Elasticsearch 客戶端
use Spatie\Activitylog\Models\Activity; // 導入 Activity 模型
use App\States\Article\Draft;
use App\States\Article\Review;
use App\States\Article\Published;
use Spatie\ModelStates\Exceptions\InvalidTransition;


class ContentController extends Controller
{
    protected $elasticsearch;

    public function __construct()
    {
        // 初始化 Elasticsearch 客戶端
        $this->elasticsearch = ClientBuilder::create()
            ->setHosts([env('ELASTICSEARCH_HOSTS', 'http://elasticsearch:9200')])
            ->build();
    }

    /**
     * 顯示所有文章的列表。
     */
    public function index(Request $request)
    {
        $locale = $request->query('locale', config('translatable.fallback_locale'));
        $articles = Article::all()->filter(function ($article) use ($locale) {
            // Filter by locale if the article has content for that locale
            // For articles created without specific locale, it will still show 'default' or first available.
            return isset($article->title[$locale]);
        });
        return response()->json($articles->values()); // Re-index keys
    }

    /**
     * 顯示指定的文章。
     */
    public function show(Article $article)
    {
        // 記錄文章瀏覽量指標到日誌
        Log::info('article_views_total', ['article_id' => $article->id, 'tenant_id' => tenancy()->tenant->id]);
        // 透過 AnalyticsModule 追蹤文章瀏覽
        app(\Analytics\AnalyticsModule::class)->trackView($article->id, tenancy()->tenant->id, ['user_agent' => request()->header('User-Agent')]);
        return response()->json($article);
    }

    /**
     * 在存儲中存儲新創建的文章。
     */
    public function store(ContentRequest $request)
    {
        $this->authorize('create', Article::class); // 使用策略進行授權

        // 存儲到 PostgreSQL
        $validatedData = $request->validated();
        $validatedData['locale'] = $request->input('locale', config('translatable.fallback_locale')); // 確保語言環境被設置

        $article = Article::create($validatedData);
        
        // 存儲到 MongoDB
        $mongoData = $validatedData;
        $mongoData['tenant_id'] = tenancy()->tenant->id; // 確保 tenant_id 存在於 MongoDB 資料中
        $mongoData['_id'] = $article->id; // 使用 PostgreSQL 的 ID 作為 MongoDB 的 ID
        ArticleMongo::create($mongoData);
        
        // 索引到 Elasticsearch
        $this->indexToElasticsearch($article);

        // 記錄文章創建活動
        activity()
            ->performedOn($article)
            ->causedBy(auth()->user()) // 記錄操作者
            ->event('created')
            ->log('文章 ' . $article->getTranslation('title', $validatedData['locale']) . ' 已創建。');

        // 記錄文章創建指標到日誌
        Log::info('articles_created_total', ['tenant_id' => tenancy()->tenant->id]);
        return response()->json($article, 201);
    }

    /**
     * 更新存儲中指定的文章。
     */
    public function update(ContentRequest $request, Article $article)
    {
        $this->authorize('update', $article); // 使用策略進行授權

        $validatedData = $request->validated();
        $article->update($validatedData);
        $article->saveSnapshot(); // 保存文章快照
        
        // 更新 MongoDB
        ArticleMongo::where('_id', $article->id)->update($validatedData);
        
        // 重新索引到 Elasticsearch
        $this->indexToElasticsearch($article);

        // 記錄文章更新活動
        activity()
            ->performedOn($article)
            ->causedBy(auth()->user())
            ->event('updated')
            ->log('文章 ' . $article->getTranslation('title', $article->locale) . ' 已更新。');

        // 記錄文章更新指標到日誌
        Log::info('articles_updated_total', ['tenant_id' => tenancy()->tenant->id]);
        return response()->json($article);
    }

    /**
     * 從存儲中刪除指定的文章。
     */
    public function destroy(Article $article)
    {
        $this->authorize('delete', $article); // 使用策略進行授權

        // 從 MongoDB 刪除
        ArticleMongo::where('_id', $article->id)->delete();
        // 從 Elasticsearch 刪除
        $this->deleteFromElasticsearch($article);
        
        $article->delete();

        // 記錄文章刪除活動
        activity()
            ->performedOn($article)
            ->causedBy(auth()->user())
            ->event('deleted')
            ->log('文章 ' . $article->getTranslation('title', $article->locale) . ' 已刪除。');

        // 記錄文章刪除指標到日誌
        Log::info('articles_deleted_total', ['tenant_id' => tenancy()->tenant->id]);
        return response()->json(null, 204);
    }

    /**
     * 發布指定的文章。
     */
    public function publish(Article $article)
    {
        $this->authorize('publish', $article); // 使用策略進行授權

        try {
            $article->status->transitionTo(Published::class);
            $article->published_at = now();
            $article->save();

            // 觸發文章發布事件
            event(new \App\Events\ArticlePublished($article));
            // 記錄文章發布活動
            activity()
                ->performedOn($article)
                ->causedBy(auth()->user())
                ->event('published')
                ->log('文章 ' . $article->getTranslation('title', $article->locale) . ' 已發布。');
            // 記錄文章發布指標到日誌
            Log::info('articles_published_total', ['tenant_id' => tenancy()->tenant->id]);
            return response()->json(['message' => '文章發布成功。']);
        } catch (InvalidTransition $e) {
            return response()->json(['error' => '無法發布文章: ' . $e->getMessage()], 400);
        }
    }

    /**
     * 提交指定的文章進行審核。
     */
    public function submitForReview(Article $article)
    {
        $this->authorize('submitForReview', $article); // 使用策略進行授權
        
        try {
            $article->status->transitionTo(Review::class);
            $article->save();
            activity()
                ->performedOn($article)
                ->causedBy(auth()->user())
                ->event('submitted_for_review')
                ->log('文章 ' . $article->getTranslation('title', $article->locale) . ' 已提交審核。');
            return response()->json(['message' => '文章已提交審核。']);
        } catch (InvalidTransition $e) {
            return response()->json(['error' => '無法提交審核: ' . $e->getMessage()], 400);
        }
    }

    /**
     * 批准審核中的文章。
     */
    public function approve(Article $article)
    {
        $this->authorize('approve', $article); // 使用策略進行授權

        try {
            $article->status->transitionTo(Published::class);
            $article->published_at = now();
            $article->save();

            activity()
                ->performedOn($article)
                ->causedBy(auth()->user())
                ->event('approved')
                ->log('文章 ' . $article->getTranslation('title', $article->locale) . ' 已被批准並發布。');

            return response()->json(['message' => '文章已批准並發布。']);
        } catch (InvalidTransition $e) {
            return response()->json(['error' => '無法批准文章: ' . $e->getMessage()], 400);
        }
    }

    /**
     * 拒絕審核中的文章，並將其返回草稿狀態。
     */
    public function reject(Article $article)
    {
        $this->authorize('reject', $article); // 使用策略進行授權

        try {
            $article->status->transitionTo(Draft::class); // 返回草稿
            $article->save();

            activity()
                ->performedOn($article)
                ->causedBy(auth()->user())
                ->event('rejected')
                ->log('文章 ' . $article->getTranslation('title', $article->locale) . ' 已被拒絕並退回草稿。');

            return response()->json(['message' => '文章已拒絕並退回草稿。']);
        } catch (InvalidTransition $e) {
            return response()->json(['error' => '無法拒絕文章: ' . $e->getMessage()], 400);
        }
    }

    /**
     * 獲取文章的版本歷史。
     *
     * @param Article $article
     * @return \Illuminate\Http\JsonResponse
     */
    public function history(Article $article)
    {
        $this->authorize('viewArticleHistory', $article); // 使用策略進行授權
        $snapshots = $article->snapshots()->latest()->get();
        return response()->json($snapshots);
    }

    /**
     * 恢復文章到特定版本。
     *
     * @param Article $article
     * @param \Spatie\EloquentSnapshot\Snapshot $snapshot
     * @return \Illuminate\Http\JsonResponse
     */
    public function restore(Article $article, \Spatie\EloquentSnapshot\Snapshot $snapshot)
    {
        $this->authorize('restoreArticleVersion', $article); // 使用策略進行授權

        try {
            $snapshot->restore();
            // 由於 restore() 不會自動觸發模型事件，所以手動記錄活動
            activity()
                ->performedOn($article)
                ->causedBy(auth()->user())
                ->event('restored')
                ->log('文章 ' . $article->getTranslation('title', $article->locale) . ' 已恢復到版本 ' . $snapshot->id . '。');
            return response()->json(['message' => '文章已成功恢復。']);
        } catch (\Exception $e) {
            return response()->json(['error' => '恢復文章失敗: ' . $e->getMessage()], 500);
        }
    }

    /**
     * 搜尋文章 (透過 Elasticsearch)。
     */
    public function search(Request $request)
    {
        $query = $request->input('q');
        $locale = $request->input('locale', config('translatable.fallback_locale')); // 從請求中獲取語言環境
        if (empty($query)) {
            return response()->json(['error' => '請提供搜尋關鍵字。'], 400);
        }
        $contentService = new \App\Services\ContentService();
        $results = $contentService->searchArticles($query, $locale);
        return response()->json($results);
    }

    /**
     * 索引文章到 Elasticsearch。
     */
    protected function indexToElasticsearch(Article $article): void
    {
        $body = [
            'status' => $article->status->name, // 使用狀態機的名稱
            'published_at' => $article->published_at ? $article->published_at->toIso8601String() : null,
            'metadata' => $article->metadata,
            'tenant_id' => tenancy()->tenant->id,
            'locale' => $article->locale, // Add locale to index
        ];

        // Add translatable fields for each locale
        foreach ($article->getTranslatableAttributes() as $attribute) {
            foreach ($article->getTranslations($attribute) as $locale => $translation) {
                $body["{$attribute}.{$locale}"] = $translation;
                // For autocomplete, if title is the field
                if ($attribute === 'title') {
                    $body["{$attribute}.{$locale}.completion"] = [
                        'input' => $translation,
                        'weight' => 1,
                    ];
                }
            }
        }

        $params = [
            'index' => 'articles_' . tenancy()->tenant->id, // 租戶專屬索引
            'id' => $article->id,
            'body' => $body,
        ];
        try {
            $this->elasticsearch->index($params);
        } catch (\Exception $e) {
            Log::error("Elasticsearch 索引文章失敗 {$article->id}: " . $e->getMessage());
        }
    }

    /**
     * 從 Elasticsearch 刪除文章。
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
            Log::warning("無法從 Elasticsearch 刪除文章 {$article->id}: " . $e->getMessage());
        }
    }
}
