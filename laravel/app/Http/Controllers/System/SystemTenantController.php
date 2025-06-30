<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Models\System\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Spatie\Activitylog\Models\Activity; // 導入 Activity 模型

class SystemTenantController extends Controller
{
    /**
     * 顯示所有租戶的列表。
     */
    public function index()
    {
        // 在實際系統中，這將需要系統級別的管理員角色。
        // 為簡化，此處未添加特定授權，假設其用於內部使用。
        $tenants = Tenant::all();
        return response()->json($tenants);
    }

    /**
     * 在存儲中存儲新創建的租戶。
     */
    public function store(Request $request)
    {
        $request->validate([
            'id' => ['required', 'string', 'max:50', Rule::unique('tenants', 'id')],
            'name' => 'required|string|max:255',
            'domain' => ['nullable', 'string', 'max:255', Rule::unique('tenants', 'domain')],
            'data' => 'nullable|json',
        ]);

        try {
            DB::beginTransaction();

            $tenant = Tenant::create($request->all());

            // 初始化並遷移新租戶的資料庫
            tenancy()->initialize($tenant);
            Artisan::call('migrate', ['--path' => 'database/migrations/tenant', '--force' => true, '--database' => 'tenant']);
            
            // 在新租戶的資料庫上重新運行角色/權限的種子
            Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\RolePermissionSeeder', '--force' => true]);
            
            tenancy()->end(); // 結束新租戶的租期

            // 記錄租戶創建活動 (在系統日誌中)
            activity('system') # 使用不同的日誌名稱來區分系統級活動
                ->performedOn($tenant)
                ->causedBy(auth()->user()) # 如果有系統管理員登錄
                ->event('created')
                ->log("租戶 '{$tenant->name}' ({$tenant->id}) 已創建。");

            DB::commit();
            Log::info("租戶已創建: {$tenant->id}");
            return response()->json($tenant, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("創建租戶失敗: " . $e->getMessage());
            return response()->json(['error' => '創建租戶失敗', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * 顯示指定的租戶。
     */
    public function show(Tenant $tenant)
    {
        return response()->json($tenant);
    }

    /**
     * 更新存儲中指定的租戶。
     */
    public function update(Request $request, Tenant $tenant)
    {
        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'domain' => ['nullable', 'string', 'max:255', Rule::unique('tenants', 'domain')->ignore($tenant->id)],
            'data' => 'nullable|json',
        ]);

        $tenant->update($request->all());

        // 記錄租戶更新活動 (在系統日誌中)
        activity('system')
            ->performedOn($tenant)
            ->causedBy(auth()->user())
            ->event('updated')
            ->log("租戶 '{$tenant->name}' ({$tenant->id}) 已更新。");

        Log::info("租戶已更新: {$tenant->id}");
        return response()->json($tenant);
    }

    /**
     * 從存儲中刪除指定的租戶。
     */
    public function destroy(Tenant $tenant)
    {
        try {
            DB::beginTransaction();
            
            // 刪除租戶的資料庫/schema
            tenancy()->initialize($tenant);
            Artisan::call('tenants:artisan', ['command' => 'migrate:fresh', '--force' => true, '--database' => 'tenant']); # 這會刪除並重新創建
            // 如需刪除 schema/資料庫，您可能需要自訂命令或直接的資料庫呼叫：
            // $tenant->deleteDatabase(); # 此方法可能取決於租戶包版本/設定是否可用
            tenancy()->end();

            $tenant->delete();

            // 記錄租戶刪除活動 (在系統日誌中)
            activity('system')
                ->performedOn($tenant)
                ->causedBy(auth()->user())
                ->event('deleted')
                ->log("租戶 '{$tenant->name}' ({$tenant->id}) 已刪除。");

            DB::commit();
            Log::info("租戶已刪除: {$tenant->id}");
            return response()->json(null, 204);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("刪除租戶失敗: " . $e->getMessage());
            return response()->json(['error' => '刪除租戶失敗', 'message' => $e->getMessage()], 500);
        }
    }
}
