<?php

declare(strict_types=1);

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once dirname(__DIR__) . '/app/db.php';
require_once dirname(__DIR__) . '/app/admin_common.php';
require_once dirname(__DIR__) . '/app/store.php';

if (!twin_admin_is_logged_in()) {
    header('Location: /crew-onboarding/admin.php');
    exit;
}

function e(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function label_map(mixed $v, array $map): string
{
    $s = (string) ($v ?? '');
    if ($s === '') {
        return '—';
    }
    return array_key_exists($s, $map) ? $map[$s] : e($s);
}

$pdo   = twin_db();
$id    = max(0, (int) ($_GET['id'] ?? 0));
$saved = false;
$error = '';

if ($id === 0) {
    header('Location: /crew-onboarding/admin.php#applicants');
    exit;
}

// ── POST: 店長記録を保存 ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!twin_admin_verify_csrf_token((string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'CSRFトークンが不正です。もう一度お試しください。';
    } else {
        $memo            = trim((string) ($_POST['memo'] ?? ''));
        $interviewRaw    = trim((string) ($_POST['interview_at'] ?? ''));
        $hiredRaw        = trim((string) ($_POST['hired_at'] ?? ''));
        $rejectedRaw     = trim((string) ($_POST['rejected_at'] ?? ''));
        $hiredEmployeeId = trim((string) ($_POST['hired_employee_id'] ?? ''));

        // 採用日と不採用日の排他処理
        if ($hiredRaw !== '' && $rejectedRaw !== '') {
            $rejectedRaw = '';
        }

        $interviewAt = $interviewRaw !== '' ? str_replace('T', ' ', $interviewRaw) . ':00' : null;
        $hiredAt     = $hiredRaw !== '' ? str_replace('T', ' ', $hiredRaw) . ':00' : null;
        $rejectedAt  = $rejectedRaw !== '' ? str_replace('T', ' ', $rejectedRaw) . ':00' : null;

        // 採用結果フィールド
        $allowedOutcomeStatus    = ['hired', 'declined', 'rejected', 'pending'];
        $allowedOutcomeSalesRank = ['S', 'A', 'B', 'C', 'D'];
        $allowedOutcomeRetention = ['active', 'left', 'unknown'];
        $allowedOutcomeBring     = ['high', 'normal', 'low', 'unknown'];

        $outcomeStatus    = trim((string) ($_POST['outcome_status']    ?? ''));
        $outcomeSalesRank = trim((string) ($_POST['outcome_sales_rank'] ?? ''));
        $outcomeRetention = trim((string) ($_POST['outcome_retention'] ?? ''));
        $outcomeBring     = trim((string) ($_POST['outcome_bring']     ?? ''));
        $outcomeCheckedRaw= trim((string) ($_POST['outcome_checked_at'] ?? ''));
        $outcomeNote      = trim((string) ($_POST['outcome_note']      ?? ''));

        $outcomeStatus    = in_array($outcomeStatus,    $allowedOutcomeStatus,    true) ? $outcomeStatus    : null;
        $outcomeSalesRank = in_array($outcomeSalesRank, $allowedOutcomeSalesRank, true) ? $outcomeSalesRank : null;
        $outcomeRetention = in_array($outcomeRetention, $allowedOutcomeRetention, true) ? $outcomeRetention : null;
        $outcomeBring     = in_array($outcomeBring,     $allowedOutcomeBring,     true) ? $outcomeBring     : null;
        $outcomeCheckedAt = $outcomeCheckedRaw !== '' ? str_replace('T', ' ', $outcomeCheckedRaw) . ':00' : null;

        $pdo->prepare(
            'UPDATE crew_applicants
             SET memo               = :memo,
                 interview_at       = :interview_at,
                 hired_at           = :hired_at,
                 rejected_at        = :rejected_at,
                 hired_employee_id  = :hired_employee_id,
                 outcome_status     = :outcome_status,
                 outcome_sales_rank = :outcome_sales_rank,
                 outcome_retention  = :outcome_retention,
                 outcome_bring      = :outcome_bring,
                 outcome_checked_at = :outcome_checked_at,
                 outcome_note       = :outcome_note
             WHERE id = :id'
        )->execute([
            'memo'               => $memo !== '' ? $memo : null,
            'interview_at'       => $interviewAt,
            'hired_at'           => $hiredAt,
            'rejected_at'        => $rejectedAt,
            'hired_employee_id'  => $hiredEmployeeId !== '' ? (int) $hiredEmployeeId : null,
            'outcome_status'     => $outcomeStatus,
            'outcome_sales_rank' => $outcomeSalesRank,
            'outcome_retention'  => $outcomeRetention,
            'outcome_bring'      => $outcomeBring,
            'outcome_checked_at' => $outcomeCheckedAt,
            'outcome_note'       => $outcomeNote !== '' ? $outcomeNote : null,
            'id'                 => $id,
        ]);

        $saved = true;
    }
}

// ── データ取得 ────────────────────────────────────────────
$stmt = $pdo->prepare('SELECT * FROM crew_applicants WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$a = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$a) {
    header('Location: /crew-onboarding/admin.php#applicants');
    exit;
}

$sess = $pdo->prepare(
    'SELECT started_at, ended_at, message_count FROM chat_sessions WHERE id = ? LIMIT 1'
);
$sess->execute([(int) $a['session_id']]);
$session = $sess->fetch(PDO::FETCH_ASSOC) ?: [];

$csrfToken = twin_admin_csrf_token();

// ── ラベルマップ ──────────────────────────────────────────
$expMap   = ['none' => '未経験', 'some' => '経験少し', 'yes' => '経験者'];
$genreMap = ['cabaret' => 'キャバクラ', 'lounge' => 'ラウンジ', 'snack' => 'スナック', 'girls_bar' => 'ガールズバー', 'other' => 'その他'];
$daysMap  = ['1_2' => '週1〜2日', '3_4' => '週3〜4日', '5_plus' => '週5日以上'];
$alcMap   = ['yes' => '飲める', 'some' => '少し飲める', 'no' => '飲めない'];
$refMap   = ['0' => '0組', '1_2' => '週1〜2組', '3_5' => '週3〜5組', '6_plus' => '週6組以上'];
$bringMap = ['yes' => 'できる', 'some' => '少しなら', 'maybe' => 'たぶんできる', 'no' => '難しい'];

