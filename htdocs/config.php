<?php
declare(strict_types=1);

return [
    'db' => [
        'host'    => 'sql105.uuk.pp.ua',
        'name'    => 'uu666_43388353_memstore',
        'user'    => 'uu666_43388353',
        'pass'    => '20130401kyz',
        'charset' => 'utf8mb4',
    ],
    'rate_limit' => [
        'enabled'        => true,
        'window_seconds' => 180,
        'whitelist'      => ['36.63.193.91'],
    ],
    'install_key'    => '8a5c462e61d5c954c8b1cd69179998409d0013a4a04649d4',
    'max_upload'     => 20 * 1024 * 1024,
    'token_ttl_days' => 30,
];
