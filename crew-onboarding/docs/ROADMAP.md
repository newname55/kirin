# Project TWIN Roadmap

最終更新: 2026-06-27

---

## Project TWIN の目的

来店前AI接客から始まり、
夜業界の成功パターンを学習し、
再現可能な運営OSを構築する。

単なる採用管理ツールではなく、
**採用後の実結果を学習して次の採用精度を上げるシステム** を目指す。

---

## 現在地

```
Phase 1  ✅ AI接客        — 来店前チャット・WBSS連携・LINE誘導
Phase 2  🚧 AI採用        — 問診エンジン・スコア算出・採用結果追跡（今ここ）
Phase 3  ⬜ AI分析        — 結果学習・Grade精度検証・採用パターン発見
Phase 4  ⬜ AIマッチング  — キャスト推薦・来店予測・定着率予測
Phase 5  ⬜ 運営OS        — 店舗ダッシュボード・AI店長・横展開
```

---

## 優先タスク（Next Actions）

### 🔴 最優先（今すぐやること）

- [ ] **Dynamic Interview Engine Step 1 — 深掘り質問提案のみ**
  - 基本5問問診完了後に、AIが「次に聞くといい質問」を1つ生成して admin_applicant.php に表示する
  - 応募者との会話は変更しない。店長向けの補助情報として出すだけ
  - 対象スロット: `days_reason`（週3など制限理由）/ `curfew_reason`（門限理由）/ `motivation`（志望動機）
  - 設計書: `docs/DYNAMIC_INTERVIEW.md`
  - DB 変更なし・既存 step machine 変更なし
- [ ] **Raspi5 本番に migration 010 を適用**
  - `sudo mysql -u root twin < .../010_add_outcome_columns.sql`
  - 採用結果フォームが本番でも動作するようにする
- [ ] **採用結果データの入力を開始する**
  - 既存採用済み応募者の `outcome_*` を手動で埋める
  - `outcome_note` に特記事項（例外ケースの理由）を書く習慣をつける

### 🟡 近期（1〜3か月）

- [ ] **採用結果分析ダッシュボードを管理画面に追加**
  - `docs/OUTCOME_ANALYSIS_SQL.md` のクエリを画面化
  - Grade別採用率・定着率の簡易グラフ
- [ ] **3か月後フォローアップ通知**
  - `hired_at + 90日` が近づいたら管理画面バナーで通知（現状は一覧バッジのみ）
- [ ] **parser テスト・ペルソナテストの CI 化**
  - push 後に自動で `php scripts/test_parsers.php` が走るようにする
  - 現状は手動実行のため抜け漏れが起きやすい
- [ ] **スコア内訳バーを admin.php 一覧にも表示**
  - 現状は `admin_applicant.php`（詳細画面）のみ
  - 一覧でも Grade ＋ 主要バーが1行で確認できると便利

### 🟢 中期（3〜6か月）

- [ ] **Grade別成功率のリアル実測値化**
  - 現状の採用推奨率（A=95%/B=80%...）はルールベース
  - 実データが20件以上揃ったら実測値に置き換える
- [ ] **D評価成功・A評価退店ケースの分析**
  - `docs/OUTCOME_ANALYSIS_SQL.md` の SQL #5/#6 を定期実行
  - 共通点を `outcome_note` から読み取り、スコアロジックを改善
- [ ] **school / childcare intent の追加**
  - P07「学校・テスト・大学」→ `student_schedule` intent
  - P10「子供・育児・急な欠席」→ `childcare_concern` intent
  - 現状は `other` に落ちている
- [ ] **`--write-md` オプションをペルソナテストに追加**
  - テスト結果を `docs/TEST_RESULTS_YYYYMMDD.md` に出力
  - テスト履歴を蓄積できるようにする

---

## 完了済み（主要マイルストーン）

### Phase 1 — AI接客

- ✅ AI接客チャット（rule / openai / hybrid モード）
- ✅ WBSS API連携（出勤・キャスト個別確認・日付指定）
- ✅ LINE CTA 誘導・クリック計測
- ✅ 複数店舗対応（store_key 基盤）
- ✅ 管理画面ダッシュボード（KPI・改善提案・質問ランキング）
- ✅ AIキャラクター設定（店舗別）
- ✅ セキュリティ強化・個人情報マスク（v1.0 RC）
- ✅ 「明日の出勤」intent 対応（v1.1.4）

### Phase 2 — AI採用

- ✅ 問診エンジン（recruit_engine: step machine 型）
- ✅ 未経験/経験者/some の3ルート分岐
- ✅ parser 単体テスト 40ケース（test_parsers.php）
- ✅ ペルソナ総合テスト 10/10（run_persona_tests.php）
- ✅ admin_applicant.php — スコア内訳バー・AIコメント・AI推奨率
- ✅ 採用結果追跡フォーム（outcome_* 6カラム）
- ✅ 「3か月後結果 未入力」アラート
- ✅ OUTCOME_ANALYSIS_SQL.md — 8本の分析クエリ集

---

## アイデア保管庫

優先順位なし。思いついたことを自由に追加する。

- LINE 応募者とのチャット連携（応募後フォローアップ）
- キャスト個別の売上トラッキング（WBSS データから）
- 応募者の「離脱ポイント分析」（どのステップで止まったか）
- 問診スコアの可視化グラフ（applicant.php 内）
- 複数応募者の比較ビュー（A vs B のスコア比較）
- 採用担当者ごとの採用傾向分析
- 季節・時期別の応募傾向分析
- 店舗ごとのスコア閾値カスタマイズ
- LINEを介した面接日程調整の自動化
- キャストからの推薦経由で来た応募者の追跡（referral 経由フラグ）

---

## 技術的負債

後でリファクタリングしたい項目。

- `admin_applicant.php` のインライン style が多い → CSS クラスに整理
- `twin_recruit_ai_cards()` のコメント生成ロジックを `recruit_engine.php` に移動すべき
  （現状 `admin_applicant.php` に定義されているが、チャット側でも使いたい可能性がある）
- parser のキーワードリストが recruit_engine.php に散在している → 設定ファイル化
- `admin.php` のクエリ数が多い → 必要なものだけ lazy load 化
- CSS 変数のダーク/ライト定義が admin.php と admin_applicant.php で重複
- ペルソナテストのスコア期待値がコメントのみ → `assert()` で自動検証にする

---

## 将来構想

```
応募前
  ↓
AI接客（来店促進・FAQ対応）
  ↓
AI採用問診（スコア算出・推奨率）
  ↓
店長判断（面接・採用決定）
  ↓
入店
  ↓
売上・定着・呼客実績
  ↓
結果学習（AI評価精度の継続改善）
  ↓
成功パターンの蓄積
  ↓
夜業界向け 採用・育成ナレッジOS
    ↓
横展開（他店舗・他業種）
```

Project TWIN の長期的な競争力は「データの蓄積量と学習の深さ」にある。
採用後の実結果が増えるほど、AI評価の精度が上がり、
他社が真似できない「採用ノウハウのブラックボックス」になる。

---

## 運用ルール

- `UPDATE_HISTORY.md` = 過去（何をやったか・なぜそうしたか）
- `ROADMAP.md` = 未来（何をやるか・優先順位）
- 完了したタスクは「完了済み」セクションに移動する
- 大きな設計変更があれば ROADMAP を必ず更新する
- 優先順位が変わったら更新する（古い ROADMAP は判断を誤らせる）
