# Project TWIN v1.1 RC リリースノート

更新日: 2026-06-24

---

## 概要

v1.1 RC では管理画面の整理・可視化改善・WBSS環境切替機能の追加を中心に実施。

当初の目的であった以下の項目について大きく前進した。

- 出勤情報連携の安定化
- 管理画面の見やすさ向上
- 店舗別運用への対応準備
- 個人情報保護対応

---

## コミット履歴（v1.1系）

| コミット | 内容 |
|---|---|
| `65ad211` | v1.1 分析→改善指示型への転換（サマリー/改善センター/ランキング/直近質問/未分類/出勤分析） |
| `a2c3697` | v1.1 バージョン表記 0.9.3→1.1 RC / 折りたたみ初期open化 |
| `5ae0dbc` | store_key UI改善 / 管理画面トップバーに星華/クレオール切替ボタン追加 / docs/STORE_KEY_GUIDE.md |
| `77ab92e` | v1.1.1 店舗ナレッジをシステム設定>店舗設定タブへ移動 |
| `0a67f33` | v1.1.2 管理画面の店舗切替UI完全削除 / 店舗設定を store_key+店舗名のみに簡素化 |
| `9abce53` | v1.1.2 switch_store GET処理・chatEnvMismatch変数群・store-switcher CSS完全削除 / 店舗設定4カード復充 |
| `05a1049` | v1.1.3 キャスト名抽出ロジック改善 / 未確認ランキング→抽出エラー分析改名 / ランキング簡素化 / 健康診断1カード化 |
| `0cb19fa` | v1.1.4 WBSS接続先切替機能追加（管理画面システム設定>WBSS接続先Devタブ） |
| `6525a10` | fix WBSSタブJS構文エラー修正・pill色クラス・テストリンクハッシュ |
| `0aa3b01` | fix WBSS env URLマッピング修正（prod/devが逆だった） |

---

## 1. WBSS環境切替機能

### 目的

本番 Raspi5 と開発 Raspi4 を管理画面から切り替えられるようにする。

### 実装ファイル

**`app/settings.php`**

追加関数:

- `twin_wbss_env_urls()` — env_key → base URL マッピング
- `twin_wbss_allowed_envs()` — 有効な env_key 一覧（`['prod', 'dev']`）
- `twin_wbss_env_label()` — 表示名（本番 Raspi5 / 開発 Raspi4）

**`app/config.php`**

`WBSS_API_BASE_URL` 定数定義前に `app_settings.wbss_env` を読み込み、接続先 URL を動的決定。DB設定が config.local.php より優先。

**`public/admin.php`**

システム設定内に「WBSS接続先 Dev」サブタブを追加。

機能:
- 現在の接続先・URL表示
- セレクト＋保存（POST `action=save_wbss_env`）
- 今すぐ接続テスト（`?test_wbss=1#system`）
- 環境一覧テーブル

### 環境定義

| env_key | 環境名 | URL |
|---|---|---|
| `prod` | 本番 Raspi5 | `https://ss5456ds1fds2f1dsf.asuscomm.com/wbss/public/api/twin` |
| `dev` | 開発 Raspi4 | `https://haruto.asuscomm.com/wbss/public/api/twin` |

### 保存先

`app_settings` テーブル、キー `wbss_env`（値: `'prod'` または `'dev'`）

### 検証結果（本番 Raspi5）

| テスト | 結果 |
|---|---|
| seika attendance | `ok:true` count=19 ✅ |
| creole attendance | `ok:true` count=17 ✅ |
| 不正 store_key | `unsupported_store` ✅ |
| 認証なし | `unauthorized` ✅ |
| seika cast_schedule | `missing_param`（正常） ✅ |
| creole cast_schedule | `missing_param`（正常） ✅ |

### WBSS本番 APIキー設定

本番 Raspi5 の `/var/www/.wbss_env` に `TWIN_API_KEY` を設定済み。  
キーは `dev`/`prod` で共通値（raspi4と同じ）。

---

## 2. 出勤分析 UI 改善

### サブタブ化

| 変更前 | 変更後 |
|---|---|
| 単一画面 | サマリー / 成功失敗 / 抽出エラー分析 / 除外語候補 / alias候補 / 詳細ログ |

### キャスト名抽出ロジック改善（v1.1.3）

除外語リスト拡充（`public/admin.php` 内 `$excludeWordList`）:

