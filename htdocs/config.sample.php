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
    // 网页面板限流：同 IP 在 window_seconds 内只放行一次；whitelist 内的 IP 不限次数。
    // 仅作用于网页页面（/ 与 上传规范），API 接口不受限制。
    'rate_limit' => [
        'enabled'        => true,
        'window_seconds' => 180,
        'whitelist'      => ['127.0.0.1'],
    ],
    'install_key'    => 'CHANGE_ME',
    'storage_dir'    => __DIR__ . '/storage',
    'max_upload'     => 20 * 1024 * 1024,
    'token_ttl_days' => 30,
];
