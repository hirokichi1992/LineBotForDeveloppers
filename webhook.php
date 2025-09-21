<?php
error_log('[DEBUG] webhook.php execution started.');

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
 * Handles text message events.
 * - If the message is "デバッグ" or "debug", it performs a system check and replies with the results.
 * - If the message is "最新情報", it sends unread notifications and archives them.
 * - If the message is "最新情報 [keyword]", it searches all notifications for the keyword.
 */
function handleTextMessage(array $event, string $channelAccessToken): void
{
    $replyToken = $event['replyToken'];
    $userMessage = trim($event['message']['text']);
    error_log("[INFO] Received text message: " . $userMessage);

    // --- Debug Mode ---
    if ($userMessage === 'デバッグ' || $userMessage === 'debug') {
        $notifDir = NOTIFICATIONS_DIR;
        $archiveDir = ROOT_PATH . '/data/archived_notifications';

        // Ensure archive directory exists for the test
        if (!is_dir($archiveDir)) {
            mkdir($archiveDir, 0777, true);
        }

        $debugInfo = "--- Gemini Bot Debug Info ---\n";
        $debugInfo .= "Notification Dir: {$notifDir}\n";
        $debugInfo .= "Archive Dir: {$archiveDir}\n\n";

        $debugInfo .= "[Directory Status]\n";
        $debugInfo .= "Notif Dir Exists: " . (is_dir($notifDir) ? 'Yes' : 'No') . "\n";
        $debugInfo .= "Notif Dir Readable: " . (is_readable($notifDir) ? 'Yes' : 'No') . "\n";
        $debugInfo .= "Notif Dir Writable: " . (is_writable($notifDir) ? 'Yes' : 'No') . "\n";
        $debugInfo .= "Archive Dir Exists: " . (is_dir($archiveDir) ? 'Yes' : 'No') . "\n";
        $debugInfo .= "Archive Dir Readable: " . (is_readable($archiveDir) ? 'Yes' : 'No') . "\n";
        $debugInfo .= "Archive Dir Writable: " . (is_writable($archiveDir) ? 'Yes' : 'No') . "\n\n";

        $debugInfo .= "[File Count]\n";
        $notifFiles = glob($notifDir . '/*.json') ?: [];
        $archiveFiles = glob($archiveDir . '/*.json') ?: [];
        $debugInfo .= "Unread files: " . count($notifFiles) . "\n";
        $debugInfo .= "Archived files: " . count($archiveFiles) . "\n\n";

        $debugInfo .= "[Rename Test]\n";
        if (!empty($notifFiles)) {
            $testFile = $notifFiles[0];
            $testFileName = basename($testFile);
            $destination = $archiveDir . '/' . $testFileName;
            $debugInfo .= "Attempting to move: {$testFileName}\n";

            $renameResult = rename($testFile, $destination);

            if ($renameResult) {
                $debugInfo .= "-> Success (true)\n";
                $moveBackResult = rename($destination, $testFile);
                $debugInfo .= "-> Moved back: " . ($moveBackResult ? 'Success' : 'Failed') . "\n";
            } else {
                $debugInfo .= "-> Failed (false)\n";
                $lastError = error_get_last();
                if ($lastError) {
                    $debugInfo .= "-> Last Error: " . $lastError['message'] . "\n";
                }
            }
        } else {
            $debugInfo .= "No files in notification dir to test rename.\n";
        }

        replyLineMessage($channelAccessToken, $replyToken, [['type' => 'text', 'text' => $debugInfo]]);
        return;
    }

    // --- Normal Operation ---
    $archiveDir = ROOT_PATH . '/data/archived_notifications';
    if (!is_dir($archiveDir)) {
        mkdir($archiveDir, 0777, true);
    }

    $parts = preg_split('/[\s　]+/u', $userMessage, 2);
    $command = $parts[0] ?? '';
    $keyword = $parts[1] ?? '';

    if ($command !== '最新情報' && $command !== 'news') {
        error_log("[INFO] Message did not match command. Ignoring.");
        return;
    }

    if (!empty($keyword)) {
        searchAndReply($replyToken, $channelAccessToken, $keyword, $archiveDir);
    } else {
        sendUnreadAndArchive($replyToken, $channelAccessToken, $archiveDir);
    }
}

/**
 * Searches both unread and archived notifications for a keyword and replies.
 */
