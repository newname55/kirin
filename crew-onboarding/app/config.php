<?php

declare(strict_types=1);

if (!defined('APP_VERSION')) {
    define('APP_VERSION', '1.1 RC');
}

require_once __DIR__ . '/settings.php';

$config = [
    'app_name' => 'CREW KIRIN',
    'timezone' => 'Asia/Tokyo',
    'trial_seconds' => 300,
    'response_mode' => 'rule',
    'admin_username' => 'newname',
    'admin_password_hash' => '$2y$12$4DBH.NNdURVhu/.uapp2luly2fVW39vaDcy9BsQYeiNU.6NyLkaw6',
    'admin_require_env_credentials' => (getenv('TWIN_REQUIRE_SECURE_ADMIN') === '1'),
    'openai_api_key' => '',
    'openai_model' => 'gpt-4.1-mini',
    'openai_timeout_seconds' => 8,
    'wbss_api_base_url' => 'https://haruto.asuscomm.com/wbss/public/api/twin',
    'wbss_twin_api_key' => '',
    'wbss_api_timeout_seconds' => 5,

    // OpenAI 概算コスト設定（実際の請求額とは異なる場合があります）
    'openai_input_cost_per_1m_tokens_usd'  => (float)(getenv('OPENAI_INPUT_COST_PER_1M_TOKENS_USD') ?: 0.40),
    'openai_output_cost_per_1m_tokens_usd' => (float)(getenv('OPENAI_OUTPUT_COST_PER_1M_TOKENS_USD') ?: 1.60),
    'usd_jpy_rate'                         => (float)(getenv('USD_JPY_RATE') ?: 150.0),

    'db' => [
        'host' => getenv('TWIN_DB_HOST') ?: '127.0.0.1',
        'port' => getenv('TWIN_DB_PORT') ?: '3306',
        'database' => getenv('TWIN_DB_DATABASE') ?: 'twin',
        'username' => getenv('TWIN_DB_USERNAME') ?: 'twin_user',
        'password' => getenv('TWIN_DB_PASSWORD') ?: 'change_me',
        'charset' => 'utf8mb4',
    ],

    'links' => [
        'line'      => getenv('TWIN_LINE_URL')      ?: '',
        'price'     => getenv('TWIN_PRICE_URL')     ?: '',
        'instagram' => getenv('TWIN_INSTAGRAM_URL') ?: '',
    ],

    'logging' => [
        'path' => dirname(__DIR__) . '/storage/logs/app.log',
    ],
];

$localConfigPath = __DIR__ . '/config.local.php';

if (is_file($localConfigPath)) {
    $localConfig = require $localConfigPath;

    if (is_array($localConfig)) {
        $config = array_replace_recursive($config, $localConfig);
    }
}

$envValues = [
    'admin_username' => getenv('ADMIN_USERNAME'),
    'admin_password_hash' => getenv('ADMIN_PASSWORD_HASH'),
    'openai_api_key' => getenv('OPENAI_API_KEY') ?: getenv('WBSS_OPENAI_API_KEY'),
    'openai_model' => getenv('OPENAI_MODEL'),
    'openai_timeout_seconds' => getenv('OPENAI_TIMEOUT_SECONDS'),
    'wbss_api_base_url' => getenv('WBSS_API_BASE_URL'),
    'wbss_twin_api_key' => getenv('WBSS_TWIN_API_KEY') ?: getenv('TWIN_API_KEY'),
    'wbss_api_timeout_seconds' => getenv('WBSS_API_TIMEOUT_SECONDS'),
    'db.host' => getenv('TWIN_DB_HOST'),
    'db.port' => getenv('TWIN_DB_PORT'),
    'db.database' => getenv('TWIN_DB_DATABASE'),
    'db.username' => getenv('TWIN_DB_USERNAME'),
    'db.password' => getenv('TWIN_DB_PASSWORD'),
    'links.line' => getenv('TWIN_LINE_URL'),
    'links.price' => getenv('TWIN_PRICE_URL'),
    'links.instagram' => getenv('TWIN_INSTAGRAM_URL'),
];

foreach ($envValues as $key => $value) {
    if ($value === false || $value === '') {
        continue;
    }

    if (str_contains($key, '.')) {
        [$section, $sectionKey] = explode('.', $key, 2);
        $config[$section][$sectionKey] = $value;
        continue;
    }

    $config[$key] = $value;
}

if (!is_int($config['openai_timeout_seconds'])) {
    $config['openai_timeout_seconds'] = (int) $config['openai_timeout_seconds'];
}

if (!is_int($config['wbss_api_timeout_seconds'])) {
    $config['wbss_api_timeout_seconds'] = (int) $config['wbss_api_timeout_seconds'];
}

$dbSettingsMode = twin_app_settings_fetch_one($config['db'], 'response_mode');
if ($dbSettingsMode !== null) {
    $normalizedDbSettingsMode = twin_normalize_response_mode_value($dbSettingsMode);
    if ($normalizedDbSettingsMode !== '') {
        $config['response_mode'] = $normalizedDbSettingsMode;
    }
}

$dbSettingsModel = twin_app_settings_fetch_one($config['db'], 'openai_model');
if ($dbSettingsModel !== null) {
    $normalizedDbModel = twin_normalize_openai_model_value($dbSettingsModel);
    if ($normalizedDbModel !== '') {
        $config['openai_model'] = $normalizedDbModel;
    }
}

if (isset($localConfig) && is_array($localConfig)) {
    foreach (['wbss_api_base_url', 'wbss_twin_api_key', 'wbss_api_timeout_seconds'] as $key) {
        if (array_key_exists($key, $localConfig)) {
            $config[$key] = $localConfig[$key];
        }
    }
}

$localMode = twin_normalize_response_mode_value((string) ($config['response_mode'] ?? ''));
if ($dbSettingsMode === null && $localMode !== '') {
    $config['response_mode'] = $localMode;
}

// DB設定 wbss_env が有効なら base URL を上書き（config.local.php / env より優先）
$dbSettingsWbssEnv = twin_app_settings_fetch_one($config['db'], 'wbss_env');
if ($dbSettingsWbssEnv !== null && in_array($dbSettingsWbssEnv, twin_wbss_allowed_envs(), true)) {
    $config['wbss_api_base_url'] = twin_wbss_env_urls()[$dbSettingsWbssEnv];
}

if (!defined('WBSS_API_BASE_URL')) {
    define('WBSS_API_BASE_URL', (string) ($config['wbss_api_base_url'] ?? 'https://haruto.asuscomm.com/wbss/public/api/twin'));
}

if (!defined('WBSS_TWIN_API_KEY')) {
    define('WBSS_TWIN_API_KEY', (string) ($config['wbss_twin_api_key'] ?? ''));
}

if (!defined('WBSS_API_TIMEOUT_SECONDS')) {
    define('WBSS_API_TIMEOUT_SECONDS', (int) ($config['wbss_api_timeout_seconds'] ?? 5));
}

return $config;
