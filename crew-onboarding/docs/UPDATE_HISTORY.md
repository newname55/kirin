# Project TWIN Update History

---

## 2026-06-28（更新）

### 追記: Project TWIN データ哲学の確立

本日の議論後半で、Project TWIN のデータ設計哲学が言語化された。

**「判断の過程」と「その結果」を保存する**

「優れた判断を保存する」ではない。
Grade=A を保存するだけでは将来の AI に理由が分からない。
会話・カルテ・店長確認・店長メモ・採用後結果まで全プロセスを残すことで、
「なぜその判断が当たったのか」を未来の AI が学習できる。

**採用データの三層構造**

| レイヤー | 内容 | 活用 |
|---|---|---|
| Layer 1（構造化） | days_reason / motivation / flag_for_interview など | 今すぐ SQL で集計可能 |
| Layer 2（人間の言葉） | interview_additional_notes（笑顔が自然・返事が早い…） | 50件以上で Embedding 活用 |
| Layer 3（結果） | outcome_status / sales_rank / retention（実装済み） | 3か月後の教師データ |

**バージョン管理の追加（採用カルテ JSON）**

- `interview_note_version` — 店長メモ形式の変更追跡
- `slot_schema_version` — スロット定義数の変更追跡（v1=7スロット）
- `ai_model_version` — 採用カルテ生成モデルの記録（GPT-4o → GPT-5 等）

**2つの学習モデルの発見**

①〜④ 応募者データ → 「誰を採るべきか」を学習
⑤    店長データ   → 「優秀な店長は何を見ているか」を学習

`flag_for_interview`（AI提案）と `interview_confirmed`（店長の実確認）と
`interview_additional_notes`（店長追加メモ）の差分が「AI改善 TODO」になる。

**AI・店長一致率（将来の管理画面分析）**

- 提案採用率 — AI提案のうち店長が実際に確認した割合
- 見落とし率 — 店長だけが確認した項目の割合
- 不要提案率 — AI は提案したが店長は確認しなかった割合

**Project TWIN の再定義**

> 現場で生まれる知恵と判断を、100年後の AI でも学べる形で残すためのプロジェクト。

「100年」は象徴的な数字。伝えたいのは「今の AI だけを相手にして設計しない」という姿勢。

**変更ファイル**

- `docs/DYNAMIC_INTERVIEW.md` — 三層構造・バージョン3フィールド・AI一致率3指標・2モデル論・設計思想の最終節を追加
- `docs/PROJECT_VISION.md` — 「判断の過程と結果を保存する」「知識保存プロジェクト」「100年後のAIでも学べる形で残す」セクションを追加・更新

---

## 2026-06-28

### 概要

実装は行わず、設計・ドキュメント整理のみ。
Dynamic Interview Engine の構想を設計書として文書化し、
ROADMAP・ARCHITECTURE・PROJECT_VISION の3ドキュメントを新規作成した。

---

### 追加機能

なし（設計フェーズ）

---

### 設計・ドキュメント作業

- **DYNAMIC_INTERVIEW.md 新規作成** — Dynamic Interview Engine の設計メモ
  - 現行 step machine を維持しつつ後段に AI 深掘りフェーズを追加する設計
  - 7スロット定義（days_reason / curfew_reason / motivation / anxiety / future_plan / family_situation / boyfriend_situation）
  - 会話設計の注意点（1回1問・最大3往復・二度聞かない・尋問感を出さない）
  - 採用カルテ JSON 案・画面表示案・テスト方針
  - 5段階の実装ロードマップ（Step 1 = 質問提案のみ、Step 5 = 学習サイクル組み込み）

- **ROADMAP.md 更新** — Phase 2 次タスクに Dynamic Interview Engine Step 1 を追加
  - 応募者との会話は変更しない。admin_applicant.php への「次に聞くといい質問」提案のみ
  - DB 変更なし・既存 step machine 変更なし

- **ARCHITECTURE.md 更新** — AI採用サブシステムに将来拡張として Dynamic Interview Engine を1段落追記

- **PROJECT_VISION.md 新規作成** — Project TWIN の理念・目的・長期ビジョン文書
  - Mission / Vision / Phase1〜5 / 開発方針5項目 / WBSS・LENS連携 / 10年後目標
  - 技術仕様を含まない「憲章」として位置付け

- **ARCHITECTURE.md 新規作成**（昨日コミット分）

---

### 技術的な学び

**Dynamic Interview Engine の設計で明らかになったこと:**
- 「AIに自由会話させる」より「スロット充填制御」の方が壊れにくい。全スロットを埋めようとしない設計が重要
- 要約ステップ（会話 → 構造化 JSON）は最も精度が不安定な箇所。テストを先行させる必要がある
- 採用カルテを「主役」にし、スコアを「派生情報」とする順序の逆転が Phase 2 の核心的な設計変更
- 応募者体験（尋問感を出さない・1回1問・締めの自然さ）は技術実装と同等の重要度を持つ

---

### 変更ファイル

- `crew-onboarding/docs/DYNAMIC_INTERVIEW.md` — 新規作成
- `crew-onboarding/docs/ROADMAP.md` — Dynamic Interview Engine Step 1 を最優先タスクに追加
- `crew-onboarding/docs/ARCHITECTURE.md` — AI採用サブシステムに将来拡張段落を追記
- `crew-onboarding/docs/PROJECT_VISION.md` — 新規作成

---

### DB変更

なし

---

### テスト結果

なし（設計フェーズのため）

---

### 今後の予定（Dynamic Interview Engine 実装順序）

1. **Step 1**: 基本5問完了後にAIが「深掘り候補質問」を1つ生成 → admin_applicant.php に表示
2. **Step 2**: 応募者との AI 深掘り会話（スロット充填制御）
3. **Step 3**: 採用カルテ JSON 生成 → admin_applicant.php に採用カルテセクション追加
4. **Step 4**: 採用カルテ → スコア反映
5. **Step 5**: 採用カルテ + outcome_* で学習サイクル完成

---

### メモ

- Dynamic Interview Engine の構想は「店長が経験で自然にやっていること」をAIで再現するもの。アンケート → 情報収集エンジンへの転換が本質
- `flag_for_interview`（面接で確認すべき点のリスト）は採用カルテの中で最も即効性が高い。Step 3 実装後すぐに店長が使える
- 「育児中で週3希望の人は定着率が高い」などのパターン発見は、夜業界に存在しないデータになる。これが半年〜1年後の Project TWIN の最大の価値

---

## 2026-06-27

### 概要

