<?php
declare(strict_types=1);

if (defined('MEMSTORE_UTIL')) {
    return;
}
define('MEMSTORE_UTIL', true);

function respond(int $status, array $data): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        respond(400, ['error' => 'invalid_json', 'message' => '请求体必须是合法的 JSON 对象']);
    }
    return $data;
}

function request_param(array $body, string $key, string $default = ''): string
{
    foreach ([$body, $_POST, $_GET] as $src) {
        if (isset($src[$key]) && is_scalar($src[$key])) {
            return trim((string)$src[$key]);
        }
    }
    return $default;
}

function bearer_token(): string
{
    $header = $_SERVER['HTTP_X_API_TOKEN'] ?? '';
    if ($header !== '') {
        return trim($header);
    }

    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($auth !== '' && preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) {
        return $m[1];
    }

    if (isset($_GET['token']) && is_string($_GET['token'])) {
        return $_GET['token'];
    }
    return '';
}

function sanitize_category(string $raw): string
{
    $value = strtolower(trim($raw));
    $value = preg_replace('/[^a-z0-9_-]+/', '-', $value) ?? '';
    $value = trim($value, '-');
    if ($value === '') {
        return 'general';
    }
    return substr($value, 0, 32);
}

function uuid4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

function safe_extension(string $name, string $mime): string
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (preg_match('/^[a-z0-9]{1,8}$/', $ext)) {
        return $ext;
    }
    $map = [
        'text/plain' => 'txt',
        'text/markdown' => 'md',
        'application/json' => 'json',
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        'application/zip' => 'zip',
    ];
    return $map[$mime] ?? 'bin';
}

function base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return ($https ? 'https' : 'http') . '://' . $host;
}
