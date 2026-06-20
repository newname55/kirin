# 2026-06-20 ローカル版改善実装メモ

## 背景

現行サイト（https://clubkirin.com/）とローカル開発版（127.0.0.1:5500）を比較し、
現行サイトにあってローカル版に不足していた5項目を実装した。

## 実装内容

### 1. ヒーロースライダー

- `index.html` のHeroセクションを静止画 → 3枚自動スライダーに変更
- 使用画像: `hero.webp` / `interior.webp` / `glass.webp`
- 切替間隔: 5秒（`assets/js/main.js` の `setInterval` で制御）
- HTML: `.hero-slider` 内に `.hero-slide[data-slide]` を3枚配置、最初の1枚に `is-active` クラス
- CSS: `.hero-slide` に `opacity: 0 / transition: opacity 1.4s`、`.is-active` で `opacity: 1`
- JS: `[data-slide]` を querySelectorAll で取得しインターバルでクラスを付け替え

### 2. キャスト個別ページ

- 5名分のプロフィールページを新規作成: `cast-asuka.html` / `cast-kanon.html` / `cast-rio.html` / `cast-runa.html` / `cast-yuki.html`
- キャスト一覧ページ `cast-list.html` を新規作成（「キャスト一覧を見る」ボタンのリンク先）
- `index.html` のキャストカードに `<a class="cast-card__link">` を追加し各個別ページへリンク
- 各ページ構成: 写真 / 名前・ローマ字 / タイプ・一言 / TEL予約ボタン / キャスト一覧へ戻るボタン
- CSS追加: `.cast-profile__grid`（2カラム）/ `.cast-profile__photo` / `.cast-profile__detail` など

### 3. Instagramフィード（LightWidget）

- 当初は6つのプレースホルダーマス目で実装
- LightWidget（https://lightwidget.com/）で実際のInstagramアカウント（@clubkirin）を連携し埋め込みコードを取得
- 最初: 3列グリッド（ウィジェットID: `74c41968ee6e5f1db037c2548f51513f`）→ 画像が大きすぎたため
- 最終: 6列グリッド（ウィジェットID: `fda134fa48075f18a39ebae8f25afdfc`）に変更
- `index.html` のSNSセクション内に `<script>` + `<iframe>` を配置
- Instagram Basic Display APIは2024年9月に廃止済みのため、サードパーティウィジェットを採用

### 4. お問い合わせフォーム（作成後に削除）

- `contact.html` を一時作成したが、電話のみで十分とのことで削除
- `index.html` の「フォームで問い合わせ」ボタンも削除
- お問い合わせ手段は引き続き電話のみ

### 5. ポケパラ・ヨルコムへのリンク追加

- `index.html` の掲載サイト一覧（`.listing-strip`）に2リンクを追加
  - ポケパラ: https://www.pokepara.jp/okayama/m721/a1654/shop13296/
  - ヨルコム: https://www.yorucom.com/mshop/83168918/

## 変更ファイル

| ファイル | 変更内容 |
|---------|---------|
| `index.html` | スライダー化・キャストリンク追加・LightWidget埋め込み・ポケパラ/ヨルコム追加 |
| `assets/css/style.css` | スライダー・キャストリンクホバー・Instagramフィード・キャストプロフィールページ用CSS追加 |
| `assets/js/main.js` | スライダー自動切替ロジック追加 |
| `cast-asuka.html` | 新規作成 |
| `cast-kanon.html` | 新規作成 |
| `cast-rio.html` | 新規作成 |
| `cast-runa.html` | 新規作成 |
| `cast-yuki.html` | 新規作成 |
| `cast-list.html` | 新規作成 |
| `contact.html` | 作成後に削除 |

## 結果

現行サイト（clubkirin.com）と比較して、ローカル版が上回る点：

- FAQセクション（SEO・構造化データ）
- 初心者向けCONCEPT・フローセクション
- ヒーロースライダー（現行は静止画）
- TikTokリンク
- 掲載サイト数（ナイツネット・体入ショコラ・dqnなど）
- 画像altのSEO品質
- ヘッダーに電話番号常時表示
- スマホ固定ボトムナビ
- キャスト個別ページ
- Instagramフィード（LightWidget）

## 次にやるとよいこと

- 本番デプロイ（ドメインへの切り替え）
- LINEアカウント稼働後にCTAバーのGUIDEボタンをLINE導線へ差し替え