crew-onboarding の採用管理UIを大幅強化。スコア内訳バーの不表示バグ（PHP strict_types + TypeError）を根本解決し、
admin_applicant.php に採用推奨率・AIコメント2ブロック・採用結果追跡フォームを実装。
DB に outcome_* 6カラムを追加（migration 010）し、「AI評価 → 採用 → 実結果 → 分析」のフルループを構築した。
応募者一覧には「3か月後結果 未入力」アラートを追加し、入力漏れを防ぐ仕組みを整えた。

---

### 追加機能

- **AI 採用推奨率表示** — Grade別ルールベース（A=95% / B=80% / C=60% / D=30%）を eval-hero に追加
- **AIコメント 2ブロック化** — `twin_recruit_ai_cards()` で「🟢 強み」「🟡 面接確認」の色付き2カード構成に変更
- **スコア内訳 優先順表示** — 店長が重視する順に並べ替え（未経験: 呼客力→出勤→飲酒→経験ボーナス / 経験者: 今の呼客→体験日→指名→出勤→前職時給）。バー左端に番号（①②③）を表示
- **採用結果追跡フォーム** — admin_applicant.php 店長記録欄に採用結果セクションを追加。6フィールド: 採用結果 / 3か月後売上ランク / 定着状況 / 呼客実績 / 結果確認日 / 結果メモ
- **「3か月後結果 未入力」アラート** — admin.php 応募者一覧で hired_at から90日以上経過・outcome未入力の行にバッジ表示 + 上部に件数サマリー
- **OUTCOME_ANALYSIS_SQL.md 作成** — Grade別採用率・定着率・売上分布・D評価成功/A評価退店外れケース等8本のSQL集

---

### 修正内容

**スコア内訳バーが DOM に出力されない問題（根本原因）**

- **原因**: `declare(strict_types=1)` 環境で `round()` が `float` を返し、`twin_eval_label(int $pct)` に渡すと TypeError が発生。PHP のエラー表示が OFF だったため画面には何も表示されず、foreach が途中で止まっていた
- **症状の経緯**: `hasBarData=true` / `scoreBarItems count=5` はデバッグ変数で確認済み。foreach 内の単純な赤枠デバッグ div は表示されたが、正式バー HTML になると表示されなかった。CSS 問題と誤認していたが DOM にも存在しなかったことが判明
- **修正**: `$pct = (int) min(100, (int) round($pts / $max * 100))` と double キャストで float → int を確実に変換。foreach 全体を `try { } catch (\Throwable $e)` で保護し、エラー時は「スコア内訳の生成に失敗しました」を表示してページは継続

**スコア内訳バーの CSS 依存を排除**

- **原因**: `.score-bar-row.eval-* .score-bar-fill { background }` の CSS セレクタが `[data-theme]` との特異性競合で効かないケースがあった
- **修正**: バー色を `style="background:#hexcode"` で直接指定し CSS クラス依存を廃止。`$evalColors` 配列でクラス名 → カラーコードに変換

---

### 技術的な学び

- **`declare(strict_types=1)` + `round()` の罠**: PHP の `round()` は常に `float` を返す。`strict_types=1` のファイルで `int` 型引数の関数に渡すと TypeError になる。`(int) round(...)` とキャストが必須。エラー表示 OFF の本番設定では silent に止まるため発見が遅れる
- **デバッグ手順の教訓**: バーが見えない → CSS問題と思いがち。実際は DOM に存在しなかった。`position:fixed` の絶対確実なデバッグ要素で「PHP が実行しているか」を分離して確認するのが正解
- **採用結果フィールドはホワイトリスト検証を必ず行う**: `outcome_status` 等の選択肢は `in_array()` でホワイトリストチェック後に DB 保存。ユーザー入力値を直接 SQL に渡さない

---

### 変更ファイル

- `crew-onboarding/public/admin_applicant.php` — バー修正・推奨率・AIコメント・採用結果フォーム・POST処理
- `crew-onboarding/public/admin.php` — 「3か月後結果 未入力」アラート・applicantRows クエリ拡張
- `crew-onboarding/database/migrations/010_add_outcome_columns.sql` — 新規作成
- `crew-onboarding/docs/OUTCOME_ANALYSIS_SQL.md` — 新規作成

---

### DB変更

**migration 010** — `crew_applicants` テーブルへ 6カラム追加（Raspi4 twin_crew_dev 適用済み）

| カラム | 型 | 用途 |
|---|---|---|
| `outcome_status` | VARCHAR(16) | hired / declined / rejected / pending |
| `outcome_sales_rank` | VARCHAR(4) | S / A / B / C / D |
| `outcome_retention` | VARCHAR(16) | active / left / unknown |
| `outcome_bring` | VARCHAR(16) | high / normal / low / unknown |
| `outcome_checked_at` | DATETIME | 結果確認日（いつ時点の評価か） |
| `outcome_note` | TEXT | 店長の結果メモ（例外理由・特記事項） |

**Raspi5 本番への適用コマンド（次回デプロイ時）**
```bash
sudo mysql -u root twin < /var/www/html/twin/crew-onboarding/database/migrations/010_add_outcome_columns.sql
```

---

### テスト結果

- `php -l admin_applicant.php` — OK
- `php -l admin.php` — OK
- DB クエリ動作確認（Raspi4）— OK
- ライト/ダークテーマ両方で表示確認 — OK

---

### 今後の予定

1. **Raspi5 本番に migration 010 を適用**（次回本番デプロイ時）
2. **採用結果データの蓄積**（20件以上で分析開始）
3. **OUTCOME_ANALYSIS_SQL.md のクエリを管理画面に組み込む**（グラフ表示）
4. **Grade別成功率の可視化**（半年後を目安にダッシュボード化）
5. **parser テスト・ペルソナテストの定期実行習慣化**（engine 変更後は必ず実行）

---

### メモ

- 「AI評価 → 採用 → 実結果 → 分析 → AI改善」のフルループが DB 上で追えるようになった。これが Project TWIN Phase2（AI採用）の核心
- D評価で成功したケースの `outcome_note` に理由を書き溜めることが、将来の採点アルゴリズム改善の一次資料になる
- 採用推奨率（95%/80%/60%/30%）は現在ルールベース。実結果データが蓄積されたら実測値に置き換えるのが次フェーズ
- スコア内訳バーの順序は「店長が最初に見たい情報順」にした。呼客力が1番なのは初日の売上に最直結するため

---

## 2026-06-25

### v1.1.4 — 「明日の出勤」intent対応

