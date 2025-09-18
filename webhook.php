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
    if ($event['type'] !== 'postback') {
        continue; // Ignore non-postback events
    }

    $replyToken = $event['replyToken'];
    $postbackDataString = $event['postback']['data'];
    parse_str($postbackDataString, $postbackData);

    // --- Handle Quiz Answer ---
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

// Respond with 200 OK to LINE Platform
http_response_code(200);
error_log('[SUCCESS] Webhook processed successfully.');
