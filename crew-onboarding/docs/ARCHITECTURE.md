# Project TWIN Architecture

最終更新: 2026-06-27

---

## Project TWIN とは

**「夜業界の成功を再現可能にする運営OS」** を目指すプロジェクト。

単なるチャットボットではなく、以下を統合したデータ基盤を構築する。

- 来店前 AI 接客（Phase 1 ✅）
- AI 採用（Phase 2 🚧）
- AI マッチング（Phase 3 ⬜）
- 売上分析（Phase 4 ⬜）
- 成功パターン学習（Phase 5 ⬜）

初期対象店舗は麒麟（Kirin）。将来は他店舗への横展開を前提とした設計にする。

---

## 基本思想

> 「人の経験をデータ化し、再現可能にする」

夜業界の採用・運営は長らく経験者の勘と属人スキルに依存してきた。
Project TWIN はその暗黙知を **観測可能なデータ** に変換し、AI と人間が協力して継続的に改善するサイクルを作る。

スコアは「人を評価する」ためではなく「面接を効率化する」ために使う。
**最終判断は必ず人間（店長）が行う。** AI はあくまで補助。

---

## システム全体像

```
来店前ユーザー        応募希望者
      │                   │
      ▼                   ▼
┌─────────────┐   ┌──────────────────┐
│  AI接客      │   │  AI採用問診       │
│ guest-concierge│  │ crew-onboarding  │
└──────┬──────┘   └────────┬─────────┘
       │                    │
       ▼                    ▼
┌──────────────────────────────────────┐
│           MariaDB (twin / twin_crew_dev)         │
│  chat_sessions / chat_messages       │
│  event_logs / crew_applicants        │
└──────────────────────────────────────┘
       │                    │
       ▼                    ▼
┌─────────────┐   ┌──────────────────┐
│ 管理ダッシュ │   │ 採用結果追跡      │
│ ボード      │   │ (outcome_*)      │
└──────┬──────┘   └────────┬─────────┘
       └──────────┬─────────┘
                  ▼
         分析 → 学習 → 改善
```

---

## サブシステム詳細

### AI 接客（guest-concierge）

**役割:** 来店前ユーザーの不安を解消し、LINE 予約に誘導する。

| 項目 | 内容 |
|---|---|
| 入力 | ユーザーのチャットメッセージ |
| 出力 | 回答 / LINE 誘導 / WBSS 出勤情報 |
| 応答方式 | `rule`（固定） / `openai`（AI） / `hybrid`（組み合わせ） |
| WBSS 連携 | 出勤キャスト・個別確認・日付指定（今日/明日） |

**設計判断:**
- ルールエンジンを優先するのは「ハルシネーションで事実誤認を返さないため」。料金・時間・場所は rule のみ
- OpenAI は感情系（不安/初めて/おすすめ）と雑談にのみ使用
- 公式情報（WBSS）を Single Source of Truth として、AI が勝手に判断しないよう制約

---

### AI 採用（crew-onboarding）

**役割:** 応募者を問診し、スコアと Grade でランク付けして店長の採用判断を補助する。

| 項目 | 内容 |
|---|---|
| 入力 | 問診（経験 / 出勤 / 飲酒 / 呼客）への返答 |
| 出力 | Grade（A〜D）/ Score（0〜100）/ AIコメント / 採用推奨率 |
| ルート | 未経験（none/some）/ 経験者（yes）の 2 分岐 |
| 保存 | `crew_applicants` テーブル（score_detail JSON 含む） |

**スコア設計思想:**

```
未経験ルート (max 100)
  呼客力      40pt  ← 初日の売上に最直結
  出勤日数    35pt  ← 貢献頻度
  飲酒        15pt
  経験ボーナス 10pt  ← experience=some のみ

経験者ルート (max 100)
  今の呼客    18pt  ← 即戦力
  体験日呼客  18pt  ← 初日の期待値
  指名実績    42pt  ← 実績の重み（上限は100内で調整）
  出勤日数    15pt
  前職時給     7pt  ← 市場価値の目安
```

Grade 閾値: A≥80 / B≥60 / C≥40 / D<40

