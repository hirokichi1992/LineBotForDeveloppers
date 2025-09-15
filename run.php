<?php
// ----------------------------------------------------------------------------
// 設定 (環境変数から取得)
// ----------------------------------------------------------------------------
$channelAccessToken = getenv('LINE_CHANNEL_ACCESS_TOKEN');
$userId = getenv('LINE_USER_ID');
$apiKey = getenv('AI_API_KEY');
$scrapingApiKey = getenv('SCRAPING_API_KEY');

if (!$channelAccessToken || !$userId) {
    die("[ERROR] Environment variables LINE_CHANNEL_ACCESS_TOKEN and LINE_USER_ID must be set.\n");
}

// ----------------------------------------------------------------------------
// 定数
// ----------------------------------------------------------------------------
$feeds = [
    [
        'name' => 'mdn',
        'url' => 'https://developer.mozilla.org/en-US/blog/rss.xml',
        'label' => 'MDN新着記事'
    ],
    [
        'name' => 'tech',
        'url' => 'https://yamadashy.github.io/tech-blog-rss-feed/feeds/rss.xml',
        'label' => '企業テックブログ新着記事'
    ],
    [
        'name' => 'php',
        'url' => 'https://php.net/feed.atom',
        'label' => 'PHP公式ニュース'
    ],
    [
        'name' => 'laravel_news',
        'url' => 'http://feed.laravel-news.com/',
        'label' => 'Laravel News'
    ],

];

define('LINE_API_URL', 'https://api.line.me/v2/bot/message/push');
define('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash-latest:generateContent');
define('BROWSERLESS_API_URL', 'https://chrome.browserless.io/content');

// ----------------------------------------------------------------------------
// ヘルパー関数
// ----------------------------------------------------------------------------

/**
 * URLから記事の本文を取得する
 * SCRAPING_API_KEYが設定されていればBrowserless.ioを、なければcURLを直接使う
 */
function getArticleText(string $url, string $scrapingApiKey): string {
    echo "[INFO] Fetching article content from: {$url}\n";

    if (!empty($scrapingApiKey)) {
        echo "[INFO] Using Browserless.io to fetch content.\n";
        $ch = curl_init(BROWSERLESS_API_URL . '?token=' . $scrapingApiKey);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['url' => $url]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    } else {
        echo "[INFO] Using direct cURL to fetch content.\n";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
    }

    $html = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200 || $html === false) {
        echo "[WARNING] Failed to fetch article content. HTTP Status: {$http_code}\n";
        return '';
    }

    // 簡単なHTMLクリーンアップ
    $text = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $html);
    $text = preg_replace('#<style(.*?)>(.*?)</style>#is', '', $text);
    $text = strip_tags($text);
    $text = preg_replace('/
+/s', ' ', $text); // 複数の改行を1つに
    return trim($text);
}

/**
 * Gemini APIを呼び出してテキストを要約する
 */
function getAiSummary(string $text, string $apiKey): string {
    if (empty($apiKey)) {
        echo "[INFO] AI_API_KEY is not set. Skipping AI summary.\n";
        return '';
    }
    if (empty($text)) {
        echo "[INFO] Article text is empty. Skipping AI summary.\n";
        return '';
    }

    echo "[INFO] Requesting AI summary...\n";
    $prompt = "以下の記事を日本語で200文字程度に要約してください。顧客ごとに合わせたスクラッチ開発をしているWeb系のエンジニアに対する要約であることも踏まえて単なる要約ではない業務に応用できるような提案も含めた形でお願いします。:\n\n" . mb_substr($text, 0, 15000); // 長すぎるテキストを切り詰める

    $data = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ],
        'generationConfig' => [
            'maxOutputTokens' => 256,
        ]
    ];

    $ch = curl_init(GEMINI_API_URL . '?key=' . $apiKey);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        echo "[WARNING] AI summary request failed. HTTP Status: {$http_code}\nResponse: {$response}\n";
        return '';
    }

    $result = json_decode($response, true);
    $summary = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';

    return trim($summary);
}


// ----------------------------------------------------------------------------
// メイン処理
// ----------------------------------------------------------------------------

foreach ($feeds as $feed) {
    $feed_name = $feed['name'];
    $rss_url = $feed['url'];
    $message_label = $feed['label'];
    $last_url_file = __DIR__ . '/last_notified_url_' . $feed_name . '.txt';

    echo "--------------------------------------------------\n";
    echo "[INFO] Processing feed: {$feed_name}\n";
    echo "[INFO] Fetching RSS feed from: {$rss_url}\n";

    $rss_content = @file_get_contents($rss_url);
    if ($rss_content === false) {
        echo "[WARNING] Failed to fetch RSS feed for {$feed_name}. Skipping.\n";
        continue;
    }

    $rss = simplexml_load_string($rss_content);
    if ($rss === false) {
        echo "[WARNING] Failed to parse RSS feed for {$feed_name}. Skipping.\n";
        continue;
    }

    // 最新の記事を取得 (RSS 2.0 / Atom両対応)
    $latest_item = $rss->channel->item[0] ?? $rss->item[0] ?? $rss->entry[0] ?? null;
    if (!$latest_item) {
        echo "[WARNING] Could not find any items in the RSS feed for {$feed_name}. Skipping.\n";
        continue;
    }

    // 各要素を取得 (RSS 2.0 / Atom両対応)
    $latest_url = (string)($latest_item->link['href'] ?? $latest_item->link);
    $latest_title = (string)$latest_item->title;
    $latest_pubDate = (string)($latest_item->pubDate ?? $latest_item->updated);

    echo "[INFO] Latest article found: {$latest_title} ({$latest_url})\n";

    $last_notified_url = file_exists($last_url_file) ? file_get_contents($last_url_file) : '';

    if ($latest_url === $last_notified_url) {
        echo "[INFO] No new articles found for {$feed_name}.\n";
        continue;
    }

    echo "[INFO] New article detected! Preparing to send LINE notification.\n";

    // --- 要約処理 ---
    $summary = '';
    $articleText = getArticleText($latest_url, $scrapingApiKey);
    $aiSummary = getAiSummary($articleText, $apiKey);

    if (!empty($aiSummary)) {
        $summary = $aiSummary;
        echo "[INFO] AI summary generated successfully.\n";
    } else {
        echo "[INFO] Falling back to description snippet for summary.\n";
        $description = strip_tags((string)($latest_item->description ?? $latest_item->summary));
        $summary = mb_substr($description, 0, 100);
        if (mb_strlen($description) > 100) {
            $summary .= '…';
        }
    }
    // --- 要約処理ここまで ---

    $message = sprintf(
        "【%s】\n%s\n%s\n\n--- (AI要約) ---\n%s\n\n%s",
        $message_label,
        $latest_title,
        date('Y/m/d H:i', strtotime($latest_pubDate)),
        $summary,
        $latest_url
    );

    $body = [
        'to' => $userId,
        'messages' => [
            ['type' => 'text', 'text' => $message]
        ]
    ];

    $ch = curl_init(LINE_API_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $channelAccessToken,
    ]);

    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code == 200) {
        echo "[SUCCESS] Notification sent successfully for {$feed_name}.\n";
        file_put_contents($last_url_file, $latest_url);
        echo "[INFO] Updated last notified URL to: {$latest_url}\n";
    } else {
        echo "[ERROR] Failed to send LINE notification for {$feed_name}. HTTP Status: {$http_code}\n";
        echo "[ERROR] Response: {$result}\n";
    }
    
    sleep(1);
}

echo "--------------------------------------------------\n";
echo "[INFO] All feeds processed. Exiting.\n";
exit(0);