```
VIP, vip, ドリンク, 料金, 金額, 何人, セット, 初めて, 一人,
緊張, 不安, いくら, 岡山, キャバクラ, キャバ, お店, 指名,
ボトル, フリー, 女の子, お姉さん, 店内, フロア, 入口, 雰囲気
```

自動分類カテゴリ:

| カテゴリ | 判定条件 |
|---|---|
| `misextract` | 8文字以上 / 数字含む / 疑問符・文節含む |
| `exclude` | 除外語リストに一致 |
| `alias` | 未登録の短い名前 |
| `pending` | 判定不能 |

### 抽出エラー分析タブ

「未確認ランキング」を「キャスト名抽出エラー分析」に改名し、カテゴリ別表示に変更。推奨対応と登録先を自動表示。

---

## 3. 質問傾向分析改善

### ランキング整理

| 変更前 | 変更後 |
|---|---|
| TOP10 + よく聞かれる内容 | 本当に聞かれる質問 TOP5 + 詳細テーブル |

### 直近質問改善

追加セクション:
- 離脱候補（会話途中で終了）
- 予約直前会話（LINE誘導直前）
- 生ログ（折りたたみ）

### 個人情報薄表示

以下マスク済みラベルを opacity 0.45 で表示:

- `[電話番号]` `[メールアドレス]` `[LINE ID]` `[住所]` `[決済情報]`

---

## 4. TWIN健康診断整理（v1.1.3）

| 変更前 | 変更後 |
|---|---|
| 多数の指標を常時表示 | 1カードに集約（良好 / 要改善 / 今日やること） |

「今日やること」自動生成条件:

- other率 ≥ 15% → `intent追加を検討`
- WBSS成功率 < 70% → `alias/除外語を整理`
- 改善候補あり → `改善センターに N件の改善候補`

---

## 5. 店舗設定整理

### 店舗切替 UI 削除（v1.1.2）

管理画面トップバーの「星華 / クレオール」切替ボタンを削除。

理由: 本番運用では店舗ごとに config.local.php で store_key を固定するため。

### 店舗設定タブ（v1.1.1）

システム設定 > 店舗設定 タブに以下を読み取り専用表示:

| カード | 表示項目 |
|---|---|
| 基本情報 | store_key / 店舗名 / AI名 / AI肩書き / 応答モード |
| 営業情報 | 営業時間 / 定休日 / エリア / 所在地 |
| 料金情報 | 指名料 / VIP料金 / サービス料 / 料金概要 |
| URL | LINE / Instagram / 料金ページ |

未設定項目はオレンジ色で警告表示。

---

## 6. 個人情報保護

### 実装済み（`app/privacy.php`）

保存前マスク対象:

| 種別 | マスク後 |
|---|---|
| 電話番号 | `[電話番号]` |
| メールアドレス | `[メールアドレス]` |
| LINE ID | `[LINE ID]` |
| 郵便番号付き住所 | `[住所]` |
| カード番号らしき数字列 | `[決済情報]` |

### 検証

DB保存時に `[電話番号]` `[メールアドレス]` へ変換されることを確認済み。

---

## 7. 改善センター（v1.1）

`twin_build_improvement_suggestions()` によって自動生成。

表示項目: タイトル / 優先度（星評価） / 現状 / 理由 / 推奨アクション / 期待効果 / 工数

---

## 8. OpenAI利用状況（2026-06-24時点）

| 項目 | 値 |
|---|---|
| モデル | GPT-4.1 mini |
| 今月利用額 | 約 ¥1.67 |
| usage 保存件数 | 20件 |
| OpenAI 失敗 | 0件 |
| fallback | 0件 |

---

## 9. 今後の予定（v1.1系以降）

### 優先度 高

- cast_aliases 強化（クレオール対応含む）
- excludeWords 強化（星華・クレオール別管理）
- キャスト推薦導線実装

### 優先度 中

- price_estimate 精度向上
- LINE誘導最適化
- 会話自然度改善

### 優先度 低

- Raspi4 / Raspi5 環境状態パネル（管理画面）
- 管理画面からの alias 登録支援 UI
- 管理画面からの excludeWords 管理 UI
- messages テーブルへの store_key カラム追加
- 店舗別分析機能

---

## 現状評価

v1.1 RC 時点で以下は実用レベルに到達:

- 出勤連携（seika / creole 両対応、本番 Raspi5 確認済み）
- OpenAI連携
- 個人情報保護
- 管理画面分析

次フェーズは「来店前マッチング」「キャスト推薦」への発展を目指す。
