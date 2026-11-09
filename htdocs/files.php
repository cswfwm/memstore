<?php
declare(strict_types=1);

require __DIR__ . '/lib/web.php';

ratelimit_guard('panel');

$user = web_current_user();
if ($user === null) {
    header('Location: login.php');
    exit;
}

$pdo = db();

// 删除操作
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'delete') {
    $token = $_POST['csrf'] ?? '';
    $uuid  = (string)($_POST['uuid'] ?? '');
    if (csrf_check(is_string($token) ? $token : null) && preg_match('/^[0-9a-f-]{36}$/', $uuid)) {
        $stmt = $pdo->prepare('SELECT id, stored_path FROM memories WHERE uuid = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$uuid, (int)$user['id']]);
        $row = $stmt->fetch();
        if ($row) {
            $pdo->prepare('DELETE FROM memories WHERE id = ?')->execute([(int)$row['id']]);
            $path = STORAGE_DIR . '/' . $row['stored_path'];
            $real = realpath($path);
            $base = realpath(STORAGE_DIR);
            if ($real !== false && $base !== false && strncmp($real, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) === 0) {
                @unlink($real);
            }
        }
    }
    header('Location: files.php');
    exit;
}

$category = trim((string)($_GET['category'] ?? ''));
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 20;
$offset   = ($page - 1) * $perPage;

$where  = 'WHERE user_id = ?';
$params = [(int)$user['id']];
if ($category !== '') {
    $where .= ' AND category = ?';
    $params[] = sanitize_category($category);
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM memories $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$sql = "SELECT uuid, title, category, tags, mime_type, size_bytes, orig_name, created_at
        FROM memories $where ORDER BY id DESC LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// 分类列表
$catStmt = $pdo->prepare('SELECT category, COUNT(*) AS n FROM memories WHERE user_id = ? GROUP BY category ORDER BY n DESC');
$catStmt->execute([(int)$user['id']]);
$cats = $catStmt->fetchAll();

$csrf = htmlspecialchars(csrf_token(), ENT_QUOTES);

$totalSize = 0;
foreach ($rows as $r) {
    $totalSize += (int)$r['size_bytes'];
}

function human_size(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return round($bytes / 1048576, 1) . ' MB';
}

$catHtml = '<a href="files.php" class="' . ($category === '' ? 'on' : '') . '">全部 (' . $total . ')</a>';
foreach ($cats as $c) {
    $isOn = ($category !== '' && sanitize_category($category) === $c['category']);
    $catHtml .= '<a href="files.php?category=' . urlencode($c['category']) . '"'
        . ($isOn ? ' class="on"' : '') . '>'
        . htmlspecialchars($c['category']) . ' (' . (int)$c['n'] . ')</a>';
}

if (!$rows) {
    $listHtml = '<p class="hint">还没有记忆。用 API 上传后会显示在这里。</p>';
} else {
    $listHtml = '<table><thead><tr><th>标题</th><th>分类</th><th>大小</th><th>时间</th><th></th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $uuidEsc = htmlspecialchars($r['uuid'], ENT_QUOTES);
        $titleEsc = htmlspecialchars($r['title'], ENT_QUOTES);
        $listHtml .= '<tr>'
            . '<td><a href="api/index.php?action=file&uuid=' . $uuidEsc . '" target="_blank">' . $titleEsc . '</a></td>'
            . '<td>' . htmlspecialchars($r['category']) . '</td>'
            . '<td>' . human_size((int)$r['size_bytes']) . '</td>'
            . '<td>' . htmlspecialchars(substr((string)$r['created_at'], 0, 16)) . '</td>'
            . '<td><form method="post" action="files.php" onsubmit="return confirm(\'确定删除这条记忆？\')" style="margin:0">'
            . '<input type="hidden" name="csrf" value="' . $csrf . '">'
            . '<input type="hidden" name="do" value="delete">'
            . '<input type="hidden" name="uuid" value="' . $uuidEsc . '">'
            . '<button type="submit" class="del">删除</button></form></td>'
            . '</tr>';
    }
    $listHtml .= '</tbody></table>';
}

$pages = (int)ceil($total / $perPage);
$pager = '';
if ($pages > 1) {
    $pager = '<div class="pager">';
    for ($i = 1; $i <= $pages; $i++) {
        $q = 'files.php?page=' . $i . ($category !== '' ? '&category=' . urlencode($category) : '');
        $pager .= '<a href="' . htmlspecialchars($q, ENT_QUOTES) . '"'
            . ($i === $page ? ' class="on"' : '') . '>' . $i . '</a>';
    }
    $pager .= '</div>';
}

$userName = htmlspecialchars($user['username']);
$caller   = htmlspecialchars((string)$user['caller_id']);
$joined   = htmlspecialchars(substr((string)$user['created_at'], 0, 10));

$body = <<<HTML
<div class="card">
  <h2>账号信息</h2>
  <table>
    <tr><th>用户名</th><td>{$userName}</td></tr>
    <tr><th>调用方标识 I</th><td>{$caller}</td></tr>
    <tr><th>注册时间</th><td>{$joined}</td></tr>
    <tr><th>记忆条数</th><td>{$total}</td></tr>
  </table>
</div>

<div class="card">
  <h2>我的记忆</h2>
  <div class="cats">{$catHtml}</div>
  {$listHtml}
  {$pager}
  <p class="hint">本页每 IP 每 3 分钟只能打开一次；上传请走 API，不受限流影响。</p>
</div>
HTML;

web_page('我的记忆', $body, $user);