**設計判断:**
- step machine 型を採用したのは「途中で離脱した場合にどこまで回答したか追跡するため」
- `score_detail` を JSON で保存するのは「将来の ML 学習入力として使えるようにするため」
- parser を個別関数（`parse_alcohol` 等）に分けたのは「単体テストで表記ゆれを検出できるようにするため」

**将来拡張 — Dynamic Interview Engine（設計フェーズ）:**
現行 step machine の後段に AI 深掘りフェーズを追加する予定。5問の基本問診が完了してスコアが確定した後、OpenAI が未充填スロット（勤務制限の理由・志望動機・不安要素など）を自然な会話で補完し、最終的に「採用カルテ JSON」を生成する。現行 step machine は変更せず、後段に接続する形をとることで安定性とテスト可能性を維持する。詳細は `docs/DYNAMIC_INTERVIEW.md` を参照。

---

### AI 分析（将来 Phase 3〜4）

**役割:** 全データを横断分析し、成功パターンを発見する。

対象データ:
- 来店前会話ログ（intent 分布・離脱ポイント）
- 採用スコア vs 実結果（Grade の予測精度）
- 売上ランク vs 問診回答（どの質問が将来の売上と相関するか）
- 定着率 vs 採用時スコア

**設計判断:**
- データ収集期から学習が始まるまでに最低 20〜50 件のサンプルが必要
- `outcome_note`（店長の例外メモ）は定量データで捉えられない文脈を残すための自由記述

---

## データフロー

### チャットフロー（AI 接客）

```
ユーザー入力
  ↓
chat_api.php
  ↓ intent 判定（rule_engine）
  ↓ 応答生成（rule / openai）
  ↓ DB 保存（chat_messages, event_logs）
  ↓
ユーザーへ返答
  ↓（LINE クリック）
event_logs に conversion 記録
```

### 問診フロー（AI 採用）

```
応募者メッセージ
  ↓
recruit_engine.php（step machine）
  ↓ step 判定 → parse_*() で回答を解析
  ↓ state に保存
  ↓ 次 step の質問を返す
  ↓（complete に到達）
twin_recruit_calc_score(state)
  ↓ score_detail, Grade, Score を算出
  ↓ crew_applicants に upsert
  ↓
admin_applicant.php で店長が確認
  ↓
採用結果を outcome_* に記録
  ↓（90日後）
OUTCOME_ANALYSIS_SQL.md のクエリで分析
```

### 学習サイクル

```
応募 → AI問診 → スコア算出
  ↓
店長判断（採用 / 不採用 / 辞退）
  ↓
入店・運営
  ↓（3か月後）
売上ランク / 定着 / 呼客実績を記録
  ↓
SQL分析（Grade別成功率・外れケース）
  ↓
スコアロジック・parser キーワードの改善
  ↓
次の採用精度が向上する
```

---

## データベース設計方針

| 方針 | 理由 |
|---|---|
| ログは削除しない | 後から分析したいデータが何かは事前に分からない |
| `score_detail` を JSON で保存 | 将来の ML 入力として使える / ルール変更後も過去の根拠が残る |
| `outcome_*` カラムで実結果を追跡 | AI 評価と実結果の乖離を発見するため |
| `intent` を chat_messages に保存 | どの質問にどう答えたか横断分析できるようにするため |
| `store_key` で全テーブルを分離 | 複数店舗展開時にデータが混在しないため |
| migration SQL を連番管理 | DB 状態を再現可能にするため / 本番適用の手順を明確にするため |

---

## テスト方針

```
1. parser 単体テスト（php scripts/test_parsers.php）
   ↓ 表記ゆれ・キーワード欠落を検出。DB 不要・高速
   ↓ 40ケース / 全 OK が必須

2. ペルソナ総合テスト（php scripts/run_persona_tests.php --clean）
   ↓ 10人分の実問診フローを再現。DB 必要（Raspi4）
   ↓ 完了 10/10・Grade 一致 10/10 が必須

3. php -l によるシンタックスチェック
   ↓ 全変更ファイルに対して実施

4. 実管理画面での目視確認
   ↓ ライト/ダーク両テーマ
```

**parser 変更時は必ず #1 → #2 の順で実行する。**
parser が通らない状態でペルソナテストを実行すると根本原因が見えにくくなる。

---

## 開発・運用ルール

