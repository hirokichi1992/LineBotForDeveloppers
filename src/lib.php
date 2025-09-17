<?php
// 共通関数をまとめたライブラリファイル

// APIのURLやその他の定数を定義
define('LINE_API_URL', 'https://api.line.me/v2/bot/message/push');
define('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent');
define('BROWSERLESS_API_URL', 'https://chrome.browserless.io/content');

/**
 * URLから記事の本文とog:imageを取得する
 */
function fetchArticleContent(string $url, string $scrapingApiKey): array {
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
        return ['text' => '', 'image_url' => ''];
    }

    $imageUrl = '';
    if (preg_match('/<meta\s+property=(?P<quote>["\'])og:image(?P=quote)\s+content=(?P<quote2>["\'])(.*?)(?P=quote2)\s*\/?\?>/i', $html, $matches)) {
        $imageUrl = html_entity_decode($matches[3]);
        echo "[INFO] Found og:image: {$imageUrl}\n";
    }

    $text = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $html);
    $text = preg_replace('#<style(.*?)>(.*?)</style>#is', '', $text);
    $text = strip_tags($text);
    $text = preg_replace('/\s+/s', ' ', $text);
    $text = trim($text);

    return ['text' => $text, 'image_url' => $imageUrl];
}

/**
 * Gemini APIを呼び出して、記事の要約とカテゴリ分類を一度に行う
 */
function getAiAnalysis(string $text, string $apiKey): array {
    $defaultResponse = ['tags' => [], 'summary' => ''];
    if (empty($apiKey) || empty($text)) {
        echo "[INFO] API key or article text is empty. Skipping AI analysis.\n";
        return $defaultResponse;
    }

    $tagList = "セキュリティ, Web開発, アプリ開発, クラウド, インフラ, AI, プログラミング言語, キャリア, ハードウェア, マーケティング, マネジメント, その他";
    $prompt = "以下の記事を分析し、指定のJSON形式で出力してください。

" 
            . "制約:
" 
            . "- summary: 顧客向けにスクラッチ開発を行うWebエンジニアの視点で、実務に応用できる提案を含めて日本語で200字程度に要約してください。
" 
            . "- tags: 記事の内容に最も関連性の高いタグを、以下のリストから最大3つまで選んでください。
" 
            . "利用可能なタグ: {$tagList}

" 
            . "記事:
" . mb_substr($text, 0, 15000) . "

" 
            . "出力形式 (JSONのみを返すこと):
" 
            . "{
" 
            . "  \"summary\": \"ここに要約が入ります。\",
" 
            . "  \"tags\": [\"タグ1\", \"タグ2\"]
" 
            . "}";

    echo "[DEBUG] Prompt sent to Gemini API:
" . $prompt . "
"; // デバッグログ追加

    $data = [
        'contents' => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => [
            'maxOutputTokens' => 512,
            'temperature' => 0.3,
            'responseMimeType' => 'application/json', // Ask for JSON response directly
        ]
    ];

    $postFields = json_encode($data);
    echo "[DEBUG] Data sent to Gemini API:
" . $postFields . "
"; // デバッグログ追加

    $ch = curl_init(GEMINI_API_URL . '?key=' . $apiKey);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "[DEBUG] Raw response from Gemini API:
" . $response . "
"; // デバッグログ追加

    if ($http_code !== 200) {
        echo "[WARNING] AI analysis request failed with HTTP Status: {$http_code}
Response: {$response}
";
        return $defaultResponse;
    }

    $result = json_decode($response, true);
    echo "[DEBUG] Decoded response from Gemini API:
" . print_r($result, true) . "
"; // デバッグログ追加

    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "[WARNING] Failed to parse AI analysis JSON response.
Response: {$response}
";
        return $defaultResponse;
    }

    return [
        'summary' => trim($result['summary'] ?? ''),
        'tags' => $result['tags'] ?? [],
    ];
}


/**
 * cURLを使ってRSSフィードの内容を堅牢に取得する
 */
function fetchRssContent(string $url): string|false {
    echo "[INFO] Fetching RSS feed from: {$url}\n";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/atom+xml, application/rss+xml, application/xml;q=0.9, */*;q=0.8']);

    $content = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200 || $content === false) {
        echo "[WARNING] Failed to fetch RSS feed. HTTP Status: {$http_code}\n";
        return false;
    }
    return $content;
}

/**
 * LINEにメッセージを送信する
 */
function sendLineMessage(string $channelAccessToken, string $userId, array $messages): bool {
    $body = [
        'to' => $userId,
        'messages' => $messages,
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
        echo "[SUCCESS] LINE message sent successfully.\n";
        return true;
    } else {
        echo "[ERROR] Failed to send LINE message. HTTP Status: {$http_code}\n";
        echo "[ERROR] Response: {$result}\n";
        return false;
    }
}