**概要**
「明日の出勤は？」「明日誰が出てる？」「明日は何人？」が `cast_schedule` に誤判定される問題を修正し、
「明日の出勤人数」をWBSS APIの日付指定で取得できるようにした。

#### 修正内容（`app/engines/rule_engine.php`）

- **`twin_extract_cast_name` 修正**
  - `'明日'`・`'あした'`・`'あす'` をキャスト名から除去する語句リストに追加
  - 除外パターン regex にも `明日|あした|あす` を追加
  - castName が1文字以下の場合は空を返す（`'子'` などの汎用残骸を除去）

- **`twin_detect_intent` 修正**
  - `attendance` キーワードに `'明日の出勤'`・`'明日誰'`・`'明日何人'`・`'明日出勤'`・`'明日の予定'`・`'明日の女の子'`・`'今日誰'`・`'今日は何人'` を追加
  - 「明日 + 出勤系ワード」をキャスト名未検出の場合に `attendance` として早期リターンする事前判定を追加（`cast_schedule` ループより先に評価）

- **`twin_detect_attendance_date` / `twin_resolve_attendance_date` 追加**
  - メッセージから `'today'` / `'tomorrow'` を判定し、`YYYY-MM-DD` 形式の日付文字列に変換するヘルパー

- **`twin_rule_attendance_response` 修正**
  - `$attendanceDate` パラメータを追加（`'today'` / `'tomorrow'`）
  - WBSS API に翌日日付 `+1 day` を渡して明日の出勤を取得
  - 返答文を `本日` / `明日` で分岐
  - GA4イベント値に `_today` / `_tomorrow` サフィックスを追加

- **`twin_rule_cast_schedule_response` 修正**
  - 「明日かなさんは？」など明日指定の個別キャスト確認に対応
  - WBSS API に翌日日付を渡し、返答文を `本日` / `明日` で分岐

#### WBSS API 対応状況
既存クライアント（`twin_wbss_fetch_attendance` / `twin_wbss_fetch_cast_schedule`）は
`?date=YYYY-MM-DD` パラメータを既に実装済みのため、クライアント側の変更は不要。

#### テスト結果
intent 判定テスト 16件すべて PASS（今日・明日の出勤/キャスト全パターン）。

## 2026-06-23

### v1.0 Release Candidate — セキュリティ・プライバシー・複数店舗対応の基盤整備

**概要**
v1.0公開前監査を実施し、セキュリティ強化・個人情報保護・複数店舗対応の基盤を整備した。コードレビューを経て Release Candidate と判定。残作業はデプロイおよび本番環境設定のみ。

#### セキュリティ強化

- **セッションCookie強化**: `HttpOnly` / `SameSite=Lax` / HTTPS時 `Secure` フラグを設定。
- **管理画面に `no-store` ヘッダー追加**: ブラウザキャッシュへの管理画面情報の残留を防止。
- **本番環境認証対応**: `TWIN_REQUIRE_SECURE_ADMIN=1` 設定時に `ADMIN_USERNAME` / `ADMIN_PASSWORD_HASH` 環境変数が未設定ならログイン不可にする仕組みを追加（`app/config.php` / `app/admin_common.php`）。

#### 個人情報保護

- **`app/privacy.php` 新規追加**: 保存前・OpenAI送信前・エクスポート時に個人情報をマスクする仕組みを実装。
  - 対象: 電話番号 / メールアドレス / LINE ID / 住所 / 決済番号らしき連番
  - 保存例: `090-1234-5678` → `[電話番号]` / `test@example.com` → `[メールアドレス]`
- **住所マスク誤検出修正**: `1234567円` や `予約番号1234567` が住所判定されていた問題を修正。郵便番号マスクに `〒` を必須化し、後続取り込み範囲を 80 → 40 字に縮小。

#### 複数店舗対応（store_key）

- **store_key 保存対応**: `chat_sessions` / `chat_messages` / `event_logs` / `conversion_events` の 4 テーブルへ `store_key` カラムを追加。未指定時は `seika` をデフォルト利用。
- **カラム存在確認付き実装** (`twin_db_column_exists()`): migration 未適用環境でもエラーにならない設計。
- **`database/migrations/009_add_store_key_to_logs.sql` 追加**: store_key カラム追加・既存データの backfill・べき等設計（再実行可能）。
- **`database/schema.sql` 更新**: 現行 DB 構成へ同期し、新規環境で schema.sql から再構築可能な状態に整理。

#### コードレビュー

- `docs/TWIN_V1_CHECKLIST.md` / `docs/TWIN_V1_AUDIT.md` 作成（Codex 監査）。
- Claude Code レビューで Critical 1 件（管理者認証）・Medium 1 件（住所マスク）を検出、対応済み。

#### Raspi4 開発環境検証

- migration 009 適用確認: 4 テーブルへの store_key カラム追加を実 DB で確認。
- 実 DB で store_key = `seika` 保存 / 電話番号・メールの `[電話番号]` `[メールアドレス]` マスク保存を確認。

#### 残タスク（デプロイ・本番設定）

- Xserver (creole) の `config.local.php` 作成・本番管理画面認証設定（C-1 対応）
- CREOLE 環境への migration 009 適用
- CSV / analysis_json エクスポートの最終確認

## 2026-06-23

### v0.9.3 - Multi-store Admin Switcher and Store-specific AI Character Settings

- **管理画面店舗切替を追加**: `public/admin.php` に `TWIN SEIKA` / `TWIN CREOLE` のピル型スイッチャーを追加。選択店舗は `twin_admin_store_key` セッションで保持する。
- **AIキャラクター設定を店舗別化**: `ai_character_settings` を `store_key` 単位で保存するようにし、AI名・役職・あいさつ・キャラクター画像を店舗ごとに切り替えられるようにした。
- **システム設定をサブタブ化**: `AIキャラクター`、`応答モード（Dev）`、`公開前チェック`、`AI利用額（Dev）`、`OpenAI診断（Dev）` を追加。初期表示は AIキャラクターに変更。
- **管理画面の運用性改善**: 編集フォームを折りたたみ化し、現在状態をカードで確認できるようにした。
- **店舗ナレッジ表示バグ修正**: `store_key=creole` でも管理画面の店舗ナレッジが Club 星華 を表示していた問題を修正。原因は `admin.php` が `$seikaKnowledge` を参照していたためで、`$adminStoreConfig` + `twin_store_value()` 参照へ変更した。影響範囲は管理画面の店舗ナレッジ表示のみで、チャット応答への影響はなし。
- **WBSS連携の対応店舗を追加**: `public/api/twin/_auth.php` の `TWIN_SUPPORTED_STORES` を `['seika']` から `['seika', 'creole']` に変更。
- **WBSS API確認済み**: `seika attendance OK`、`creole attendance OK`、`unsupported_store OK`、`unauthorized OK` を確認。
- **Git運用整理**: Project TWIN は店舗サイト本体とは別 Git 管理とし、`public_html/twin/` 以下を `project_twin` リポジトリとして扱う方針に整理。
- **cre-ship 保護対応**: クレオール本体デプロイの `rsync --delete` で `public_html/twin` が削除されないよう、`.zshrc` の rsync 設定に `--exclude='twin/'` を追加。
- **Xserver展開準備**: クレオール側は `https://xn--kckj5pc5f.club/` 配下の `/public_html/twin` に設置予定。DB は `xs110262_twin`、`store_key` は `creole`。

