<?php

return [
    'storage' => [
        'db' => [
            'connection' => 'pgsql',
            'schema_manager' => Stancl\Tenancy\Database\Contracts\SchemaManager::class,
        ],
    ],
    'database' => [
        'prefix' => 'tenant_',
        'suffix' => '',
        'central_connection' => 'pgsql',
        'tenant_connection' => 'tenant', # 此連接將用於租戶特定模型
        'queue_database_migration' => true,
    ],
    'models' => [
        'tenant' => \App\Models\System\Tenant::class,
    ],
    'migration_paths' => [
        database_path('migrations/tenant'),
    ],
    'middleware' => [
        'web' => [
            \Stancl\Tenancy\Middleware\InitializeTenancyByDomain::class, # 或根據您的需求自訂
        ],
        'api' => [
            \App\Http\Middleware\InitializeTenancy::class,
        ],
    ],
    # 權限和角色將透過 Spatie 針對每個租戶進行管理
    'features' => [
        # ... 其他功能 ...
        Stancl\Tenancy\Features\UniversalRoutes::class, # 如果您有通用路由
    ],
];
