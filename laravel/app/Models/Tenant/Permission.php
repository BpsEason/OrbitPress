<?php

namespace App\Models\Tenant;

use Spatie\Permission\Models\Permission as SpatiePermission;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

class Permission extends SpatiePermission
{
    use BelongsToTenant;

    # 為此模型指定連接
    protected $connection = 'tenant';
}