### v0.9.2 - Store Key Foundation

- **store_key 基盤を追加**: `app/store.php` と `app/knowledge/stores.php` を追加。
- **店舗判定ヘルパー追加**: `twin_current_store_key()`、`twin_store_config()`、`twin_store_value()` を追加。
- **store_key 取得優先順位**: `TWIN_STORE_KEY` 環境変数、`config.local.php`、デフォルト `seika` の順で解決する。
- **店舗情報を一元管理**: `stores.php` に `seika` / `creole` の店舗設定を集約。管理項目は `store_name`、`display_name`、`area`、`address`、`business_hours`、`closed_days`、`line_url`、`instagram_url`、`price_url`、`price_summary`、`default_ai_name`、`default_role_label`、`default_greeting`。
- **OpenAI engine を store_key 対応**: `app/engines/openai_engine.php` が現在店舗のナレッジと AIキャラクター設定を使うようにし、星華固定参照を廃止。
- **Rule engine を store_key 対応**: `app/engines/rule_engine.php` の料金・営業時間・定休日・場所・LINE・Instagram 応答を現在店舗の設定から返すようにした。
- **フロントを store_key 対応**: `public/index.php` で店舗名、AI名、役職、あいさつ、LINE URL、Instagram URL、料金URLを店舗ごとに切り替えるようにした。
- **複数店舗移行の対象店舗**: 初期対象は `seika` と `creole`。

## 2026-06-22

### v0.9.1 - AIキャラクター設定基盤

- **`ai_character_settings` テーブル追加**: AI名・役職・初回あいさつ・キャラクター画像・ロゴを store_id 単位で管理。migration: `008_create_ai_character_settings.sql`
- **`app/ai_character_settings.php` 追加**: ロード/保存/画像アップロード検証ヘルパー。is_active=1 に切り替えると同一 store_id の他レコードが自動で 0 に落ちる
- **`public/index.php`**: アクティブ設定が有効な場合に AI名・役職・初回あいさつ・キャラクター画像を反映。DB未適用時はデフォルト値（TWIN SEIKA）で動作継続
- **`app/engines/openai_engine.php`**: system prompt にキャラクター設定の AI名・役職を注入（公式情報・WBSS 優先の制約は維持）
- **管理画面 「AIキャラクター設定」セクション追加**: AI名・役職・あいさつ文の編集、画像アップロード（2MB・JPEG/PNG/GIF/WebP）、有効化チェックボックス、設定履歴一覧
- **`public/uploads/characters/`**: 画像アップロード先ディレクトリ（`.gitkeep` で追跡）
- DB migration が必要: `mysql -u twin_user -p twin < database/migrations/008_create_ai_character_settings.sql`
- `APP_VERSION` を `0.9.1` に更新

### v0.9.0 - Release (v0.8.7〜v0.8.9 統合リリース)

- v0.8.7: TWIN健康診断をシステム品質スコアに再定義（LINE CTR をスコアから分離）
- v0.8.8: チャット画面下部に固定CTAバー追加（LINE予約/料金/Instagram 常時表示）
- v0.8.9: 固定CTA計測修正・質問ランキングフィルタ改善・AI提案番号修正
- `APP_VERSION` を `0.9.0` に更新

### v0.8.9 - Fix Fixed CTA Metrics and Admin Cleanup

- **固定CTA表示ログ追加**: ページロード時に `cta_view` + `fixed_line/fixed_price/fixed_instagram` を1セッション1回だけ保存（sessionStorage でデdup）。固定CTAのCTRが正しく計算されるようになった
- **質問ランキングフィルタに「v0.8.6以降」追加**: intent精度修正が入った 2026-06-22 以降のログのみを表示するフィルタを追加（`TWIN_V086_SINCE` 定数で管理）。全期間・「v0.8.6以降」フィルタ時に注釈を表示
- **質問ランキングのデフォルト期間を「過去24時間」に変更**（修正前ログが引っかかりにくくなる）
- **AI改善提案プロンプト番号重複修正**: 23/24 が重複していたのを 27/28 に振り直し
- **改善TOP3のWBSSエラー判定を24hのみに変更**: `twin_build_improvement_suggestions` に渡す `wbss_error_count` を全期間から直近24hに変更。古い開発エラーだけで「WBSSエラーを確認する」が改善TOP3に出なくなった
- DB migration 不要
- `APP_VERSION` を `0.8.9` に更新

### v0.8.8 - Fixed CTA Bar (常時表示CTAバー)

- **チャット画面下部に固定CTAバーを追加**: LINE予約 / 料金 / Instagram の3ボタンを常時表示。`position: fixed` でスクロールに追随
- **入力欄との重なり防止**: `.page-shell` に `padding-bottom: 5rem` を追加し、固定バーがtextareaを隠さない
- **デザイン**: 黒×金テーマに準拠。LINE予約を2fr（最大幅）で目立たせ、料金・Instagramを控えめに
- **trial終了後の強調**: 無料体験終了時に `is-finished` クラスを付与。LINE予約ボタンが発光エフェクト + 文言「LINEで予約」に変化
- **固定CTAクリックを event_logs に保存**: `cta_click` + `event_value = fixed_line / fixed_price / fixed_instagram` で通常CTAと区別
- **管理画面 来店導線分析**: ctaMap に固定CTA行を追加（LINE予約 通常CTA / LINE予約 固定CTA / 料金 通常CTA / 料金 固定CTA / Instagram 通常CTA / Instagram 固定CTA）
- **PC幅対応**: `@media (min-width: 42rem)` で固定バー幅を 36rem に拡大し、チャットカード幅に揃える
- DB migration 不要（既存 `event_logs` に `fixed_line` 等の event_value を追記するだけ）
- `APP_VERSION` を `0.8.8` に更新

### v0.8.7 - Health Score: Separate System Quality from LINE CTR KPI

