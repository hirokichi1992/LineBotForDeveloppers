<?php
// 共通関数をまとめたライブラリファイル

// APIのURLやその他の定数を定義
define('LINE_API_URL', 'https://api.line.me/v2/bot/message/push');
define('LINE_REPLY_API_URL', 'https://api.line.me/v2/bot/message/reply');
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
        return $defaultResponse;
    }

    $tagList = "セキュリティ, Web開発, アプリ開発, クラウド, インフラ, AI, プログラミング言語, キャリア, ハードウェア, マーケティング, マネジメント, その他";
    $prompt = "以下の記事を分析し、指定のJSON形式で出力してください。\n\n" 
            . "制約:\n" 
            . "- summary: 顧客向けにスクラッチ開発を行うWebエンジニアの視点で、実務に応用できる提案を含めて日本語で200字程度に要約してください。\n" 
            . "- tags: 記事の内容に最も関連性の高いタグを、以下のリストから最大3つまで選んでください。\n" 
            . "利用可能なタグ: {$tagList}\n\n" 
            . "記事:\n" . mb_substr($text, 0, 15000) . "\n\n" 
            . "出力形式 (JSONのみを返すこと):\n" 
            . "{\n" 
            . "  \"summary\": \"ここに要約が入ります。\",\n" 
            . "  \"tags\": [\"タグ1\", \"タグ2\"]\n" 
            . "}";

    $data = [
        'contents' => [['parts' => [['text' => $prompt]]]]
    ];

    $postFields = json_encode($data);

    $ch = curl_init(GEMINI_API_URL . '?key=' . $apiKey);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        return $defaultResponse;
    }

    $result = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        return $defaultResponse;
    }

    $aiOutputText = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
    $aiParsedOutput = json_decode($aiOutputText, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        return $defaultResponse;
    }

    return [
        'summary' => trim($aiParsedOutput['summary'] ?? ''),
        'tags' => $aiParsedOutput['tags'] ?? [],
    ];
}

/**
 * Gemini APIを呼び出して、記事から三択クイズを生成する
 */
function generateQuizFromArticle(string $text, string $apiKey): ?array {
    if (empty($apiKey) || empty($text)) {
        return null;
    }

    $prompt = "以下の記事の要点に基づき、内容の理解度を確認するための三択クイズを1問作成してください。\n" 
            . "出力は必ず指定のJSON形式に従ってください。\n\n" 
            . "# 制約\n" 
            . "- 読者が記事を読むことで答えがわかるような、核心的な内容を問うクイズにしてください。\n" 
            . "- 選択肢は3つにしてください。\n" 
            . "- 正解の選択肢は1つだけにしてください。\n" 
            . "- `options` 配列には、選択肢のテキストを3つ格納してください。\n" 
            . "- `correct_index` には、`options` 配列における正解の選択肢のインデックス（0, 1, または 2）を数値で指定してください。\n" 
            . "- 問題文 (`question`)、選択肢 (`options`) はすべて日本語にしてください。\n\n" 
            . "# 記事\n" . mb_substr($text, 0, 8000) . "\n\n" 
            . "# 出力形式 (JSONのみを返すこと)\n" 
            . "{\n" 
            . "  \"question\": \"ここに問題文が入ります。\",\n" 
            . "  \"options\": [\"選択肢1\", \"選択肢2\", \"選択肢3\"],\n" 
            . "  \"correct_index\": 0\n" 
            . "}";

    $data = [
        'contents' => [['parts' => [['text' => $prompt]]]]
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
        return null;
    }

    $result = json_decode($response, true);
    $quizText = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
    $quizData = json_decode($quizText, true);

    if (json_last_error() !== JSON_ERROR_NONE || !isset($quizData['question']) || !isset($quizData['options']) || !isset($quizData['correct_index'])) {
        return null;
    }
    
    return $quizData;
}


/**
 * cURLを使ってRSSフィードの内容を堅牢に取得する
 */
function fetchRssContent(string $url): string|false {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/atom+xml, application/rss+xml, application/xml;q=0.9, */*;q=0.8']);

    $content = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200 || $content === false) {
        return false;
    }
    return $content;
}

/**
 * LINEにプッシュメッセージを送信する
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
        return true;
    } else {
        return false;
    }
}

/**
 * LINEにリプライメッセージを送信する
 */
function replyLineMessage(string $channelAccessToken, string $replyToken, array $messages): bool {
    $body = [
        'replyToken' => $replyToken,
        'messages' => $messages,
    ];

    $ch = curl_init(LINE_REPLY_API_URL);
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
        error_log("[SUCCESS] LINE reply sent successfully.");
        return true;
    } else {
        error_log("[ERROR] Failed to send LINE reply. HTTP Status: {$http_code} | Response: {$result}");
        return false;
    }
}
