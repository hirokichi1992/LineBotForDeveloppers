<?php

require_once __DIR__ . '/src/lib.php';

// ----------------------------------------------------------------------------
// Load Environment Variables
// ----------------------------------------------------------------------------

// Load .env file if it exists
$dotenv_path = __DIR__ . '/.env';
if (file_exists($dotenv_path)) {
    $lines = file($dotenv_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (preg_match('/^\s*([^=]+)\s*=\s*(.*?)?\s*$/', $line, $matches)) {
            putenv(sprintf('%s=%s', $matches[1], $matches[2]));
            $_ENV[$matches[1]] = $matches[2];
            $_SERVER[$matches[1]] = $matches[2];
        }
    }
}

$channelAccessToken = getenv('LINE_CHANNEL_ACCESS_TOKEN');
$channelSecret = getenv('LINE_CHANNEL_SECRET');

if (!$channelAccessToken || !$channelSecret) {
    http_response_code(500);
    error_log('[ERROR] Missing LINE_CHANNEL_ACCESS_TOKEN or LINE_CHANNEL_SECRET');
    exit();
}

// ----------------------------------------------------------------------------
// Verify Request Signature
// ----------------------------------------------------------------------------

$signature = $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '';
if (empty($signature)) {
    http_response_code(400);
    error_log('[ERROR] Signature not set in request header.');
    exit();
}

$httpRequestBody = file_get_contents('php://input');
$hash = hash_hmac('sha256', $httpRequestBody, $channelSecret, true);
$expectedSignature = base64_encode($hash);

if ($signature !== $expectedSignature) {
    http_response_code(400);
    error_log('[ERROR] Invalid signature.');
    exit();
}

define('ROOT_PATH', __DIR__);
define('NOTIFICATIONS_DIR', ROOT_PATH . '/data/notifications');

// ----------------------------------------------------------------------------
// Handle Events
// ----------------------------------------------------------------------------

$events = json_decode($httpRequestBody, true);
if (empty($events['events'])) {
    http_response_code(200);
    error_log('[INFO] No events found.');
    exit();
}

foreach ($events['events'] as $event) {
    $type = $event['type'] ?? 'unknown';

    switch ($type) {
        case 'postback':
            handlePostbackEvent($event, $channelAccessToken);
            break;
        case 'message':
            if (($event['message']['type'] ?? 'unknown') === 'text') {
                handleTextMessage($event, $channelAccessToken);
            }
            break;
        default:
            error_log("[INFO] Ignoring event type: {$type}");
            break;
    }
}

/**
 * Handles postback events (e.g., quiz answers).
 */
function handlePostbackEvent(array $event, string $channelAccessToken): void
{
    $replyToken = $event['replyToken'];
    $postbackDataString = $event['postback']['data'];
    parse_str($postbackDataString, $postbackData);

    if (isset($postbackData['action']) && $postbackData['action'] === 'quiz_answer') {
        $isCorrect = $postbackData['is_correct'] === '1';

        if ($isCorrect) {
            $responseText = '正解です！🎉 さすがですね！';
        } else {
            $correctAnswer = $postbackData['correct_answer'] ?? '';
            $responseText = "残念、不正解です！\n正解は「{$correctAnswer}」でした。\n次もチャレンジしてみてくださいね！";
        }

        $replyMessage = [
            'type' => 'text',
            'text' => $responseText
        ];

        replyLineMessage($channelAccessToken, $replyToken, [$replyMessage]);
    }
}

/**
 * Handles text message events, specifically looking for a keyword to trigger notifications.
 */
function handleTextMessage(array $event, string $channelAccessToken): void
{
    $replyToken = $event['replyToken'];
    // strtolowerを削除し、前後の空白除去のみにすることで、マルチバイト文字の判定を確実にする
    $userMessage = trim($event['message']['text']);

    error_log("[INFO] Received text message: " . $userMessage);

    // Only trigger on specific keywords
    if ($userMessage !== '最新情報' && $userMessage !== 'news') {
        error_log("[INFO] Message did not match keywords. Ignoring.");
        return; // サイレントに終了
    }

    error_log("[INFO] Keyword matched. Looking for notification files in " . NOTIFICATIONS_DIR);

    $notificationFiles = glob(NOTIFICATIONS_DIR . '/*.json');

    // glob()が失敗したかどうかをチェック
    if ($notificationFiles === false) {
        error_log("[ERROR] glob() function failed to read notifications directory.");
        return; // エラー時はサイレントに終了
    }

    if (empty($notificationFiles)) {
        error_log("[INFO] No notification files found. Sending 'no news' message.");
        $reply = [
            'type' => 'text',
            'text' => '新しいお知らせはありませんでした。GitHub Actionsが1時間に1回、最新情報を確認していますので、しばらくしてからもう一度お試しください。'
        ];
        if (!replyLineMessage($channelAccessToken, $replyToken, [$reply])) {
            error_log("[ERROR] Failed to send 'no news' message.");
        }
        return;
    }

    error_log("[INFO] Found " . count($notificationFiles) . " notification files. Preparing carousel message.");

    $bubbles = [];
    foreach ($notificationFiles as $file) {
        $content = file_get_contents($file);
        $data = json_decode($content, true);
        if ($data && isset($data['contents'])) {
            $bubbles[] = $data['contents']; // バブルコンテンツを配列に追加
        }
    }

    if (empty($bubbles)) {
        error_log("[ERROR] Notification files were found, but failed to parse bubble content.");
        $reply = [
            'type' => 'text',
            'text' => '通知の準備中にエラーが発生しました。しばらくしてからもう一度お試しください。'
        ];
        replyLineMessage($channelAccessToken, $replyToken, [$reply]);
        return;
    }

    // カルーセルの上限は10件
    if (count($bubbles) > 10) {
        $bubbles = array_slice($bubbles, 0, 10);
        error_log("[INFO] Sliced bubbles to 10 for carousel limit.");
    }

    $carouselMessage = [
        'type' => 'flex',
        'altText' => '新着記事があります！',
        'contents' => [
            'type' => 'carousel',
            'contents' => $bubbles
        ]
    ];

    if (replyLineMessage($channelAccessToken, $replyToken, [$carouselMessage])) {
        error_log("[SUCCESS] Sent carousel message with " . count($bubbles) . " bubbles.");
        // 送信済みのファイルを削除
        foreach ($notificationFiles as $file) {
            unlink($file);
        }
        error_log("[INFO] Cleaned up sent notification files.");
    } else {
        error_log('[ERROR] Failed to send carousel message.');
    }
}

// Respond with 200 OK to LINE Platform
http_response_code(200);
error_log('[SUCCESS] Webhook processed successfully.');
