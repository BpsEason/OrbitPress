<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Resolvers\DomainTenantResolver; # 未直接使用，但通常是租戶設定的一部分

class InitializeTenancy extends InitializeTenancyByDomain
{
    /**
     * 處理傳入的請求。
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        # 使用從 API 閘道透過 X-Tenant-ID 標頭傳遞的租戶 ID
        $tenantId = $request->header('X-Tenant-ID');

        if ($tenantId) {
            $tenant = \App\Models\System\Tenant::find($tenantId);
            if ($tenant) {
                tenancy()->initialize($tenant);
                return $next($request);
            }
        }

        # 如果未提供租戶 ID 或未找到，則返回未經授權的回應
        return response()->json(['error' => '租戶未找到或 X-Tenant-ID 標頭缺失'], 404);
    }
}
