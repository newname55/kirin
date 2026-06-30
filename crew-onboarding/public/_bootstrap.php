<?php

declare(strict_types=1);

/**
 * 非公開ディレクトリ（app/database/storage/docs/scripts）へのパス解決。
 *
 * 本番構成（Xserver）:
 *   /home/USER/crew-onboarding-private/{app,database,storage,docs,scripts}
 *   /home/USER/public_html/crew-onboarding/{index.php, admin.php, ...}  ← このファイルの場所
 *   → dirname(__DIR__, 2) . '/crew-onboarding-private' が app/ を持つので採用
 *
 * ローカル開発構成（このリポジトリ）:
 *   crew-onboarding/{app,database,storage,docs,scripts,public/}
 *   crew-onboarding/public/{index.php, admin.php, ...}  ← このファイルの場所
 *   → 本番想定パスに app/ が存在しないため、dirname(__DIR__)（= crew-onboarding/）にフォールバック
 */

if (!defined('CREW_PRIVATE_ROOT')) {
    $prodCandidate = dirname(__DIR__, 2) . '/crew-onboarding-private';
    define(
        'CREW_PRIVATE_ROOT',
        is_dir($prodCandidate . '/app') ? $prodCandidate : dirname(__DIR__)
    );
}

if (!defined('CREW_PUBLIC_ROOT')) {
    define('CREW_PUBLIC_ROOT', __DIR__);
}
