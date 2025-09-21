# LINE Bot for Developers

## 概要

このプロジェクトは、開発者向けの技術記事をLINEで自動配信するBotです。RSSフィードから最新記事を収集し、Gemini APIによるAI分析（要約、タグ付け、クイズ生成）を行い、リッチなFlex Messageとしてユーザーに届けます。記事データはPostgreSQLデータベースで管理され、未読記事の段階的配信やキーワード検索が可能です。

## 機能

-   **RSSフィードからの自動記事収集**: 設定されたRSSフィードから定期的に最新記事を取得します。
-   **AIによる記事分析**: Gemini APIを利用して、記事の要約、関連タグの抽出、内容に関する三択クイズの生成を行います。
-   **LINE Flex Message**: 記事のタイトル、要約、タグ、クイズを視覚的に分かりやすい形式で配信します。
-   **データベースによる記事管理**: 記事データはPostgreSQLデータベースに保存され、効率的に管理されます。
-   **未読記事の段階的配信**: 「`最新情報`」コマンドで、未読の記事を古いものから最大10件ずつ配信します。配信済みの記事はアーカイブされます。
-   **キーワード検索**: 「`最新情報 [キーワード]`」コマンドで、過去に配信されたすべての記事（未読・アーカイブ済み含む）からキーワードに一致する記事を検索し、新しいものから最大10件表示します。
-   **クイズ機能**: 記事内容に関するクイズにLINEのPostbackアクションで回答できます。

## 技術スタック

-   **言語**: PHP
-   **LINE API**: LINE Messaging API
-   **AI**: Google Gemini API
-   **データベース**: PostgreSQL
-   **ホスティング**: Render
-   **定期実行**: GitHub Actions
-   **スクレイピング**: Browserless.io (オプション)

## セットアップ

### 1. Renderでのサービス準備

1.  **Web Serviceのデプロイ**: このリポジトリをRenderにデプロイし、PHPのWeb Serviceとして設定します。
2.  **PostgreSQLデータベースの作成**: RenderダッシュボードでPostgreSQLデータベースをFreeプランで作成します。
3.  **データベースの接続**: 作成したPostgreSQLデータベースを、BotのWeb Serviceの「Environment」設定で`DATABASE_URL`としてリンクします。

### 2. 環境変数の設定

BotのWeb Serviceの「Environment」設定画面で、以下の環境変数を設定してください。

-   `LINE_CHANNEL_ACCESS_TOKEN`: LINE Developersで取得したチャネルアクセストークン
-   `LINE_CHANNEL_SECRET`: LINE Developersで取得したチャネルシークレット
-   `AI_API_KEY`: Google Gemini APIのAPIキー
-   `SCRAPING_API_KEY`: Browserless.ioのAPIキー（記事の本文取得に必要、任意）
-   `DATABASE_URL`: Renderが自動で設定するPostgreSQLの接続URL（RenderのUIでDBをリンクすると自動設定されます）

### 3. フィード設定

`config/feeds.php`ファイルを編集し、Botが記事を収集するRSSフィードのURLと表示名を定義します。

```php
<?php
// config/feeds.php

return [
    'tech' => [
        'name' => 'tech', // 内部識別名
        'label' => 'Tech', // LINEで表示されるソース名
        'url' => 'https://tech.example.com/rss',
        'default_image_url' => 'https://example.com/default_tech_image.png', // オプション
    ],
    // 他のフィードも同様に追加
];
```

### 4. GitHub Actionsの設定

`.github/workflows/notify.yml`ファイルを編集し、`scripts/run_daily.php`が定期的に実行されるように設定します。これにより、記事の自動収集とデータベースへの保存が行われます。

### 5. 初期データ投入

`run_daily.php`が一度実行されると、データベースに`articles`テーブルが自動的に作成され、記事データが投入され始めます。手動で初回実行したい場合は、RenderのShellから`php scripts/run_daily.php`を実行することも可能です（RenderのShellは有料機能です）。

## 使い方

LINE Botに以下のメッセージを送信してください。

-   `最新情報`: 未読の最新記事を最大10件表示します。続けて送信すると次の10件が表示されます。
-   `最新情報 [キーワード]`: キーワードに一致する記事を検索し、新しいものから最大10件表示します。（例: `最新情報 PHP`）
-   クイズの回答: クイズが表示された場合、選択肢のボタンをタップして回答します。

## システムアーキテクチャのイメージ図

以下は、このBotの主要なコンポーネントとデータの流れを示すイメージ図の構成案です。Mermaidなどのツールで図に変換していただくと、より分かりやすくなります。

```mermaid
graph TD
    subgraph User Interaction
        A[LINE User] -- "メッセージ送信 (最新情報 / 最新情報 [キーワード])" --> B(LINE Platform)
        B -- "Webhook Event" --> C(LINE Bot Web Service)
        C -- "Flex Message返信" --> B
        B -- "メッセージ受信" --> A
    end

    subgraph Data Processing & Storage
        C -- "DB接続" --> D(PostgreSQL Database)
        D -- "記事データ保存/取得" --> C
    end

    subgraph Article Collection & Analysis
        E[GitHub Actions] -- "定期実行トリガー" --> F(LINE Bot run_daily.php)
        F -- "RSSフィード取得" --> G[外部RSSフィード]
        F -- "記事内容スクレイピング" --> H[Browserless.io (Optional)]
        F -- "AI分析 (要約/タグ/クイズ)" --> I[Google Gemini API]
        F -- "記事データ保存" --> D
    end

    style A fill:#fff,stroke:#333,stroke-width:2px
    style B fill:#00B900,stroke:#333,stroke-width:2px,color:#fff
    style C fill:#6495ED,stroke:#333,stroke-width:2px,color:#fff
    style D fill:#FFD700,stroke:#333,stroke-width:2px
    style E fill:#9370DB,stroke:#333,stroke-width:2px,color:#fff
    style F fill:#6495ED,stroke:#333,stroke-width:2px,color:#fff
    style G fill:#fff,stroke:#333,stroke-width:2px
    style H fill:#fff,stroke:#333,stroke-width:2px
    style I fill:#fff,stroke:#333,stroke-width:2px
```