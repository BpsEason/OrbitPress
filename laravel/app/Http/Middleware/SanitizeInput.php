<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SanitizeInput
{
    /**
     * 處理傳入請求。
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next)
    {
        $input = $request->all();
        $sanitized = $this->sanitize($input);
        $request->replace($sanitized);
        return $next($request);
    }

    /**
     * 遞迴地淨化輸入資料。
     *
     * @param array $input
     * @return array
     */
    protected function sanitize(array $input): array
    {
        return collect($input)->map(function ($value) {
            if (is_string($value)) {
                # 使用 htmlspecialchars 進行基本的 HTML 實體編碼，防範 XSS
                return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); 
            } elseif (is_array($value)) {
                return $this->sanitize($value);
            }
            return $value;
        })->toArray();
    }
}