function searchAndReply(string $replyToken, string $channelAccessToken, string $keyword, string $archiveDir): void
{
    error_log("[INFO] Search mode activated. Keyword: " . $keyword);
    $unreadFiles = glob(NOTIFICATIONS_DIR . '/*.json') ?: [];
    $archivedFiles = glob($archiveDir . '/*.json') ?: [];
    $allFiles = array_merge($unreadFiles, $archivedFiles);

    if (empty($allFiles)) {
        replyLineMessage($channelAccessToken, $replyToken, [['type' => 'text', 'text' => '検索対象の記事がありません。']]);
        return;
    }

    $foundBubbles = [];
    foreach ($allFiles as $file) {
        $content = file_get_contents($file);
        $data = json_decode($content, true);
        if (!$data || !isset($data['contents']['body']['contents'])) continue;

        $bodyContents = $data['contents']['body']['contents'];

        // 1. Extract Title
        $title = $bodyContents[0]['text'] ?? '';

        // 2. Extract Tags
        $tagString = '';
        if (isset($bodyContents[1]['contents']) && is_array($bodyContents[1]['contents'])) {
            $tags = array_map(fn($tagBox) => $tagBox['contents'][0]['text'] ?? '', $bodyContents[1]['contents']);
            $tagString = implode(' ', $tags);
        }

        // 3. Extract Summary
        $summary = '';
        foreach ($bodyContents as $box) {
            if (isset($box['contents'][0]['text']) && $box['contents'][0]['text'] === 'Summary') {
                $summary = $box['contents'][1]['text'] ?? '';
                break;
            }
        }

        // Search keyword in title, tags, or summary
        if (mb_stripos($title, $keyword) !== false || mb_stripos($summary, $keyword) !== false || mb_stripos($tagString, $keyword) !== false) {
            // Use filename as key for sorting
            $foundBubbles[basename($file)] = $data['contents'];
        }
    }

    if (empty($foundBubbles)) {
        replyLineMessage($channelAccessToken, $replyToken, [['type' => 'text', 'text' => "キーワード「{$keyword}」に一致する記事は見つかりませんでした。"]]);
        return;
    }

    // Sort by filename (timestamp) descending to show newest first
    krsort($foundBubbles);
    $bubblesToSend = array_slice(array_values($foundBubbles), 0, 10);

    $carouselMessage = createCarouselMessage($bubblesToSend, '検索結果');
    replyLineMessage($channelAccessToken, $replyToken, [$carouselMessage]);
}

/**
 * Sends the oldest unread notifications and archives them upon success.
 */
function sendUnreadAndArchive(string $replyToken, string $channelAccessToken, string $archiveDir): void
{
    error_log("[INFO] Default mode activated. Sending unread notifications.");
    $notificationFiles = glob(NOTIFICATIONS_DIR . '/*.json');

    if ($notificationFiles === false || empty($notificationFiles)) {
        $reply = ['type' => 'text', 'text' => '新しいお知らせはありませんでした。GitHub Actionsが1時間に1回、最新情報を確認していますので、しばらくしてからもう一度お試しください。'];
        replyLineMessage($channelAccessToken, $replyToken, [$reply]);
        return;
    }

    // Sort by filename (timestamp) to send the oldest first
    sort($notificationFiles);
    $filesToSend = array_slice($notificationFiles, 0, 10);

    $bubbles = [];
    foreach ($filesToSend as $file) {
        $content = file_get_contents($file);
        $data = json_decode($content, true);
        if ($data && isset($data['contents'])) {
            $bubbles[] = $data['contents'];
        }
    }

    if (empty($bubbles)) {
        $reply = ['type' => 'text', 'text' => '通知の準備中にエラーが発生しました。'];
        replyLineMessage($channelAccessToken, $replyToken, [$reply]);
        return;
    }

    $carouselMessage = createCarouselMessage($bubbles, '新着記事があります！');

    if (replyLineMessage($channelAccessToken, $replyToken, [$carouselMessage])) {
        error_log("[SUCCESS] Sent carousel message with " . count($bubbles) . " bubbles.");
        foreach ($filesToSend as $file) {
            $archivePath = $archiveDir . '/' . basename($file);
            rename($file, $archivePath);
        }
        error_log("[INFO] Archived " . count($filesToSend) . " notification files.");
    } else {
        error_log('[ERROR] Failed to send carousel message.');
    }
}

/**
 * Creates a LINE Flex Carousel message.
 */
function createCarouselMessage(array $bubbles, string $altText): array
{
    return [
        'type' => 'flex',
        'altText' => $altText,
        'contents' => [
            'type' => 'carousel',
            'contents' => $bubbles
        ]
    ];
}

// Respond with 200 OK to LINE Platform
http_response_code(200);
error_log('[SUCCESS] Webhook processed successfully.');
