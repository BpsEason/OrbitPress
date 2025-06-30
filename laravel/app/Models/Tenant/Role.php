<?php

namespace App\Models\Tenant;

use Spatie\Permission\Models\Role as SpatieRole;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

class Role extends SpatieRole
{
    use BelongsToTenant;

    # 為此模型指定連接
    protected $connection = 'tenant';
}
