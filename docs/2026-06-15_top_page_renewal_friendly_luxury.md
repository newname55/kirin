# 2026-06-15 トップページリニューアル（高級感×親しみやすさ）実装メモ

## 目的

既存の青×黒・高級感のあるデザインを維持しつつ、club 麒麟の強みである

- 初めてでも入りやすいキャバクラ
- スナックの気軽さを兼ね備えたキャバクラ

を伝わる構成に改善する。バランスは「高級感 60% / 親しみやすさ 40%」を目標とした。

## 表現ルール（重要・今後も厳守）

- 「お好みの席へ案内」「自由に席を選べる」という表現は **使用禁止**。
- 正しい表現は **「入店時スタッフがお席にご案内します」**。
  - Concept カード、初めての麒麟セクション、FAQ、構造化データのすべてでこの表現に統一済み。
- 店内写真は既存画像をそのまま使用（加工・生成・改変なし）。新規画像が必要な箇所は仮のカードレイアウトで実装し、画像差し替え可能な構造にした。

## 変更ファイル

- `index.html`（メイン）
- `system.html` / `recruit.html` / `first-guide.html`（モバイル下部CTAバー追加のみ）
- `privacy.html`（CSSキャッシュバスター更新のみ）
- `assets/css/style.css`

CSS の `?v=` クエリは `20260615-guide` → `20260615-renewal` に更新済み（全ページ）。

## 実装内容（index.html）

### 1. Hero セクション

- `<h1>` を `岡山・柳町のキャバクラ` + `club 麒麟` の2行構成に変更（SEO要件「H1が明確であること」に対応）。
  - HTML構造: `<h1 class="hero__title"><span class="hero__title-eyebrow">岡山・柳町のキャバクラ</span><span class="hero__title-main">club 麒麟</span></h1>`
- H1直下にキャッチコピー `<p class="hero__catch">` を追加：
  「初めてでも気軽に楽しめる、高級感と親しみやすさを兼ね備えた大人のキャバクラ。」
- ロゴ中心だった `hero__logo`（club / 麒麟 / -Kirin- の大きな装飾ブロック）は **index.html から削除**（CSSは recruit.html で使用中のため残置）。
- モバイル用CSS（`@media (max-width: 768px)`）に `.hero__title-eyebrow` / `.hero__title-main` / `.hero .hero__catch` の文字サイズ調整を追加し、文字が潰れないようにした。

### 2. Concept セクション

- 見出しを「club 麒麟 Kirinについて。」→「初めてでも、気軽に。落ち着いて。」に変更。
- 本文を、麒麟の特徴（華やかさ＋スナックの気軽さ、初めての方への配慮）を伝える内容に書き直し。
- 既存の `concept__grid`（テキスト＋写真）の下に、4枚のカードグリッド `.guide-feature-grid.concept-points` を追加：
  1. 初めてでも利用しやすい
  2. 落ち着いて飲める空間
  3. 入店時スタッフがお席にご案内（**指定表現をそのまま使用**）
  4. 華やかさと気軽さを両立
- `.guide-feature-grid` / `.guide-feature-card` は `first-guide.html` で既に使われていた汎用クラス（`.guide-page` にスコープされていない）をそのまま再利用。デスクトップは2x2、タブレットは2列、モバイルは1列になる既存のレスポンシブ定義を活用。

### 3. 新セクション「初めての麒麟」（漫画風説明）

- Concept セクションの直後、Cast セクションの直前に `#first-timer` セクションを新規追加。
- 既存の漫画画像 `assets/img/guide-flow1.webp` 〜 `guide-flow3.webp`（`first-guide.html` で使用中の素材）を再利用し、3枚のカード（`.guide-flow-grid.first-timer__grid` + `.guide-flow-card`）で構成：
  1. まずはご来店
  2. スタッフがお席にご案内（**指定表現を使用**）
  3. 会話を楽しむ
- セクション末尾に `first-guide.html` への誘導ボタン「麒麟の特徴をもっと見る」を配置。
- 画像差し替え時の注意: `guide-flow1〜5.webp` は `first-guide.html` の「ご来店の流れ」セクションでも使用されているため、削除・上書きする際は両ページに影響する。

### 4. Cast セクション（モバイル調整）

- `assets/css/style.css` の `@media (max-width: 768px)` 内、`.cast-card__photo` の `aspect-ratio` を `4 / 5` → `3 / 4.5` に変更（写真を縦方向に約25%拡大、横幅は変えず「太って見える」のを回避）。
- `.cast-card::after`（下部グラデーション）の高さをモバイルで `10rem` → `6.5rem` に縮小し、写真面積を確保。
- `.cast-list` の gap をモバイルで `0.75rem` → `0.6rem` に縮小。
- 2カラム構成（`grid-template-columns: repeat(2, minmax(0, 1fr))`）は維持。

### 5. FAQ セクション + 構造化データ

- 表示用 `<details>` リストと `FAQPage` の `mainEntity`（JSON-LD）の両方を以下6問に統一（同期済み）：
  1. 初めてでも利用できますか？
  2. 一人でも利用できますか？
  3. クレジットカードは使えますか？（VISA・Mastercard・JCB・American Express・Diners Clubに対応している旨を明記）
  4. 指名はできますか？（料金システムページへの誘導）
  5. 団体利用はできますか？
  6. 求人応募はできますか？
- 旧FAQ（営業時間・所在地など）はNightClubスキーマ側の情報と重複するため削除。

### 6. SEO / 構造化データ

