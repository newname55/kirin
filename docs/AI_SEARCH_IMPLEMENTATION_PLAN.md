# club 麒麟 Kirin AI検索対応 実装計画

## 方針

星華・クレオールと同じく、見た目だけのLPではなく、検索エンジンとAI回答エンジンが事実を拾いやすい静的サイトとして設計する。
公式ドメインは `https://clubkirin.com/` として扱う。

- 重要情報を画像内文字だけに閉じ込めず、HTML本文として掲載する
- 店舗名、住所、電話番号、営業時間、料金、求人、FAQをページ上とJSON-LDで整合させる
- 更新しやすい静的構成から始め、必要になった段階でニュース・キャスト・求人を分離する
- 実在キャスト写真と料金表は、運用側で確定後に差し替える

## 現在の構成

- `index.html`: LP本体、JSON-LD、FAQ、店舗情報
- `privacy.html`: プライバシーポリシー
- `assets/css/style.css`: 青黒基調のデザインシステム
- `assets/js/main.js`: モバイルナビ、ページ上部戻り
- `assets/img/*.webp`: Web用の軽量画像
- `robots.txt`, `sitemap.xml`: クロール導線

## 公開前に必ず確認する項目

- canonical、OG URL、JSON-LD、`robots.txt`、`sitemap.xml` が `https://clubkirin.com/` を指していること
- LINE予約URL
- Instagram URL
- TikTok URL
- 料金システムの確定情報
- キャスト写真、名前、年齢の公開可否
- 求人条件、応募導線
- 定休日と営業時間
- 既存公式サイトから取得した住所、電話番号、定休日と差異がないこと

## 段階的な拡張案

1. 静的LPとして公開し、Search Consoleとインデックス状況を確認する
2. `system.html`, `cast.html`, `recruit.html`, `access.html` を分割する
3. ニュースをJSONまたはCMS化し、更新担当者が触れる形にする
4. FAQ、料金、求人を構造化データとして追加拡張する
5. 実写真と動画を追加し、AI検索で引用されやすい説明本文を増やす

## rollback方針

静的サイトなので、公開時は旧ファイルをサーバー側で日時付きバックアップしてから同期する。問題が出た場合はバックアップディレクトリを戻すか、Gitの直前コミットへ戻して再同期する。
