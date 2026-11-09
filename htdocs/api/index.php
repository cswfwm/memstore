<?php
declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

if ($action === 'health') {
    respond(200, ['ok' => true, 'service' => 'memstore', 'version' => '1.0.0', 'time' => now_utc()]);
}

if ($action === 'install') {
    install_schema();
}

if ($action === 'register' && $method === 'POST') {
    action_register();
}

if ($action === 'login' && $method === 'POST') {
    action_login();
}

if ($action === 'upload' && $method === 'POST') {
    action_upload();
}

if ($action === 'batch_upload' && $method === 'POST') {
    action_batch_upload();
}

if ($action === 'list' && $method === 'GET') {
    action_list();
}

if ($action === 'file' && $method === 'GET') {
    action_file();
}

if ($action === 'delete' && $method === 'POST') {
    action_delete();
}

if ($action === 'me' && $method === 'GET') {
    $user = require_user(db());
    respond(200, ['user' => [
        'id' => (int)$user['id'],
        'username' => $user['username'],
        'service_name' => $user['service_name'],
        'I' => $user['caller_id'],
    ]]);
}

respond(404, ['error' => 'unknown_action', 'message' => '未知的 action，请参阅 /api.md']);


function install_schema(): void
{
    $key = request_param([], 'key');
    if ($key === '' || !hash_equals((string)$GLOBALS['config']['install_key'], $key)) {
        respond(403, ['error' => 'forbidden', 'message' => 'install key 不正确']);
    }

    $pdo = db();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            username VARCHAR(64) NOT NULL,
            service_name VARCHAR(120) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            keycode_hash CHAR(64) NOT NULL,
            caller_id VARCHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $col = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'keycode_hash'"
    );
    $col->execute();
    if ((int)$col->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE users ADD COLUMN keycode_hash CHAR(64) NOT NULL DEFAULT ''");
    }
    $col2 = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'caller_id'"
    );
    $col2->execute();
    if ((int)$col2->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE users ADD COLUMN caller_id VARCHAR(64) NOT NULL DEFAULT ''");
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS api_tokens (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            last_used_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_token (token_hash),
            KEY idx_user (user_id),
            CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS memories (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            uuid CHAR(36) NOT NULL,
            title VARCHAR(200) NOT NULL,
            category VARCHAR(32) NOT NULL,
            tags VARCHAR(255) NOT NULL DEFAULT '',
            mime_type VARCHAR(120) NOT NULL,
            size_bytes INT UNSIGNED NOT NULL,
            orig_name VARCHAR(200) NOT NULL,
            stored_path VARCHAR(255) NOT NULL,
            sha256 CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_uuid (uuid),
            KEY idx_user_cat (user_id, category, created_at),
            CONSTRAINT fk_mem_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    respond(200, ['ok' => true, 'message' => '数据表已创建']);
}

function action_register(): void
{
    $body = read_json_body();
    $username = request_param($body, 'username');
    $password = request_param($body, 'password');
    $service = request_param($body, 'service_name');
    $callerId = request_param($body, 'I');

    if (!preg_match('/^[a-zA-Z0-9_.-]{3,64}$/', $username)) {
        respond(422, ['error' => 'invalid_username', 'message' => '用户名需为 3-64 位字母数字或 _ . -']);
    }
    if (strlen($password) < 8) {
        respond(422, ['error' => 'weak_password', 'message' => '密码至少 8 位']);
    }
    if ($service === '') {
        respond(422, ['error' => 'missing_service_name', 'message' => 'service_name 不能为空（填写调用方 AI 服务名称）']);
    }
    if (!preg_match('/^[a-zA-Z0-9_.:-]{1,64}$/', $callerId)) {
        respond(422, ['error' => 'invalid_caller_id', 'message' => '字段 I 必填，1-64 位字母数字或 _ . : -']);
    }

    $pdo = db();
    $exists = $pdo->prepare('SELECT id FROM users WHERE username = ?');
    $exists->execute([$username]);
    if ($exists->fetch()) {
        respond(409, ['error' => 'username_taken', 'message' => '用户名已被占用']);
    }

    $keycode = bin2hex(random_bytes(16));

    $stmt = $pdo->prepare('INSERT INTO users (username, service_name, password_hash, keycode_hash, caller_id, created_at) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $username, mb_substr($service, 0, 120), password_hash($password, PASSWORD_DEFAULT),
        hash('sha256', $keycode), $callerId, now_utc(),
    ]);
    $userId = (int)$pdo->lastInsertId();

    $token = issue_token($pdo, $userId);
    respond(201, [
        'user'  => ['id' => $userId, 'username' => $username, 'service_name' => $service, 'I' => $callerId],
        'keycode' => $keycode,
        'token' => $token['token'],
        'token_expires_at' => $token['expires_at'],
    ]);
}

function action_login(): void
{
    $body = read_json_body();
    $username = request_param($body, 'username');
    $password = request_param($body, 'password');
    $keycode = strtolower(request_param($body, 'keycode'));

    if ($keycode === '') {
        respond(422, ['error' => 'missing_keycode', 'message' => '缺少 keycode（注册时返回的 128 位校验码）']);
    }

    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, username, service_name, caller_id, password_hash, keycode_hash FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        respond(401, ['error' => 'invalid_credentials', 'message' => '用户名或密码错误']);
    }
    if (!hash_equals((string)$user['keycode_hash'], hash('sha256', $keycode))) {
        respond(401, ['error' => 'invalid_keycode', 'message' => '校验码不正确']);
    }

    $token = issue_token($pdo, (int)$user['id']);
    respond(200, [
        'user'  => ['id' => (int)$user['id'], 'username' => $user['username'], 'service_name' => $user['service_name'], 'I' => $user['caller_id']],
        'token' => $token['token'],
        'token_expires_at' => $token['expires_at'],
    ]);
}

