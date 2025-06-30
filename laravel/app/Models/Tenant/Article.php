<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;
use Spatie\Activitylog\Traits\LogsActivity; # 導入特性
use Spatie\Activitylog\LogOptions; # 導入 LogOptions
use Spatie\ModelStates\HasStates; # 導入 HasStates 特性
use Spatie\EloquentSnapshot\UsesSnapshots; # 導入 Snapshots 特性
use Spatie\Translatable\HasTranslations; # 導入 HasTranslations 特性

class Article extends Model
{
    use HasFactory, BelongsToTenant, LogsActivity, HasStates, UsesSnapshots, HasTranslations; # 使用所有特性

    protected $fillable = [
        'title',
        'content',
        'status',
        'published_at',
        'metadata',
        'tenant_id', # 確保此欄位可大量賦值
        'locale', # 新增多語系欄位
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'metadata' => 'array', # 將 metadata 轉換為陣列以便處理
        'status' => \App\States\Article\ArticleState::class, # 轉換狀態機
    ];

    public $translatable = ['title', 'content']; # 定義可翻譯的欄位

    # 定義活動日誌選項
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable() # 記錄所有可填充的屬性
            ->logOnlyDirty() # 僅記錄更改的屬性
            ->dontSubmitEmptyLogs(); # 不記錄沒有更改的日誌
    }

    # 定義快照名稱 (可選)
    public function uniqueSnapshotName(): string
    {
        return "article-{$this->id}-{$this->tenant_id}";
    }
}
