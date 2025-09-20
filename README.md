# LINE Bot for Developers

開発者向けの技術ブログやニュースの新着記事をLINEで受け取れるBotです。

![richmenu](https://github.com/user-attachments/assets/16ac7599-8229-409b-95b0-f74b1a0a7789)

## 主な機能

- **オンデマンドな記事取得**: トーク画面で「最新情報」と送信すると、その時点での新着記事をカルーセル形式で受け取れます。
- **毎日の自動記事収集**: GitHub Actionsが1時間ごとに複数の技術系サイトのRSSフィードを巡回し、新着記事を収集・保存します。
- **週次のサマリー通知**: 週末に、その週に収集した記事のサマリーがプッシュ通知で届きます。
- **AIによる記事要約とタグ生成**: Google Gemini APIを利用し、記事の要約、関連タグ、三択クイズを自動で生成します。

## アーキテクチャ

このBotは、LINEの無料API上限を回避しつつ、柔軟な通知を実現するために、オンデマンドな応答（プル型）と週次のプッシュ通知を組み合わせたハイブリッド構成になっています。

1.  **記事の収集 (GitHub Actions)**
    - `.github/workflows/notify.yml` に基づき、1時間ごとに `scripts/run_daily.php` が実行されます。
    - スクリプトは `config/feeds.php` のRSSフィードをチェックし、新着記事を見つけます。
    - 新着記事ごとに、AIで要約・タグ・クイズを生成し、LINEのメッセージ配信用JSONファイルとして `data/notifications/` ディレクトリに保存します。
    - 週次サマリーのため、記事情報を `data/weekly_articles.json` にも追記します。
    - 最後に、生成されたデータファイルをリポジトリにコミットします。

2.  **記事の配信 (Render + Webhook)**
    - `webhook.php` が、RenderのようなPaaS上でWebサービスとして常時稼働します。
    - ユーザーがLINEで「最新情報」と送信すると、LINEプラットフォームから `webhook.php` にリクエストが送られます。
    - `webhook.php` は `data/notifications/` に保存されているJSONファイルをすべて読み込み、カルーセル形式のFlex Messageを組み立てて、応答メッセージとして送信します。
    - 送信後、処理済みのJSONファイルは削除されます。

3.  **週次サマリー (GitHub Actions)**
    - 週末に、GitHub Actionsが `scripts/run_weekly.php` を実行します。
    - このスクリプトは `data/weekly_articles.json` を読み込み、要約メッセージをLINEのPush API経由で送信します。

## セットアップ手順

### 1. リポジトリの準備

このリポジトリを自身のGitHubアカウントにフォーク（Fork）またはクローンします。

### 2. アプリケーションのデプロイ

RenderのようなPaaSにアプリケーションをデプロイします。以下はRenderでの手順です。

1.  RenderにGitHubアカウントでサインアップします。
2.  ダッシュボードで「New +」>「Web Service」を選択し、このリポジトリを接続します。
3.  以下の通り設定します。
    - **Environment**: `Docker` （リポジトリ内の`Dockerfile`が自動で使われます）
    - **Name**: 好きな名前（例: `line-bot-developpers`）
    - **Start Command**: 空欄のままにします。
    - **Instance Type**: `Free`
4.  「Create Web Service」をクリックしてデプロイします。

### 3. 環境変数の設定

デプロイしたサービスの「Environment」タブで、以下の環境変数を設定します。

- `LINE_CHANNEL_ACCESS_TOKEN`: LINE Developersコンソールのチャネルアクセストークン。
- `LINE_CHANNEL_SECRET`: LINE Developersコンソールのチャネルシークレット。
- `LINE_USER_ID`: 週次サマリーのプッシュ通知を受け取るあなたのLINEユーザーID。
- `AI_API_KEY`: （任意）Google AI Studioで取得したGemini APIキー。
- `SCRAPING_API_KEY`: （任意）記事のスクレイピングにBrowserless.ioなどを使う場合のAPIキー。

### 4. LINE Botの設定

1.  [LINE Developersコンソール](https://developers.line.biz/console/)の「Messaging API設定」を開きます。
2.  「Webhook URL」に、Renderで作成したサービスのURLの末尾に `/webhook.php` を付けたものを入力します。
    - 例: `https://your-service-name.onrender.com/webhook.php`
3.  「更新」を押し、「検証」ボタンで成功することを確認します。
4.  「応答メッセージ」機能をオフにし、Webhookからの応答のみが有効になるようにします。

### 5. GitHub Actionsの設定

GitHub Actionsが記事を収集したり、週次通知を送信したりできるように、GitHubリポジトリにシークレットを設定します。

1.  リポジトリの「Settings」>「Secrets and variables」>「Actions」を開きます。
2.  「New repository secret」ボタンを押し、以下のシークレットを登録します。（値はRenderに設定したものと同じです）
    - `LINE_CHANNEL_ACCESS_TOKEN`
    - `LINE_USER_ID`
    - `AI_API_KEY`
    - `SCRAPING_API_KEY`

以上でセットアップは完了です。1時間ごとに新着記事が自動で収集され、「最新情報」と送ることで記事を受け取れるようになります。

## フィードの管理

通知対象のRSSフィードは `config/feeds.php` で管理しています。このファイルを編集して、好きなサイトを追加・削除してください。
