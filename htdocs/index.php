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
<link rel="stylesheet" href="assets/style.css">
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
      <li><strong>注册</strong>：<code>POST ?action=register</code>（username / password / service_name），返回 token。</li>
      <li><strong>上传</strong>：<code>POST ?action=upload</code>，带 <code>X-Api-Token: &lt;token&gt;</code> 与 <code>file</code>、<code>category</code>、<code>tags</code>。</li>
      <li><strong>检索</strong>：<code>GET ?action=list&amp;category=…</code>，用 <code>?action=file&amp;uuid=…</code> 下载。</li>
    </ol>
  </section>

  <section class="card">
    <h2>快速验证</h2>
    <pre>curl -X POST '<?= htmlspecialchars($apiUrl) ?>?action=register' \
  -H 'Content-Type: application/json' \
  -d '{"username":"demo-agent","password":"demo12345","service_name":"Demo"}'

curl -X POST '<?= htmlspecialchars($apiUrl) ?>?action=upload' \
  -H "X-Api-Token: $TOKEN" \
  -F 'source=text' -F 'content=第一条记忆' \
  -F 'filename=first.txt' -F 'category=general'</pre>
  </section>
</main>

<footer>MemStore 1.0.0 · wfwm.uuk.pp.ua</footer>
</body>
</html>