### v0.8.6 - Dashboard Improvements (LINE CTA / 24h集計 / intent追加)

- **回答直後の LINE 確認導線**: 料金・出勤・個別出勤・推薦・初回/不安・混雑/来店時間など対象 intent の回答直後に、インライン LINE ボタンを表示（トライアル終了を待たない）。intent カテゴリに応じて文面を自然に分岐
- **しつこさ抑制**: 既存 `line_guided_count` を流用し、1セッション最大2回まで。3回目以降は非表示
- **LINE CTA イベント**: `line_cta_shown` を event_logs に記録（DB migration 不要 / `event_name` は VARCHAR）
- **WBSS 初期エラーの扱い**: ダッシュボードのエラー表示を「直近24h / 直近7日 / 全期間」に分離。改善提案は直近24時間のエラーのみで判定し、初期設定・古い開発エラー（過去の4件など）に引っ張られないよう変更（ログは物理削除しない）
- **other率の期間ベース化**: 直近24時間にデータがあれば24h値を優先表示。Operations Summary・Intent精度TOP課題・改善提案を24h基準に。全期間値も併記
- **intent 追加**: `初めまして`→greeting、`営業している`/`営業中`/`今日営業`→business_hours。`営業`/`開いてる` を cast_schedule 禁止語に追加
- 「かな」単体は other のまま、「かなさんは？」は cast_schedule を維持

### v0.8.6 - Improve Intent Accuracy and Recommendation Flow

- **cast_schedule 誤判定修正**: 20文字超メッセージを除外、禁止語に `2人`/`3人`/`場所`/`雰囲気` などを追加
- **cast_schedule を attendance より先に評価**: `出勤` 単体キーワードで attendance に誤判定されるのを防止
- **cast_type を recommend_cast に統合**: `どんな子`/`どんな女の子`/`女の子多い`/`可愛い子` 等を recommend_cast に移動
- **price_estimate を price より先に評価**: 人数＋セット数 / 料金＋数量の組み合わせを早期 return で確定
- **arrival_time / crowd / repeat_visitor キーワード拡充**: `また来たよ`/`2回目`/`混んでる？`/`8時` など追加
- **area_question キーワード拡充**: `岡山キャバ` 追加
- **複合 intent ログ**: `secondary_intents` として event_logs に保存
- **LINE誘導ランダム化**: `twin_line_nudge()` で3パターンをランダム選択
- **OpenAI system prompt 短縮**: 約700トークン以下を目標に簡略化
- **管理画面「Intent精度 TOP課題」追加**: cast_schedule未一致率・price_estimate件数・other率を一覧表示
- `APP_VERSION` を `0.8.6` に更新

### v0.8.5 - Cast Name Mismatch Analysis

- **キャスト名正規化強化**: カタカナ→ひらがな変換・半角カタカナ正規化を追加し、「ゆうちゃん」「ﾕｳ」などの表記ゆれを吸収
- **alias ファイル追加**: `app/knowledge/cast_aliases.php` でキャスト名の表記ゆれ正規マッピングをファイル管理（DB不要）
- **禁止語強化**: `不安` / `緊張` / `初めて` / `一人` / `出勤人数` / `何名` などを `cast_schedule` 禁止語に追加
- **cast_name_detected ログ改善**: `cast=名前,raw=元メッセージ` 形式に変更し、未一致時のデバッグが容易に
- **管理画面「キャスト名未一致ランキング」追加**: cast_schedule_not_found のキャスト名を集計し、alias候補・禁止語候補・推奨対応を表示
- `APP_VERSION` を `0.8.5` に更新

### v0.8.4 - Fix WBSS Accuracy Score

- **出勤精度スコアの計算修正**: `attendance_empty` / `cast_schedule_not_working` を成功扱いに追加し、実際の WBSS 成功率を正しく反映するよう修正
- **`cast_schedule_not_found` の分離**: API 障害とは別扱いにし、分母（スコア計算）に含めないよう変更。健康診断に「キャスト名未一致 〇件」として別表示
- **health score 呼び出し順序の修正**: WBSS 集計が確定する前に `twin_build_health_score_breakdown()` を呼んでいたバグを修正（0点になる根本原因）
- **Operations Summary と健康診断の成功率を統一**: 同じ `$wbssSuccessRate` 変数を共有するよう整理
- **出勤精度 note 改善**: 成功件数・エラー件数・キャスト名未一致件数を詳細表示
- **改善提案の文言修正**: エラー 0 件でキャスト名未一致のみ多い場合は「表記ゆれ改善」として表示
- `APP_VERSION` を `0.8.4` に更新

### v0.8.3 - Intent Refinement & Conversion Flow

- **anxiety intent 追加**: 不安/緊張/怖い/心配など初来店不安系の発言を専用 intent で検出し、安心させる応答＋LINE誘導を返すようにした
- **price_estimate 過検知修正**: 単純なキーワードリストから条件分岐ロジックに変更し、不安・緊張・初めて・一人系の発言で誤検知しないようにした
- **arrival_time / crowd / repeat_visitor / recommend_cast キーワード強化**: 実際の発言パターンに合わせてキーワードを拡充
- **recommend_cast 返答改善**: LINE誘導を自然に含め、出勤情報ゼロでも preferences 質問で会話を続けるように変更。`recommend_cast_prompted` イベントでログを追加
- **LINE 誘導強化**: price / vip / drink_price / group_visit / arrival_time / repeat_visitor の各返答に自然な LINE 誘導文を追加
- **質問ランキングのデフォルト期間を 24h に変更**: 古い誤判定ログが改善状況を隠しにくくした
- **質問ランキング補足表示**: 「過去ログには修正前の誤判定が含まれる場合があります」の注釈を追加
- **health score 出勤精度 "未計測" 対応**: WBSS 呼び出し総数 0 の場合は 0 点ではなく「未計測」と表示するように修正
- **AI 改善プロンプト更新**: anxiety/arrival_time/crowd/repeat_visitor/recommend_cast→LINE の観点を追加（指示項目 23〜26）
- **intent ラベル更新**: anxiety → 不安・緊張、repeat_visitor → 再来店
- `APP_VERSION` を `0.8.3` に更新

## 2026-06-21

### v0.8.2 - Admin Wording Localization

- **管理画面用語の日本語化**: `intent` / `CTA` / `WBSS` / `Conversation` などの見出しを、店舗運営者向けの日本語へ変更
- **表示ラベルのみ変更**: `cast_schedule`、`attendance`、`price_estimate`、`response_mode` などの内部キーは変更せず、見た目だけをローカライズ
- **会話・来店導線ダッシュボード**: 管理画面のタブとセクション文言を運営オペレーション向けに整理
- `APP_VERSION` を `0.8.2` に更新

