<?php
declare(strict_types=1);

function issue_token(PDO $pdo, int $userId): array
{
    $token = bin2hex(random_bytes(32));
    $ttlDays = (int)($GLOBALS['config']['token_ttl_days'] ?? 30);
    $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttlDays * 86400);

    $stmt = $pdo->prepare('INSERT INTO api_tokens (user_id, token_hash, created_at, expires_at) VALUES (?, ?, ?, ?)');
    $stmt->execute([$userId, hash('sha256', $token), now_utc(), $expiresAt]);

    return ['token' => $token, 'expires_at' => $expiresAt];
}

function current_user(PDO $pdo): ?array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $token = bearer_token();
    if ($token === '') {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT t.id AS token_id, t.expires_at, u.id, u.username, u.service_name, u.caller_id
         FROM api_tokens t JOIN users u ON u.id = t.user_id
         WHERE t.token_hash = ? LIMIT 1'
    );
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }
    if (strtotime($row['expires_at'] . ' UTC') < time()) {
        return null;
    }

    $pdo->prepare('UPDATE api_tokens SET last_used_at = ? WHERE id = ?')->execute([now_utc(), $row['token_id']]);

    $cached = $row;
    return $cached;
}

function require_user(PDO $pdo): array
{
    $user = current_user($pdo);
    if ($user === null) {
        respond(401, [
            'error'   => 'unauthorized',
            'message' => '需要有效令牌：请求头 X-Api-Token: <token>（或 Authorization: Bearer <token>），请先调用 /api/index.php?action=login',
        ]);
    }
    return $user;
}
