<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\Tenant\Article;
use App\Models\Tenant\User;
use App\Models\System\Tenant as SystemTenant; # 別名以避免衝突
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission; # 導入 Permission 模型
use Stancl\Tenancy\Features\UniversalRoutes;
use Spatie\Activitylog\Models\Activity; # 導入 Activity 模型
use Spatie\EloquentSnapshot\Snapshot; # 導入 Snapshot 模型

class ContentTest extends TestCase
{
    use RefreshDatabase; # 每個測試重置資料庫

    protected $tenant;
    protected $chiefEditorUser;
    protected $editorUser;
    protected $reviewerUser;

    protected function setUp(): void
    {
        parent::setUp();

        # 1. 創建一個系統租戶 (在中央資料庫上)
        $this->tenant = SystemTenant::create(['id' => 'testtenant', 'name' => '測試租戶']);
        
        # 2. 為此測試租戶初始化租期
        tenancy()->initialize($this->tenant);

        # 3. 為此租戶的資料庫創建角色和權限
        $chiefEditorRole = Role::create(['name' => 'chief_editor', 'tenant_id' => $this->tenant->id, 'guard_name' => 'api']);
        $editorRole = Role::create(['name' => 'editor', 'tenant_id' => $this->tenant->id, 'guard_name' => 'api']);
        $reviewerRole = Role::create(['name' => 'reviewer', 'tenant_id' => $this->tenant->id, 'guard_name' => 'api']);
        
        $publishPermission = Permission::create(['name' => 'publish_article', 'tenant_id' => $this->tenant->id, 'guard_name' => 'api']);
        $editPermission = Permission::create(['name' => 'edit_article', 'tenant_id' => $this->tenant->id, 'guard_name' => 'api']);
        $reviewPermission = Permission::create(['name' => 'review_article', 'tenant_id' => $this->tenant->id, 'guard_name' => 'api']);
        $approvePermission = Permission::create(['name' => 'approve_article', 'tenant_id' => $this->tenant->id, 'guard_name' => 'api']);
        $rejectPermission = Permission::create(['name' => 'reject_article', 'tenant_id' => $this->tenant->id, 'guard_name' => 'api']);
        $viewHistoryPermission = Permission::create(['name' => 'view_article_history', 'tenant_id' => $this->tenant->id, 'guard_name' => 'api']);
        $restoreVersionPermission = Permission::create(['name' => 'restore_article_version', 'tenant_id' => $this->tenant->id, 'guard_name' => 'api']);


        $chiefEditorRole->givePermissionTo([
            $publishPermission, $editPermission, $reviewPermission, 
            $approvePermission, $rejectPermission, $viewHistoryPermission, $restoreVersionPermission
        ]);
        $editorRole->givePermissionTo([$editPermission, $viewHistoryPermission]);
        $reviewerRole->givePermissionTo([$reviewPermission, $approvePermission, $rejectPermission, $viewHistoryPermission]);


        # 4. 為此租戶創建用戶並分配角色
        $this->chiefEditorUser = User::factory()->create([
            'email' => 'chief_editor@testtenant.com',
            'password' => bcrypt('password'),
        ])->assignRole($chiefEditorRole);

        $this->editorUser = User::factory()->create([
            'email' => 'editor@testtenant.com',
            'password' => bcrypt('password'),
        ])->assignRole($editorRole);

        $this->reviewerUser = User::factory()->create([
            'email' => 'reviewer@testtenant.com',
            'password' => bcrypt('password'),
        ])->assignRole($reviewerRole);

        # 確保租戶連接在測試中是活躍的 (Laravel 刷新資料庫處理此問題)
        # \Config::set('database.connections.tenant.database', 'tenant_' . $this->tenant->id);
    }

    protected function tearDown(): void
    {
        tenancy()->end(); # 每個測試後結束租期
        $this->tenant->delete(); # 清理測試租戶
        parent::tearDown();
    }

    /**
     * 獲取 API 請求的租戶特定標頭。
     */
    protected function tenantHeaders(?User $user = null): array
    {
        $user = $user ?: $this->chiefEditorUser;
        # 確保為測試用戶創建 token
        $token = $user->createToken('test-token', ['*'], ['tenant_id' => $this->tenant->id])->plainTextToken;
        return [
            'X-Tenant-ID' => $this->tenant->id,
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $token, 
        ];
    }

