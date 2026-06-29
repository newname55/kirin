<?php

declare(strict_types=1);

/**
 * recruit_engine parser 単体テスト
 *
 * DB接続不要。parse_* 関数を直接呼び、入力・期待値・実際の値・OK/NG を表示する。
 * NG が1件でも存在した場合は exit(1) で終了する。
 *
 * 実行: php scripts/test_parsers.php
 *
 * このスクリプトは開発用 Raspi4 を対象とする。本番 Raspi5 では実行しないこと。
 */

$appDir = dirname(__DIR__) . '/app';

// parser は store.php → knowledge/stores.php → recruit_engine.php の順でロードされる
// (いずれも DB 接続なしで include 可能)
require_once $appDir . '/knowledge/stores.php';
require_once $appDir . '/store.php';
require_once $appDir . '/engines/recruit_engine.php';

// ── CLI ヘルパー ──────────────────────────────────────────────────

function tp_color(string $text, string $color): string
{
    if (!posix_isatty(STDOUT)) {
        return $text;
    }
    $codes = [
        'green'  => "\033[32m",
        'red'    => "\033[31m",
        'yellow' => "\033[33m",
        'cyan'   => "\033[36m",
        'bold'   => "\033[1m",
        'reset'  => "\033[0m",
    ];
    return ($codes[$color] ?? '') . $text . $codes['reset'];
}

function tp_out(string $line): void
{
    echo $line . PHP_EOL;
}

// ── テストケース定義 ──────────────────────────────────────────────
//
// 各ケース: ['fn' => parser関数名, 'input' => 入力文字列, 'expected' => 期待値（null を許容する場合は null）]
// expected は string|null。null は「いずれの値でも null が返ること」を意味する。
// ※ parse_alcohol の 'some' = weak 相当（呼び出し元の表記に合わせて 'some' で統一）

$cases = [

    // ── parse_alcohol ─────────────────────────────────────────────
    ['fn' => 'twin_recruit_parse_alcohol', 'label' => 'parse_alcohol',
        'input' => '飲めます',              'expected' => 'yes'],
    ['fn' => 'twin_recruit_parse_alcohol', 'label' => 'parse_alcohol',
        'input' => 'かなり飲めます',        'expected' => 'yes'],
    ['fn' => 'twin_recruit_parse_alcohol', 'label' => 'parse_alcohol',
        'input' => 'お酒はかなり飲めます',  'expected' => 'yes'],
    ['fn' => 'twin_recruit_parse_alcohol', 'label' => 'parse_alcohol',
        'input' => '飲みます',              'expected' => 'yes'],
    ['fn' => 'twin_recruit_parse_alcohol', 'label' => 'parse_alcohol',
        'input' => '少しなら飲めます',      'expected' => 'some'],
    ['fn' => 'twin_recruit_parse_alcohol', 'label' => 'parse_alcohol',
        'input' => 'ちょっとなら飲めます',  'expected' => 'some'],
    ['fn' => 'twin_recruit_parse_alcohol', 'label' => 'parse_alcohol',
        'input' => '飲めません',            'expected' => 'no'],
    ['fn' => 'twin_recruit_parse_alcohol', 'label' => 'parse_alcohol',
        'input' => '飲めない',              'expected' => 'no'],
    ['fn' => 'twin_recruit_parse_alcohol', 'label' => 'parse_alcohol',
        'input' => '飲みません',            'expected' => 'no'],
    ['fn' => 'twin_recruit_parse_alcohol', 'label' => 'parse_alcohol',
        'input' => 'お酒は無理です',        'expected' => 'no'],
    ['fn' => 'twin_recruit_parse_alcohol', 'label' => 'parse_alcohol',
        'input' => '苦手です',              'expected' => 'no'],

    // ── parse_bring ──────────────────────────────────────────────
    ['fn' => 'twin_recruit_parse_bring', 'label' => 'parse_bring',
        'input' => '呼べます',                    'expected' => 'yes'],
    ['fn' => 'twin_recruit_parse_bring', 'label' => 'parse_bring',
        'input' => '友達を2〜3人呼べます',         'expected' => 'yes'],
    ['fn' => 'twin_recruit_parse_bring', 'label' => 'parse_bring',
        'input' => '連れてこられます',             'expected' => 'yes'],
    ['fn' => 'twin_recruit_parse_bring', 'label' => 'parse_bring',
        'input' => '連れてこれます',               'expected' => 'yes'],
    ['fn' => 'twin_recruit_parse_bring', 'label' => 'parse_bring',
        'input' => 'たぶん呼べると思います',       'expected' => 'maybe'],
    ['fn' => 'twin_recruit_parse_bring', 'label' => 'parse_bring',
        'input' => '呼べるかもしれません',         'expected' => 'maybe'],
    ['fn' => 'twin_recruit_parse_bring', 'label' => 'parse_bring',
        'input' => '呼ぶのは難しいです',           'expected' => 'no'],
    ['fn' => 'twin_recruit_parse_bring', 'label' => 'parse_bring',
        'input' => '呼べません',                   'expected' => 'no'],
    ['fn' => 'twin_recruit_parse_bring', 'label' => 'parse_bring',
        'input' => '誘えません',                   'expected' => 'no'],
    ['fn' => 'twin_recruit_parse_bring', 'label' => 'parse_bring',
        'input' => '知り合いはいません',           'expected' => 'no'],

    // ── parse_days ───────────────────────────────────────────────
    ['fn' => 'twin_recruit_parse_days', 'label' => 'parse_days',
        'input' => '週1か2日でお願いしたいです', 'expected' => '1_2'],
    ['fn' => 'twin_recruit_parse_days', 'label' => 'parse_days',
        'input' => 'まずは週1で',               'expected' => '1_2'],
    ['fn' => 'twin_recruit_parse_days', 'label' => 'parse_days',
        'input' => '週3か4日希望です',           'expected' => '3_4'],
    ['fn' => 'twin_recruit_parse_days', 'label' => 'parse_days',
        'input' => '週5以上入れます',            'expected' => '5_plus'],
    ['fn' => 'twin_recruit_parse_days', 'label' => 'parse_days',
        'input' => '週5日以上入れます',          'expected' => '5_plus'],
    ['fn' => 'twin_recruit_parse_days', 'label' => 'parse_days',
        'input' => 'ほぼ毎日入れます',           'expected' => '5_plus'],

    // ── parse_experience ─────────────────────────────────────────
    ['fn' => 'twin_recruit_parse_experience', 'label' => 'parse_experience',
        'input' => '未経験です',                          'expected' => 'none'],
    ['fn' => 'twin_recruit_parse_experience', 'label' => 'parse_experience',
        'input' => 'キャバは全くないです',                 'expected' => 'none'],
    ['fn' => 'twin_recruit_parse_experience', 'label' => 'parse_experience',
        'input' => '3年経験があります',                   'expected' => 'yes'],
    ['fn' => 'twin_recruit_parse_experience', 'label' => 'parse_experience',
        'input' => 'キャバクラ経験があります',             'expected' => 'yes'],
    ['fn' => 'twin_recruit_parse_experience', 'label' => 'parse_experience',
        'input' => 'キャバは少しだけ経験があります',       'expected' => 'some'],
    ['fn' => 'twin_recruit_parse_experience', 'label' => 'parse_experience',
        'input' => 'ガールズバーが1年あります',            'expected' => 'some'],

    // ── parse_referrals ──────────────────────────────────────────
    ['fn' => 'twin_recruit_parse_referrals', 'label' => 'parse_referrals',
        'input' => '週に6組以上は指名がありました', 'expected' => '6_plus'],
    ['fn' => 'twin_recruit_parse_referrals', 'label' => 'parse_referrals',
        'input' => '3〜5組は指名がありました',      'expected' => '3_5'],
    ['fn' => 'twin_recruit_parse_referrals', 'label' => 'parse_referrals',
        'input' => '1〜2組は常連さんがいます',      'expected' => '1_2'],
    ['fn' => 'twin_recruit_parse_referrals', 'label' => 'parse_referrals',
        'input' => '指名はほとんどなかったです',    'expected' => '0'],

    // ── parse_hourly ─────────────────────────────────────────────
    ['fn' => 'twin_recruit_parse_hourly', 'label' => 'parse_hourly',
        'input' => '時給は5000円でした',   'expected' => 5000],
    ['fn' => 'twin_recruit_parse_hourly', 'label' => 'parse_hourly',
        'input' => '4000円くらいでした',   'expected' => 4000],
    ['fn' => 'twin_recruit_parse_hourly', 'label' => 'parse_hourly',
        'input' => '3500円〜4000円でした', 'expected' => 3500],

];

