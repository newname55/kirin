<?php

declare(strict_types=1);

/**
 * store_key 管理 (v0.9.3)
 *
 * 優先順位:
 *   1. 環境変数 TWIN_STORE_KEY（本番固定用。これがあれば管理画面切替不可）
 *   2. 管理画面セッション $_SESSION['twin_admin_store_key']（ボタンで切替）
 *   3. config.local.php の 'store_key'（デプロイ設定）
 *   4. デフォルト 'seika'
 *
 * 切替はリダイレクト経由で行うため static キャッシュの問題は発生しない。
 */

if (!function_exists('twin_current_store_key')) {
    function twin_current_store_key(): string
    {
        static $key = null;
        if ($key !== null) {
            return $key;
        }

        // 1. 環境変数（本番固定）
        $env = (string) (getenv('TWIN_STORE_KEY') ?: '');
        if ($env !== '' && in_array($env, twin_valid_store_keys(), true)) {
            $key = $env;
            return $key;
        }

        // 2. 管理画面セッション
        if (session_status() === PHP_SESSION_ACTIVE) {
            $fromSession = (string) ($_SESSION['twin_admin_store_key'] ?? '');
            if ($fromSession !== '' && in_array($fromSession, twin_valid_store_keys(), true)) {
                $key = $fromSession;
                return $key;
            }
        }

        // 3. config 経由（twin_config() 優先、$GLOBALS['config'] はフォールバック）
        $configArr = function_exists('twin_config') ? twin_config() : ($GLOBALS['config'] ?? []);
        $fromConfig = (string) ($configArr['store_key'] ?? '');
        if ($fromConfig !== '' && in_array($fromConfig, twin_valid_store_keys(), true)) {
            $key = $fromConfig;
            return $key;
        }

        // 4. デフォルト
        $key = 'seika';
        return $key;
    }
}

if (!function_exists('twin_valid_store_keys')) {
    function twin_valid_store_keys(): array
    {
        return ['seika', 'creole', 'kirin'];
    }
}
