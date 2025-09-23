<?php
// scripts/run_weekly.php

require_once __DIR__ . '/../src/lib.php';
define('ROOT_PATH', dirname(__DIR__));

// Load .env file
$dotenv_path = ROOT_PATH . '/.env';
if (file_exists($dotenv_path)) {
    $lines = file($dotenv_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (preg_match('/^\s*([^=]+)\s*=\s*(.*?)?\s*$/', $line, $matches)) {
            putenv(sprintf('%s=%s', $matches[1], $matches[2]));
        }
    }
}

// Load environment variables
$channelAccessToken = getenv('LINE_CHANNEL_ACCESS_TOKEN');
$userId = getenv('LINE_USER_ID');
$apiKey = getenv('AI_API_KEY');

if (!$channelAccessToken || !$userId || !$apiKey) {
    die("[ERROR] Environment variables LINE_CHANNEL_ACCESS_TOKEN, LINE_USER_ID, and AI_API_KEY must be set.\n");
}

echo "[INFO] Starting weekly summary process.\n";

try {
    $pdo = getDbConnection();

    // 1. Fetch articles from the last 7 days from the database
    $stmt = $pdo->prepare(
        "SELECT title, summary, tags, url FROM articles 
         WHERE created_at >= NOW() - INTERVAL '7 days' 
         ORDER BY published_at DESC"
    );
    $stmt->execute();
    $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($articles)) {
        echo "[INFO] No articles found in the last 7 days. Exiting.\n";
        exit(0);
    }

    echo "[INFO] Found " . count($articles) . " articles from the last week to summarize.\n";

    // 2. Create prompt for AI
    $prompt = "以下は、今週開発者向けに配信された技術記事のリストです。\n";
    $prompt .= "これらの記事全体を俯瞰し、今週の重要な技術トレンド、注目すべきニュース、セキュリティ情報などをまとめて、日本語のマークダウン形式で「週間サマリー」を作成してください。\n";
    $prompt .= "特に重要なポイントを3〜5個の箇条書きでハイライトし、全体で400〜500字程度の読みやすい文章にしてください。\n\n";
    $prompt .= "---記事リスト---\n";
    foreach ($articles as $index => $article) {
        $prompt .= ($index + 1) . ". " . $article['title'] . " (タグ: " . $article['tags'] . ")\n";
        $prompt .= "   要約: " . $article['summary'] . "\n\n";
    }
    $prompt .= "---ここまで---";

    // 3. Call Gemini API to generate summary
    $aiSummary = getAiAnalysis($prompt, $apiKey);
    $weeklySummary = $aiSummary['summary'];
    if (empty($weeklySummary)) {
        $weeklySummary = '今週のサマリー生成に失敗しました。';
    }

    echo "[INFO] AI weekly summary generated successfully.\n";

    // 4. Create LINE Flex Message
    $bubble = [
        'type' => 'bubble',
        'styles' => [
            'header' => ['backgroundColor' => '#1E2A38'],
            'body'   => ['backgroundColor' => '#2D3748'],
        ],
        'header' => [
            'type' => 'box',
            'layout' => 'vertical',
            'contents' => [
                [
                    'type' => 'text',
                    'text' => '【🤖 週間テックサマリー】',
                    'weight' => 'bold',
                    'color' => '#1DB446',
                    'size' => 'lg',
                ],
                 [
                    'type' => 'text',
                    'text' => date('Y/m/d', strtotime('last monday')) . ' - ' . date('Y/m/d'),
                    'color' => '#A0AEC0',
                    'size' => 'sm',
                    'margin' => 'md'
                ]
            ],
            'paddingAll' => '12px',
        ],
        'body' => [
            'type' => 'box',
            'layout' => 'vertical',
            'spacing' => 'md',
            'contents' => [
                [
                    'type' => 'text',
                    'text' => $weeklySummary,
                    'wrap' => true,
                    'size' => 'sm',
                    'color' => '#E2E8F0',
                ],
                [
                    'type' => 'separator',
                    'margin' => 'xl'
                ],
                [
                    'type' => 'text',
                    'text' => '今週の記事一覧 (全' . count($articles) . '件)',
                    'size' => 'xs',
                    'color' => '#A0AEC0',
                    'margin' => 'lg'
                ]
            ],
        ],
    ];

    foreach($articles as $article) {
        $bubble['body']['contents'][] = [
            'type' => 'box',
            'layout' => 'horizontal',
            'margin' => 'lg',
            'contents' => [
                [
                    'type' => 'text',
                    'text' => '●',
                    'size' => 'xs',
                    'color' => '#1DB446',
                    'flex' => 0,
                    'margin' => 'sm'
                ],
                [
                    'type' => 'text',
                    'text' => $article['title'],
                    'wrap' => true,
                    'size' => 'sm',
                    'color' => '#FFFFFF',
                    'action' => [
                        'type' => 'uri',
                        'label' => '記事を読む',
                        'uri' => $article['url']
                    ]
                ]
            ]
        ];
    }

    $flexMessage = [
        'type' => 'flex',
        'altText' => '週間テックサマリーが届きました！',
        'contents' => $bubble,
    ];

    // 5. Send message
    if (sendLineMessage($channelAccessToken, $userId, [$flexMessage])) {
        echo "[SUCCESS] Weekly summary sent successfully.\n";
    } else {
        echo "[ERROR] Failed to send weekly summary.\n";
    }

} catch (Exception $e) {
    die("[ERROR] An exception occurred: " . $e->getMessage() . "\n");
}

echo "[INFO] Weekly summary process finished.\n";
exit(0);
