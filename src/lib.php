<?php
// 共通関数をまとめたライブラリファイル

// APIのURLやその他の定数を定義
define('LINE_API_URL', 'https://api.line.me/v2/bot/message/push');
define('LINE_REPLY_API_URL', 'https://api.line.me/v2/bot/message/reply');
// define('GEMINI_API_URL', '...'); // 動的に生成するため不要
define('BROWSERLESS_API_URL', 'https://chrome.browserless.io/content');

/**
 * URLから記事の本文とog:imageを取得する
 */
function fetchArticleContent(string $url, string $scrapingApiKey): array
{
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
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko)
  Chrome/91.0.4472.124 Safari/537.36');
    }

    $html = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200 || $html === false) {
        echo "[WARNING] Failed to fetch article content. HTTP Status: {$http_code}\n";
        return ['text' => '', 'image_url' => ''];
    }

    $imageUrl = '';
    // OGP画像取得の正規表現を修正
    if (preg_match('/<meta\s+property=(?P<quote>["\])og:image(?P=quote)\s+content=(?P=quote2>["\])(.*?)(?P=quote2)\s*\/?\?>/i', $html, $matches)) {
        $imageUrl = html_entity_decode($matches[3]);
    }

    $text = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $html);
    $text = preg_replace('#<style(.*?)>(.*?)</style>#is', '', $html);
    $text = strip_tags($text);
    $text = preg_replace('/\s+/s', ' ', $text);
    $text = trim($text);

    return ['text' => $text, 'image_url' => $imageUrl];
}


/**
 * Gemini APIを呼び出して、記事の要約、タグ、クイズを一度に取得する
 * 複数のAPIキーと複数のモデルを順番に試行するフォールバック機能を持つ
 */
