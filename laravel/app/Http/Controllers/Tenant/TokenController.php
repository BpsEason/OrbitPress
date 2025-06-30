<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class TokenController extends Controller
{
    /**
     * 創建一個新的 API Token。
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function createToken(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
            'device_name' => 'required|string',
        ]);

        // 在當前租戶的資料庫中查找用戶
        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => [trans('auth.failed')],
            ]);
        }

        // 創建一個新的 Sanctum Token
        // 'tenant_id' 應該已經在當前租戶上下文中，可以從 tenancy() 助手獲取
        $token = $user->createToken($request->device_name, ['*'], ['tenant_id' => tenancy()->tenant->id])->plainTextToken;

        // 記錄用戶登錄活動
        activity()
            ->performedOn($user)
            ->causedBy($user) # 用戶自己是操作者
            ->event('logged_in')
            ->log('用戶 ' . $user->email . ' 已成功登錄。');

        return response()->json(['token' => $token]);
    }
}
