<?php
declare(strict_types=1);

if (defined('MEMSTORE_WEB')) {
    return;
}
define('MEMSTORE_WEB', true);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/ratelimit.php';

date_default_timezone_set('Asia/Shanghai');

/**
 * 网页会话：使用独立 cookie，避免与 API token 混用。
 * 会话只存 user_id，真正的权限仍由数据库校验。
 */
const SESSION_COOKIE = 'ms_session';
const SESSION_TTL = 86400 * 7;

function web_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_name(SESSION_COOKIE);
    session_set_cookie_params([
        'lifetime' => SESSION_TTL,
        'path'     => '/',
        'httponly' => true,
        'secure'   => $isHttps,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function web_current_user(): ?array
{
    web_session_start();
    $userId = $_SESSION['user_id'] ?? null;
    if (!is_int($userId) && !ctype_digit((string)$userId)) {
        return null;
    }

    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, username, service_name, caller_id, created_at FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(int)$userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function web_login_user(int $userId): void
{
    web_session_start();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
}

function web_logout(): void
{
    web_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(SESSION_COOKIE, '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool)$params['secure'], (bool)$params['httponly']);
    }
    session_destroy();
}

function csrf_token(): string
{
    web_session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_check(?string $token): bool
{
    web_session_start();
    return is_string($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

/** 极简页面框架：统一输出 HTML 外壳 */
function web_page(string $title, string $bodyHtml, ?array $user = null): void
{
    $nav = $user
        ? '<span class="who">' . htmlspecialchars($user['username']) . '</span>'
          . '<a href="files.php">我的记忆</a>'
          . '<a href="logout.php">退出</a>'
        : '<a href="login.php">登录</a><a href="register.php">注册</a>';

    echo <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title} · MemStore</title>
<style>
:root{--bg:#f5f7fa;--card:#fff;--text:#1f2933;--muted:#6b7785;--accent:#2563eb;--border:#e2e8f0;--danger:#b91c1c;--ok:#166534}
*{box-sizing:border-box}
body{margin:0;padding:0 16px 48px;background:var(--bg);color:var(--text);font:16px/1.6 -apple-system,"Segoe UI","Microsoft YaHei",sans-serif}
header,main,footer{max-width:640px;margin:0 auto}
header{padding:28px 0 8px;display:flex;flex-wrap:wrap;align-items:center;gap:12px}
header h1{margin:0;font-size:24px;flex:1}
header nav a{margin-left:14px;color:var(--accent);text-decoration:none;font-size:15px}
header nav a:hover{text-decoration:underline}
.who{color:var(--muted);font-size:14px;margin-left:14px}
.card{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:20px;margin:16px 0}
.card h2{margin:0 0 14px;font-size:18px}
label{display:block;margin:12px 0 6px;font-size:14px;color:var(--muted)}
input[type=text],input[type=password],select,textarea{width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;font:inherit;background:#fbfdff}
button{margin-top:16px;padding:11px 22px;border:0;border-radius:8px;background:var(--accent);color:#fff;font:inherit;cursor:pointer}
button:hover{background:#1d4ed8}
.msg{padding:10px 12px;border-radius:6px;margin:12px 0;font-size:14px}
.msg.err{background:#fef2f2;color:var(--danger);border-left:3px solid var(--danger)}
.msg.ok{background:#f0fdf4;color:var(--ok);border-left:3px solid var(--ok)}
.hint{font-size:13px;color:var(--muted);margin-top:10px}
code{background:#eef2f7;padding:1px 5px;border-radius:4px;font-size:13px;word-break:break-all}
.keybox{margin-top:14px;padding:14px;background:#fffbeb;border-left:3px solid #f0b400;border-radius:6px}
.keybox strong{display:block;margin-bottom:6px;font-size:14px}
footer{color:var(--muted);font-size:13px;margin-top:24px}
.cats{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px}
.cats a{padding:5px 12px;border:1px solid var(--border);border-radius:20px;text-decoration:none;color:var(--text);font-size:13px;background:#fbfdff}
.cats a.on{background:var(--accent);color:#fff;border-color:var(--accent)}
.pager{margin-top:14px;display:flex;gap:6px;flex-wrap:wrap}
.pager a{padding:5px 11px;border:1px solid var(--border);border-radius:6px;text-decoration:none;color:var(--text);font-size:13px}
.pager a.on{background:var(--accent);color:#fff;border-color:var(--accent)}
button.del{margin:0;padding:5px 12px;background:#fee2e2;color:var(--danger);font-size:13px}
button.del:hover{background:#fecaca}
td a{color:var(--accent);text-decoration:none}
td a:hover{text-decoration:underline}
table{width:100%;border-collapse:collapse;font-size:14px}
th,td{text-align:left;padding:8px 6px;border-bottom:1px solid var(--border)}
th{color:var(--muted);font-weight:600}
</style>
</head>
<body>
<header>
  <h1><a href="index.php" style="color:inherit;text-decoration:none">MemStore</a></h1>
  <nav>{$nav}</nav>
</header>
<main>
{$bodyHtml}
</main>
<footer>MemStore 1.0.0 · cswfwm.ccwu.cc</footer>
</body>
</html>
HTML;
    exit;
}
