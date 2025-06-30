<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserRequest;
use App\Models\Tenant\User;
use Spatie\Permission\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity; // 導入 Activity 模型

class UserController extends Controller
{
    /**
     * 顯示所有用戶的列表。
     */
    public function index()
    {
        // 確保用戶有權限查看用戶 (例如 'manage_users' 或 'view_dashboard_data')
        if (Gate::denies('view_dashboard_data')) {
            return response()->json(['error' => '未經授權查看用戶'], 403);
        }
        $users = User::with('roles')->get();
        return response()->json($users);
    }

    /**
     * 為用戶分配角色。
     */
    public function assignRole(Request $request, User $user)
    {
        // 確保用戶有權限管理角色
        if (Gate::denies('manage_roles')) { // 假設有一個 'manage_roles' 權限
            return response()->json(['error' => '未經授權分配角色'], 403);
        }

        $request->validate(['role' => 'required|string']);

        $role = Role::where('name', $request->role)
                    ->where('tenant_id', tenancy()->tenant->id) // 確保角色屬於當前租戶
                    ->first();

        if (!$role) {
            return response()->json(['error' => '此租戶找不到該角色'], 404);
        }

        $user->assignRole($role);

        // 記錄角色分配活動
        activity()
            ->performedOn($user)
            ->causedBy(auth()->user())
            ->event('role_assigned')
            ->log("角色 '{$role->name}' 已分配給用戶 '{$user->name}' ({$user->email})。");

        return response()->json(['message' => '角色分配成功。']);
    }

    /**
     * 撤銷用戶的角色。
     */
    public function revokeRole(Request $request, User $user)
    {
        // 確保用戶有權限管理角色
        if (Gate::denies('manage_roles')) { // 假設有一個 'manage_roles' 權限
            return response()->json(['error' => '未經授權撤銷角色'], 403);
        }

        $request->validate(['role' => 'required|string']);

        $user->removeRole($request->role);

        // 記錄角色撤銷活動
        activity()
            ->performedOn($user)
            ->causedBy(auth()->user())
            ->event('role_revoked')
            ->log("用戶 '{$user->name}' ({$user->email}) 已撤銷角色 '{$request->role}'。");

        return response()->json(['message' => '角色撤銷成功。']);
    }
}
