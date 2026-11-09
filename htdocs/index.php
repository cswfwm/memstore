<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Shanghai');

$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
if ($base === '.' || $base === '/api') {
    $base = '';
}
$apiUrl = $base . '/api/index.php';
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MemStore · AI 记忆存储服务</title>
<style>
:root {
  --bg: #f5f7fa;
  --card: #ffffff;
  --text: #1f2933;
  --muted: #6b7785;
  --accent: #2563eb;
  --border: #e2e8f0;
}

* { box-sizing: border-box; }

body {
  margin: 0;
  padding: 0 16px 48px;
  background: var(--bg);
  color: var(--text);
  font: 16px/1.6 -apple-system, "Segoe UI", "Microsoft YaHei", sans-serif;
}

header, main, footer {
  max-width: 760px;
  margin: 0 auto;
}

header { padding: 32px 0 8px; }

header h1 { margin: 0 0 6px; font-size: 28px; }

.tagline { margin: 0 0 12px; color: var(--muted); }

nav a { margin-right: 16px; color: var(--accent); text-decoration: none; }
nav a:hover { text-decoration: underline; }

.card {
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 18px 20px;
  margin-bottom: 16px;
}

.card h2 { margin: 0 0 12px; font-size: 18px; }

.env, .steps { margin: 0; padding-left: 22px; }
.env li { color: var(--muted); }

pre {
  background: #0f172a;
  color: #e2e8f0;
  padding: 14px 16px;
  border-radius: 8px;
  overflow-x: auto;
  font-size: 13px;
  line-height: 1.55;
}

code {
  background: #eef2f7;
  padding: 1px 5px;
  border-radius: 4px;
  font-size: 14px;
}

footer { color: var(--muted); font-size: 13px; }

.note {
  margin: 12px 0 0;
  padding: 10px 12px;
  background: #fffbea;
  border-left: 3px solid #f0b400;
  border-radius: 4px;
  font-size: 14px;
  color: #6b5a1e;
}
</style>
</head>
<body>
<header>
  <h1>MemStore</h1>
  <p class="tagline">AI 记忆文件的集中存储服务：注册登录后按标准格式上传，自动分类归档。</p>
  <nav>
    <a href="API.md">上传规范（API.md）</a>
    <a href="<?= htmlspecialchars($apiUrl) ?>?action=health">健康检查</a>
  </nav>
</header>

<main>
  <section class="card">
    <h2>服务信息</h2>
    <ul class="env">
      <li>API 入口：<code><?= htmlspecialchars($apiUrl) ?></code></li>
      <li>PHP 版本：<?= htmlspecialchars(PHP_VERSION) ?></li>
      <li>服务器时间：<?= htmlspecialchars(date('Y-m-d H:i:s')) ?>（Asia/Shanghai）</li>
      <li>单文件上限：20 MB</li>
      <li>数据存储：MySQL 元数据 + 按年月分类的磁盘归档</li>
    </ul>
  </section>

  <section class="card">
    <h2>三步接入</h2>
    <ol class="steps">
      <li><strong>注册</strong>：<code>POST ?action=register</code>（username / password / service_name / I），返回 <code>keycode</code> 校验码与 token。</li>
      <li><strong>上传</strong>：<code>POST ?action=upload</code>，带 <code>X-Api-Token: &lt;token&gt;</code> 与 <code>file</code>、<code>category</code>、<code>tags</code>。</li>
      <li><strong>批量写入</strong>：<code>POST ?action=batch_upload</code>，一次请求写最多 50 条，只消耗 1 个 hits。</li>
      <li><strong>检索</strong>：<code>GET ?action=list&amp;category=…</code>，用 <code>?action=file&amp;uuid=…</code> 下载；支持 ETag，未变更时返回 304。</li>
    </ol>
    <p class="note">登录需三要素：用户名 + 密码 + 注册时返回的 <code>keycode</code>（128 位校验码，仅返回一次，请自行保存）。
    </ol>
  </section>

  <section class="card">
    <h2>快速验证</h2>
    <pre>curl -X POST '<?= htmlspecialchars($apiUrl) ?>?action=register' \
  -H 'Content-Type: application/json' \
  -d '{"username":"demo-agent","password":"demo12345","service_name":"Demo","I":"svc-001"}'

curl -X POST '<?= htmlspecialchars($apiUrl) ?>?action=upload' \
  -H "X-Api-Token: $TOKEN" \
  -F 'source=text' -F 'content=第一条记忆' \
  -F 'filename=first.txt' -F 'category=general'

# 再次登录（三要素）
curl -X POST '<?= htmlspecialchars($apiUrl) ?>'?action=login' \
  -H 'Content-Type: application/json' \
  -d '{"username":"demo-agent","password":"demo12345","keycode":"<注册返回的 keycode>"}'</pre>
  </section>
</main>

<footer>MemStore 1.0.0 · cswfwm.ccwu.cc</footer>
</body>
</html>
