# XServer デプロイ接続メモ

Project TWIN の本番デプロイ先候補となる XServer の接続情報まとめ。
秘密鍵の中身は記載しない。鍵ファイルは `~/.ssh/xserver_xs110262`。

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
