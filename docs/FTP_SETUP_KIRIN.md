# 麒麟サイト FTP / VSCode SFTP 運用メモ

作成日: 2026-06-23

## 目的

麒麟サイトの新しい静的サイト制作フォルダと、FTP ダウンロード途中で混入した旧 WordPress ファイル群をローカル上で分離する。

この整理はローカル作業のみを対象とし、リモート FTP へのアップロード、削除、同期は行わない。

## 現在のローカル構成

- 静的サイト本体: プロジェクト直下の `index.html`, `cast-*.html`, `recruit.html`, `assets/` など
- 旧 WordPress バックアップ: `_wp_backup_partial/`
- VSCode SFTP 設定: `.vscode/sftp.json`

`public_html/` は削除せず、`_wp_backup_partial/` にリネーム済み。

## VSCode SFTP 設定方針

`.vscode/sftp.json` は以下の方針で運用する。

- `uploadOnSave` は必ず `false`
- `remotePath` は `/` のまま維持
- SFTP Sync / Upload / Download は手動確認なしに実行しない
- 旧 WordPress バックアップや WordPress の重いディレクトリは ignore する
- パスワード、鍵、接続情報はドキュメントへ転記しない

## Git管理除外方針

Git 管理では、FTP 接続時にローカルへ混入しやすいサーバー由来ファイルと認証まわりを除外する。

- `.vscode/sftp.json` は Git 管理しない
- `_wp_backup_partial/` は旧 WordPress のローカル退避として Git 管理しない
- `mail/` はサーバー同期由来のメール関連ファイルとして Git 管理しない
- `xserver_php/` はホスティング環境由来の PHP 設定群として Git 管理しない
- 静的サイト本体の編集対象は直下の HTML と `assets/` に限定する

## ignore 追加項目

以下を `.vscode/sftp.json` の `ignore` に含める。

```json
[
  "_wp_backup_partial",
  "_wp_backup_partial/**",
  "public_html/wp-admin",
  "public_html/wp-admin/**",
  "public_html/wp-includes",
  "public_html/wp-includes/**",
  "public_html/wp-content/uploads",
  "public_html/wp-content/uploads/**"
]
```

`public_html/...` の ignore は、将来誤って同名フォルダが再生成された場合の事故防止として残す。

## 禁止事項

- `rm -rf` で旧 WordPress ファイルを削除しない
- VSCode SFTP の Sync / Upload / Download を実行しない
- `_wp_backup_partial/` または `public_html/` を本番へアップロードしない
- FTP パスワードや秘密情報をログ、Markdown、Git 管理ファイルへ書かない

## 次回以降の作業手順

1. 静的サイトの編集対象はプロジェクト直下の HTML と `assets/` に限定する。
2. 作業前に `.vscode/sftp.json` の `uploadOnSave: false` を確認する。
3. アップロード前に `git status --short` と SFTP ignore を確認する。
4. `_wp_backup_partial/` は旧 WordPress 参照用として扱い、静的サイト制作物とは混ぜない。
5. 旧 WordPress の完全バックアップが必要な場合は、別作業として保存先と取得範囲を明確にしてから実行する。

## rollback 方針

ローカル整理を戻す必要がある場合のみ、以下を実行する。

```bash
mv _wp_backup_partial public_html
```

ただし、戻した後も VSCode SFTP の Sync / Upload / Download は実行しない。

## favicon運用方針

- `<link rel="icon" href="/favicon.ico">` を全ページで統一する
- favicon 実体は既存 WordPress 側の `/favicon.ico` を流用する
- テスト公開先が `/public_html/new` でも、ルートの favicon を参照する前提で扱う
- favicon を静的サイト用に複製する場合は、別作業として配置先と上書き範囲を明確にする

## `/new` テスト公開手順

`/public_html/new/` は既存 WordPress と分離した静的テスト公開先として扱う。

### 最小アップロード対象

- `index.html`
- `assets/`

### 追加アップロード対象

- `cast-*.html`
- `cast-list.html`
- `first-guide.html`
- `privacy.html`
- `recruit.html`
- `robots.txt`
- `sitemap.xml`
- `system.html`

### 配置の考え方

- HTML 内の相対パスは `/public_html/new/` 配下でもそのまま機能する
- `assets/` はページ群と同じ階層関係を保って配置する
- `favicon` は `/favicon.ico` を参照するので、`/public_html/` 直下の既存 WordPress 側資産を流用する
- `robots.txt` と `sitemap.xml` は現状の内容が本番ルート前提のため、`/new` を一般公開する前は URL 露出方針を再確認する

### 置かないもの

- `_wp_backup_partial/`
- `.vscode/sftp.json`
- `mail/`
- `xserver_php/`
- WordPress の `wp-admin`, `wp-includes`, `wp-content/uploads`

### 事前チェック

1. `index.html` と追加ページの `<link rel="icon" href="/favicon.ico">` を確認する
2. HTML から参照される `assets/` のファイル不足がないか確認する
3. `assets/` の未参照で重い画像は `/new` に載せない
4. `robots.txt` と `sitemap.xml` は本番切替直前に最終確認する
5. `git status --short` で意図した差分だけがあることを確認する

## deploy-ftp.sh方式

星華サイトと同じく、麒麟サイトでも `lftp` ベースの手動デプロイに統一する。

- デプロイスクリプトは `deploy-ftp.sh` を使う
- 実際の接続情報は `.env.ftp` にだけ置く
- `.env.ftp` は Git 管理しない
- `.env.ftp.example` を雛形として使い、人間が `.env.ftp` を作成する
- 今回の初期対象は `/public_html/new` のみ
- `public_html` 直下の本番 WordPress には触らない
- HTML 類は `put`、`assets/` は `mirror -R` で同期する

### 実行前の確認

1. `.env.ftp` が存在することを確認する
2. `FTP_REMOTE` が `/public_html/new` であることを確認する
3. `deploy-ftp.sh` の除外対象に作業用ファイルが入っていることを確認する
4. `bash -n deploy-ftp.sh` で構文を確認する
5. 実行は人間が手動で行い、`public_html` 直下へ向かないことを再確認する

## 本番切替手順

本番切替は `deploy-ftp.sh` を使う。

- `FTP_REMOTE` は `/public_html` の時だけ動く
- スクリプト内では WordPress の削除や退避はしない
- `index.html` と各 HTML 類は `put`
- `assets/` は `mirror -R`
- `robots.txt` と `sitemap.xml` は本番 URL 用の内容として反映する
- 実行前に `public_html` 直下へ既存 WordPress が残っていないことを人間が確認する

### ロールバック手順

問題が出た場合は、反映前に退避していた WordPress 側へ人間が戻す。

1. 直前の本番状態を確認する
2. 静的ファイルを `/public_html` から止める
3. 必要なら退避済み WordPress を `/public_html` に戻す
4. `robots.txt` と `sitemap.xml` を旧運用へ戻す

## Instagramウィジェット削除

- `index.html` から LightWidget の埋め込み `script` と `iframe` は削除する
- Instagram への導線は `@clubkirin` のテキストリンクまたはボタンで残す
- `assets/css/style.css` から Instagram フィード専用の未使用スタイルを削除する
- 外部JS依存を増やさず、スマホ表示速度と安定性を優先する
- `git diff --check` を通してから公開作業に進む
