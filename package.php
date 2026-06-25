<?php

declare(strict_types=1);

return [
    'config' => [
        'framework_start' => [
            'nova\\plugin\\ai\\AiPluginManager',
        ],
    ],
    'require' => [
        'tpl', 'login', 'http'
    ],
];
