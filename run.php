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
// MDNのRSSフィードURL
define('RSS_URL', 'https://developer.mozilla.org/en-US/blog/rss.xml');
// LINE APIのエンドポイント
define('LINE_API_URL', 'https://api.line.me/v2/bot/message/push');
// 最後に通知した記事のURLを保存するファイル
define('LAST_URL_FILE', __DIR__ . '/last_notified_url.txt');

// ----------------------------------------------------------------------------
// メイン処理
// ----------------------------------------------------------------------------

echo "[INFO] Fetching RSS feed from: " . RSS_URL . "\n";
$rss_content = file_get_contents(RSS_URL);
if ($rss_content === false) {
    die("[ERROR] Failed to fetch RSS feed.\n");
}

$rss = simplexml_load_string($rss_content);
if ($rss === false) {
    die("[ERROR] Failed to parse RSS feed.\n");
}

// 最新の記事を取得 (RSSフィードの最初のitem)
$latest_item = $rss->channel->item[0];
$latest_url = (string)$latest_item->link;
$latest_title = (string)$latest_item->title;
$latest_pubDate = (string)$latest_item->pubDate;

echo "[INFO] Latest article found: {$latest_title} ({$latest_url})\n";

// 前回のURLを取得
$last_notified_url = file_exists(LAST_URL_FILE) ? file_get_contents(LAST_URL_FILE) : '';

// URLを比較して、新着でなければ終了
if ($latest_url === $last_notified_url) {
    echo "[INFO] No new articles found. Exiting.\n";
    exit(0);
}

echo "[INFO] New article detected! Preparing to send LINE notification.\n";

// LINEに送信するメッセージを作成
$message = sprintf(
    "【MDN新着記事】\n%s\n%s\n\n%s",
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
    echo "[SUCCESS] Notification sent successfully.\n";
    // 最後に通知したURLをファイルに保存
    file_put_contents(LAST_URL_FILE, $latest_url);
    echo "[INFO] Updated last notified URL to: {$latest_url}\n";
} else {
    echo "[ERROR] Failed to send LINE notification. HTTP Status: {$http_code}\n";
    echo "[ERROR] Response: {$result}\n";
    exit(1); // エラーで終了
}

exit(0);

