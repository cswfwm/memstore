<?php
declare(strict_types=1);

return [
    'db' => [
        'host'    => 'sql105.uuk.pp.ua',
        'name'    => 'uu666_43388353_memstore',
        'user'    => 'uu666_43388353',
        'pass'    => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],
    'install_key'    => 'CHANGE_ME',
    'storage_dir'    => __DIR__ . '/storage',
    'max_upload'     => 20 * 1024 * 1024,
    'token_ttl_days' => 30,
];
