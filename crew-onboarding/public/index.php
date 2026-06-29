<?php

declare(strict_types=1);

$config = require dirname(__DIR__) . '/app/config.php';

require_once dirname(__DIR__) . '/app/store.php';
require_once dirname(__DIR__) . '/app/knowledge/stores.php';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// store_key に応じた店舗設定を取得
$storeKey    = twin_current_store_key();
$storeConfig = twin_store_config($storeKey);

// store_key に応じたリンクURLを決定（config.local.php > stores.php の順で優先）
$lineUrl      = trim((string) ($config['links']['line']      ?? '')) ?: twin_store_value($storeKey, 'line_url');
$instagramUrl = trim((string) ($config['links']['instagram'] ?? '')) ?: twin_store_value($storeKey, 'instagram_url');
$siteUrl      = twin_store_value($storeKey, 'site_url', '');
$tel          = twin_store_value($storeKey, 'tel', '');

// アクティブなキャラクター設定を読み込む（DBエラー時は store デフォルト値）
$character = [
    'ai_name'              => $storeConfig['default_ai_name']    ?? 'TWIN SEIKA',
    'ai_title'             => $storeConfig['default_role_label'] ?? '',
    'greeting_message'     => $storeConfig['default_greeting']   ?? '',
    'character_image_path' => null,
    'logo_image_path'      => null,
];
try {
    require_once dirname(__DIR__) . '/app/db.php';
    require_once dirname(__DIR__) . '/app/ai_character_settings.php';
    $character = twin_ai_character_load_active(twin_db(), $storeKey);
} catch (Throwable $e) {
    // DBが未起動・migration未適用の場合もデフォルト値で動作継続
}
?>
<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?= e((string) ($character['ai_name'] ?? $config['app_name'])) ?></title>
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-SEHNPWLM29"></script>
    <script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag("js",new Date());gtag("config","G-SEHNPWLM29");</script>
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-VYZTD2MMCH"></script>
    <script>gtag("config","G-VYZTD2MMCH");</script>
    <link rel="stylesheet" href="/crew-onboarding/assets/css/style.css">
</head>
<body>
    <header class="top-nav" aria-label="Crew Onboarding navigation">
        <?php if ($siteUrl !== ''): ?>
        <a class="top-nav__brand" href="<?= e($siteUrl) ?>">サイトTOP</a>
        <?php endif; ?>
        <button class="top-nav__back" type="button" data-fallback-url="<?= e($siteUrl) ?>" onclick="if (history.length > 1) { history.back(); } else if (this.dataset.fallbackUrl) { location.href = this.dataset.fallbackUrl; }">戻る</button>
    </header>

    <main class="page-shell" data-trial-seconds="<?= (int) $config['trial_seconds'] ?>" data-line-url="<?= e($lineUrl) ?>" data-store-key="<?= e($storeKey) ?>" data-tel="<?= e($tel) ?>">
        <section class="hero">
            <?php
                $charImgSrc = trim((string) ($character['character_image_path'] ?? ''));
                $aiName  = e((string) ($character['ai_name']  ?? 'CREW KIRIN'));
                $aiTitle = e((string) ($character['ai_title'] ?? 'CLUB 麒麟 入店前コンシェルジュ'));
            ?>
            <?php if ($charImgSrc !== ''): ?>
            <div class="avatar-frame">
                <img
                    src="<?= e($charImgSrc) ?>"
                    alt="<?= $aiName ?>"
                    class="avatar"
                    onerror="this.style.display='none'; this.parentElement.classList.add('is-missing');"
                >
            </div>
            <?php endif; ?>
            <p class="eyebrow"><?= $aiTitle ?></p>
            <h1><?= $aiName ?></h1>
            <p class="lead">体験入店・給与・勤務時間など、気になることを何でも聞いてください。</p>
        </section>

        <section class="chat-panel" aria-label="CREW KIRIN チャット">
            <div class="chat-status">
                <span class="status-dot" aria-hidden="true"></span>
                <strong id="countdown" hidden></strong>
            </div>

            <div id="messages" class="messages" aria-live="polite">
                <div class="message-row twin">
                    <div class="bubble" style="white-space:pre-wrap"><?= e("こんにちは！CREW KIRINです♪\n\nいくつか質問に答えてもらえると、保証時給の目安をお伝えできます。\n\n夜のお仕事の経験はありますか？\n\n①未経験\n②少しだけある\n③経験あり") ?></div>
                </div>
                <div id="typingIndicator" class="message-row twin typing-row" hidden>
                    <div class="bubble typing-bubble">入力中...</div>
                </div>
            </div>

            <div id="systemMessage" class="system-message" role="status"></div>

            <form id="chatForm" class="chat-form" autocomplete="off">
                <label class="sr-only" for="messageInput">メッセージ</label>
                <textarea id="messageInput" name="message" maxlength="500" rows="1" placeholder="給与・体験入店・勤務時間などを聞いてみる"></textarea>
                <button id="sendButton" type="submit">送信</button>
            </form>

            <div class="app-version" aria-label="Application version">
                Project TWIN v<?= e(APP_VERSION) ?>
            </div>

            <div id="finishedPanel" class="finished-panel" hidden>
                <p class="finished-title">ここまでありがとう♪</p>
                <p class="finished-copy">体験入店の日程をLINEかお電話で相談してみませんか？</p>
                <div class="cta-grid">
                    <?php if ($lineUrl !== ''): ?>
                    <a href="<?= e($lineUrl) ?>" data-cta-value="line" class="cta primary">LINEで体験入店を相談する</a>
                    <?php endif; ?>
                    <?php if ($tel !== ''): ?>
                    <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $tel)) ?>" data-cta-value="tel" class="cta">電話で問い合わせる</a>
                    <?php endif; ?>
                    <?php if ($instagramUrl !== ''): ?>
                    <a href="<?= e($instagramUrl) ?>" data-cta-value="instagram" class="cta">Instagram</a>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </main>

    <!-- 固定CTAバー -->
    <div id="fixedCtaBar" class="fixed-cta-bar">
        <?php if ($lineUrl !== ''): ?>
        <a href="<?= e($lineUrl) ?>" class="fixed-cta fixed-cta-line" data-fixed-cta="fixed_line">LINE応募</a>
        <?php endif; ?>
        <?php if ($tel !== ''): ?>
        <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $tel)) ?>" class="fixed-cta" data-fixed-cta="fixed_tel">電話</a>
        <?php endif; ?>
        <?php if ($instagramUrl !== ''): ?>
        <a href="<?= e($instagramUrl) ?>" class="fixed-cta fixed-cta-instagram" data-fixed-cta="fixed_instagram">Instagram</a>
        <?php endif; ?>
    </div>

    <script src="/crew-onboarding/assets/js/chat.js" defer></script>
</body>
</html>
