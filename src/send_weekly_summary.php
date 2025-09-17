<?php
// ----------------------------------------------------------------------------
// 設定 (環境変数から取得)
// ----------------------------------------------------------------------------
$channelAccessToken = getenv('LINE_CHANNEL_ACCESS_TOKEN');
$userId = getenv('LINE_USER_ID');
$apiKey = getenv('AI_API_KEY');

if (!$channelAccessToken || !$userId || !$apiKey) {
    die("[ERROR] Environment variables LINE_CHANNEL_ACCESS_TOKEN, LINE_USER_ID, and AI_API_KEY must be set.\n");
}

// ----------------------------------------------------------------------------
// 定数
// ----------------------------------------------------------------------------
define('WEEKLY_ARTICLES_FILE', dirname(__DIR__) . '/data/weekly_articles.json');
define('LINE_API_URL', 'https://api.line.me/v2/bot/message/push');
define('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash-latest:generateContent');

// ----------------------------------------------------------------------------
// メイン処理
// ----------------------------------------------------------------------------

echo "[INFO] Starting weekly summary process.\n";

// 1. 蓄積された記事データを読み込む
if (!file_exists(WEEKLY_ARTICLES_FILE)) {
    echo "[INFO] weekly_articles.json not found. No articles to summarize. Exiting.\n";
    exit(0);
}

$jsonContent = file_get_contents(WEEKLY_ARTICLES_FILE);
$articles = json_decode($jsonContent, true);

if (empty($articles)) {
    echo "[INFO] No articles in weekly_articles.json. Exiting.\n";
    // ファイルは存在するが空なので、削除しておく
    unlink(WEEKLY_ARTICLES_FILE);
    exit(0);
}

echo "[INFO] Found " . count($articles) . " articles to summarize.\n";

// 2. AIに渡すためのプロンプトを作成
$prompt = "以下は、今週開発者向けに配信された技術記事のリストです。\n";
$prompt .= "これらの記事全体を俯瞰し、今週の重要な技術トレンド、注目すべきニュース、セキュリティ情報などをまとめて、日本語のマークダウン形式で「週間サマリー」を作成してください。\n";
$prompt .= "特に重要なポイントを3〜5個の箇条書きでハイライトし、全体で400〜500字程度の読みやすい文章にしてください。\n\n";
$prompt .= "---記事リスト---\n";
foreach ($articles as $index => $article) {
    $prompt .= ($index + 1) . ". " . $article['title'] . " (タグ: " . implode(', ', $article['tags']) . ")\n";
    $prompt .= "   要約: " . $article['summary'] . "\n\n";
}
$prompt .= "---ここまで---";


// 3. Gemini APIを呼び出してサマリーを生成
$data = [
    'contents' => [
        ['parts' => [['text' => $prompt]]]
    ],
    'generationConfig' => [
        'maxOutputTokens' => 1024,
        'temperature' => 0.5
    ]
];

$ch = curl_init(GEMINI_API_URL . '?key=' . $apiKey);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code !== 200) {
    die("[ERROR] Failed to generate AI summary. HTTP Status: {$http_code}\nResponse: {$response}\n");
}

$result = json_decode($response, true);
$weeklySummary = $result['candidates'][0]['content']['parts'][0]['text'] ?? 'サマリーの生成に失敗しました。';

echo "[INFO] AI weekly summary generated successfully.\n";

// 4. LINE Flex Messageを作成
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

// 記事一覧をBodyに追加
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

$body = [
    'to' => $userId,
    'messages' => [$flexMessage],
];

// 5. LINEに送信
$ch_line = curl_init(LINE_API_URL);
curl_setopt($ch_line, CURLOPT_POST, true);
curl_setopt($ch_line, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch_line, CURLOPT_POSTFIELDS, json_encode($body));
curl_setopt($ch_line, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $channelAccessToken,
]);

$result_line = curl_exec($ch_line);
$http_code_line = curl_getinfo($ch_line, CURLINFO_HTTP_CODE);
curl_close($ch_line);

if ($http_code_line == 200) {
    echo "[SUCCESS] Weekly summary sent successfully.\n";
    // 6. 送信が成功したらファイルを削除
    unlink(WEEKLY_ARTICLES_FILE);
    echo "[INFO] weekly_articles.json has been deleted.\n";
} else {
    echo "[ERROR] Failed to send weekly summary. HTTP Status: {$http_code_line}\n";
    echo "[ERROR] Response: {$result_line}\n";
}

echo "[INFO] Weekly summary process finished.\n";
exit(0);

