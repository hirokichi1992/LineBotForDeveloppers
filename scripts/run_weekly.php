<?php
// ----------------------------------------------------------------------------
// Setup
// ----------------------------------------------------------------------------
require_once __DIR__ . '/../src/lib.php';

// Define root path for consistent file access
define('ROOT_PATH', dirname(__DIR__));

// Load environment variables
$channelAccessToken = getenv('LINE_CHANNEL_ACCESS_TOKEN');
$userId = getenv('LINE_USER_ID');
$apiKey = getenv('AI_API_KEY');

if (!$channelAccessToken || !$userId || !$apiKey) {
    die("[ERROR] Environment variables LINE_CHANNEL_ACCESS_TOKEN, LINE_USER_ID, and AI_API_KEY must be set.\n");
}

// Define constants used in lib.php and this script
define('LINE_API_URL', 'https://api.line.me/v2/bot/message/push');
define('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash-latest:generateContent');
define('WEEKLY_ARTICLES_FILE', ROOT_PATH . '/data/weekly_articles.json');

// ----------------------------------------------------------------------------
// Main Processing
// ----------------------------------------------------------------------------

echo "[INFO] Starting weekly summary process.\n";

// 1. Read accumulated articles
if (!file_exists(WEEKLY_ARTICLES_FILE)) {
    echo "[INFO] weekly_articles.json not found. No articles to summarize. Exiting.\n";
    exit(0);
}

$jsonContent = file_get_contents(WEEKLY_ARTICLES_FILE);
$articles = json_decode($jsonContent, true);

if (empty($articles)) {
    echo "[INFO] No articles in weekly_articles.json. Exiting.\n";
    unlink(WEEKLY_ARTICLES_FILE);
    exit(0);
}

echo "[INFO] Found " . count($articles) . " articles to summarize.\n";

// 2. Create prompt for AI
$prompt = "以下は、今週開発者向けに配信された技術記事のリストです。\n";
$prompt .= "これらの記事全体を俯瞰し、今週の重要な技術トレンド、注目すべきニュース、セキュリティ情報などをまとめて、日本語のマークダウン形式で「週間サマリー」を作成してください。\n";
$prompt .= "特に重要なポイントを3〜5個の箇条書きでハイライトし、全体で400〜500字程度の読みやすい文章にしてください。\n\n";
$prompt .= "---記事リスト---\n";
foreach ($articles as $index => $article) {
    $prompt .= ($index + 1) . ". " . $article['title'] . " (タグ: " . implode(', ', $article['tags']) . ")\n";
    $prompt .= "   要約: " . $article['summary'] . "\n\n";
}
$prompt .= "---ここまで---";


// 3. Call Gemini API to generate summary
$data = [
    'contents' => [
        ['parts' => [['text' => $prompt]]]
    ],
    'generationConfig' => [
        'maxOutputTokens' => 1024,
        'temperature' => 0.5
    ]
];

$ch_gemini = curl_init(GEMINI_API_URL . '?key=' . $apiKey);
curl_setopt($ch_gemini, CURLOPT_POST, true);
curl_setopt($ch_gemini, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch_gemini, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch_gemini, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch_gemini, CURLOPT_SSL_VERIFYPEER, false);

$response = curl_exec($ch_gemini);
$http_code = curl_getinfo($ch_gemini, CURLINFO_HTTP_CODE);
curl_close($ch_gemini);

if ($http_code !== 200) {
    die("[ERROR] Failed to generate AI summary. HTTP Status: {$http_code}\nResponse: {$response}\n");
}

$result = json_decode($response, true);
$weeklySummary = $result['candidates'][0]['content']['parts'][0]['text'] ?? 'サマリーの生成に失敗しました。';

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

// 5. Send message using the library function
if (sendLineMessage($channelAccessToken, $userId, [$flexMessage])) {
    // 6. Cleanup the data file
    unlink(WEEKLY_ARTICLES_FILE);
    echo "[INFO] weekly_articles.json has been deleted.\n";
}

echo "[INFO] Weekly summary process finished.\n";
exit(0);