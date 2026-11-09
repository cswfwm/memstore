<?php
declare(strict_types=1);

require __DIR__ . '/lib/web.php';

ratelimit_guard('auth');

$user = web_current_user();
if ($user !== null) {
    header('Location: files.php');
    exit;
}

$error = '';
$created = null;
$form = ['username' => '', 'service_name' => '', 'caller_id' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['username']     = trim((string)($_POST['username'] ?? ''));
    $form['service_name'] = trim((string)($_POST['service_name'] ?? ''));
    $form['caller_id']    = trim((string)($_POST['caller_id'] ?? ''));
    $password             = (string)($_POST['password'] ?? '');
    $password2            = (string)($_POST['password2'] ?? '');
    $token                = $_POST['csrf'] ?? '';

    if (!csrf_check(is_string($token) ? $token : null)) {
        $error = '页面已过期，请刷新后重试';
    } elseif (!preg_match('/^[a-zA-Z0-9_.-]{3,64}$/', $form['username'])) {
        $error = '用户名需为 3-64 位字母、数字或 _ . -';
    } elseif (strlen($password) < 8) {
        $error = '密码至少 8 位';
    } elseif ($password !== $password2) {
        $error = '两次输入的密码不一致';
    } elseif ($form['service_name'] === '') {
        $error = '请填写服务名称';
    } elseif ($form['caller_id'] === '') {
        $error = '请填写调用方标识 I';
    } elseif (!preg_match('/^[a-zA-Z0-9_.:-]{1,64}$/', $form['caller_id'])) {
        $error = '调用方标识 I 需为 1-64 位字母、数字或 _ . : -';
    } else {
        $pdo = db();
        $exists = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $exists->execute([$form['username']]);
        if ($exists->fetch()) {
            $error = '该用户名已被占用';
        } else {
            $keycode = bin2hex(random_bytes(16));
            $stmt = $pdo->prepare(
                'INSERT INTO users (username, service_name, password_hash, keycode_hash, caller_id, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $form['username'],
                mb_substr($form['service_name'], 0, 120),
                password_hash($password, PASSWORD_DEFAULT),
                hash('sha256', $keycode),
                $form['caller_id'],
                now_utc(),
            ]);
            $created = ['username' => $form['username'], 'keycode' => $keycode];
        }
    }
}

$err = $error !== '' ? '<div class="msg err">' . htmlspecialchars($error) . '</div>' : '';
$csrf = htmlspecialchars(csrf_token(), ENT_QUOTES);
$uEsc = htmlspecialchars($form['username'], ENT_QUOTES);
$sEsc = htmlspecialchars($form['service_name'], ENT_QUOTES);
$cEsc = htmlspecialchars($form['caller_id'], ENT_QUOTES);

if ($created !== null) {
    $kEsc = htmlspecialchars($created['keycode'], ENT_QUOTES);
    $uOk  = htmlspecialchars($created['username'], ENT_QUOTES);
    $okBody = <<<HTML
<div class="card">
  <h2>注册成功</h2>
  <div class="msg ok">账号 {$uOk} 已创建，请立刻保存下面的校验码。</div>
  <div class="keybox">
    <strong>校验码 keycode（登录必需，仅显示这一次）</strong>
    <code id="kc">{$kEsc}</code>
    <p class="hint">丢失后无法找回，只能重新注册。请复制到安全的地方保存。</p>
  </div>
  <button type="button" onclick="var t=document.getElementById('kc').innerText;if(navigator.clipboard){navigator.clipboard.writeText(t);this.textContent='已复制'}">复制校验码</button>
  <p class="hint"><a href="login.php">前往登录</a></p>
</div>
HTML;
    web_page('注册成功', $okBody);
}

$body = <<<HTML
<div class="card">
  <h2>注册</h2>
  {$err}
  <form method="post" action="register.php" autocomplete="off">
    <input type="hidden" name="csrf" value="{$csrf}">
    <label for="u">用户名</label>
    <input id="u" type="text" name="username" value="{$uEsc}" required maxlength="64" placeholder="3-64 位字母数字或 _ . -">
    <label for="p">密码</label>
    <input id="p" type="password" name="password" required minlength="8">
    <label for="p2">确认密码</label>
    <input id="p2" type="password" name="password2" required minlength="8">
    <label for="s">服务名称 service_name</label>
    <input id="s" type="text" name="service_name" value="{$sEsc}" required maxlength="120" placeholder="例如 My AI Agent">
    <label for="c">调用方标识 I</label>
    <input id="c" type="text" name="caller_id" value="{$cEsc}" required maxlength="64" placeholder="1-64 位字母数字或 _ . : -">
    <button type="submit">创建账号</button>
  </form>
  <p class="hint">注册成功后会生成一个 128 位校验码 keycode，登录时必须用到，请务必保存。</p>
</div>
HTML;

web_page('注册', $body);