| ルール | 理由 |
|---|---|
| `crew-onboarding` と `guest-concierge` を分離する | 互いに影響しない独立した責務を持つため |
| `config.local.php` は Git 管理外 | APIキー・DBパスワードのリーク防止 |
| Raspi5（本番）には直接触れない | 本番障害を防ぐ。デプロイは `git pull` のみ |
| Macローカルで編集 → push → Raspi4で pull → 確認 → Raspi5 で pull | 変更の正（Source of Truth）を Mac に統一 |
| parser 変更後は必ず parser test → persona test を実行 | step machine の壊れ方はテストなしには気づきにくい |
| UPDATE_HISTORY.md に変更理由を残す | 「なぜこの設計か」が将来の判断基準になる |

---

## 他システムとの連携

### WBSS（営業管理・送迎・出勤管理）

```
WBSS API
  ↓ twin_wbss_fetch_attendance()  → 今日/明日の出勤人数
  ↓ twin_wbss_fetch_cast_schedule() → 特定キャストの出勤確認
  ↓（将来）キャスト売上データ連携 → AI採用精度の向上
```

- WBSS は Single Source of Truth。AI が勝手に出勤情報を作らない
- API キーは `config.local.php` 経由。Git に含めない
- 現在対応店舗: seika / creole

### LENS（GBP・SEO・MEO 分析）

```
LENS（集客分析）
  ↓（将来連携）
  ↓ 集客経路 × 来店率の相関分析
  ↓ どのチャネルの来店者がリピートしやすいか
  ↓
Project TWIN（接客最適化）
```

現状は独立システム。将来は集客データと接客・採用データを統合して分析する。

---

## ファイル構成

```
crew-onboarding/
├── app/
│   ├── chat_api.php           ← API エントリポイント
│   ├── response_engine.php    ← rule/openai/hybrid 振り分け
│   ├── engines/
│   │   ├── recruit_engine.php ← 問診 step machine + parser 群 + score 計算
│   │   ├── rule_engine.php    ← ルールベース応答
│   │   └── openai_engine.php  ← OpenAI 応答
│   ├── knowledge/
│   │   └── stores.php         ← 店舗設定（store_key ベース）
│   ├── store.php              ← 現在店舗の解決
│   ├── db.php                 ← DB 接続
│   └── admin_common.php       ← 管理画面共通（認証・CSRF）
├── public/
│   ├── index.php              ← チャット画面
│   ├── admin.php              ← 管理ダッシュボード
│   └── admin_applicant.php    ← 応募者詳細・採用判断
├── scripts/
│   ├── run_persona_tests.php  ← ペルソナ総合テスト
│   └── test_parsers.php       ← parser 単体テスト
├── database/
│   ├── schema.sql             ← 全テーブル定義
│   └── migrations/            ← 連番 SQL（べき等設計）
└── docs/
    ├── ARCHITECTURE.md        ← 本ファイル
    ├── ROADMAP.md             ← 今後の開発計画
    ├── UPDATE_HISTORY.md      ← 変更履歴
    ├── TEST_PERSONAS.md       ← ペルソナ定義
    ├── TEST_SQL.md            ← DB 確認 SQL
    └── OUTCOME_ANALYSIS_SQL.md← 採用結果分析 SQL
```

---

## 将来構想

```
現在
  AI接客（来店促進）
  AI採用（スコア算出）
      ↓ データ蓄積
      ↓（Phase 3）
  AI分析
  ・Grade別成功率の実測値化
  ・採用スコアの自動キャリブレーション
  ・成功キャストの共通パターン発見
      ↓（Phase 4）
  AIマッチング
  ・来店前に「あなたに合うキャスト」を推薦
  ・来店予測 / 定着率予測 / 呼客実績予測
      ↓（Phase 5）
  運営OS
  ・シフト最適化 AI
  ・売上予測 AI
  ・AI 店長補助
      ↓
  夜業界向け 採用・育成ナレッジ OS
  ・他店舗への横展開
  ・業界標準の採用指標の確立
```

Project TWIN が積み上げるデータと学習の深さが、
他社が短期間では真似できない参入障壁になる。

---

## 更新ルール

- 設計思想が変わったら必ず更新する
- 大きな機能追加時はサブシステムのセクションを追記する
- データフロー図が変わったら図も更新する
- 「なぜその設計なのか」を重視する。コードの詳細より全体構成と判断理由を記録する
