# 2026-06-15 Kirin Website Implementation Summary

## 概要

KIRINサイトに対して、トップページの導線整理、初めての方向けLPの追加、求人ページの新規作成、画像のWebP化、不要画像の整理を行いました。

## 実装内容

### 1. トップページの導線を整理

- `index.html` のナビゲーションに「初めての方へ」を追加
- 求人導線を新規 `recruit.html` へ分離
- FAQ セクションをトップの基本情報として整理
- 画像参照のキャッシュバスターを更新

### 2. 「初めての方へ / コンセプト」LPを追加

- `first-guide.html` を LP として構成
- 麒麟の特徴、一般的なキャバクラとの違い、ご来店の流れ、おすすめ対象、CTA を整理
- 流れの漫画画像は `guide-flow1.webp` 〜 `guide-flow5.webp` を使用
- セクション見出しの重複感を減らし、LPらしい強弱をつけた

### 3. 求人ページを新規作成

- `recruit.html` を新規追加
- 募集要項、働きやすさ、応募の流れ、FAQ、応募導線、掲載先リンクを整理
- LINE と電話の応募導線をページ上部と下部に配置

### 4. 画像の最適化

- `assets/img/guide-flow1.png` 〜 `guide-flow5.png` を削除
- 同内容の WebP 版を生成して差し替え
- `hero.webp`、`interior.webp`、`glass.webp` も更新

### 5. 付随修正

- `privacy.html` の CSS バージョンを更新
- `sitemap.xml` に `first-guide.html` と `recruit.html` を追加

## 確認したこと

- `git diff --check` は通過
- `guide-flow` の参照先は `.webp` に更新済み
- 旧 PNG の流れ画像は削除済み

## 変更の意図

- 既存の高級感を保ちながら、初見ユーザーに伝わる導線を増やす
- 求人情報をトップと分離し、目的別に見やすくする
- 画像を軽量化して、運用時の表示負荷を下げる

