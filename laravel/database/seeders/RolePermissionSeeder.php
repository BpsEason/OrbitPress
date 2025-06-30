<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\System\Tenant; # 導入 Tenant 模型
use App\States\Article\Draft;
use App\States\Article\Review;
use App\States\Article\Published;


class RolePermissionSeeder extends Seeder
{
    /**
     * 運行資料庫種子。
     */
    public function run(): void
    {
        # 定義系統級別權限 (如果有的話，例如用於管理租戶的超級管理員)
        # 這通常在中央資料庫連接上完成。
        # 目前，我們專注於租戶特定的角色/權限。

        # 定義預設租戶。在實際應用程式中，這些將透過系統 UI 進行管理。
        $defaultTenants = [
            ['id' => 'cw', 'name' => '天下雜誌', 'domain' => 'cw.localhost'],
            ['id' => 'health', 'name' => '康健雜誌', 'domain' => 'health.localhost'],
            ['id' => 'parenting', 'name' => '親子天下', 'domain' => 'parenting.localhost'],
        ];

        foreach ($defaultTenants as $tenantData) {
            # 在中央資料庫中創建租戶 (如果不存在)
            $tenant = Tenant::firstOrCreate(['id' => $tenantData['id']], $tenantData);

            # 為當前租戶初始化租期，將角色/權限應用到其資料庫
            tenancy()->initialize($tenant);

            $tenantId = $tenant->id;
            $guardName = 'api'; # 用於 API 用戶的守衛

            // 確保 Spatie 的內部種子運行以創建預設角色表
            // 這可能會在 migrate 時自動處理，但在 seeder 中再次呼叫以防萬一
            // $this->call([
            //     \Spatie\Permission\Database\Seeders\PermissionsTableSeeder::class,
            // ]);

            # 創建角色
            $chiefEditor = Role::firstOrCreate(['name' => 'chief_editor', 'tenant_id' => $tenantId, 'guard_name' => $guardName]);
            $editor = Role::firstOrCreate(['name' => 'editor', 'tenant_id' => $tenantId, 'guard_name' => $guardName]);
            $reviewer = Role::firstOrCreate(['name' => 'reviewer', 'tenant_id' => $tenantId, 'guard_name' => $guardName]);
            $communityManager = Role::firstOrCreate(['name' => 'community_manager', 'tenant_id' => $tenantId, 'guard_name' => $guardName]);
            $dataAnalyst = Role::firstOrCreate(['name' => 'data_analyst', 'tenant_id' => $tenantId, 'guard_name' => $guardName]);

            # 定義當前租戶的權限
            $permissions = [
                'publish_article',
                'edit_article',
                'review_article',
                'manage_comments',
                'view_dashboard_data',
                'manage_roles', # 為用戶角色管理添加
                'access_reports', # 訪問報表權限
                'manage_tenant_settings', # 管理租戶設定權限
                'approve_article', // 新增權限：批准文章
                'reject_article',  // 新增權限：拒絕文章
                'view_article_history', // 新增權限：查看文章歷史
                'restore_article_version', // 新增權限：恢復文章版本
            ];

            foreach ($permissions as $permissionName) {
                Permission::firstOrCreate(['name' => $permissionName, 'tenant_id' => $tenantId, 'guard_name' => $guardName]);
            }

            # 為當前租戶的角色分配權限
            $chiefEditor->givePermissionTo([
                'publish_article',
                'edit_article',
                'review_article',
                'manage_comments',
                'view_dashboard_data',
                'manage_roles',
                'access_reports',
                'manage_tenant_settings',
                'approve_article',
                'reject_article',
                'view_article_history',
                'restore_article_version',
            ]);
            $editor->givePermissionTo(['edit_article', 'view_article_history']);
            $reviewer->givePermissionTo(['review_article', 'approve_article', 'reject_article', 'view_article_history']); // 審核者可以批准和拒絕
            $communityManager->givePermissionTo(['manage_comments']);
            $dataAnalyst->givePermissionTo(['view_dashboard_data', 'access_reports']);
            # 為每個租戶添加一個預設用戶用於測試 (例如，一個總編輯)
            # 此用戶將在租戶特定的 'users' 表中創建
            $defaultUser = \App\Models\Tenant\User::firstOrCreate(
                ['email' => 'chief_editor_' . $tenantId . '@example.com'],
                [
                    'name' => 'Chief Editor ' . strtoupper($tenantId),
                    'password' => bcrypt('password'), # 生產環境請更改
                    'email_verified_at' => now(),
                ]
            );
            $defaultUser->assignRole($chiefEditor);

            echo "已為租戶播種角色和權限: {$tenant->name} ({$tenant->id})\n";

            tenancy()->end(); # 結束當前租戶的租期
        }
    }
}
