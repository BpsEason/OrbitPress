<?php

namespace App\Models\System;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Concerns\HasDatabase;

class Tenant extends Model implements TenantWithDatabase
{
    use HasFactory, HasDatabase, HasDomains;

    protected $guarded = [];

    /**
     * 獲取租戶的資料庫連接名稱。
     *
     * @return string
     */
    public function getTenantDatabaseConnectionName(): string
    {
        return 'tenant'; # 在 config/database.php 中定義
    }
}
