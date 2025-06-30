<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserRequest extends FormRequest
{
    /**
     * 判斷用戶是否有權限發出此請求。
     */
    public function authorize(): bool
    {
        return true; # 授權應由策略/門戶處理用戶管理
    }

    /**
     * 獲取適用於請求的驗證規則。
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        $rules = [
            'name' => 'required|string|max:255',
            'password' => 'required|string|min:8|confirmed',
        ];

        # 僅在創建或電子郵件更改時檢查電子郵件唯一性
        if ($this->isMethod('post')) {
            $rules['email'] = 'required|email|unique:tenant.users,email'; # 'tenant' 是連接名稱
        } elseif ($this->isMethod('put') || $this->isMethod('patch')) {
            $rules['email'] = [
                'required',
                'email',
                Rule::unique('tenant.users', 'email')->ignore($this->route('user')), # 更新時忽略當前用戶
            ];
        }

        return $rules;
    }
}
