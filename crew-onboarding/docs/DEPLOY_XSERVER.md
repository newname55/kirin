# XServer デプロイ接続メモ

Project TWIN の本番デプロイ先候補となる XServer の接続情報まとめ。
秘密鍵の中身は記載しない。鍵ファイルは `~/.ssh/xserver_xs110262`。

---

## クラブ麒麟（clubkirin.com）本番ディレクトリ構成（2026-06-30 安全化済み）

非公開ファイル（app/database/storage/docs/scripts、`config.local.php` 含む）が
`public_html/crew-onboarding/` 配下に直置きされ、Web経由で到達可能だった問題を解消。
`public_html` の外（FTPルート直下）に非公開ディレクトリを分離した。

### 本番構成

```
/                                          ← FTPルート = ドメインのホームディレクトリ
├── crew-onboarding-private/               ← 非公開（Web非公開）
│   ├── app/                               ← PHPロジック・config.local.php
│   ├── database/                          ← schema.sql・migrations
│   ├── storage/logs/                      ← app.log
│   ├── docs/
│   ├── scripts/
│   └── _legacy_backup_public_html_exposed/  ← 旧構成の退避（要・後日削除確認）
│
└── public_html/
    └── crew-onboarding/                   ← 公開（Web公開、URLは従来通り /crew-onboarding/）
        ├── index.php
        ├── admin.php
        ├── admin_export.php
        ├── admin_store_parse.php
        ├── admin_applicant.php
        ├── chat_api.php
        ├── _bootstrap.php                 ← CREW_PRIVATE_ROOT / CREW_PUBLIC_ROOT を定義
        ├── .htaccess                      ← Options -Indexes, .env/.git/composer/.sql/.md/.log拒否
        ├── assets/
        └── uploads/
```

### パス解決の仕組み

`public_html/crew-onboarding/_bootstrap.php` が各エントリーポイントPHPの先頭で読み込まれ、以下の定数を定義する。

```php
define('CREW_PRIVATE_ROOT', dirname(__DIR__, 2) . '/crew-onboarding-private');
define('CREW_PUBLIC_ROOT', __DIR__);
```

- `dirname(__DIR__, 2)` は `public_html/crew-onboarding/` から2階層上（FTPルート＝ホームディレクトリ）を指す。
- 公開側PHP（index.php・admin.php等）の `require_once dirname(__DIR__) . '/app/...'` は
  すべて `require_once CREW_PRIVATE_ROOT . '/app/...'` に置換済み。
- `app/ai_character_settings.php` のアップロード画像保存先のみ `CREW_PUBLIC_ROOT . '/uploads/characters/'` に変更
  （`CREW_PUBLIC_ROOT` 未定義時はローカル開発構成にフォールバック）。
- `app/` 内部の相互require（`__DIR__`・`dirname(__DIR__)` で `app/` 内の兄弟ファイルを参照する箇所）は
  移動の影響を受けないため変更不要。

### ローカル開発構成との違い

ローカルリポジトリ（`crew-onboarding/`）は従来通り `app/ database/ storage/ docs/ scripts/ public/` が
同一ディレクトリ配下に同居する構成のまま。本番用ファイルはリポジトリの `public/*.php` を元に
`_bootstrap.php` 読み込み追加・require パス置換を行ったコピーをデプロイする。

### デプロイ手順（再デプロイ時）

1. `crew-onboarding/public/*.php` を編集
2. `crew-onboarding-private` 用と `public_html/crew-onboarding` 用に分けてコピーし、
   require パスを `CREW_PRIVATE_ROOT` / `CREW_PUBLIC_ROOT` ベースに置換
3. `php -l` で構文チェック
4. FTPで `/crew-onboarding-private/` と `/public_html/crew-onboarding/` にそれぞれアップロード
5. `https://clubkirin.com/crew-onboarding/` と `/admin.php` の動作確認（Fatal error / Warning がないか）
6. `https://clubkirin.com/crew-onboarding/app/config.local.php` などが404になること（非公開化の確認）

### 退避済みの旧ファイル

`_legacy_backup_public_html_exposed/` に、旧 `public_html/crew-onboarding/{app,database,storage,scripts,public,INSTALL.md,README.md}` を
そのまま退避済み（削除はしていない）。新構成での動作確認が一定期間問題なければ削除して問題ない。

---

## Creole（TWIN 設置予定）

| 項目 | 値 |
|---|---|
| SSH Host alias | `xserver-new` |
| HostName | `sv17093.xserver.jp` |
| User | `xs110262` |
| Port | `10022` |
| IdentityFile | `~/.ssh/xserver_xs110262` |

### ディレクトリ構成

| 用途 | パス | SSH alias |
|---|---|---|
| リポジトリ置き場 | `/home/xs110262/repo/creole` | `xserver-new-repo` |
| 公開ディレクトリ | `/home/xs110262/xn--kckj5pc5f.club/public_html` | `xserver-new-site` |
| TWIN 設置予定 | `/home/xs110262/xn--kckj5pc5f.club/public_html/twin/` | — |

> ドメイン `xn--kckj5pc5f.club` はPunycodeのため、実際のドメイン名を別途確認してください。

### 接続コマンド早見

```bash
# 通常ログイン
ssh xserver-new

# リポジトリに直接移動
ssh xserver-new-repo

# 公開ディレクトリに直接移動
ssh xserver-new-site

# SCPでファイル転送（例）
scp -P 10022 file.php xs110262@sv17093.xserver.jp:/home/xs110262/xn--kckj5pc5f.club/public_html/
```

---

## EDEN / Style（参考）

| 項目 | 値 |
|---|---|
| HostName | `sv17110.xserver.jp` |
| User | `kubokuboben` |
| Port | `10022` |

| 用途 | パス | SSH alias |
|---|---|---|
| EDEN 公開 | `/home/kubokuboben/clubeden-okayama.com/public_html` | `eden-site` |
| Style 公開 | `/home/kubokuboben/okayama-style.com/public_html` | `style-site` |

---


## Raspberry Pi（開発・本番）

| 用途 | SSH alias | Tailscale IP |
|---|---|---|
| 開発・検証 | `raspi4` | `100.88.234.87` |
| WBSS 本番 | `raspi5` | `100.119.41.118` |

---

## TWIN XServer デプロイ手順（予定）

1. `ssh xserver-new-repo` でリポジトリに移動
2. `git pull origin main` で最新コードを取得
3. `app/config.local.php` に本番設定（DB・APIキー・store_key）を配置
4. DB は XServer の MySQL パネルで作成、`database/migrations/` を順番に実行
5. `public_html/twin/` へのシンボリックリンクまたはコピーで公開

> **注意**: `storage/logs/`・`public/uploads/` の書き込み権限、`app/config.local.php` の非公開を確認すること。
