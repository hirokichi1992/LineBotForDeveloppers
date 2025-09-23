<?php
error_log('[DEBUG] webhook.php execution started.');

require_once __DIR__ . '/src/lib.php';

// ----------------------------------------------------------------------------
// Load Environment Variables
// ----------------------------------------------------------------------------
$dotenv_path = __DIR__ . '/.env';
if (file_exists($dotenv_path)) {
    $lines = file($dotenv_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (preg_match('/^\s*([^=]+)\s*=\s*(.*?)?\s*$/', $line, $matches)) {
            putenv(sprintf('%s=%s', $matches[1], $matches[2]));
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
    // This function remains unchanged.
    $replyToken = $event['replyToken'];
    $postbackDataString = $event['postback']['data'];
    parse_str($postbackDataString, $postbackData);

    if (isset($postbackData['action']) && $postbackData['action'] === 'quiz_answer') {
        $isCorrect = $postbackData['is_correct'] === '1';
        $responseText = $isCorrect ? '正解です！🎉 さすがですね！' : "残念、不正解です！\n正解は「{$postbackData['correct_answer']}」でした。";
        replyLineMessage($channelAccessToken, $replyToken, [['type' => 'text', 'text' => $responseText]]);
    }
}

/**
 * Handles text message events by querying the database.
 */
function handleTextMessage(array $event, string $channelAccessToken): void
{
    $replyToken = $event['replyToken'];
    $userMessage = trim($event['message']['text']);
    error_log("[INFO] Received text message: " . $userMessage);

    $parts = preg_split('/[\s　]+/u', $userMessage, 2);
    $command = $parts[0] ?? '';
    $keyword = $parts[1] ?? '';

    if ($command !== '最新情報' && $command !== 'news') {
        return; // Ignore messages that are not commands
    }

    try {
        $pdo = getDbConnection();
        $bubbles = [];
        $articleIds = [];

        if (!empty($keyword)) {
            // --- Keyword Search Mode ---
            error_log("[INFO] Search mode. Keyword: {$keyword}");
            $stmt = $pdo->prepare(
                "SELECT id, flex_message_json FROM articles 
                 WHERE title ILIKE :keyword OR summary ILIKE :keyword OR tags ILIKE :keyword
                 ORDER BY published_at DESC LIMIT 10"
            );
            $stmt->execute([':keyword' => "%{$keyword}%"]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $altText = '「' . $keyword . '」の検索結果';

        } else {
            // --- Unread Articles Mode ---
            error_log("[INFO] Unread mode.");
            $stmt = $pdo->prepare(
                "SELECT id, flex_message_json FROM articles 
                 WHERE is_archived = false 
                 ORDER BY published_at DESC LIMIT 10"
            );
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $altText = '新着記事があります！';
        }

        foreach ($results as $row) {
            $articleIds[] = $row['id'];
            $bubble = json_decode($row['flex_message_json'], true);

            // --- Sanitize Flex Bubble on read ---
            if (isset($bubble['body']['contents'])) {
                foreach ($bubble['body']['contents'] as &$content) {
                    // Identify the quiz button container
                    if ($content['type'] === 'box' && isset($content['contents'][0]['type']) && $content['contents'][0]['type'] === 'button') {
                        foreach ($content['contents'] as &$button) {
                            if (isset($button['action']['type']) && $button['action']['type'] === 'postback') {
                                // 1. Truncate label
                                if (isset($button['action']['label'])) {
                                    $button['action']['label'] = mb_substr($button['action']['label'], 0, 40);
                                }
                                if (isset($button['action']['displayText'])) {
                                    $button['action']['displayText'] = mb_substr($button['action']['displayText'], 0, 40);
                                }

                                // 2. Truncate data field
                                if (isset($button['action']['data'])) {
                                    parse_str($button['action']['data'], $postbackData);
                                    if (isset($postbackData['correct_answer'])) {
                                        $postbackData['correct_answer'] = mb_substr($postbackData['correct_answer'], 0, 100);
                                        $button['action']['data'] = http_build_query($postbackData);
                                    }
                                }
                            }
                        }
                        unset($button);
                    }
                }
                unset($content);
            }
            // --- End Sanitization ---

            $bubbles[] = $bubble;
        }

        if (empty($bubbles)) {
            $replyText = empty($keyword) ? '新しいお知らせはありませんでした。' : "キーワード「{$keyword}」に一致する記事は見つかりませんでした。";
            replyLineMessage($channelAccessToken, $replyToken, [['type' => 'text', 'text' => $replyText]]);
            return;
        }

        $carouselMessage = ['type' => 'flex', 'altText' => $altText, 'contents' => ['type' => 'carousel', 'contents' => $bubbles]];

        if (replyLineMessage($channelAccessToken, $replyToken, [$carouselMessage])) {
            // Mark articles as archived only in unread mode
            if (empty($keyword) && !empty($articleIds)) {
                $idList = implode(',', array_map('intval', $articleIds));
                $pdo->exec("UPDATE articles SET is_archived = true WHERE id IN ({$idList})");
                error_log("[INFO] Archived " . count($articleIds) . " articles.");
            }
        }

    } catch (Exception $e) {
        error_log("[ERROR] handleTextMessage failed: " . $e->getMessage());
        replyLineMessage($channelAccessToken, $replyToken, [['type' => 'text', 'text' => 'エラーが発生しました。しばらくしてからもう一度お試しください。']]);
    }
}

// Respond with 200 OK to LINE Platform
http_response_code(200);
error_log('[SUCCESS] Webhook processed successfully.');