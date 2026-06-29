<?php

declare(strict_types=1);

require_once __DIR__ . '/privacy.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/crew-onboarding/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('twin_admin');
    session_start();
}

function twin_admin_is_logged_in(): bool
{
    return !empty($_SESSION['twin_admin_authenticated']);
}

function twin_admin_logout(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function twin_admin_login_error(): string
{
    return (string) ($_SESSION['twin_admin_login_error'] ?? '');
}

function twin_admin_set_login_error(string $message): void
{
    $_SESSION['twin_admin_login_error'] = $message;
}

function twin_admin_clear_login_error(): void
{
    unset($_SESSION['twin_admin_login_error']);
}

function twin_admin_csrf_token(): string
{
    if (empty($_SESSION['twin_admin_csrf_token']) || !is_string($_SESSION['twin_admin_csrf_token'])) {
        $_SESSION['twin_admin_csrf_token'] = bin2hex(random_bytes(16));
    }

    return (string) $_SESSION['twin_admin_csrf_token'];
}

function twin_admin_verify_csrf_token(string $token): bool
{
    $stored = (string) ($_SESSION['twin_admin_csrf_token'] ?? '');
    if ($stored === '' || $token === '') {
        return false;
    }

    return hash_equals($stored, $token);
}

function twin_admin_require_login(): void
{
    if (!twin_admin_is_logged_in()) {
        http_response_code(403);
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

/**
 * ログイン可能ユーザー一覧を返す。
 * 優先順位: admin_users（複数ID）> admin_username + admin_password_hash（後方互換）
 * 戻り値: ['username' => 'password_hash', ...]
 */
function twin_admin_resolve_users(array $config): array
{
    $users = $config['admin_users'] ?? [];
    if (is_array($users) && count($users) > 0) {
        // admin_users が設定されていればそれのみ使用
        $result = [];
        foreach ($users as $u => $h) {
            if (is_string($u) && $u !== '' && is_string($h) && $h !== '') {
                $result[$u] = $h;
            }
        }
        if (count($result) > 0) {
            return $result;
        }
    }

    // 後方互換: admin_username / admin_password_hash
    $u = (string) ($config['admin_username'] ?? '');
    $h = (string) ($config['admin_password_hash'] ?? '');
    if ($u !== '' && $h !== '') {
        return [$u => $h];
    }

    return [];
}

/**
 * ユーザー名とパスワードを検証して一致するユーザー名を返す。
 * 一致しない場合は null。
 */
function twin_admin_verify_credentials(array $config, string $username, string $password): ?string
{
    if ($username === '' || $password === '') {
        return null;
    }
    $users = twin_admin_resolve_users($config);
    foreach ($users as $u => $hash) {
        if (hash_equals($u, $username) && password_verify($password, $hash)) {
            return $u;
        }
    }
    return null;
}

function twin_admin_requires_env_credentials(array $config): bool
{
    return !empty($config['admin_require_env_credentials']);
}

function twin_admin_has_env_credentials(): bool
{
    return (string) getenv('ADMIN_USERNAME') !== ''
        && (string) getenv('ADMIN_PASSWORD_HASH') !== '';
}

function twin_admin_event_session_id(PDO $pdo): int
{
    $token = 'admin-settings';
    $stmt = $pdo->prepare('SELECT id FROM chat_sessions WHERE session_token = :session_token LIMIT 1');
    $stmt->execute(['session_token' => $token]);
    $row = $stmt->fetch();
    if ($row) {
        return (int) $row['id'];
    }

    $columns = ['session_token', 'started_at', 'ended_at', 'message_count', 'session_duration_seconds', 'user_agent', 'ip_address'];
    $values = [':session_token', 'NOW()', 'NOW()', '0', '0', ':user_agent', ':ip_address'];
    $params = [
        'session_token' => $token,
        'user_agent' => 'admin-panel',
        'ip_address' => '127.0.0.1',
    ];

    if (function_exists('twin_db_column_exists') && twin_db_column_exists($pdo, 'chat_sessions', 'store_key')) {
        $columns[] = 'store_key';
        $values[] = ':store_key';
        $params['store_key'] = function_exists('twin_current_store_key') ? twin_current_store_key() : 'seika';
    }

    $insert = $pdo->prepare(
        'INSERT INTO chat_sessions (' . implode(', ', $columns) . ')
         VALUES (' . implode(', ', $values) . ')'
    );
    $insert->execute($params);

    return (int) $pdo->lastInsertId();
}

function twin_admin_log_event(PDO $pdo, string $eventName, ?string $eventValue): void
{
    $sessionId = twin_admin_event_session_id($pdo);
    $columns = ['session_id', 'event_name', 'event_value', 'created_at'];
    $values = [':session_id', ':event_name', ':event_value', 'NOW()'];
    $params = [
        'session_id' => $sessionId,
        'event_name' => $eventName,
        'event_value' => twin_safe_log_value($eventValue, 1000),
    ];

    if (function_exists('twin_db_column_exists') && twin_db_column_exists($pdo, 'event_logs', 'store_key')) {
        $columns[] = 'store_key';
        $values[] = ':store_key';
        $params['store_key'] = function_exists('twin_current_store_key') ? twin_current_store_key() : 'seika';
    }

    $stmt = $pdo->prepare(
        'INSERT INTO event_logs (' . implode(', ', $columns) . ')
         VALUES (' . implode(', ', $values) . ')'
    );
    $stmt->execute($params);
}
