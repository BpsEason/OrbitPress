<?php

namespace App\Models\Tenant;

use Jenssegers\Mongodb\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

class ArticleMongo extends Model
{
    use BelongsToTenant;

    protected $connection = 'mongodb';
    protected $collection = 'articles'; # MongoDB 集合名稱，可依據需求定義

    protected $fillable = [
        'title',
        'content',
        'status',
        'published_at',
        'metadata',
        'tenant_id',
        'locale', # 新增多語系欄位
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'metadata' => 'array',
    ];
}
