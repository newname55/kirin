<?php

declare(strict_types=1);

function twin_allowed_response_modes(): array
{
    return ['rule', 'openai', 'hybrid'];
}

function twin_allowed_openai_models(): array
{
    return ['gpt-4.1-mini', 'gpt-4.1-nano'];
}

function twin_normalize_openai_model_value(?string $value): string
{
    $value = strtolower(trim((string) $value));
    return in_array($value, twin_allowed_openai_models(), true) ? $value : '';
}

/**
 * モデル別の料金単価（USD/1Mトークン）と表示名を返す。
 * 単価は公式ページの概算値。実際の請求とは異なる場合がある。
 */
function twin_openai_model_costs(): array
{
    return [
        'gpt-4.1-mini' => [
            'label'  => 'GPT-4.1 mini',
            'input'  => 0.40,
            'output' => 1.60,
        ],
        'gpt-4.1-nano' => [
            'label'  => 'GPT-4.1 nano',
            'input'  => 0.10,
            'output' => 0.40,
        ],
    ];
}

function twin_openai_model_label(string $model): string
{
    return twin_openai_model_costs()[$model]['label'] ?? $model;
}

function twin_normalize_response_mode_value(?string $value): string
{
    $value = strtolower(trim((string) $value));
    if ($value === '') {
        return '';
    }

    return in_array($value, twin_allowed_response_modes(), true) ? $value : '';
}

function twin_app_settings_pdo(array $dbConfig): ?PDO
{
    try {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $dbConfig['host'],
            $dbConfig['port'],
            $dbConfig['database'],
            $dbConfig['charset']
        );

        return new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (Throwable $e) {
        error_log('[TWIN settings] Failed to connect for settings lookup: ' . $e->getMessage());

        return null;
    }
}

function twin_app_settings_fetch_one(array $dbConfig, string $settingKey): ?string
{
    $pdo = twin_app_settings_pdo($dbConfig);
    if (!$pdo instanceof PDO) {
        return null;
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT setting_value
             FROM app_settings
             WHERE setting_key = :setting_key
             LIMIT 1'
        );
        $stmt->execute(['setting_key' => $settingKey]);
        $value = $stmt->fetchColumn();

        if ($value === false || $value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    } catch (Throwable $e) {
        $message = $e->getMessage();
        if (!preg_match('/Base table or view not found|Table .* doesn\'t exist|1146/i', $message)) {
            error_log('[TWIN settings] Failed to read setting ' . $settingKey . ': ' . $message);
        }

        return null;
    }
}

function twin_app_settings_fetch_many(PDO $pdo, array $settingKeys): array
{
    if (!$settingKeys) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($settingKeys), '?'));
    $stmt = $pdo->prepare(
        "SELECT setting_key, setting_value
         FROM app_settings
         WHERE setting_key IN ({$placeholders})"
    );
    $stmt->execute(array_values($settingKeys));

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[(string) $row['setting_key']] = (string) $row['setting_value'];
    }

    return $rows;
}

function twin_app_settings_upsert(PDO $pdo, string $settingKey, string $settingValue): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO app_settings (setting_key, setting_value, updated_at)
         VALUES (:setting_key, :setting_value, NOW())
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)'
    );
    $stmt->execute([
        'setting_key' => $settingKey,
        'setting_value' => $settingValue,
    ]);
}

/** WBSS接続先 env_key → base URL マッピング */
function twin_wbss_env_urls(): array
{
    return [
        'prod' => 'https://ss5456ds1fds2f1dsf.asuscomm.com/wbss/public/api/twin',
        'dev'  => 'https://haruto.asuscomm.com/wbss/public/api/twin',
    ];
}

function twin_wbss_allowed_envs(): array
{
    return ['prod', 'dev'];
}

function twin_wbss_env_label(string $env): string
{
    return match ($env) {
        'prod'  => '本番 Raspi5',
        'dev'   => '開発 Raspi4',
        default => $env,
    };
}

function twin_app_response_mode(PDO $pdo, array $config = []): string
{
    $dbMode = twin_app_settings_fetch_many($pdo, ['response_mode'])['response_mode'] ?? '';
    $dbMode = twin_normalize_response_mode_value($dbMode);
    if ($dbMode !== '') {
        return $dbMode;
    }

    $localMode = twin_normalize_response_mode_value((string) ($config['response_mode'] ?? ''));
    if ($localMode !== '') {
        return $localMode;
    }

    return 'rule';
}