function getAiAnalysis(string $text, string $apiKeysString): array
{
    $defaultResponse = ['summary' => '', 'tags' => [], 'quiz' => null];

    // Get API Keys
    $apiKeys = array_filter(array_map('trim', explode(',', $apiKeysString)));
    if (empty($apiKeys) || empty($text)) {
        echo "[INFO] API keys or article text is empty. Skipping AI analysis.\n";
        return $defaultResponse;
    }

    // Get Models to try
    $modelsString = getenv('GEMINI_MODELS');
    $models = !empty($modelsString) ? array_filter(array_map('trim', explode(',', $modelsString))) : ['gemini-1.5-flash-latest'];
    if (empty($models)) {
        $models = ['gemini-1.5-flash-latest']; // Fallback to a default model
    }

    $tagList = "セキュリティ, Web開発, アプリ開発, クラウド, インフラ, AI, プログラミング言語, キャリア, ハードウェア, マーケティング, マネジメント, その他";
    $prompt = "以下の記事を分析し、指定のJSON形式で出力してください。\n\n" 
        . "# 制約\n" 
        . "- summary: 顧客向けにスクラッチ開発を行うWebエンジニアの視点で、実務に応用できる提案を含めて日本語で200字程度に要約してください。\n" 
        . "- tags: 記事の内容に最も関連性の高いタグを、以下のリストから最大3つまで選んでください。\n" 
        . "- quiz: 記事の核心的な内容を問う三択クイズを1問作成してください。question（問題文）、options（3つの選択肢の配列）、correct_index（正解のインデックス番号 0-2）のキーを持つオブジェクトにしてください。\n" 
        . "- quizが不要または作成困難な場合は quiz の値を null にしてください。\n\n" 
        . "# 利用可能なタグリスト\n{$tagList}\n\n" 
        . "# 記事\n" . mb_substr($text, 0, 8000) . "\n\n" 
        . "# 出力形式 (JSONのみを返すこと)\n" 
        . "{\n" 
        . "  \"summary\": \"ここに要約が入ります。\",\n" 
        . "  \"tags\": [\"タグ1\", \"タグ2\"],\n" 
        . "  \"quiz\": {\n" 
        . "    \"question\": \"ここに問題文が入ります。\",\n" 
        . "    \"options\": [\"選択肢1\", \"選択肢2\", \"選択肢3\"],\n" 
        . "    \"correct_index\": 0\n" 
        . "  }\n" 
        . "}";

    $data = [
        'contents' => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => [
            'maxOutputTokens' => 1024,
            'temperature' => 0.3,
            'responseMimeType' => 'application/json',
        ]
    ];

    foreach ($apiKeys as $keyIndex => $apiKey) {
        foreach ($models as $modelIndex => $model) {
            echo "[INFO] Attempting AI analysis with API key #" . ($keyIndex + 1) . " and model '{$model}'...\n";
            
            $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";

            $ch = curl_init($apiUrl . '?key=' . $apiKey);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($http_code === 200) {
                echo "[SUCCESS] AI analysis successful with key #" . ($keyIndex + 1) . " and model '{$model}'.\n";
                $result = json_decode($response, true);
                $aiOutputText = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';

                if (empty($aiOutputText)) {
                    echo "[WARNING] AI response text was empty for model '{$model}'. Trying next model/key...\n";
                    continue; // Continue to next model
                }

                if (preg_match('/^`json\s*(.*?)\s*`$/s', $aiOutputText, $matches)) {
                    $aiOutputText = $matches[1];
                }

                $aiParsedOutput = json_decode($aiOutputText, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    echo "[WARNING] Failed to parse AI output text as JSON: " . json_last_error_msg() . "\nExtracted text: {$aiOutputText}\n";
                    return $defaultResponse; // Don't retry on parsing failure
                }

                $quizData = $aiParsedOutput['quiz'] ?? null;
                if ($quizData !== null && (!isset($quizData['question']) || !isset($quizData['options']) || !is_array($quizData['options']) || count($quizData['options']) !== 3 || !isset($quizData['correct_index']))) {
                    $quizData = null;
                }

                return [
                    'summary' => trim($aiParsedOutput['summary'] ?? ''),
                    'tags' => $aiParsedOutput['tags'] ?? [],
                    'quiz' => $quizData,
                ];
            }

            if ($http_code === 429) {
                echo "[WARNING] Rate limit hit for key #" . ($keyIndex + 1) . " with model '{$model}'. Trying next model/key...\n";
                // Continue to the next model in the inner loop
            } else {
                echo "[WARNING] AI analysis failed for key #" . ($keyIndex + 1) . " with model '{$model}'. HTTP Status: {$http_code}. Breaking model loop to try next key.\n";
                break; // Break from the inner model loop, proceed to the next API key
            }
        }
    }

    echo "[ERROR] AI analysis failed with all provided API keys and models.\n";
    return $defaultResponse;
}

/**
 * cURLを使ってRSSフィードの内容を堅牢に取得する
 */
function fetchRssContent(string $url): string|false
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124
  Safari/537.36');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/atom+xml, application/rss+xml, application/xml;q=0.9, /;q=0.8']);

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
function sendLineMessage(string $channelAccessToken, string $userId, array $messages): bool
{
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
function replyLineMessage(string $channelAccessToken, string $replyToken, array $messages): bool
{
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

/**
 * DATABASE_URLをパースしてPDOデータベース接続を返す
 * @return PDO
 * @throws Exception
 */
function getDbConnection(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }

    $dbUrl = getenv('DATABASE_URL');
    if (empty($dbUrl)) {
        throw new Exception('DATABASE_URL is not set.');
    }

    $dbConfig = parse_url($dbUrl);

    if (!isset($dbConfig['port'])) {
        $dbConfig['port'] = 5432; // Default PostgreSQL port
    }
    $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;user=%s;password=%s;sslmode=require',
        $dbConfig['host'],
        $dbConfig['port'],
        ltrim($dbConfig['path'], '/'),
        $dbConfig['user'],
        $dbConfig['pass']
    );

    try {
        $pdo = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        return $pdo;
    } catch (PDOException $e) {
        throw new Exception('Failed to connect to database: ' . $e->getMessage());
    }
}