- `<meta name="description">` / `og:description` に「岡山・柳町」「キャバクラ」「club 麒麟」「初めての方でも入りやすい」「入店時はスタッフがお席にご案内」を含むよう更新。
- `NightClub` の `description` にも「初めての方でも入店時にスタッフがお席にご案内する」旨を追記し、AI検索でも親しみやすさが伝わるようにした。
- `title` / `og:title` は既存のまま（岡山・柳町・キャバクラ・club麒麟を含み変更不要だったため）。
- `robots.txt` / `sitemap.xml` は変更なし（既存のまま壊していない）。

## 7. モバイル下部固定CTAバー（新規・全主要ページ）

`index.html` / `system.html` / `recruit.html` / `first-guide.html` の `</body>` 直前に `<nav class="mobile-cta-bar">` を追加。`@media (max-width: 1080px)` で表示（PC幅では `display: none`）。

- 構成は3ボタン：
  - **TEL**（`tel:0862340030`）
  - **GUIDE**（`first-guide.html` への誘導。recruit.html では `index.html#concept` 等ページに応じて変更）
  - **RECRUIT**（`recruit.html` または `#overview` への求人導線。強調背景色）
- `body` に `padding-bottom: 4.4rem`（`@media (max-width: 1080px)`）を追加し、固定バーがフッターやコンテンツに重ならないようにした。

### LINEボタンについて（重要・申し送り）

- 当初要件では「TEL / LINE / 求人」の3導線が提案されていたが、直前のコミット（`Remove LINE button from top headers`, 0af41a3）でLINEが「準備中」のため全ページのヘッダーから削除された経緯がある。
- この方針と矛盾しないよう、モバイル下部CTAバーの2番目のボタンは LINE ではなく **「初めての方へ」(GUIDE)** とした。
- 今後LINE公式アカウントが稼働開始したら、各ページの `mobile-cta-bar__item`（GUIDE枠）を LINE導線に差し替えることを推奨。`.line-cta` のスタイルは既存CSSに残っているため再利用可能。

## 8. 追加・変更したCSSクラス一覧（`assets/css/style.css`）

| クラス | 用途 |
|---|---|
| `.hero__title` / `.hero__title-eyebrow` / `.hero__title-main` | Hero H1の2階層表示 |
| `.hero .hero__catch` | Heroキャッチコピー（`.hero p` の border-top を打ち消すため `.hero` を前置） |
| `.concept-points` | Conceptカードグリッドの列数指定（`.guide-feature-grid` を流用） |
| `.first-timer .section-heading p` | 「初めての麒麟」セクションのリード文 |
| `.first-timer__grid` | 「初めての麒麟」カードグリッドの列数指定（`.guide-flow-grid` を流用） |
| `.first-timer .concept-cta` | セクション内CTAボタンの中央寄せ |
| `.mobile-cta-bar` / `.mobile-cta-bar__item` / `.mobile-cta-bar__item--recruit` | モバイル下部固定CTAバー本体 |

新規メディアクエリブロックをファイル末尾に2つ追加：
- `@media (max-width: 1080px)`: `.mobile-cta-bar` の表示と `body` の `padding-bottom`
- `@media (max-width: 768px)`: Hero タイトル／キャッチコピーの文字サイズ調整

既存の `@media (max-width: 768px)` ブロック内の `.cast-card__photo` / `.cast-card::after` / `.cast-list` も直接編集済み。

## 9. LINE予約の削除・クレジットカード対応の明記（追記修正）

- 麒麟ではLINE予約を実施していないため、`.contact-strip` 内の `<a class="line-cta" href="#top"><span>LINEで予約</span><small>準備中</small></a>` ブロックを削除。
  - `.contact-actions` には電話CTAのみが残る。
- `.price-box` の予約行を「電話・LINE受付」→「お電話にて受付」に変更。
- FAQ（表示用 `<details>` と `FAQPage` JSON-LD の両方）の「クレジットカードは使えますか？」の回答を、
  「はい、ご利用いただけます。VISA・Mastercard・JCB・American Express・Diners Clubなど主要なクレジットカードに対応しています。」
  に更新。
- `recruit.html` の求人問い合わせ用LINEリンク（`@371frwet`、「LINEでも求人問い合わせしてるよ」）は「LINE予約」とは無関係（求人応募導線）のため、変更・削除していない。
- `.line-cta` のCSSスタイルは `recruit.html` で使用中のため、CSS側の削除は行っていない。

## 確認済み事項

- `git diff --stat`: `index.html` / `first-guide.html` / `system.html` / `recruit.html` / `privacy.html` / `assets/css/style.css` の6ファイルを変更
- JSON-LD（`FAQPage` の `mainEntity`）はPythonで `json.loads` してパース可能であることを確認（6問）
- 全ページの `<h1>` は1つのみ、画像参照（`assets/img/...`）はすべて実ファイル存在を確認
- ライブプレビュー（ローカル静的サーバー）はサンドボックスのファイルシステム同期の問題で旧内容を返したため、実機ブラウザでの最終確認は未実施。**次の作業者は実機/ブラウザでのスマホ幅確認を行うこと。**

## 次にやるとよいこと（申し送り）

- LINE公式アカウント稼働後、モバイル下部CTAバーのGUIDEボタンをLINE導線に変更
- 「初めての麒麟」セクション用に専用の漫画画像（新規カット）を用意できれば、`guide-flow1〜3.webp` から差し替え可能（HTML構造はそのまま `src` だけ変更すればよい）
- 実機ブラウザでスマホ幅（375px前後）の表示確認、特に Hero のキャッチコピーの折り返しと Cast カードの見え方