// Grade ごとの CSS クラス名（ダーク/ライト両対応の変数で色付け）
$gradeCssClass = ['A' => 'grade-a', 'B' => 'grade-b', 'C' => 'grade-c', 'D' => 'grade-d'];
$grade         = (string) ($a['priority_grade'] ?? '');
$gradeClass    = $gradeCssClass[$grade] ?? '';

// 採用アクションラベル
$actionMap = [
    'A' => ['label' => '積極採用',   'class' => 'action-a'],
    'B' => ['label' => '面接推奨',   'class' => 'action-b'],
    'C' => ['label' => '条件確認',   'class' => 'action-c'],
    'D' => ['label' => '慎重判断',   'class' => 'action-d'],
];
$action = $actionMap[$grade] ?? null;

$isHired    = !empty($a['hired_at']);
$isRejected = !empty($a['rejected_at']);
$statusLabel = $isHired ? '採用' : ($isRejected ? '不採用' : '検討中');
$statusCss   = $isHired ? 'status-hired' : ($isRejected ? 'status-rejected' : 'status-pending');

// 会話ログ件数
$msgCount = null;
if (!empty($a['session_id'])) {
    $mc = $pdo->prepare('SELECT COUNT(*) FROM chat_messages WHERE session_id = ?');
    $mc->execute([(int) $a['session_id']]);
    $msgCount = (int) $mc->fetchColumn();
}

$scoreDetail = [];
$scoreDetailRaw = (string) ($a['score_detail'] ?? '');
if ($scoreDetailRaw !== '') {
    $decoded = json_decode($scoreDetailRaw, true);
    $scoreDetail = is_array($decoded) ? $decoded : [];
}

// score_detail に route がない場合は experience カラムから補完
// （問診完了済みだが score_detail が旧形式の場合でも route を確定させる）
if (!isset($scoreDetail['route'])) {
    $scoreDetail['route'] = ($a['experience'] ?? '') === 'yes' ? 'experienced' : 'novice';
}

// スコア内訳バー表示用：店長が重視する順に並べる
// novice:      呼客力(40) > 出勤日数(35) > 飲酒(15) > 経験ボーナス(10)
// experienced: 呼客（今+体験)(18+18) > 指名実績(42) > 出勤日数(15) > 前職時給(7)
$isExperienced = ($scoreDetail['route'] ?? '') === 'experienced';
if ($isExperienced) {
    $scoreBarItems = [
        ['rank' => 1, 'label' => '今の呼客',   'key' => 'bring_now',   'max' => 18],
        ['rank' => 2, 'label' => '体験日呼客', 'key' => 'bring_trial', 'max' => 18],
        ['rank' => 3, 'label' => '指名実績',   'key' => 'referrals',   'max' => 42],
        ['rank' => 4, 'label' => '出勤日数',   'key' => 'days',        'max' => 15],
        ['rank' => 5, 'label' => '前職時給',   'key' => 'prev_hourly', 'max' => 7],
    ];
} else {
    $scoreBarItems = [
        ['rank' => 1, 'label' => '呼客力',       'key' => 'bring_trial', 'max' => 40],
        ['rank' => 2, 'label' => '出勤日数',     'key' => 'days',        'max' => 35],
        ['rank' => 3, 'label' => '飲酒',         'key' => 'alcohol',     'max' => 15],
        ['rank' => 4, 'label' => '経験ボーナス', 'key' => 'exp_bonus',   'max' => 10],
    ];
}

// 採用推奨率（ルールベース）
$recommendRate = match($grade) {
    'A' => 95,
    'B' => 80,
    'C' => 60,
    'D' => 30,
    default => 0,
};

// 評価ラベル: パーセンテージ → 店長向けラベル + CSSクラス
// クラス: eval-strong / eval-good / eval-mid / eval-weak / eval-check
function twin_eval_label(int $pct): array
{
    return match(true) {
        $pct >= 80 => ['text' => '強い',   'class' => 'eval-strong'],
        $pct >= 55 => ['text' => '良い',   'class' => 'eval-good'],
        $pct >= 30 => ['text' => '普通',   'class' => 'eval-mid'],
        $pct >  0  => ['text' => '弱め',   'class' => 'eval-weak'],
        default    => ['text' => '要確認', 'class' => 'eval-check'],
    };
}

