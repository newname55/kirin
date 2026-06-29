# Project TWIN インストール手順

新規サーバーへの導入手順です。

---

## 必要環境

| 項目 | 要件 |
|---|---|
| PHP | 8.1 以上 |
| MySQL / MariaDB | 5.7 以上 |
| Web サーバー | Apache / Nginx |
| 必須 PHP 拡張 | pdo_mysql, mbstring, json |

---

## 1. ファイル配置

```bash
# Git からクローン（または FTP でアップロード）
git clone https://github.com/newname55/project_twin.git project_twin/

# このアプリのディレクトリ構成
project_twin/crew-onboarding/
├── app/
├── public/        ← ドキュメントルートに設定する
├── database/
└── storage/
```

---

## 2. データベース作成

XServer など共有ホスティングの場合は管理パネルから作成してください。

```sql
CREATE DATABASE `your_db_name`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
```

---

## 3. テーブル作成（1ファイルのみ）

**`database/schema.sql` を実行するだけで完了です。**

migration ファイルは既存 DB のアップグレード用であり、新規インストールには不要です。

```bash
# コマンドラインの場合
mysql -u your_user -p your_db_name < database/schema.sql

# phpMyAdmin の場合
「インポート」タブ → database/schema.sql を選択 → 実行
```

実行後に作成されるテーブル:

| テーブル | 用途 |
|---|---|
| `chat_sessions` | チャットセッション |
| `chat_messages` | チャットメッセージ |
| `event_logs` | イベントログ |
| `conversion_events` | LINE/Instagram タップ等のコンバージョン |
| `app_settings` | 応答モード・WBSS接続先等の設定 |
| `ai_usage_logs` | OpenAI 利用ログ |
| `ai_character_settings` | AI キャラクター設定 |

---

## 4. 設定ファイル作成

`app/config.local.php` を新規作成します（Git 管理外・FTP でアップロード）。

```php
<?php
return [
    'store_key'      => 'seika',   // 'seika' または 'creole'

    'openai_api_key' => 'sk-proj-xxxxxxxx',  // OpenAI API キー（任意）

    'db' => [
        'host'     => 'localhost',
        'database' => 'your_db_name',
        'username' => 'your_db_user',
        'password' => 'your_db_password',
    ],

    'admin_users' => [
        'your_admin_id' => '$2y$12$xxxxxxxx',  // password_hash() で生成
    ],
];
```

**パスワードハッシュの生成方法:**

```bash
php -r "echo password_hash('任意のパスワード', PASSWORD_BCRYPT) . PHP_EOL;"
```

---

## 5. ディレクトリ権限設定

```bash
chmod 755 storage/logs/
chmod 755 public/uploads/
```

---

## 6. 動作確認

| URL | 内容 |
|---|---|
| `https://your-domain/twin/public/` | チャット画面 |
| `https://your-domain/twin/public/admin.php` | 管理画面ログイン |

---

## 管理会社への依頼事項（FTP のみの場合）

自分でできること:
- FTP でファイルアップロード
- `app/config.local.php` の作成・アップロード

管理会社に依頼すること:

> 1. MySQL データベースの作成（文字コード: utf8mb4）
> 2. `database/schema.sql` の実行（phpMyAdmin のインポート機能で可）
> 3. `storage/logs/` ディレクトリへの書き込み権限付与
> 4. PHP 8.1 以上が使えるか確認

---

## 既存 DB のアップグレード（migration）

既に稼働中の DB に対してバージョンアップする場合のみ `database/migrations/` 内の SQL を番号順に実行してください。

| ファイル | 内容 | 対象バージョン |
|---|---|---|
| `002_add_intent_to_chat_messages.sql` | intent カラム追加 | v0.1 以前から更新 |
| `003_add_conversation_analytics.sql` | 分析カラム追加 | v0.3 以前から更新 |
| `006_create_app_settings.sql` | 設定テーブル作成 | v0.6 以前から更新 |
| `007_create_ai_usage_logs.sql` | AI 利用ログテーブル | v0.7 以前から更新 |
| `008_create_ai_character_settings.sql` | キャラクター設定テーブル | v0.9 以前から更新 |
| `009_add_store_key_to_logs.sql` | store_key カラム追加 | v1.0 以前から更新 |

**新規インストールの場合、migration は実行不要です。**