function action_upload(): void
{
    $user = require_user(db());
    $pdo = db();

    if ((int)($user['id']) <= 0) {
        respond(401, ['error' => 'unauthorized']);
    }

    $problem = upload_input_problem();
    if ($problem !== '') {
        respond(400, ['error' => 'upload_failed', 'message' => $problem]);
    }

    $isJson = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
    $jsonBody = $isJson ? read_json_body() : [];

    $category = sanitize_category(request_param($jsonBody, 'category', 'general'));
    $title = request_param($jsonBody, 'title');
    $tags = request_param($jsonBody, 'tags');
    $source = request_param($jsonBody, 'source', 'file');

    if ($source === 'text') {
        $text = request_param($jsonBody, 'content');
        if ($text === '') {
            respond(422, ['error' => 'missing_content', 'message' => "source=text 时必须提供 content"]);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'mem');
        file_put_contents($tmp, $text);
        $fileName = request_param($jsonBody, 'filename', 'note.txt');
        $mime = 'text/plain';
        $streamPath = $tmp;
        $cleanup = true;
        $size = filesize($streamPath);
    } else {
        $file = $_FILES['file'] ?? null;
        if (!$file || !is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            respond(422, ['error' => 'missing_file', 'message' => '缺少上传文件字段 file']);
        }
        $streamPath = $file['tmp_name'];
        $fileName = (string)basename($file['name']);
        $size = (int)$file['size'];
        $cleanup = false;
        $mime = detect_mime($streamPath, $fileName);
    }

    $maxBytes = (int)$GLOBALS['config']['max_upload'];
    if ($size <= 0) {
        respond(422, ['error' => 'empty_file', 'message' => '文件为空']);
    }
    if ($size > $maxBytes) {
        respond(413, ['error' => 'file_too_large', 'message' => '文件超过 ' . round($maxBytes / 1048576) . ' MB 限制']);
    }

    if ($title === '') {
        $title = mb_substr(pathinfo($fileName, PATHINFO_FILENAME), 0, 200);
    }

    $uuid = uuid4();
    $ext = safe_extension($fileName, $mime);
    $relDir = date('Y/m');
    $dir = STORAGE_DIR . '/' . $relDir . '/' . $category;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        respond(500, ['error' => 'storage_error', 'message' => '存储目录创建失败']);
    }

    $storedName = $uuid . ($ext !== '' ? '.' . $ext : '');
    $relPath = $relDir . '/' . $category . '/' . $storedName;
    $dest = STORAGE_DIR . '/' . $relPath;

    if (!move_uploaded_file_safe($streamPath, $dest)) {
        respond(500, ['error' => 'storage_error', 'message' => '文件写入失败']);
    }
    if ($cleanup) {
        @unlink($streamPath);
    }

    $sha = hash_file('sha256', $dest);

    $stmt = $pdo->prepare(
        'INSERT INTO memories (user_id, uuid, title, category, tags, mime_type, size_bytes, orig_name, stored_path, sha256, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        (int)$user['id'], $uuid, mb_substr($title, 0, 200), $category,
        mb_substr($tags, 0, 255), $mime, $size, mb_substr($fileName, 0, 200),
        $relPath, $sha, now_utc(),
    ]);

    respond(201, [
        'memory' => [
            'uuid'       => $uuid,
            'title'      => $title,
            'category'   => $category,
            'tags'       => $tags,
            'mime_type'  => $mime,
            'size_bytes' => $size,
            'sha256'     => $sha,
            'created_at' => now_utc(),
            'download_url' => base_url() . '/api/index.php?action=file&uuid=' . $uuid,
        ],
    ]);
}