### v0.8.1 - OpenAI Diagnostics & Health Score Breakdown

- **OpenAI診断セクション**: 呼び出し回数・成功回数・失敗回数・fallback回数・usage保存件数・最終利用日時・最終エラーを管理画面で可視化
- **health score breakdown**: intent精度・LINE導線・会話自然度・出勤精度・料金精度・OpenAI接続を個別カードで表示
- **質問ランキング期間切替**: 全期間 / 過去30日 / 過去7日 / 過去24時間を切り替えできるようにして、古いログが改善効果を隠しにくくした
- **改善提案ロジック強化**: OpenAI接続スコアが 0 の場合は OpenAI 利用状況確認を最優先にし、usage件数 0 の場合は usage 保存処理確認を出す
- **analysis_json 拡張**: `openai_diagnostics` と `health_score_breakdown` を追加
- `APP_VERSION` を `0.8.1` に更新

### v0.8.0 - Operations Dashboard

- **Operations Summary**: 健康診断スコア・LINE CTR・other率・AI利用額・WBSS接続・応答方式を最上部に集約
- **改善提案タブ**: 数値から改善候補を一覧化し、優先度・現在値・理由・推奨アクション・工数・効果を表示
- **質問ランキングタブ**: 本当に聞かれる質問ランキングを intent 別に見える化し、代表質問・改善提案・優先度を表示
- **intent 棒グラフ強化**: intent TOP10 を横棒グラフ風で確認できるようにした
- **返答改善候補カード化**: 重複返答や other 連続などをカードで見やすくした
- **WBSS セクション簡潔化**: 接続状態・呼び出し数・成功率・平均レスポンス・直近エラー件数を先頭に表示
- **AI利用額の上部表示**: Operations Summary でも今日/今月を確認できるようにした
- **analysis_json 拡張**: `operations_summary`・`improvement_suggestions`・`real_question_ranking`・`twin_health_score`・`line_pre_intent_summary`・`reply_improvement_summary` を追加
- `APP_VERSION` を `0.8.0` に更新

### v0.7.4 - Real Question Ranking & Improvement Suggestions

- **本当に聞かれる質問ランキング**: `chat_messages.sender='user'` を基準に、直近7日の質問を intent 別に集計し、代表質問例・改善提案・優先度まで表示
- **今週の改善TOP5**: 件数と優先度をもとに、実装順の改善候補を自動表示
- **改善提案ロジック**: `price` / `price_estimate` / `attendance` / `cast_schedule` / `first_visit` / `anxiety` / `alone` / `group_visit` / `arrival_time` / `crowd` / `vip` / `drink_price` / `cast_type` / `recommend_cast` / `other` を intent 別に固定化
- **analysis_json 拡張**: `real_question_ranking` と `weekly_improvement_top5` を追加し、AI分析用エクスポートからも改善判断できるようにした
- `APP_VERSION` を `0.7.4` に更新

### v0.7.3 - Hybrid Usage Fix & Intent Routing

- **openai_engine.php: usage保存デバッグ強化**: session_id が 0 の場合に警告ログ出力。INSERT失敗時に `error_log` でエラー記録。保存成功時もデバッグログを出力（コメントアウト可）。
- **chat_api.php: session_id をコンテキストに追加**: `$responseContext` に `session_id` を含めるよう修正し、hybridモードでもusageが正しく保存されるようになった。
- **rule_engine.php: cast_schedule 誤判定を強く制限**:
  - 禁止語リストを大幅強化（VIP/ドリンク/料金/岡山/キャバクラ等）
  - メッセージ全体にも禁止語チェックを適用
  - castName の最大長を6文字に制限
  - 「〇〇は？」形式のみでは出勤確認文脈なしで cast_schedule にしないよう制限
- **新intent追加**:
  - `vip`: VIPルーム料金の質問（11,000円/1set 1席）
  - `drink_price`: ドリンク料金・ハウスボトルの質問
  - `area_question`: 岡山・中央町エリアのキャバクラ質問
- **intent順序**: vip・drink_price は cast_schedule より前に配置し誤判定を防止
- **response_engine.php: hybridモード実装**:
  - ruleで返すintent: price, price_estimate, vip, drink_price, business_hours, location, attendance, cast_schedule, group_visit, arrival_time, crowd, reservation
  - OpenAIに回すintent: anxiety, cast_type, recommend_cast, atmosphere, repeat_visitor, general_chat, other
  - OpenAI使用時は必ずusageを保存
- **admin.php: AI利用額セクション強化**: usage記録件数・直近5件テーブルを追加
- `APP_VERSION` を `0.7.3` に更新

## 2026-06-21

### v0.7.2 - Intent Classification Fix & Admin Improvements

- **intent判定順変更**: `greeting` を先頭に移動し、`price_estimate`（新規）→ `price` → `business_hours` → `reservation` → `group_visit` → `arrival_time` → `crowd` → `repeat_visitor`（新規）→ `cast_type`（新規）→ `recommend_cast` → `attendance` → `cast_schedule` の順に整理。
- **新intent追加**:
  - `price_estimate`: 人数・セット数から料金概算を計算して返答。`price_estimate_detected` イベントをDB保存。
  - `cast_type`: キャストタイプの質問に返答。
  - `repeat_visitor`: 再来店・来店経験ありの返答。
- **greeting キーワード強化**: `おはよう`、`おはようございます`、`どうも`、`やっほー` を追加。
- **cast_schedule 誤判定防止**: `twin_detect_cast_schedule_query()` に除外条件を追加（castName空・10文字以上・数字含む・除外語含む）。
- **twin_intent_label() 更新**: `price_estimate`、`cast_type`、`repeat_visitor` のラベルを追加。
- **admin.php: WBSS接続判定改善**: URLとキーの両方が設定されているか、または直近7日以内の成功ログがあるかで判定。
- **admin.php: AI利用額 usage未記録警告**: openaiモードのセッションがあるがusage未記録の場合に警告を表示。
- `APP_VERSION` を `0.7.2` に更新。

## 2026-06-21

### v0.7.1 - AI Usage Cost Dashboard

