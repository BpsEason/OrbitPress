<?php

return [
    'roles' => [
        'chief_editor' => [
            'permissions' => ['publish_article', 'edit_article', 'review_article', 'manage_comments', 'view_dashboard_data', 'manage_roles'],
        ],
        'editor' => [
            'permissions' => ['edit_article'],
        ],
        'reviewer' => [
            'permissions' => ['review_article'],
        ],
        'community_manager' => [
            'permissions' => ['manage_comments'],
        ],
        'data_analyst' => [
            'permissions' => ['view_dashboard_data'],
        ],
    ],
    # 確保如果您在 UserController 中使用它，則有 'manage_roles' 權限
    'additional_permissions' => [
        'manage_roles'
    ]
];
