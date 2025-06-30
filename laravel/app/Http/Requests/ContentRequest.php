<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ContentRequest extends FormRequest
{
    /**
     * 判斷用戶是否有權限發出此請求。
     */
    public function authorize(): bool
    {
        # 授權在控制器中使用 Gate/Policy 進行更精細的控制
        return true;
    }

    /**
     * 獲取適用於請求的驗證規則。
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'title' => 'required|array', # 更改為數組以支援多語系 JSON
            'title.zh_TW' => 'required_without_all:title.en,title.zh_CN|string|max:255',
            'title.en' => 'required_without_all:title.zh_TW,title.zh_CN|string|max:255',
            'title.zh_CN' => 'required_without_all:title.zh_TW,title.en|string|max:255',
            'content' => 'required|array', # 更改為數組以支援多語系 JSON
            'content.zh_TW' => 'required_without_all:content.en,content.zh_CN|string',
            'content.en' => 'required_without_all:content.zh_TW,content.zh_CN|string',
            'content.zh_CN' => 'required_without_all:content.zh_TW,content.en|string',
            'status' => 'nullable|string|in:draft,review,published',
            'metadata' => 'nullable|json',
            'locale' => 'required|string|in:zh_TW,zh_CN,en', # 新增多語系欄位
        ];
    }
}
