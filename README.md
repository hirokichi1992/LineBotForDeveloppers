# LINE Bot for Developers

開発者向けの技術ブログやニュースの新着記事をLINEに通知するBotです。

![richmenu](https://github.com/user-attachments/assets/16ac7599-8229-409b-95b0-f74b1a0a7789)

## 主な機能

- **毎日の新着記事通知**: 複数の技術系サイトのRSSフィードを監視し、新着記事を毎日LINEに通知します。
- **AIによる記事要約とタグ生成**: Google Gemini API (`gemini-2.0-flash-latest` モデル) を利用し、新着記事の本文からAIが要約を生成し、関連するタグを自動で付与します。これにより、記事の内容を素早く把握できます。
- **週次の人気記事まとめ**: 一週間分の記事の中から、特に注目度の高いものをまとめて通知します。
- **サーバーレス運用**: GitHub Actionsを利用して定期的にスクリプトを実行するため、別途サーバーを用意する必要はありません。

---

## 開発者向け情報

このプロジェクトのメンテナンスや改修を行う方向けの情報です。

### 必須環境

- PHP 8.1以上
- Composer
- 以下のPHP拡張機能:
    - `curl`
    - `json`
    - `mbstring`
    - `SimpleXML`
    - `libxml`

### セットアップ

1.  リポジトリをクローンします。
    ```bash
    git clone https://github.com/your-username/LineBotForDeveloppers.git
    cd LineBotForDeveloppers
    ```

2.  `.env.example` ファイルをコピーして `.env` ファイルを作成します。
    ```bash
    cp .env.example .env
    ```

3.  `.env` ファイルを編集し、以下の環境変数を設定します。

    -   **`LINE_CHANNEL_ACCESS_TOKEN`** (必須)
        -   LINE Developersコンソールで発行したチャネルアクセストークン（長期）を設定します。
    -   **`LINE_CHANNEL_SECRET`** (必須)
        -   LINE Developersコンソールで確認できるチャネルシークレットを設定します。
    -   **`LINE_USER_ID`** (必須)
        -   通知を受け取りたいLINEユーザーのIDを設定します。これは、Botと友達になった後にWebhook経由で取得できます。
    -   **`AI_API_KEY`** (任意)
        -   Google AI Studioなどで取得したGemini APIキーを設定します。設定しない場合、AI要約機能は無効になり、記事の冒頭が通知されます。

    ```dotenv
    LINE_CHANNEL_ACCESS_TOKEN="YOUR_CHANNEL_ACCESS_TOKEN"
    LINE_CHANNEL_SECRET="YOUR_CHANNEL_SECRET"
    LINE_USER_ID="YOUR_LINE_USER_ID"
    AI_API_KEY="YOUR_GEMINI_API_KEY"
    ```

### 主要なスクリプトとローカルでの実行

GitHub Actionsを使用せず、ローカルPCから直接スクリプトを実行することも可能です。
その場合は、[セットアップ](#セットアップ)を完了し、PHPがインストールされている必要があります。

- **日次通知の実行**
  `config/feeds.php` に基づいて新着記事をチェックし、LINEに通知します。
  ```bash
  php scripts/run_daily.php
  ```

- **週次通知の実行**
  `data/weekly_articles.json` をもとに週次の人気記事を通知します。
  ```bash
  php scripts/run_weekly.php
  ```

- **リッチメニューの作成・更新**
  LINEのチャット画面に表示されるリッチメニューを作成・更新します。
  ```bash
  php scripts/create_rich_menu.php
  ```

### フィードの管理

通知を受け取りたいRSSフィードは `config/feeds.php` で管理されています。
新しいフィードを追加したり、既存のフィードを変更・削除したりする場合は、このファイルを編集してください。

```php
// config/feeds.php

return [
    [
        'id' => 'publickey',
        'url' => 'https://www.publickey1.jp/atom.xml',
    ],
    [
        'id' => 'codezine',
        'url' => 'https://codezine.jp/rss/new/20/index.xml',
    ],
    // ... 他のフィード
];
```

### 定期実行

このBotはGitHub Actionsによって自動で実行されます。

- **設定ファイル**: `.github/workflows/notify.yml`
- **スケジュール**: 毎日定時に `scripts/run_daily.php` を実行するように設定されています（cron式で指定）。

```yaml
# .github/workflows/notify.yml
name: Notify
on:
  schedule:
    - cron: '0 22 * * *' # UTCの22:00 (JSTの7:00) に毎日実行
# ...
```

### ディレクトリ構成

```
.
├── .env.example          # 環境変数のサンプル
├── .github/workflows/    # GitHub Actions ワークフロー
│   └── notify.yml
├── config/
│   └── feeds.php         # 監視対象のRSSフィードリスト
├── data/                 # 処理データを保存するディレクトリ
│   ├── last_notified_url_*.txt # 各フィードの最後に通知した記事URL
│   └── weekly_articles.json    # 週次通知用の記事データ
├── scripts/              # 実行スクリプト
│   ├── create_rich_menu.php # リッチメニュー作成
│   ├── run_daily.php        # 日次通知
│   └── run_weekly.php       # 週次通知
├── src/
│   └── lib.php           # 共通処理をまとめたライブラリ
└── README.md             # このファイル
```