// AIコメント生成（ルールベース）: ['strengths' => string[], 'checks' => string[]]
function twin_recruit_ai_cards(array $a, array $scoreDetail): array
{
    $exp        = (string) ($a['experience']    ?? '');
    $days       = (string) ($a['days_per_week'] ?? '');
    $alcohol    = (string) ($a['alcohol']       ?? '');
    $bringNow   = (string) ($a['bring_now']     ?? '');
    $bringTrial = (string) ($a['bring_trial']   ?? '');
    $referrals  = (string) ($a['referrals']     ?? '');
    $grade      = (string) ($a['priority_grade'] ?? '');
    $isExp      = ($scoreDetail['route'] ?? '') === 'experienced';

    $strengths = [];
    $checks    = [];

    // ── 経験・指名 ──
    if ($exp === 'yes') {
        match($referrals) {
            '6_plus' => $strengths[] = '指名実績が豊富で即戦力',
            '3_5'    => $strengths[] = '指名実績あり・安定集客が見込める',
            '1_2'    => $checks[]   = '経験者だが指名実績は少なめ',
            default  => $checks[]   = '経験者だが指名ゼロ・集客力は未知数',
        };
    } elseif ($exp === 'some') {
        $checks[] = '水商売経験あり（ガールズバー等）・面接で詳細確認を';
    } else {
        $checks[] = '未経験者・育成コストを見込む';
    }

    // ── 出勤 ──
    match($days) {
        '5_plus' => $strengths[] = '週5日以上出勤希望・主力候補',
        '3_4'    => $strengths[] = '週3〜4日出勤・安定した戦力',
        '1_2'    => $checks[]   = '週1〜2日希望・補助的な運用になる',
        default  => null,
    };

    // ── 呼客 ──
    if ($isExp) {
        match(true) {
            $bringNow === 'yes'                            => $strengths[] = '今すぐ呼客できる常連客あり',
            $bringNow === 'some' && $bringTrial === 'yes' => $strengths[] = '今は少し・体験日も声かけ予定',
            $bringNow === 'some'                           => $checks[]   = '呼客は少しできる程度',
            $bringNow === 'no' && $bringTrial === 'no'    => $checks[]   = '呼客力は現時点では期待薄',
            default                                        => $checks[]   = '呼客はたぶん可能（未確定）',
        };
    } else {
        match($bringTrial) {
            'yes'   => $strengths[] = '友人・知人を呼客できる',
            'maybe' => $checks[]   = '呼客はたぶん可能（未確定）',
            'no'    => $checks[]   = '呼客は難しい・新規育成メイン',
            default => null,
        };
    }

    // ── 飲酒 ──
    match($alcohol) {
        'yes'  => $strengths[] = 'お酒が飲める',
        'some' => $checks[]   = '少し飲める程度・無理させず活かす',
        'no'   => $checks[]   = 'お酒が飲めない・盛り上げ方の工夫が必要',
        default => null,
    };

    // ── 総合 ──
    match($grade) {
        'A' => $strengths[] = '総合的に優秀・早めにアプローチを',
        'B' => $strengths[] = '基準を満たす・面接で人柄を確認',
        'C' => $checks[]   = '一部条件が弱め・動機・意欲を重点確認',
        'D' => $checks[]   = '現時点では採用基準を下回る・慎重に判断',
        default => null,
    };

    return ['strengths' => $strengths, 'checks' => $checks];
}

$aiCards = twin_recruit_ai_cards($a, $scoreDetail);

$scoreDetailLabels = [
    'route'       => 'ルート',
    'bring_trial' => '体験日呼客',
    'days'        => '出勤日数',
    'alcohol'     => '飲酒',
    'exp_bonus'   => '経験ボーナス',
    'referrals'   => '指名実績',
    'bring_now'   => '今の呼客力',
    'prev_hourly' => '前職時給',
];

function fmt_datetime(?string $v): string
{
    if ($v === null || $v === '') {
        return '—';
    }
    return e(substr($v, 0, 16));
}

function to_datetime_local(?string $v): string
{
    if ($v === null || $v === '') {
        return '';
    }
    return e(substr(str_replace(' ', 'T', $v), 0, 16));
}

?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>応募者 No.<?= e((string) $id) ?> — 入店前コンシェルジュ管理</title>
<!-- テーマ即時適用: body描画前に data-theme を設定してFOUCを防ぐ -->
<script>
(function(){
    var t = localStorage.getItem('twin_admin_theme') || 'auto';
    var d = t === 'auto' ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light') : t;
    document.documentElement.setAttribute('data-theme', d);
})();
</script>
<style>
/* ── CSS 変数（ダーク・デフォルト） ──────────────────────── */
:root {
    color-scheme: dark;
    --bg:         #0a0c0f;
    --panel:      #13161c;
    --panel-soft: #1a1d24;
    --gold:       #d7b46a;
    --gold-bright:#f4d891;
    --text:       #f0eadc;
    --muted:      #8a9ab0;
    --line:       rgba(215,180,106,0.18);
    --green:      #50d67a;
    --danger:     #f0b8a5;
    /* Grade カラー（ダーク用） */
    --grade-a: #50d67a;
    --grade-b: #5ab4ff;
    --grade-c: #ffbf66;
    --grade-d: #f0706a;
    --grade-a-bg: rgba(80,214,122,0.10);
    --grade-b-bg: rgba(90,180,255,0.10);
    --grade-c-bg: rgba(255,191,102,0.10);
    --grade-d-bg: rgba(240,112,106,0.10);
}

