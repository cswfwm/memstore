<?php
declare(strict_types=1);

require __DIR__ . '/lib/web.php';

// 登录页同样受网页面板限流保护
ratelimit_guard('auth');

$user = web_current_user();
if ($user !== null) {
    header('Location: files.php');
    exit;
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $keycode  = strtolower(trim((string)($_POST['keycode'] ?? '')));
    $token    = $_POST['csrf'] ?? '';

    if (!csrf_check(is_string($token) ? $token : null)) {
        $error = '页面已过期，请刷新后重试';
    } elseif ($username === '' || $password === '') {
        $error = '请填写用户名和密码';
    } elseif ($keycode === '') {
        $error = '请填写注册时获得的校验码 keycode';
    } else {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT id, username, password_hash, keycode_hash FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($password, $row['password_hash'])) {
            $error = '用户名或密码错误';
        } elseif (!hash_equals((string)$row['keycode_hash'], hash('sha256', $keycode))) {
            $error = '校验码不正确';
        } else {
            web_login_user((int)$row['id']);
            header('Location: files.php');
            exit;
        }
    }
}

$err = $error !== '' ? '<div class="msg err">' . htmlspecialchars($error) . '</div>' : '';
$csrf = htmlspecialchars(csrf_token(), ENT_QUOTES);
$usernameEsc = htmlspecialchars($username, ENT_QUOTES);

$body = <<<HTML
<div class="card">
  <h2>登录</h2>
  {$err}
  <form method="post" action="login.php" autocomplete="off">
    <input type="hidden" name="csrf" value="{$csrf}">
    <label for="u">用户名</label>
    <input id="u" type="text" name="username" value="{$usernameEsc}" required maxlength="64">
    <label for="p">密码</label>
    <input id="p" type="password" name="password" required>
    <label for="k">校验码 keycode</label>
    <input id="k" type="text" name="keycode" required maxlength="64" placeholder="注册时返回的 128 位校验码">
    <button type="submit">登录</button>
  </form>
  <p class="hint">还没有账号？<a href="register.php">立即注册</a></p>
</div>
HTML;

web_page('登录', $body);