// ── テスト実行 ────────────────────────────────────────────────────

tp_out(tp_color('══════════════════════════════════════════════════', 'bold'));
tp_out(tp_color(' recruit_engine parser 単体テスト', 'bold'));
tp_out(tp_color('══════════════════════════════════════════════════', 'bold'));
tp_out('');

$pass = 0;
$fail = 0;
$prevLabel = '';

foreach ($cases as $case) {
    $fn       = $case['fn'];
    $label    = $case['label'];
    $input    = $case['input'];
    $expected = $case['expected'];

    if ($label !== $prevLabel) {
        if ($prevLabel !== '') {
            tp_out('');
        }
        tp_out(tp_color("  ── {$label} ──────────────────────────────────────", 'cyan'));
        $prevLabel = $label;
    }

    /** @var string|int|null $actual */
    $actual = $fn($input);
    $ok     = ($actual === $expected);

    if ($ok) {
        $pass++;
        $status = tp_color('OK', 'green');
    } else {
        $fail++;
        $status = tp_color('NG', 'red');
    }

    $expectedStr = $expected === null ? 'null' : (string) $expected;
    $actualStr   = $actual   === null ? 'null' : (string) $actual;

    $line = sprintf('  %s  %-40s → expected:%-8s actual:%s',
        $status,
        mb_substr($input, 0, 40),
        $expectedStr,
        $ok ? tp_color($actualStr, 'green') : tp_color($actualStr, 'red')
    );
    tp_out($line);
}

// ── サマリー ──────────────────────────────────────────────────────

tp_out('');
tp_out(tp_color('══════════════════════════════════════════════════', 'bold'));
$total = $pass + $fail;
if ($fail === 0) {
    tp_out(tp_color(" 全件 OK: {$pass}/{$total}", 'green'));
} else {
    tp_out(tp_color(" NG: {$fail}/{$total}  ← 上記の NG 行を修正してください", 'red'));
}
tp_out(tp_color('══════════════════════════════════════════════════', 'bold'));
tp_out('');

exit($fail > 0 ? 1 : 0);
