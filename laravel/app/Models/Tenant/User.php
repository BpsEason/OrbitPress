<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;
use Spatie\Permission\Traits\HasRoles;
use Laravel\Sanctum\HasApiTokens; # 導入 HasApiTokens trait
use Spatie\Activitylog\Traits\LogsActivity; # 導入特性
use Spatie\Activitylog\LogOptions; # 導入 LogOptions

class User extends Authenticatable
{
    use HasFactory, Notifiable, BelongsToTenant, HasRoles, HasApiTokens, LogsActivity; # 使用 LogsActivity

    # 'tenant_id' 欄位由 BelongsToTenant trait 預設處理
    # 我們需要為此模型設定連接
    protected $connection = 'tenant';

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    # 定義活動日誌選項
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable() # 記錄所有可填充的屬性
            ->logOnlyDirty() # 僅記錄更改的屬性
            ->dontSubmitEmptyLogs(); # 不記錄沒有更改的日誌
    }
}
