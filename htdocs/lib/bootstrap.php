<?php
declare(strict_types=1);

// 防止被重复 require（web.php 也会引入本文件）
if (defined('MEMSTORE_BOOTSTRAPPED')) {
    return;
}
define('MEMSTORE_BOOTSTRAPPED', true);

$configFile = dirname(__DIR__) . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'config_missing', 'message' => '缺少 config.php，请从 config.sample.php 复制并填写']);
    exit;
}

define('APP_ROOT', dirname(__DIR__));
define('STORAGE_DIR', dirname(__DIR__) . '/storage');

require __DIR__ . '/util.php';
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';

$GLOBALS['config'] = require $configFile;