- **ai_usage_logs テーブル追加**: `database/migrations/007_create_ai_usage_logs.sql` を追加。OpenAI API呼び出しごとのトークン数・概算コストを記録。
- **openai_engine.php 更新**: APIレスポンスの `usage` フィールドから `prompt_tokens`、`completion_tokens`、`total_tokens` を取得し `ai_usage_logs` に保存。コスト計算はgpt-4.1-mini概算単価（input $0.40/1M、output $1.60/1M）を使用。記録失敗はサイレントに握りつぶす。
- **config.php 更新**: `openai_input_cost_per_1m_tokens_usd`、`openai_output_cost_per_1m_tokens_usd`、`usd_jpy_rate` を追加。環境変数で上書き可能。
- **admin.php 更新**: 「AI利用額 (概算)」セクション（id="ai-cost"）を追加。今日/今月/累計の概算額カード・モデル別テーブル・注意書きを表示。テーブル未作成環境でもエラーにならない。
- **admin_export.php 更新**: analysis_json に `ai_usage_summary` を追加（today_jpy/month_jpy/total_jpy/total_tokens/total_calls）。
- `APP_VERSION` を `0.7.1` に更新。

## 2026-06-21

### v0.7 - Conversation Intelligence

- **新intent追加**: `group_visit`（複数人来店）、`arrival_time`（来店時間）、`crowd`（混雑確認）、`recommend_cast`（キャスト推薦）を `rule_engine.php` に追加。
- **「はい」分岐改善**: `waiting_group_visit_answer`、`waiting_arrival_time_answer`、`waiting_crowd_answer` に対応する肯定返答を追加（`group_visit_yes`、`arrival_time_yes`、`crowd_yes`）。
- **キャスト推薦ロジック v0.1**: `recommend_cast` intent でWBSS出勤APIから本日出勤キャスト最大3名を取得し、テンプレートで返答。出勤情報取得不可の場合はLINE誘導。
- **`recommend_cast_executed` イベント**: `event_logs` に推薦実行イベントを保存。
- **会話コンテキスト強化**: `twin_detect_conversation_context()` に新intent対応のコンテキスト検出を追加。
- **openai_engine.php 更新**: system promptに新4intentの説明を追記。
- **admin.php 更新**: キャスト推薦分析セクション（推薦実行回数・推薦後LINEクリック数・推薦後CTR）を追加。AI分析プロンプトに項目18〜20を追加。
- **`twin_intent_label()` 更新**: 新4intentのラベルを追加（グループ来店、来店時間、混雑確認、キャスト推薦）。
- `APP_VERSION` を `0.7` に更新。

## 2026-06-21

### v0.6.10

- **Admin 公開前対応**: `header('X-Robots-Tag: noindex, nofollow')` を admin.php 先頭に追加。
- 開発用語の日本語化: `response_mode` → 応答方式、`rule` → 安定モード、`openai` → AI会話モード、`hybrid` → ハイブリッドモード、`fallback_rule` → 安定モードへ自動切替、`APIキー` → WBSS接続キー、`OpenAIキー` → AI接続。
- `twin_intent_label()` 関数を追加し、intent名を管理画面全体で日本語ラベル表示するよう対応（関数は既存呼び出しのみで使用済みのものも含む）。
- `twin_response_mode_label()` 関数を追加し、モード名表示を日本語化。
- 応答方式の説明文（`twin_admin_mode_description()`）を公開向けに更新。
- 公開前チェックリストを設定セクション内に追加（自動判定4項目 + 手動確認4項目）。
- README.md に Apache Basic認証推奨を追記。
- `APP_VERSION` を `0.6.10` に更新。

## 2026-06-21

### v0.6.9

- **Admin改善ダッシュボード化**: 管理画面を「確認画面」から「改善ダッシュボード」へ進化。
- Added KPI card section (6 metrics): LINE CTR, other率（色分け：緑/黄/赤）, 出勤質問率, 料金質問率, 総セッション数, 総メッセージ数. Grid is 3-col → tablet 2-col → mobile 1-col.
- Added TWIN健康診断スコア: intentカバー率スコア（100 - other率）・LINE誘導スコア・会話自然度スコアの平均（100点満点）を概要セクション内に表示。
- Added 次にやるべき改善TOP3: other率・LINE CTR・返答改善候補件数・WBSSエラー・cast_schedule_not_foundから優先度付きで最大3件を自動生成。
- Added LINEクリック前intent分析: `event_logs` + `chat_messages` JOINでLINEクリック直前のintentを集計し、CTA分析セクション内に横棒グラフで表示。予約導線改善の入口。
- Extended AI analysis prompt with items 14–17 (KPI活用・健康診断スコア・LINEクリック前intent・other率削減intent追加案).
- Updated `APP_VERSION` to `0.6.9`.

## 2026-06-21

### v0.6.8

- **開発運用ルール変更**: Macローカル `/Users/newname/webproject/project_twin` を正（Source of Truth）とし、raspi4は `git pull --ff-only` のみのデプロイ先とする。raspi4での直接編集禁止。
- Added `other intent` analysis section in admin: recent 50 `other` messages and top 20 frequent words.
- Added suggested new intents panel: detects `anxiety`, `drink`, `age`, `payment`, `dress_code`, `parking` from `other` messages.
- Added `dropoff_sessions` section: sessions with 1–2 user messages, no CTA click, and no trial finish.
- Added `line_opportunity_sessions` section: sessions with price/attendance/reservation intents but no LINE click.
- Added `response_improvement_candidates` section: detects duplicate TWIN replies (A), repeated reply after `はい` (B), and consecutive `other` intents (C).
- Extended `analysis_json` export with `other_messages`, `suggested_intents`, `dropoff_sessions`, `line_opportunity_sessions`, `response_improvement_candidates`.
- Updated AI analysis prompt with items 9–13 (other intent追加候補、LINE誘導パターン、離脱原因仮説、返答改善案、v0.7 cast-matching提案).
- Added `会話分析` tab to admin navigation.
- This release is a pre-v0.7 analysis upgrade before OpenAI hybrid integration.
- Updated `APP_VERSION` to `0.6.8`.

## 2026-06-21

### v0.6.5

- Added `app_settings` for operational settings storage.
- Added admin UI to change `response_mode` between `rule`, `openai`, and `hybrid`.
- Added CSV exports for conversations, events, WBSS, and CTA logs.
- Added `analysis_json` export for AI-assisted conversation analysis.
- Added an AI analysis prompt block in the admin screen.
- Improved the admin dashboard UI for tablet use with sticky tabs, collapsible sections, and horizontal scrolling tabs.
- Added operator-friendly descriptions for `rule`, `openai`, and `hybrid` modes.
- Marked `hybrid` as a planned v0.7 mode and kept it display/save ready.
- Reminded operators that logs may contain personal information and must be handled carefully.

## 2026-06-21

### v0.6