    /** @test */
    public function chief_editor_can_create_article()
    {
        $this->actingAs($this->chiefEditorUser, 'api');
        
        $articleData = [
            'title' => ['zh_TW' => '總編輯測試文章', 'en' => 'Chief Editor Test Article'],
            'content' => ['zh_TW' => '這是內容。', 'en' => 'This is content.'],
            'status' => 'draft',
            'metadata' => json_encode(['author' => '總編輯']),
            'locale' => 'zh_TW',
        ];

        $response = $this->postJson('/tenant-routes/articles', $articleData, $this->tenantHeaders());

        $response->assertStatus(201)
                 ->assertJsonFragment(['title' => ['zh_TW' => '總編輯測試文章', 'en' => 'Chief Editor Test Article']]);

        $this->assertDatabaseHas('tenant.articles', ['title' => '{"zh_TW":"總編輯測試文章","en":"Chief Editor Test Article"}']);
        # 檢查活動日誌
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'default', # 預設日誌名稱
            'description' => '文章 {"zh_TW":"總編輯測試文章","en":"Chief Editor Test Article"} 已創建。',
            'subject_type' => Article::class,
            'causer_id' => $this->chiefEditorUser->id,
            'event' => 'created',
        ]);
    }

    /** @test */
    public function editor_can_create_article()
    {
        $this->actingAs($this->editorUser, 'api'); # 以編輯身份操作

        $articleData = [
            'title' => ['zh_TW' => '編輯測試文章', 'en' => 'Editor Test Article'],
            'content' => ['zh_TW' => '這是內容。', 'en' => 'This is content.'],
            'status' => 'draft',
            'metadata' => json_encode(['author' => '編輯']),
            'locale' => 'zh_TW',
        ];

        $response = $this->postJson('/tenant-routes/articles', $articleData, $this->tenantHeaders($this->editorUser));

        $response->assertStatus(201)
                 ->assertJsonFragment(['title' => ['zh_TW' => '編輯測試文章', 'en' => 'Editor Test Article']]);

        $this->assertDatabaseHas('tenant.articles', ['title' => '{"zh_TW":"編輯測試文章","en":"Editor Test Article"}']);
        # 檢查活動日誌
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'default',
            'description' => '文章 {"zh_TW":"編輯測試文章","en":"Editor Test Article"} 已創建。',
            'subject_type' => Article::class,
            'causer_id' => $this->editorUser->id,
            'event' => 'created',
        ]);
    }

    /** @test */
    public function chief_editor_can_publish_article()
    {
        $this->actingAs($this->chiefEditorUser, 'api');
        
        $article = Article::create([
            'title' => ['zh_TW' => '待發布文章', 'en' => 'Article to Publish'],
            'content' => ['zh_TW' => '一些內容在此。', 'en' => 'Some content here.'],
            'status' => 'draft',
            'tenant_id' => $this->tenant->id,
            'locale' => 'zh_TW',
        ]);

        $response = $this->postJson("/tenant-routes/articles/{$article->id}/publish", [], $this->tenantHeaders());

        $response->assertStatus(200)
                 ->assertJson(['message' => '文章發布成功。']);

        $this->assertDatabaseHas('tenant.articles', [
            'id' => $article->id,
            'status' => 'published',
        ]);
        # 檢查活動日誌
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'default',
            'description' => '文章 {"zh_TW":"待發布文章","en":"Article to Publish"} 已發布。',
            'subject_type' => Article::class,
            'causer_id' => $this->chiefEditorUser->id,
            'event' => 'published',
        ]);
    }

    /** @test */
    public function editor_cannot_publish_article()
    {
        $this->actingAs($this->editorUser, 'api');

        $article = Article::create([
            'title' => ['zh_TW' => '由編輯發布的文章', 'en' => 'Article by Editor'],
            'content' => ['zh_TW' => '一些內容。', 'en' => 'Some content.'],
            'status' => 'draft',
            'tenant_id' => $this->tenant->id,
            'locale' => 'zh_TW',
        ]);

        $response = $this->postJson("/tenant-routes/articles/{$article->id}/publish", [], $this->tenantHeaders($this->editorUser));

        $response->assertStatus(403); # 禁止
        $this->assertDatabaseHas('tenant.articles', [
            'id' => $article->id,
            'status' => 'draft', # 狀態應保持為草稿
        ]);
        # 驗證沒有發布的活動日誌
        $this->assertDatabaseMissing('activity_log', [
            'log_name' => 'default',
            'description' => '文章 {"zh_TW":"由編輯發布的文章","en":"Article by Editor"} 已發布。',
            'subject_type' => Article::class,
            'causer_id' => $this->editorUser->id,
            'event' => 'published',
        ]);
    }

    /** @test */
    public function user_can_view_articles()
    {
        $this->actingAs($this->chiefEditorUser, 'api'); # 任何經過身份驗證的用戶都可以查看
        
        Article::create(['title' => ['zh_TW' => '文章 1', 'en' => 'Article 1'], 'content' => ['zh_TW' => '內容 1', 'en' => 'Content 1'], 'tenant_id' => $this->tenant->id, 'locale' => 'zh_TW']);
        Article::create(['title' => ['zh_TW' => '文章 2', 'en' => 'Article 2'], 'content' => ['zh_TW' => '內容 2', 'en' => 'Content 2'], 'tenant_id' => $this->tenant->id, 'locale' => 'zh_TW']);

        $response = $this->getJson('/tenant-routes/articles', $this->tenantHeaders());

        $response->assertStatus(200)
                 ->assertJsonCount(2)
                 ->assertJsonFragment(['title' => ['zh_TW' => '文章 1', 'en' => 'Article 1']])
                 ->assertJsonFragment(['title' => ['zh_TW' => '文章 2', 'en' => 'Article 2']]);
    }

    /** @test */
    public function chief_editor_can_delete_article()
    {
        $this->actingAs($this->chiefEditorUser, 'api');

        $article = Article::create([
            'title' => ['zh_TW' => '要刪除的文章', 'en' => 'Article to Delete'],
            'content' => ['zh_TW' => '內容。', 'en' => 'Content.'],
            'status' => 'draft', # 刪除不需要是已發布狀態
            'tenant_id' => $this->tenant->id,
            'locale' => 'zh_TW',
        ]);

        $response = $this->deleteJson("/tenant-routes/articles/{$article->id}", [], $this->tenantHeaders());

        $response->assertStatus(204); # 無內容
        $this->assertDatabaseMissing('tenant.articles', ['id' => $article->id]);
        # 檢查活動日誌
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'default',
            'description' => '文章 {"zh_TW":"要刪除的文章","en":"Article to Delete"} 已刪除。',
            'subject_type' => Article::class,
            'causer_id' => $this->chiefEditorUser->id,
            'event' => 'deleted',
        ]);
    }

    /** @test */
    public function editor_cannot_delete_article()
    {
        $this->actingAs($this->editorUser, 'api');

        $article = Article::create([
            'title' => ['zh_TW' => '不能刪除的文章', 'en' => 'Article Not Deletable'],
            'content' => ['zh_TW' => '內容。', 'en' => 'Content.'],
            'status' => 'draft',
            'tenant_id' => $this->tenant->id,
            'locale' => 'zh_TW',
        ]);

        $response = $this->deleteJson("/tenant-routes/articles/{$article->id}", [], $this->tenantHeaders($this->editorUser));

        $response->assertStatus(403); # 禁止
        $this->assertDatabaseHas('tenant.articles', ['id' => $article->id]);
        # 驗證沒有刪除的活動日誌
        $this->assertDatabaseMissing('activity_log', [
            'log_name' => 'default',
            'description' => '文章 {"zh_TW":"不能刪除的文章","en":"Article Not Deletable"} 已刪除。',
            'subject_type' => Article::class,
            'causer_id' => $this->editorUser->id,
            'event' => 'deleted',
        ]);
    }

    /** @test */
    public function article_version_history_is_recorded()
    {
        $this->actingAs($this->chiefEditorUser, 'api');
        
        $article = Article::create([
            'title' => ['zh_TW' => '初始標題', 'en' => 'Initial Title'],
            'content' => ['zh_TW' => '初始內容', 'en' => 'Initial Content'],
            'status' => 'draft',
            'tenant_id' => $this->tenant->id,
            'locale' => 'zh_TW',
        ]);

        # 第一次更新
        $article->update([
            'title' => ['zh_TW' => '更新標題 V1', 'en' => 'Updated Title V1'],
            'content' => ['zh_TW' => '更新內容 V1', 'en' => 'Updated Content V1'],
        ]);
        # 第二次更新
        $article->update([
            'title' => ['zh_TW' => '更新標題 V2', 'en' => 'Updated Title V2'],
            'content' => ['zh_TW' => '更新內容 V2', 'en' => 'Updated Content V2'],
        ]);

        $response = $this->getJson("/tenant-routes/articles/{$article->id}/history", $this->tenantHeaders());
        
        $response->assertStatus(200)
                 ->assertJsonCount(2); # 應該有 2 個快照 (每次更新創建一個)

        # 驗證快照包含預期的資料
        $response->assertJson([
            ['payload' => [
                'title' => ['zh_TW' => '更新標題 V1', 'en' => 'Updated Title V1'],
            ]],
            ['payload' => [
                'title' => ['zh_TW' => '更新標題 V2', 'en' => 'Updated Title V2'],
            ]],
        ]);
    }

    /** @test */
    public function article_can_be_restored_to_previous_version()
    {
        $this->actingAs($this->chiefEditorUser, 'api');
        
        $article = Article::create([
            'title' => ['zh_TW' => '原始標題', 'en' => 'Original Title'],
            'content' => ['zh_TW' => '原始內容', 'en' => 'Original Content'],
            'status' => 'draft',
            'tenant_id' => $this->tenant->id,
            'locale' => 'zh_TW',
        ]);

        $article->update([
            'title' => ['zh_TW' => '第一個版本', 'en' => 'First Version'],
            'content' => ['zh_TW' => '第一個內容', 'en' => 'First Content'],
        ]);
        $firstSnapshot = $article->snapshots()->latest()->first(); # 獲取第一個快照

        $article->update([
            'title' => ['zh_TW' => '第二個版本', 'en' => 'Second Version'],
            'content' => ['zh_TW' => '第二個內容', 'en' => 'Second Content'],
        ]);

        # 斷言當前標題是第二個版本
        $this->assertEquals(['zh_TW' => '第二個版本', 'en' => 'Second Version'], $article->refresh()->title);

        # 恢復到第一個快照
        $response = $this->postJson("/tenant-routes/articles/{$article->id}/restore/{$firstSnapshot->id}", [], $this->tenantHeaders());

        $response->assertStatus(200)
                 ->assertJson(['message' => '文章已成功恢復。']);

        # 斷言標題已恢復到第一個版本
        $this->assertEquals(['zh_TW' => '第一個版本', 'en' => 'First Version'], $article->refresh()->title);

        # 檢查活動日誌
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'default',
            'description' => '文章 {"zh_TW":"第一個版本","en":"First Version"} 已恢復到版本 ' . $firstSnapshot->id . '。',
            'subject_type' => Article::class,
            'causer_id' => $this->chiefEditorUser->id,
            'event' => 'restored',
        ]);
    }

    /** @test */
    public function article_can_be_submitted_for_review()
    {
        $this->actingAs($this->chiefEditorUser, 'api');
        
        $article = Article::create([
            'title' => ['zh_TW' => '待審核文章', 'en' => 'Article to Review'],
            'content' => ['zh_TW' => '這是待審核的內容。', 'en' => 'This is content to be reviewed.'],
            'status' => 'draft',
            'tenant_id' => $this->tenant->id,
            'locale' => 'zh_TW',
        ]);

        $response = $this->postJson("/tenant-routes/articles/{$article->id}/submit-for-review", [], $this->tenantHeaders());

        $response->assertStatus(200)
                 ->assertJson(['message' => '文章已提交審核。']);

        $this->assertDatabaseHas('tenant.articles', [
            'id' => $article->id,
            'status' => 'review',
        ]);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'default',
            'description' => '文章 {"zh_TW":"待審核文章","en":"Article to Review"} 已提交審核。',
            'subject_type' => Article::class,
            'causer_id' => $this->chiefEditorUser->id,
            'event' => 'submitted_for_review',
        ]);
    }

    /** @test */
    public function reviewer_can_approve_article()
    {
        $this->actingAs($this->reviewerUser, 'api');
        
        $article = Article::create([
            'title' => ['zh_TW' => '審核中文章', 'en' => 'Article in Review'],
            'content' => ['zh_TW' => '這是審核中的內容。', 'en' => 'Content in review.'],
            'status' => 'review',
            'tenant_id' => $this->tenant->id,
            'locale' => 'zh_TW',
        ]);

        $response = $this->postJson("/tenant-routes/articles/{$article->id}/approve", [], $this->tenantHeaders($this->reviewerUser));

        $response->assertStatus(200)
                 ->assertJson(['message' => '文章已批准並發布。']);

        $this->assertDatabaseHas('tenant.articles', [
            'id' => $article->id,
            'status' => 'published',
        ]);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'default',
            'description' => '文章 {"zh_TW":"審核中文章","en":"Article in Review"} 已被批准並發布。',
            'subject_type' => Article::class,
            'causer_id' => $this->reviewerUser->id,
            'event' => 'approved',
        ]);
    }

    /** @test */
    public function reviewer_can_reject_article()
    {
        $this->actingAs($this->reviewerUser, 'api');
        
        $article = Article::create([
            'title' => ['zh_TW' => '審核中文章（拒絕）', 'en' => 'Article in Review (Reject)'],
            'content' => ['zh_TW' => '這是審核中的內容。', 'en' => 'Content in review.'],
            'status' => 'review',
            'tenant_id' => $this->tenant->id,
            'locale' => 'zh_TW',
        ]);

        $response = $this->postJson("/tenant-routes/articles/{$article->id}/reject", [], $this->tenantHeaders($this->reviewerUser));

        $response->assertStatus(200)
                 ->assertJson(['message' => '文章已拒絕並退回草稿。']);

        $this->assertDatabaseHas('tenant.articles', [
            'id' => $article->id,
            'status' => 'draft',
        ]);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'default',
            'description' => '文章 {"zh_TW":"審核中文章（拒絕）","en":"Article in Review (Reject)"} 已被拒絕並退回草稿。',
            'subject_type' => Article::class,
            'causer_id' => $this->reviewerUser->id,
            'event' => 'rejected',
        ]);
    }

    /** @test */
    public function articles_can_be_searched_by_locale()
    {
        $this->actingAs($this->chiefEditorUser, 'api');
        
        Article::create(['title' => ['zh_TW' => '中文標題文章', 'en' => 'Chinese Title Article'], 'content' => ['zh_TW' => '這是中文內容。', 'en' => 'This is Chinese content.'], 'tenant_id' => $this->tenant->id, 'locale' => 'zh_TW']);
        Article::create(['title' => ['zh_TW' => '英文標題文章', 'en' => 'English Title Article'], 'content' => ['zh_TW' => '這是英文內容。', 'en' => 'This is English content.'], 'tenant_id' => $this->tenant->id, 'locale' => 'en']);
        
        // Ensure Elasticsearch is properly mocked or running for this test
        // For actual integration tests, you'd ensure Elastic is reachable
        // For unit tests, you might mock the Elasticsearch client within ContentService

        $response = $this->getJson("/tenant-routes/articles/search?q=中文&locale=zh_TW", $this->tenantHeaders());
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('articles'));
        $this->assertEquals(['zh_TW' => '中文標題文章', 'en' => 'Chinese Title Article'], $response->json('articles.0.title'));

        $response = $this->getJson("/tenant-routes/articles/search?q=English&locale=en", $this->tenantHeaders());
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('articles'));
        $this->assertEquals(['zh_TW' => '英文標題文章', 'en' => 'English Title Article'], $response->json('articles.0.title'));
    }
}
