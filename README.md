# MDN Notifier for LINE

MDNブログの新着記事をLINEに通知するBotです。
GitHub Actionsを利用して、1時間に1回自動で新着を確認し、新しい記事があった場合にのみLINEにプッシュ通知を送信します。

## 必要なもの

- GitHubアカウント
- LINEアカウント
- LINE Developersアカウント

## セットアップ手順

このBotを動作させるには、いくつかの認証情報を設定する必要があります。

### 1. LINEチャネルの準備

1.  [LINE Developersコンソール](https://developers.line.biz/ja/)にログインします。
2.  新規プロバイダーを作成します（既にあれば不要）。
3.  **Messaging API** のチャネルを新規作成します。
4.  作成したチャネルの「チャネル基本設定」タブで、**チャネルシークレット**を確認します。
5.  「Messaging API設定」タブを開き、**チャネルアクセストークン（長期）**を発行します。
6.  作成したBotを自身のLINEアカウントで友達追加し、何かメッセージを送信してください。これにより、通知の送信先であるご自身のユーザーIDを後ほど確認できます。

### 2. ユーザーIDの確認

LINEチャネルのWebhook URLを設定することで、ユーザーIDを確認できます。一時的に [beeceptor](https://beeceptor.com/) などのサービスを利用して確認するのが簡単です。

1.  beeceptorで一時的なエンドポイントを作成します（例: `https://example.free.beeceptor.com`）。
2.  LINEチャネルの「Messaging API設定」にあるWebhook URLに、そのエンドポイントURLを貼り付け、「検証」ボタンを押します。
3.  LINEアプリからBotに何かメッセージを送ります。
4.  beeceptorの画面にリクエストが届き、そのJSONデータの中にあなたのユーザーID（`"userId": "Uxxxxxxxx..."`）が含まれています。

### 3. GitHubリポジトリの作成とコードのプッシュ

1.  ご自身のGitHubアカウントで、このBot用の新しいPublicリポジトリを作成します。
2.  このフォルダにあるファイル（`run.php`, `.github`フォルダなどすべて）を、作成したリポジトリにプッシュします。

### 4. GitHub Secretsの設定

次に、LINEの認証情報をGitHubリポジトリに安全に保存します。

1.  作成したGitHubリポジトリのページを開き、「Settings」タブに移動します。
2.  左側のメニューから「Secrets and variables」>「Actions」を選択します。
3.  「New repository secret」ボタンを押し、以下の2つのシークレットを登録します。

    -   **`LINE_CHANNEL_ACCESS_TOKEN`**: 
        - 値：手順1で取得した**チャネルアクセストークン（長期）**

    -   **`LINE_USER_ID`**: 
        - 値：手順2で取得したご自身の**LINEユーザーID**

## 動作の確認

設定が完了すれば、Botは1時間ごとに自動で実行されます。
すぐに動作を確認したい場合は、手動で実行することも可能です。

1.  リポジトリの「Actions」タブに移動します。
2.  左側のワークフローリストから「Send LINE Notification」を選択します。
3.  「Run workflow」というドロップダウンが表示されるので、ブランチを選択して「Run workflow」ボタンを押します。

実行ログを確認し、エラーが出ていなければ成功です。MDNブログに新しい記事があれば、LINEに通知が届きます。