function action_batch_upload(): void
{
    $user = require_user(db());
    $pdo = db();

    $body = read_json_body();
    $items = $body['items'] ?? null;
    if (!is_array($items) || $items === []) {
        respond(422, ['error' => 'missing_items', 'message' => 'items 必须是非空数组']);
    }
    if (count($items) > 50) {
        respond(422, ['error' => 'too_many_items', 'message' => '单次最多 50 条']);
    }

    $maxBytes = (int)$GLOBALS['config']['max_upload'];
    $maxTotal = $maxBytes * 4;
    $created = [];
    $failed = [];
    $totalBytes = 0;

    foreach ($items as $index => $item) {
        if (!is_array($item)) {
            $failed[] = ['index' => $index, 'error' => 'invalid_item'];
            continue;
        }

        $text = trim((string)($item['content'] ?? ''));
        if ($text === '') {
            $failed[] = ['index' => $index, 'error' => 'missing_content'];
            continue;
        }

        $size = strlen($text);
        if ($size > $maxBytes) {
            $failed[] = ['index' => $index, 'error' => 'file_too_large'];
            continue;
        }
        $totalBytes += $size;
        if ($totalBytes > $maxTotal) {
            $failed[] = ['index' => $index, 'error' => 'batch_too_large'];
            continue;
        }

        $category = sanitize_category((string)($item['category'] ?? 'general'));
        $fileName = trim((string)($item['filename'] ?? '')) ?: 'note.txt';
        $title = trim((string)($item['title'] ?? '')) ?: mb_substr(pathinfo($fileName, PATHINFO_FILENAME), 0, 200);
        $tags = trim((string)($item['tags'] ?? ''));

        $uuid = uuid4();
        $ext = safe_extension($fileName, 'text/plain');
        $relDir = date('Y/m');
        $dir = STORAGE_DIR . '/' . $relDir . '/' . $category;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            $failed[] = ['index' => $index, 'error' => 'storage_error'];
            continue;
        }

        $storedName = $uuid . ($ext !== '' ? '.' . $ext : '.txt');
        $relPath = $relDir . '/' . $category . '/' . $storedName;
        $dest = STORAGE_DIR . '/' . $relPath;

        if (file_put_contents($dest, $text, LOCK_EX) === false) {
            $failed[] = ['index' => $index, 'error' => 'storage_error'];
            continue;
        }
        $sha = hash_file('sha256', $dest);

        $stmt = $pdo->prepare(
            'INSERT INTO memories (user_id, uuid, title, category, tags, mime_type, size_bytes, orig_name, stored_path, sha256, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (int)$user['id'], $uuid, mb_substr($title, 0, 200), $category,
            mb_substr($tags, 0, 255), 'text/plain', $size, mb_substr($fileName, 0, 200),
            $relPath, $sha, now_utc(),
        ]);

        $created[] = [
            'index' => $index,
            'uuid' => $uuid,
            'title' => $title,
            'category' => $category,
        ];
    }

    respond(200, [
        'created_count' => count($created),
        'failed_count' => count($failed),
        'created' => $created,
        'failed' => $failed,
    ]);
}