- Added short-term memory for the current session by combining session state with the latest chat history.
- Added conversation context detection for follow-up replies like `はい` and `うん`.
- Stored `last_cast_name` in session state and logged `conversation_context` to `event_logs`.
- Added admin visibility for conversation context counts and recent context logs.

## 2026-06-21

### v0.5.1

- Improved `cast_schedule` name extraction so short follow-up questions like `ももさんは？` can be handled in attendance context.
- Added `cast_name_detected` logging to `event_logs` and surfaced it in the admin dashboard.
- Updated the application version display to `0.5.1`.

## 2026-06-21

### v0.5

- Added the WBSS attendance API client.
- Added `attendance` and `cast_schedule` intents.
- Made WBSS the Single Source of Truth for working status and staff attendance.
- Kept the WBSS API key out of Git and routed it through `WBSS_TWIN_API_KEY` or `app/config.local.php`.
- Ensured OpenAI mode still defers to WBSS for attendance questions.
- Kept `count=0` responses careful so they do not overstate that nobody is working.

## 2026-06-21

### v0.4.1.2

- Added `APP_VERSION` as a single source of truth in `app/config.php`.
- Displayed the version on the public homepage and admin screen.
- Added README guidance for updating the version by changing `APP_VERSION` only.

## 2026-06-21

### v0.4.1.1

- Documented the raspi4 migration path for `/var/www/html/twin`.
- Clarified that future updates should be applied with `git pull` inside the live checkout.
- Noted that `app/config.local.php` and `storage/` are environment-specific and must be preserved on refresh.
- Added a reminder that GitHub pushes do not affect raspi4 until the live checkout pulls the change.

## 2026-06-21

### v0.4.1

- Added `app/knowledge/seika.php` to store official Club 星華 information from `okayama-seika.com`.
- Updated `rule_engine.php` to answer with concrete store facts for pricing, hours, location, atmosphere, first visit, reservation, and recruitment questions.
- Updated `openai_engine.php` to inject the same knowledge into the system prompt.
- Kept the existing analytics, CTA tracking, fallback behavior, and admin login intact.
- Preserved the policy of not guessing facts that are not on the official site.

## 2026-06-21

### v0.4

- Added conversation analytics to the admin dashboard.
- Added CTA view and click tracking with CTR calculation.
- Added `chat_sessions.message_count` and `chat_sessions.session_duration_seconds`.
- Added `conversion_events` as a future tracking table.
- Expanded the admin dashboard into overview, conversation analytics, CTA analytics, intent analytics, and recent questions.
- Added response mode distribution visualization.
- Kept the existing chat, timer, CTA, and fallback behavior intact.

## 2026-06-21

### v0.3

- Added admin session login.
- Split response handling into rule and OpenAI engines.
- Added automatic fallback to rule mode when OpenAI is unavailable or fails.
- Expanded intent vocabulary to cover business hours, reservation, friends, after-party, and budget.
- Added `response_mode` logging and admin aggregation.
- Added docs-based hand-off and update history files.

### Notes

- No additional schema migration was required for v0.3.
- Existing v0.2 migration for `chat_messages.intent` remains the only required migration if the DB predates v0.2.

## 2026-06-21

### v0.2

- Added intent storage.
- Added intent ranking admin view.
- Improved rule-based conversational flow with question back prompts.
- Added `intent_detected` logging.

## 2026-06-21

### v0.1

- Initial TWIN SEIKA prototype with five-minute trial UI, rule-based replies, DB storage, and LINE/price/Instagram CTA flow.

## 2026-06-23

### v0.9.2 — store_key 基盤実装 + システム設定UI改善

#### store_key 基盤

- `app/store.php` を新規作成。`twin_current_store_key()` で現在の対象店舗を返す。
  - 優先順位: 環境変数 `TWIN_STORE_KEY` → `config.local.php` の `store_key` → デフォルト `seika`
- `app/knowledge/stores.php` を新規作成。seika / creole の店舗設定を配列で一元管理。
  - `twin_store_config(string $storeKey): array` — 店舗設定取得
  - `twin_store_value(string $storeKey, string $key): string` — 値取得ヘルパー
  - `twin_store_knowledge_prompt_block(array $cfg): string` — OpenAI prompt用テキスト生成
- `app/engines/rule_engine.php`: `twin_rule_knowledge()` / `twin_rule_value()` を store_key 対応に変更。WBSS呼び出しの `'seika'` ハードコードを `wbss_store_key` 設定値から取得するよう変更。
- `app/engines/openai_engine.php`: knowledge を store_key 対応の `twin_store_config()` から取得。キャラクター設定も store_key で分離。
- `public/index.php`: store_key に応じてリンクURL・AI名・役職・あいさつ文・アバターを切替。
- `public/admin.php`: AIキャラクター保存・読み込みを store_key で分離。トップバーに対象店舗を表示。

#### seika.php の互換性維持

- `app/knowledge/seika.php` は削除せずそのまま残す（既存コード互換維持のため）。
- 新規コードはすべて `twin_store_config()` 経由で取得する。

#### 店舗追加手順（将来）

1. `app/knowledge/stores.php` の `twin_all_store_configs()` に新しい store_key のエントリを追加。
2. `app/store.php` の `twin_valid_store_keys()` に追加。
3. `TWIN_STORE_KEY=<新store_key>` を環境変数または `config.local.php` で設定。

#### creole の切替方法（現在）

- `config.local.php` に `'store_key' => 'creole'` を追加、または環境変数 `TWIN_STORE_KEY=creole` を設定。
- creole の URL（LINE/Instagram/料金）は `stores.php` 内の `creole` エントリを要更新。

#### システム設定UI改善 (v0.9.2)

- システム設定パネルをサブタブ構造に変更: AIキャラクター / 応答モード / 公開前チェック / AI利用額 / OpenAI診断
- AIキャラクターカード型表示 + 編集フォームを `<details>` で開閉
- 応答モード・AI利用額・OpenAI診断に `Dev` バッジを追加

## 2026-06-23

### v0.9.3 — 管理画面ストア切替スイッチャー

- `app/store.php`: `twin_current_store_key()` にセッション読み取りを追加（優先順位2位）
- `public/admin.php`:
  - `?switch_store=<key>` GETパラメータでセッションに店舗を保存 → リダイレクト
  - トップバーに「TWIN SEIKA / TWIN CREOLE」ピル型切替ボタンを追加
  - 環境変数 `TWIN_STORE_KEY` が設定されている場合は切替ボタンを無効化（🔒 env固定表示）
- `app/config.php`: v0.9.3
