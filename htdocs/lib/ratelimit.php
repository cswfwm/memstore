<?php
declare(strict_types=1);

/**
 * 取客户端真实 IP。主机前置有 openresty 反向代理，REMOTE_ADDR 是代理地址，
 * 真实 IP 在 X-Real-IP / X-Forwarded-For 里。
 */
function client_ip(): string
{
    $candidates = [];
    foreach (['HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP'] as $key) {
        if (!empty($_SERVER[$key])) {
            $candidates[] = $_SERVER[$key];
        }
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        foreach (explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']) as $part) {
            $candidates[] = trim($part);
        }
    }
    if (!empty($_SERVER['REMOTE_ADDR'])) {
        $candidates[] = $_SERVER['REMOTE_ADDR'];
    }

    foreach ($candidates as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return '0.0.0.0';
}

/**
 * 网页面板限流：同一 IP 在窗口期内只放行一次。
 * 白名单 IP 不限次数。API 侧不调用本函数。
 */
function ratelimit_guard(string $scope = 'panel'): void
{
    $cfg = $GLOBALS['config']['rate_limit'] ?? [];
    if (empty($cfg['enabled'])) {
        return;
    }

    $ip = client_ip();

    foreach (($cfg['whitelist'] ?? []) as $allowed) {
        if ($ip === $allowed) {
            header('X-RateLimit-Limit: unlimited');
            return;
        }
    }

    $window = max(30, (int)($cfg['window_seconds'] ?? 180));
    $dir = STORAGE_DIR . '/.ratelimit';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return;
    }

    $file = $dir . '/' . $scope . '-' . hash('sha256', $ip) . '.txt';
    $fh = @fopen($file, 'c+');
    if ($fh === false) {
        return;
    }

    $now = time();
    $retryAfter = 0;

    if (flock($fh, LOCK_EX)) {
        $last = (int)trim((string)stream_get_contents($fh));
        $elapsed = $now - $last;

        if ($last > 0 && $elapsed >= 0 && $elapsed < $window) {
            $retryAfter = $window - $elapsed;
        } else {
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, (string)$now);
            fflush($fh);
        }
        flock($fh, LOCK_UN);
    }
    fclose($fh);

    ratelimit_prune($dir, $window * 4);

    if ($retryAfter > 0) {
        ratelimit_reject($retryAfter, $window);
    }

    header('X-RateLimit-Limit: 1/' . $window . 's');
    header('X-RateLimit-Remaining: 0');
}

function ratelimit_prune(string $dir, int $maxAge): void
{
    // 1/20 概率清理，避免每次请求都扫目录
    if (random_int(1, 20) !== 1) {
        return;
    }
    $deadline = time() - $maxAge;
    foreach ((array)glob($dir . '/*.txt') as $path) {
        if (@filemtime($path) < $deadline) {
            @unlink($path);
        }
    }
}

function ratelimit_reject(int $retryAfter, int $window): void
{
    http_response_code(429);
    header('Retry-After: ' . $retryAfter);
    header('Content-Type: text/html; charset=utf-8');

    $minutes = (int)ceil($retryAfter / 60);
    $windowMinutes = (int)round($window / 60);
    $body = '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>访问过于频繁</title><style>'
        . 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
        . 'background:#f5f7fa;color:#1f2933;font:16px/1.7 -apple-system,"Microsoft YaHei",sans-serif;padding:24px}'
        . '.box{max-width:420px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:28px}'
        . 'h1{margin:0 0 12px;font-size:20px}p{margin:0 0 10px;color:#6b7785}'
        . '.hint{font-size:13px;color:#94a3b8;margin-top:16px}'
        . '</style></head><body><div class="box">'
        . '<h1>访问过于频繁</h1>'
        . '<p>本页面每个 IP 每 ' . $windowMinutes . ' 分钟只能访问一次。</p>'
        . '<p>请在 <strong>' . $minutes . ' 分钟</strong>后再试。</p>'
        . '<p class="hint">数据上传接口不受此限制，AI 服务可正常调用 API。</p>'
        . '</div></body></html>';

    header('Content-Length: ' . strlen($body));
    echo $body;
    exit;
}