function upload_input_problem(): string
{
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
        return '';
    }
    if (!isset($_FILES['file'])) {
        $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($len > 0 && empty($_POST) && empty($_FILES)) {
            return '请求体超过 post_max_size（' . ini_get('post_max_size') . '）';
        }
    }
    return '';
}

function move_uploaded_file_safe(string $src, string $dest): bool
{
    if (is_uploaded_file($src)) {
        return move_uploaded_file($src, $dest);
    }
    // source=text 生成的临时文件不是上传文件，用流拷贝
    return copy($src, $dest);
}

function detect_mime(string $path, string $name): string
{
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $mime = finfo_file($fi, $path) ?: '';
            finfo_close($fi);
            if ($mime !== '') {
                return $mime;
            }
        }
    }
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $map = ['txt' => 'text/plain', 'md' => 'text/markdown', 'json' => 'application/json', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'];
    return $map[$ext] ?? 'application/octet-stream';
}

function action_list(): void
{
    $user = require_user(db());
    $pdo = db();

    $category = request_param([], 'category');
    $limit = max(1, min(100, (int)request_param([], 'limit', '20')));
    $offset = max(0, (int)request_param([], 'offset', '0'));

    $sql = 'SELECT uuid, title, category, tags, mime_type, size_bytes, orig_name, created_at FROM memories WHERE user_id = ?';
    $params = [(int)$user['id']];
    if ($category !== '') {
        $sql .= ' AND category = ?';
        $params[] = sanitize_category($category);
    }
    $sql .= ' ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $row['size_bytes'] = (int)$row['size_bytes'];
        $row['download_url'] = base_url() . '/api/index.php?action=file&uuid=' . $row['uuid'];
    }

    $payload = ['total_returned' => count($rows), 'limit' => $limit, 'offset' => $offset, 'items' => $rows];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $etag = '"' . hash('sha256', $json) . '"';

    header('ETag: ' . $etag);
    header('Cache-Control: private, max-age=0, must-revalidate');

    $ifNoneMatch = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
    if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
        http_response_code(304);
        header_remove('Content-Type');
        exit;
    }

    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo $json;
    exit;
}

function action_file(): void
{
    $user = require_user(db());
    $pdo = db();
    $uuid = request_param([], 'uuid');
    if (!preg_match('/^[0-9a-f-]{36}$/', $uuid)) {
        respond(422, ['error' => 'invalid_uuid', 'message' => 'uuid 格式不正确']);
    }

    $stmt = $pdo->prepare('SELECT * FROM memories WHERE uuid = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$uuid, (int)$user['id']]);
    $row = $stmt->fetch();
    if (!$row) {
        respond(404, ['error' => 'not_found', 'message' => '记录不存在或无权访问']);
    }

    $path = STORAGE_DIR . '/' . $row['stored_path'];
    $real = realpath($path);
    $base = realpath(STORAGE_DIR);
    if ($real === false || $base === false || strncmp($real, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) !== 0) {
        respond(404, ['error' => 'not_found', 'message' => '文件不存在']);
    }

    header('Content-Type: ' . $row['mime_type']);
    header('Content-Length: ' . filesize($real));
    header('Content-Disposition: attachment; filename="' . rawurlencode($row['orig_name']) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($real);
    exit;
}

function action_delete(): void
{
    $user = require_user(db());
    $pdo = db();
    $uuid = request_param(read_json_body(), 'uuid');
    if (!preg_match('/^[0-9a-f-]{36}$/', $uuid)) {
        respond(422, ['error' => 'invalid_uuid', 'message' => 'uuid 格式不正确']);
    }

    $stmt = $pdo->prepare('SELECT id, stored_path FROM memories WHERE uuid = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$uuid, (int)$user['id']]);
    $row = $stmt->fetch();
    if (!$row) {
        respond(404, ['error' => 'not_found', 'message' => '记录不存在或无权访问']);
    }

    $pdo->prepare('DELETE FROM memories WHERE id = ?')->execute([(int)$row['id']]);
    $path = STORAGE_DIR . '/' . $row['stored_path'];
    $real = realpath($path);
    $base = realpath(STORAGE_DIR);
    if ($real !== false && $base !== false && strncmp($real, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) === 0) {
        @unlink($real);
    }

    respond(200, ['ok' => true, 'deleted' => $uuid]);
}
