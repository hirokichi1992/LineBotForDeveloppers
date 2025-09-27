# タスクリスト

## Phase 1: 環境構築と基本設定

- [ ] `composer.json` を初期化し、必要なPHP拡張機能（curl, json, pdo_pgsqlなど）を定義する。
- [ ] `Dockerfile` を作成し、PHPとApacheが動作するコンテナ環境を定義する。
- [ ] `schema.sql` に `articles` テーブルのCREATE文を定義する。
- [ ] `.env.example` ファイルを作成し、プロジェクトで利用する環境変数をリストアップする。
- [ ] `config/feeds.php` を作成し、収集対象のRSSフィードを管理する仕組みを構築する。

## Phase 2: コア機能の実装 (`src/lib.php`)

- [ ] `getDbConnection()`: `DATABASE_URL` 環境変数を解釈してPostgreSQLに接続するPDOインスタンスを返す関数を実装する。
- [ ] `fetchRssContent()`: cURLを使用してRSSフィードの内容を堅牢に取得する関数を実装する。
- [ ] `fetchArticleContent()`: 記事のURLから本文とOGP画像を取得するスクレイピング関数を実装する。
- [ ] `getAiAnalysis()`: Gemini APIと通信し、受け取ったテキストから要約・タグ・クイズをJSON形式で生成する関数を実装する。
- [ ] `getAiAnalysis()` に、複数のAPIキーとモデルを試行するフォールバック機能を追加する。
- [ ] `createFlexBubble()`: 記事やAIの分析結果を基に、LINE Flex MessageのJSON構造を生成する関数を実装する。
- [ ] `sendLineMessage()` / `replyLineMessage()`: LINE Messaging APIにリクエストを送信し、プッシュメッセージと応答メッセージを送信する関数を実装する。

## Phase 3: LINE対話機能の実装 (`public/webhook.php`)

- [ ] LINEからのWebhookリクエストの署名を検証するロジックを実装する。
- [ ] `message` イベントを処理し、テキストメッセージを解析するロジックを実装する。
- [ ] 「最新情報」コマンド（キーワードなし）に対応し、未読記事をDBから取得して返信する機能を実装する。
- [ ] 「最新情報 `[キーワード]`」コマンドに対応し、記事をキーワード検索して返信する機能を実装する。
- [ ] 取得した記事データを基に、カルーセル形式のFlex Messageを構築するロジックを実装する。
- [ ] ユーザーに応答後、配信した記事を `is_archived = true` に更新する処理を実装する。
- [ ] `postback` イベントを処理し、クイズの回答に対する正解・不正解を返信する機能を実装する。

## Phase 4: バッチ処理の実装 (`scripts/`)

- [ ] `run_daily.php`: `config/feeds.php` を読み込み、各フィードの記事を収集・分析・DB保存する日次バッチスクリプトを作成する。
- [ ] `run_weekly.php`: 過去1週間の記事をDBから集計し、AIでサマリーを生成して管理者にLINEで送信する週次バッチスクリプトを作成する。
- [ ] `setup_rich_menu.php`: `assets/richmenu.png` を読み込み、LINEのデフォルトリッチメニューとして登録するワンタイムスクリプトを作成する。

## Phase 5: デプロイと自動化

- [ ] `.github/workflows/notify.yml` を作成し、GitHub Actionsのワークフローを定義する。
- [ ] `run_daily.php` をスケジュール実行（8時間ごと）するジョブを定義する。
- [ ] `run_weekly.php` をスケジュール実行（週次）するジョブを定義する。
- [ ] `setup_rich_menu.php` を含む各ジョブを、手動（`workflow_dispatch`）でも実行可能にする。
- [ ] RenderのWeb Serviceとしてデプロイするための手順を `README.md` に文書化する。
- [ ] Renderの無料DBが90日で削除される件と、その手動更新手順を `README.md` に文書化する。
