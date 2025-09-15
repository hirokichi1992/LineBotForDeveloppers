<?php
// ----------------------------------------------------------------------------
// 設定 (環境変数から取得)
// ----------------------------------------------------------------------------
$channelAccessToken = getenv('LINE_CHANNEL_ACCESS_TOKEN');
$userId = getenv('LINE_USER_ID');

if (!$channelAccessToken || !$userId) {
    die("[ERROR] Environment variables LINE_CHANNEL_ACCESS_TOKEN and LINE_USER_ID must be set.\n");
}

// ----------------------------------------------------------------------------
// 定数
// ----------------------------------------------------------------------------
// 監視対象のRSSフィードリスト
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
    [
        'name' => 'php_weekly',
        'url' => 'https://phpweekly.com/feed',
        'label' => 'PHP Weekly'
    ]
];

// LINE APIのエンドポイント
define('LINE_API_URL', 'https://api.line.me/v2/bot/message/push');

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

    // 最新の記事を取得
    $latest_item = $rss->channel->item[0] ?? $rss->item[0] ?? null;
    if (!$latest_item) {
        echo "[WARNING] Could not find any items in the RSS feed for {$feed_name}. Skipping.\n";
        continue;
    }

    $latest_url = (string)$latest_item->link;
    $latest_title = (string)$latest_item->title;
    $latest_pubDate = (string)($latest_item->pubDate ?? $latest_item->updated);

    echo "[INFO] Latest article found: {$latest_title} ({$latest_url})\n";

    // 前回のURLを取得
    $last_notified_url = file_exists($last_url_file) ? file_get_contents($last_url_file) : '';

    // URLを比較して、新着でなければ次へ
    if ($latest_url === $last_notified_url) {
        echo "[INFO] No new articles found for {$feed_name}.\n";
        continue;
    }

    echo "[INFO] New article detected! Preparing to send LINE notification.\n";

    // LINEに送信するメッセージを作成
    $message = sprintf(
        "【%s】\n%s\n%s\n\n%s",
        $message_label,
        $latest_title,
        date('Y/m/d H:i', strtotime($latest_pubDate)),
        $latest_url
    );

    $body = [
        'to' => $userId,
        'messages' => [
            [
                'type' => 'text',
                'text' => $message,
            ],
        ],
    ];

    // LINE APIにリクエストを送信
    $ch = curl_init(LINE_API_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
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
        // 最後に通知したURLをファイルに保存
        file_put_contents($last_url_file, $latest_url);
        echo "[INFO] Updated last notified URL to: {$latest_url}\n";
    } else {
        echo "[ERROR] Failed to send LINE notification for {$feed_name}. HTTP Status: {$http_code}\n";
        echo "[ERROR] Response: {$result}\n";
        // このフィードでエラーが起きても、他のフィードの処理を続ける
    }
    
    // APIリクエストのレート制限を避けるために少し待機
    sleep(1);
}

echo "--------------------------------------------------\n";
echo "[INFO] All feeds processed. Exiting.\n";
exit(0);