/* ── ライトテーマ ─────────────────────────────────────────── */
[data-theme="light"] {
    color-scheme: light;
    --bg:         #ececec;
    --panel:      #ffffff;
    --panel-soft: #f5f5f7;
    --gold:       #8a6200;
    --gold-bright:#6b4c00;
    --text:       #1c1c1e;
    --muted:      #6c6c70;
    --line:       rgba(0,0,0,0.12);
    --green:      #1a7a35;
    --danger:     #b91c1c;
    --grade-a: #1a7a35;
    --grade-b: #0055cc;
    --grade-c: #b85c00;
    --grade-d: #b91c1c;
    --grade-a-bg: rgba(26,122,53,0.08);
    --grade-b-bg: rgba(0,85,204,0.08);
    --grade-c-bg: rgba(184,92,0,0.08);
    --grade-d-bg: rgba(185,28,28,0.08);
}
[data-theme="light"] body           { background: #ececec; }
[data-theme="light"] .top-bar       { background: rgba(236,236,236,0.96); border-color: rgba(0,0,0,0.12); }
[data-theme="light"] .card          { background: #fff; border-color: rgba(0,0,0,0.10); }
[data-theme="light"] .card-head     { background: #f5f5f7; border-color: rgba(0,0,0,0.08); }
[data-theme="light"] .form-input,
[data-theme="light"] .form-textarea { background: #fff; border-color: rgba(0,0,0,0.18); color: #1c1c1e; }
[data-theme="light"] .form-input:focus,
[data-theme="light"] .form-textarea:focus { border-color: #8a6200; box-shadow: 0 0 0 2px rgba(138,98,0,0.12); }
[data-theme="light"] .score-bar-track { background: rgba(0,0,0,0.08); }
[data-theme="light"] .wage-pill     { background: #f5f0e8; border-color: rgba(0,0,0,0.10); color: #6b4c00; }
[data-theme="light"] .line-stamp    { background: rgba(26,122,53,0.08); border-color: rgba(26,122,53,0.28); color: #1a7a35; }
[data-theme="light"] .save-notice   { background: rgba(26,122,53,0.08); border-color: rgba(26,122,53,0.28); color: #1a7a35; }
[data-theme="light"] .error-notice  { background: rgba(185,28,28,0.06); border-color: rgba(185,28,28,0.25); color: #b91c1c; }
[data-theme="light"] .btn-save      { background: linear-gradient(135deg,#c9960a,#7a5100); color: #fff; }
[data-theme="light"] a              { color: #8a6200; }
[data-theme="light"] .back-link     { color: #6c6c70; border-color: rgba(0,0,0,0.15); }
[data-theme="light"] .back-link:hover { color: #1c1c1e; border-color: #8a6200; }
[data-theme="light"] .theme-btn.is-active { border-color: #007aff; color: #007aff; background: rgba(0,122,255,0.10); }
[data-theme="light"] .conv-link     { color: #0055cc; border-color: rgba(0,85,204,0.30); background: rgba(0,85,204,0.05); }

/* ── テーマ切替ボタン ─────────────────────────────────────── */
.theme-switcher { display:flex; gap:4px; align-items:center; margin-left:auto; }
.theme-btn {
    padding: 4px 10px; border-radius: 999px;
    border: 1px solid var(--line); background: transparent;
    color: var(--muted); font-size: 11px; cursor: pointer;
    transition: border-color .15s, color .15s, background .15s;
    font-family: inherit;
}
.theme-btn.is-active { border-color: var(--gold); color: var(--gold); background: rgba(215,180,106,0.10); }

/* ── リセット ── */
* { box-sizing: border-box; margin: 0; padding: 0; }

body {
    background: var(--bg); color: var(--text);
    font-family: -apple-system, BlinkMacSystemFont, "Hiragino Sans", "Yu Gothic", Meiryo, sans-serif;
    font-size: 14px; line-height: 1.6; padding-bottom: 4rem;
}
a { color: var(--gold); text-decoration: none; }
a:hover { text-decoration: underline; }

/* ── トップバー ── */
.top-bar {
    display: flex; align-items: center; gap: 0.75rem;
    padding: 0.7rem 1.25rem; border-bottom: 1px solid var(--line);
    background: rgba(10,12,15,0.96); position: sticky; top: 0; z-index: 10;
}
.back-link {
    display: inline-flex; align-items: center; gap: 0.3rem;
    padding: 0.3rem 0.8rem; border: 1px solid var(--line); border-radius: 999px;
    font-size: 0.8rem; color: var(--muted); white-space: nowrap;
}
.back-link:hover { color: var(--text); border-color: var(--gold); text-decoration: none; }
.top-title { font-size: 1rem; font-weight: 700; color: var(--gold-bright); flex: 1; }

/* ステータスチップ */
.status-chip {
    padding: 0.18rem 0.7rem; border-radius: 999px; font-size: 0.8rem;
    font-weight: 700; border: 1px solid currentColor; white-space: nowrap;
}
.status-hired    { color: var(--grade-a); }
.status-rejected { color: var(--danger);  }
.status-pending  { color: var(--muted);   }

/* ── ページレイアウト ── */
.page { max-width: 44rem; margin: 0 auto; padding: 1.5rem 1.25rem; display: grid; gap: 1.25rem; }

/* ── カード ── */
.card { border: 1px solid var(--line); border-radius: 0.6rem; background: var(--panel); overflow: hidden; }
.card-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 0.6rem 1rem; border-bottom: 1px solid var(--line); background: var(--panel-soft);
}
.card-head h2 { font-size: 0.78rem; font-weight: 700; color: var(--muted); letter-spacing: 0.07em; text-transform: uppercase; }
.card-head-note { font-size: 0.72rem; color: var(--muted); opacity: 0.7; }
.card-body { padding: 1rem 1rem 1.1rem; }

/* ── 定義リスト ── */
.dl { display: grid; grid-template-columns: auto 1fr; gap: 0.3rem 1.25rem; align-items: baseline; }
.dl dt { color: var(--muted); font-size: 0.8rem; white-space: nowrap; }
.dl dd { color: var(--text); font-size: 0.9rem; }

/* ── AI 内部評価: スコア/グレードゾーン ── */
.eval-hero {
    display: flex; align-items: center; gap: 1.4rem; flex-wrap: wrap;
    margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 1px solid var(--line);
}
.score-block { text-align: center; }
.score-num {
    font-size: 3rem; font-weight: 900; line-height: 1; letter-spacing: -0.03em;
    transition: color .2s;
}
.score-sub { font-size: 0.68rem; color: var(--muted); margin-top: 4px; letter-spacing: .04em; }

/* Grade バッジ */
.grade-badge {
    display: inline-flex; align-items: center; justify-content: center;
    width: 3.4rem; height: 3.4rem; border-radius: 50%;
    font-size: 1.8rem; font-weight: 900; border: 2.5px solid currentColor;
    transition: color .2s;
}

/* Grade カラークラス（CSS 変数で両テーマ対応） */
.grade-a { color: var(--grade-a); }
.grade-b { color: var(--grade-b); }
.grade-c { color: var(--grade-c); }
.grade-d { color: var(--grade-d); }

/* 採用アクションバナー */
.action-banner {
    display: inline-flex; align-items: center; gap: 0.45rem;
    padding: 0.38rem 1rem; border-radius: 999px; font-size: 0.88rem;
    font-weight: 700; border: 1.5px solid currentColor;
}
.action-a { color: var(--grade-a); background: var(--grade-a-bg); }
.action-b { color: var(--grade-b); background: var(--grade-b-bg); }
.action-c { color: var(--grade-c); background: var(--grade-c-bg); }
.action-d { color: var(--grade-d); background: var(--grade-d-bg); }

/* 推定時給 */
.wage-pill {
    display: inline-block; padding: 0.3rem 0.85rem; border-radius: 999px;
    border: 1px solid var(--line); background: var(--panel-soft);
    color: var(--gold-bright); font-size: 0.92rem;
}

/* ── 採用推奨率 ── */
.recommend-rate {
    font-size: 2rem; font-weight: 900; line-height: 1; letter-spacing: -0.02em;
    transition: color .2s;
}

/* ── スコア内訳バー ── */
.score-bars { margin-top: 0.5rem; }
.eval-tag {
    font-size: 0.7rem; font-weight: 700; padding: 1px 6px;
    border-radius: 999px; border: 1px solid currentColor;
    display: inline-block;
}
.eval-strong { color: var(--grade-a); }
.eval-good   { color: var(--grade-b); }
.eval-mid    { color: var(--gold);    }
.eval-weak   { color: var(--grade-c); }
.eval-check  { color: var(--grade-d); }

.score-bar-pts { font-size: 0.78rem; color: var(--text); font-weight: 600; white-space: nowrap; }

/* ── AI コメント ── */
/* 旧 ai-comment-box は2ブロック構造に移行済み。inline style で実装。 */
.ai-comment-box-unused {
    border-radius: 0.5rem; border: 1px solid var(--line);
    background: rgba(255,255,255,0.03); padding: 0.85rem 1rem;
}
.ai-comment-head { font-size: 0.72rem; font-weight: 700; color: var(--muted); letter-spacing: .06em; margin-bottom: 0.5rem; }
.ai-comment-body { font-size: 0.88rem; line-height: 1.75; color: var(--text); white-space: pre-wrap; }

/* ── タイムライン ── */
.line-stamp {
    display: inline-flex; align-items: center; gap: 0.35rem;
    padding: 0.2rem 0.65rem; border-radius: 999px;
    background: rgba(6,199,85,0.1); border: 1px solid rgba(6,199,85,0.3);
    color: #06c755; font-size: 0.82rem;
}
.no-stamp { color: var(--muted); font-size: 0.88rem; }

/* 会話ログリンク */
.conv-link {
    display: inline-flex; align-items: center; gap: 0.3rem;
    padding: 0.2rem 0.7rem; border-radius: 999px; font-size: 0.82rem;
    border: 1px solid rgba(215,180,106,0.35); color: var(--gold);
    background: rgba(215,180,106,0.06); text-decoration: none;
    transition: background .15s, border-color .15s;
}
.conv-link:hover { background: rgba(215,180,106,0.12); border-color: var(--gold); text-decoration: none; }

/* ── フォーム ── */
.form-row { margin-bottom: 0.85rem; }
.form-row:last-child { margin-bottom: 0; }
.form-label { display: block; font-size: 0.78rem; color: var(--muted); margin-bottom: 0.3rem; }
.form-input, .form-textarea {
    width: 100%; padding: 0.55rem 0.75rem;
    background: #0c0e13; border: 1px solid rgba(215,180,106,0.25); border-radius: 0.4rem;
    color: var(--text); font: inherit; font-size: 0.9rem; transition: border-color 0.15s;
}
.form-input:focus, .form-textarea:focus {
    outline: none; border-color: var(--gold); box-shadow: 0 0 0 2px rgba(215,180,106,0.12);
}
.form-textarea { resize: vertical; min-height: 5.5rem; }
.form-2col { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }
.form-note { font-size: 0.74rem; color: var(--muted); margin-top: 0.25rem; }

/* ── ボタン ── */
.btn-save {
    display: inline-flex; align-items: center; justify-content: center;
    padding: 0.55rem 1.5rem; border: 0; border-radius: 999px;
    background: linear-gradient(135deg, var(--gold-bright), #9a7535);
    color: #0d0a04; font: inherit; font-size: 0.9rem; font-weight: 700;
    cursor: pointer; transition: opacity 0.15s;
}
.btn-save:hover { opacity: 0.85; }

/* ── 通知 ── */
.save-notice {
    display: flex; align-items: center; gap: 0.5rem; padding: 0.55rem 1rem;
    border-radius: 0.4rem; background: rgba(80,214,122,0.1);
    border: 1px solid rgba(80,214,122,0.28); color: var(--green); font-size: 0.88rem;
}
.error-notice {
    display: flex; align-items: center; gap: 0.5rem; padding: 0.55rem 1rem;
    border-radius: 0.4rem; background: rgba(240,184,165,0.1);
    border: 1px solid rgba(240,184,165,0.28); color: var(--danger); font-size: 0.88rem;
}

@media (max-width: 480px) {
    .form-2col { grid-template-columns: 1fr; }
    .eval-hero  { gap: 1rem; }
    .score-bar-row { grid-template-columns: 4rem 1fr auto; }
    .eval-tag { display: none; } /* スマホは評価ラベルを非表示（バー色で判断） */
}
</style>
</head>
<body>

<div class="top-bar">
    <a class="back-link" href="/crew-onboarding/admin.php#applicants">← 応募者一覧</a>
    <span class="top-title">応募者 No.<?= e((string) $id) ?></span>
    <span class="status-chip <?= e($statusCss) ?>"><?= e($statusLabel) ?></span>
    <div class="theme-switcher">
        <button class="theme-btn" data-theme-val="auto"  onclick="setTheme('auto')">自動</button>
        <button class="theme-btn" data-theme-val="light" onclick="setTheme('light')">ライト</button>
        <button class="theme-btn" data-theme-val="dark"  onclick="setTheme('dark')">ダーク</button>
    </div>
</div>

<div class="page">

<?php if ($saved): ?>
    <div class="save-notice">✓ 保存しました</div>
<?php elseif ($error !== ''): ?>
    <div class="error-notice">⚠ <?= e($error) ?></div>
<?php endif; ?>

<!-- ── AI 内部評価 ───────────────────────────────────────── -->
<div class="card">
    <div class="card-head">
        <h2>AI 内部評価</h2>
        <span class="card-head-note">店長の判断材料 / 応募者には非表示</span>
    </div>
    <div class="card-body">

        <!-- スコア / グレード / 採用アクション -->
        <div class="eval-hero">
            <div class="score-block">
                <div class="score-num <?= e($gradeClass) ?>"><?= e((string) ($a['candidate_score'] ?? '—')) ?></div>
                <div class="score-sub">応募者スコア</div>
            </div>
            <div class="score-block">
                <div class="grade-badge <?= e($gradeClass) ?>"><?= e($grade ?: '—') ?></div>
                <div class="score-sub">優先度グレード</div>
            </div>
            <?php if ($action): ?>
            <div class="score-block" style="text-align:left">
                <div class="action-banner <?= e($action['class']) ?>">
                    <?php
                    $actionIcons = ['action-a' => '●', 'action-b' => '◆', 'action-c' => '▲', 'action-d' => '！'];
                    echo $actionIcons[$action['class']] ?? '';
                    ?>&nbsp;<?= e($action['label']) ?>
                </div>
                <div class="score-sub" style="margin-top:6px">採用アクション</div>
            </div>
            <?php endif; ?>
            <?php if ($recommendRate > 0): ?>
            <div class="score-block">
                <div class="recommend-rate <?= e($gradeClass) ?>"><?= $recommendRate ?>%</div>
                <div class="score-sub">AI 採用推奨率</div>
            </div>
            <?php endif; ?>
            <?php if (!empty($a['estimated_wage'])): ?>
            <div class="score-block">
                <div class="wage-pill"><?= e((string) $a['estimated_wage']) ?></div>
                <div class="score-sub">推定保証時給</div>
            </div>
            <?php endif; ?>
        </div>

        <!-- スコア内訳バー（常に表示、データ欠損時はフォールバック） -->
        <div class="score-bars">
            <?php
            $routeLabel = $isExperienced ? '経験者ルート' : '未経験ルート';
            // score_detail が空かどうか（route キーのみ、または完全に空）
            $hasBarData = !empty($scoreDetail) && count(array_filter(
                array_keys($scoreDetail),
                fn ($k) => $k !== 'route'
            )) > 0;
            ?>
            <div style="font-size:0.72rem;color:var(--muted);margin-bottom:0.25rem">
                スコア内訳 — <?= e($routeLabel) ?>
            </div>
<?php if ($hasBarData): ?>
                <?php
                $evalColors = [
                    'eval-strong' => ['bg' => '#50d67a', 'label' => '強い'],
                    'eval-good'   => ['bg' => '#5ab4ff', 'label' => '良い'],
                    'eval-mid'    => ['bg' => '#d7b46a', 'label' => '普通'],
                    'eval-weak'   => ['bg' => '#ffbf66', 'label' => '弱め'],
                    'eval-check'  => ['bg' => '#f0706a', 'label' => '要確認'],
                ];
                ?>
                <?php
                try {
                    foreach ($scoreBarItems as $bar) {
                        $pts  = (int) ($scoreDetail[$bar['key']] ?? 0);
                        $max  = (int) $bar['max'];
                        $pct  = $max > 0 ? (int) min(100, (int) round($pts / $max * 100)) : 0;
                        $eval = twin_eval_label($pct);
                        $ec   = $evalColors[$eval['class']] ?? ['bg' => '#d7b46a', 'label' => '普通'];
                        $wPct = $pct > 0 ? $pct : 2;
                        ?>
                        <div style="margin-bottom:10px">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
                                <span style="font-size:0.8rem;color:var(--muted)">
                                    <span style="font-size:0.68rem;opacity:0.6;margin-right:4px">
                                        <?= (int) ($bar['rank'] ?? 0) ?>
                                    </span><?= e($bar['label']) ?></span>
                                <span style="display:flex;align-items:center;gap:6px">
                                    <span class="eval-tag <?= e($eval['class']) ?>"><?= e($ec['label']) ?></span>
                                    <span style="font-size:0.8rem;font-weight:600;color:var(--text)"><?= $pts ?>pt</span>
                                </span>
                            </div>
                            <div style="width:100%;height:10px;background:rgba(128,128,128,0.2);border-radius:5px;overflow:hidden">
                                <div style="width:<?= $wPct ?>%;height:10px;background:<?= $ec['bg'] ?>;border-radius:5px;display:block"></div>
                            </div>
                        </div>
                        <?php
                    }
                } catch (\Throwable $e) {
                    echo '<div style="font-size:0.82rem;color:var(--danger)">スコア内訳の生成に失敗しました</div>';
                }
                ?>
            <?php else: ?>
                <div style="font-size:0.82rem;color:var(--muted)">スコア内訳データなし（問診未完了または旧レコード）</div>
            <?php endif; ?>
        </div>

    </div>
</div>

<!-- ── AI コメント（強み / 面接確認の2ブロック） -->
<div class="card">
    <div class="card-head">
        <h2>AI アシスタントコメント</h2>
        <span class="card-head-note">ルールベース自動生成 / 参考情報</span>
    </div>
    <div class="card-body">
        <?php if (!empty($aiCards['strengths']) || !empty($aiCards['checks'])): ?>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem">
            <!-- 強みブロック -->
            <div style="background:rgba(80,214,122,0.07);border:1px solid rgba(80,214,122,0.22);border-radius:0.5rem;padding:0.75rem">
                <div style="font-size:0.72rem;font-weight:700;color:var(--grade-a);letter-spacing:.06em;margin-bottom:0.5rem">
                    🟢 強み
                </div>
                <?php if (!empty($aiCards['strengths'])): ?>
                    <?php foreach ($aiCards['strengths'] as $s): ?>
                        <div style="font-size:0.82rem;color:var(--text);padding:2px 0;display:flex;gap:6px">
                            <span style="color:var(--grade-a);flex-shrink:0">✔</span>
                            <span><?= e($s) ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="font-size:0.78rem;color:var(--muted)">強みなし</div>
                <?php endif; ?>
            </div>
            <!-- 面接確認ブロック -->
            <div style="background:rgba(215,180,106,0.07);border:1px solid rgba(215,180,106,0.22);border-radius:0.5rem;padding:0.75rem">
                <div style="font-size:0.72rem;font-weight:700;color:var(--gold);letter-spacing:.06em;margin-bottom:0.5rem">
                    🟡 面接確認
                </div>
                <?php if (!empty($aiCards['checks'])): ?>
                    <?php foreach ($aiCards['checks'] as $c): ?>
                        <div style="font-size:0.82rem;color:var(--text);padding:2px 0;display:flex;gap:6px">
                            <span style="color:var(--gold);flex-shrink:0">・</span>
                            <span><?= e($c) ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="font-size:0.78rem;color:var(--muted)">確認事項なし</div>
                <?php endif; ?>
            </div>
        </div>
        <?php else: ?>
            <div style="font-size:0.82rem;color:var(--muted)">コメント生成データなし（問診未完了または採点前）</div>
        <?php endif; ?>
    </div>
</div>

<!-- ── 問診結果 ───────────────────────────────────────────── -->
<div class="card">
    <div class="card-head"><h2>問診結果</h2></div>
    <div class="card-body">
        <dl class="dl">
            <dt>経験</dt>
            <dd><?= label_map($a['experience'], $expMap) ?></dd>

            <dt>前職業種</dt>
            <dd><?= label_map($a['genre'], $genreMap) ?></dd>

            <dt>前職時給</dt>
            <dd><?= ($a['prev_hourly'] !== null && $a['prev_hourly'] !== '') ? e((string) $a['prev_hourly']) . '円' : '—' ?></dd>

            <dt>指名実績</dt>
            <dd><?= label_map($a['referrals'], $refMap) ?></dd>

            <dt>今の呼客</dt>
            <dd><?= label_map($a['bring_now'], $bringMap) ?></dd>

            <dt>体験日呼客</dt>
            <dd><?= label_map($a['bring_trial'], $bringMap) ?></dd>

            <dt>出勤希望</dt>
            <dd><?= label_map($a['days_per_week'], $daysMap) ?></dd>

            <dt>飲酒</dt>
            <dd><?= label_map($a['alcohol'], $alcMap) ?></dd>
        </dl>
    </div>
</div>

<!-- ── 応募タイムライン ───────────────────────────────────── -->
<div class="card">
    <div class="card-head"><h2>応募タイムライン</h2></div>
    <div class="card-body">
        <dl class="dl">
            <dt>問診完了</dt>
            <dd><?= fmt_datetime($a['completed_at']) ?></dd>

            <dt>LINE タップ</dt>
            <dd>
                <?php if (!empty($a['line_applied_at'])): ?>
                    <span class="line-stamp">✓ <?= fmt_datetime($a['line_applied_at']) ?></span>
                <?php else: ?>
                    <span class="no-stamp">未</span>
                <?php endif; ?>
            </dd>

            <?php if ($session): ?>
            <dt>会話開始</dt>
            <dd><?= fmt_datetime($session['started_at'] ?? null) ?></dd>

            <dt>会話終了</dt>
            <dd><?= fmt_datetime($session['ended_at'] ?? null) ?></dd>

            <dt>会話数</dt>
            <dd>
                <?php if ($msgCount !== null && $msgCount > 0): ?>
                    <?php
                    // 会話ログは admin.php#messages タブにセッションフィルター付きで遷移
                    // TODO: admin.php 側にセッション単位のログビューが実装されたらURLを更新
                    $convUrl = '/crew-onboarding/admin.php#messages';
                    ?>
                    <a class="conv-link" href="<?= e($convUrl) ?>" title="管理画面のメッセージログを見る">
                        会話を見る（<?= $msgCount ?>件）
                    </a>
                <?php elseif ($session['message_count'] !== null): ?>
                    <?= e((string) $session['message_count']) ?> 往復
                <?php else: ?>
                    —
                <?php endif; ?>
            </dd>
            <?php endif; ?>

            <?php if (!empty($a['interview_at'])): ?>
            <dt>面接日</dt>
            <dd><?= fmt_datetime($a['interview_at']) ?></dd>
            <?php endif; ?>

            <?php if ($isHired): ?>
            <dt>採用日</dt>
            <dd style="color:var(--green)"><?= fmt_datetime($a['hired_at']) ?></dd>
            <?php endif; ?>

            <?php if ($isRejected): ?>
            <dt>不採用日</dt>
            <dd style="color:var(--danger)"><?= fmt_datetime($a['rejected_at']) ?></dd>
            <?php endif; ?>

            <?php if (!empty($a['hired_employee_id'])): ?>
            <dt>WBSS 社員ID</dt>
            <dd style="color:var(--gold-bright);font-weight:700"><?= e((string) $a['hired_employee_id']) ?></dd>
            <?php endif; ?>
        </dl>
    </div>
</div>

<!-- ── 店長記録フォーム ───────────────────────────────────── -->
<div class="card">
    <div class="card-head"><h2>店長記録</h2></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

            <div class="form-row">
                <label class="form-label" for="memo">店長メモ</label>
                <textarea class="form-textarea" id="memo" name="memo"
                    placeholder="面接印象・連絡内容・懸念点など"><?= e((string) ($a['memo'] ?? '')) ?></textarea>
            </div>

            <div class="form-2col form-row">
                <div>
                    <label class="form-label" for="interview_at">面接日</label>
                    <input class="form-input" type="datetime-local" id="interview_at" name="interview_at"
                        value="<?= to_datetime_local($a['interview_at'] ?? null) ?>">
                </div>
                <div>
                    <label class="form-label" for="hired_employee_id">WBSS 社員ID</label>
                    <input class="form-input" type="number" id="hired_employee_id" name="hired_employee_id"
                        min="1" placeholder="採用後に入力"
                        value="<?= e((string) ($a['hired_employee_id'] ?? '')) ?>">
                </div>
            </div>

            <div class="form-2col form-row">
                <div>
                    <label class="form-label" for="hired_at">採用日</label>
                    <input class="form-input" type="datetime-local" id="hired_at" name="hired_at"
                        value="<?= to_datetime_local($a['hired_at'] ?? null) ?>">
                    <p class="form-note">入力すると不採用日はクリアされます</p>
                </div>
                <div>
                    <label class="form-label" for="rejected_at">不採用日</label>
                    <input class="form-input" type="datetime-local" id="rejected_at" name="rejected_at"
                        value="<?= to_datetime_local($a['rejected_at'] ?? null) ?>">
                    <p class="form-note">採用日と同時には保存できません</p>
                </div>
            </div>

            <!-- ── 採用結果 ── -->
            <div style="margin-top:1.5rem;padding-top:1.1rem;border-top:1px solid var(--line)">
                <div style="font-size:0.72rem;font-weight:700;color:var(--muted);letter-spacing:.07em;text-transform:uppercase;margin-bottom:0.75rem">
                    採用結果（入店後に記録）
                </div>

                <div class="form-2col form-row">
                    <div>
                        <label class="form-label" for="outcome_status">採用結果</label>
                        <select class="form-input" id="outcome_status" name="outcome_status">
                            <option value="">— 未選択 —</option>
                            <option value="hired"    <?= ($a['outcome_status'] ?? '') === 'hired'    ? 'selected' : '' ?>>採用</option>
                            <option value="declined" <?= ($a['outcome_status'] ?? '') === 'declined' ? 'selected' : '' ?>>辞退</option>
                            <option value="rejected" <?= ($a['outcome_status'] ?? '') === 'rejected' ? 'selected' : '' ?>>不採用</option>
                            <option value="pending"  <?= ($a['outcome_status'] ?? '') === 'pending'  ? 'selected' : '' ?>>保留</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label" for="outcome_checked_at">結果確認日</label>
                        <input class="form-input" type="datetime-local" id="outcome_checked_at" name="outcome_checked_at"
                            value="<?= to_datetime_local($a['outcome_checked_at'] ?? null) ?>">
                    </div>
                </div>

                <div class="form-2col form-row">
                    <div>
                        <label class="form-label" for="outcome_sales_rank">3か月後 売上ランク</label>
                        <select class="form-input" id="outcome_sales_rank" name="outcome_sales_rank">
                            <option value="">— 未記録 —</option>
                            <?php foreach (['S', 'A', 'B', 'C', 'D'] as $r): ?>
                                <option value="<?= $r ?>" <?= ($a['outcome_sales_rank'] ?? '') === $r ? 'selected' : '' ?>><?= $r ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label" for="outcome_retention">定着状況</label>
                        <select class="form-input" id="outcome_retention" name="outcome_retention">
                            <option value="">— 未記録 —</option>
                            <option value="active"  <?= ($a['outcome_retention'] ?? '') === 'active'  ? 'selected' : '' ?>>継続</option>
                            <option value="left"    <?= ($a['outcome_retention'] ?? '') === 'left'    ? 'selected' : '' ?>>退店</option>
                            <option value="unknown" <?= ($a['outcome_retention'] ?? '') === 'unknown' ? 'selected' : '' ?>>未確認</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <label class="form-label" for="outcome_bring">呼客実績</label>
                    <select class="form-input" id="outcome_bring" name="outcome_bring" style="max-width:16rem">
                        <option value="">— 未記録 —</option>
                        <option value="high"    <?= ($a['outcome_bring'] ?? '') === 'high'    ? 'selected' : '' ?>>多い</option>
                        <option value="normal"  <?= ($a['outcome_bring'] ?? '') === 'normal'  ? 'selected' : '' ?>>普通</option>
                        <option value="low"     <?= ($a['outcome_bring'] ?? '') === 'low'     ? 'selected' : '' ?>>少ない</option>
                        <option value="unknown" <?= ($a['outcome_bring'] ?? '') === 'unknown' ? 'selected' : '' ?>>未確認</option>
                    </select>
                </div>

                <div class="form-row">
                    <label class="form-label" for="outcome_note">結果メモ（例外理由・特記事項）</label>
                    <textarea class="form-textarea" id="outcome_note" name="outcome_note"
                        rows="2"
                        placeholder="例: D評価だったが店長の直感で採用→売上Aランク。理由は○○"><?= e((string) ($a['outcome_note'] ?? '')) ?></textarea>
                </div>
            </div>

            <div class="form-row" style="margin-top:1.1rem">
                <button type="submit" class="btn-save">保存する</button>
            </div>
        </form>
    </div>
</div>

</div><!-- /page -->

<script>
// 採用日と不採用日の排他制御（クライアント側でもリアルタイムにクリア）
const hiredInput    = document.getElementById('hired_at');
const rejectedInput = document.getElementById('rejected_at');

hiredInput.addEventListener('change', () => {
    if (hiredInput.value !== '') rejectedInput.value = '';
});

rejectedInput.addEventListener('change', () => {
    if (rejectedInput.value !== '') hiredInput.value = '';
});

// テーマ切替（admin.php と共通ロジック・localStorage キーも共通）
function applyTheme(theme) {
    const html = document.documentElement;
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    html.setAttribute('data-theme', theme === 'auto' ? (prefersDark ? 'dark' : 'light') : theme);
    document.querySelectorAll('.theme-btn').forEach(btn => {
        btn.classList.toggle('is-active', btn.dataset.themeVal === theme);
    });
}
function setTheme(theme) {
    localStorage.setItem('twin_admin_theme', theme);
    applyTheme(theme);
}
(function() {
    const saved = localStorage.getItem('twin_admin_theme') || 'auto';
    applyTheme(saved);
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function() {
        if ((localStorage.getItem('twin_admin_theme') || 'auto') === 'auto') applyTheme('auto');
    });
})();
</script>
</body>
</html>
