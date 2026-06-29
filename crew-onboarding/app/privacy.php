<?php

declare(strict_types=1);

/**
 * Public-chat privacy helpers.
 *
 * The goal is conservative masking of clearly structured personal data before
 * it is stored, exported, or sent to external AI services. Name detection is
 * intentionally not attempted here to avoid excessive false positives.
 */

function twin_mask_personal_data(?string $value): string
{
    $text = (string) $value;
    if ($text === '') {
        return '';
    }

    $masked = $text;

    // Email addresses.
    $masked = preg_replace(
        '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu',
        '[メールアドレス]',
        $masked
    ) ?? $masked;

    // Japanese phone numbers and compact 10-11 digit numbers.
    $masked = preg_replace(
        '/(?<!\d)(?:0\d{1,4}[-ー−\s]?\d{1,4}[-ー−\s]?\d{3,4}|0[789]0[-ー−\s]?\d{4}[-ー−\s]?\d{4})(?!\d)/u',
        '[電話番号]',
        $masked
    ) ?? $masked;
    $masked = preg_replace('/(?<!\d)0\d{9,10}(?!\d)/u', '[電話番号]', $masked) ?? $masked;

    // Explicit LINE ID patterns. Keep this narrow to avoid masking ordinary
    // words that happen to contain "line".
    $masked = preg_replace(
        '/(?:LINE\s*ID|Line\s*ID|ライン\s*ID|LINEID|ラインID)\s*[:：は=]?\s*[A-Za-z0-9_.\-]{3,32}/u',
        '[LINE ID]',
        $masked
    ) ?? $masked;

    // Postal code plus following address-ish text. Require the 〒 marker so
    // ordinary 7-digit numbers (order/member IDs, prices) are not over-masked,
    // and limit how much trailing text is consumed.
    $masked = preg_replace(
        '/〒\s*\d{3}[-ー−]?\d{4}\s*[^\s　,，。]{0,40}/u',
        '[住所]',
        $masked
    ) ?? $masked;

    // Address-like prefecture/city/block patterns.
    $masked = preg_replace(
        '/(?:北海道|東京都|京都府|大阪府|.{2,3}県)[^\s　,，。]{2,60}(?:市|区|町|村)[^\s　,，。]{0,60}(?:\d|[０-９]|丁目|番地|番|号)[^\s　,，。]{0,40}/u',
        '[住所]',
        $masked
    ) ?? $masked;

    // Credit card-like sequences.
    $masked = preg_replace(
        '/(?<!\d)(?:\d[ -]?){13,19}(?!\d)/u',
        '[決済情報]',
        $masked
    ) ?? $masked;

    return $masked;
}

function twin_safe_log_value(?string $value, int $maxLength = 160): string
{
    $masked = twin_mask_personal_data($value);
    $masked = trim($masked);

    if ($masked === '') {
        return '';
    }

    return function_exists('mb_strimwidth')
        ? mb_strimwidth($masked, 0, $maxLength, '...', 'UTF-8')
        : substr($masked, 0, $maxLength);
}
