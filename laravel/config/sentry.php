<?php

return [
    'dsn' => env('SENTRY_LARAVEL_DSN'),

    # The environment used for the Sentry events.
    'environment' => env('APP_ENV'),

    # The release version of your application.
    'release' => env('APP_VERSION', null), # 您可以從 Git 提交或 CI/CD 設定中獲取版本

    # When the application is in debug mode, captured exceptions are logged directly to stderr.
    'breadcrumbs' => [
        'logs' => true,
        'sql_queries' => true,
        'console_commands' => true,
        'queue_info' => true,
        'binding_data' => true,
    ],

    # By default, only exceptions are captured. You can also add specific HTTP status codes.
    'send_default_pii' => false, # 根據您的隱私需求設定
    'traces_sample_rate' => 1.0, # 調整為您的需求
    'profiles_sample_rate' => 1.0, # 調整為您的需求
];

