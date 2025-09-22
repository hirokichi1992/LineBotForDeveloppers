<?php
// ----------------------------------------------------------------------------
// Setup
// ----------------------------------------------------------------------------
require_once __DIR__ . '/../src/lib.php';

define('ROOT_PATH', dirname(__DIR__));

// Load .env file
$dotenv_path = ROOT_PATH . '/.env';
if (file_exists($dotenv_path)) {
    $lines = file($dotenv_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (preg_match('/^\s*([^=]+)\s*=\s*(.*?)?\s*$/', $line, $matches)) {
            putenv(sprintf('%s=%s', $matches[1], $matches[2]));
        }
    }
}

// ----------------------------------------------------------------------------
// Database Initialization
// ----------------------------------------------------------------------------
try {
    $pdo = getDbConnection();
    $pdo->exec(
        "\n        CREATE TABLE IF NOT EXISTS articles (\n            id SERIAL PRIMARY KEY,\n            source VARCHAR(50) NOT NULL,\n            title TEXT NOT NULL,\n            url TEXT NOT NULL UNIQUE,\n            summary TEXT,\n            tags TEXT,\n            quiz_question TEXT,\n            quiz_options TEXT,\n            quiz_correct_index INTEGER,\n            image_url TEXT,\n            published_at TIMESTAMPTZ,\n            is_archived BOOLEAN DEFAULT false NOT NULL,\n            flex_message_json TEXT,\n            created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP\n        );\n    ");
} catch (Exception $e) {
    error_log("DB Connection/Init Error: " . $e->getMessage());
    die("[ERROR] Failed to connect to or initialize database.\n");
}

// ----------------------------------------------------------------------------
// Main Processing
// ----------------------------------------------------------------------------
$feeds = require ROOT_PATH . '/config/feeds.php';
$geminiApiKey = getenv('AI_API_KEY');
$scrapingApiKey = getenv('SCRAPING_API_KEY');

foreach ($feeds as $feedKey => $feed) {
    echo "--------------------------------------------------\n";
    echo "[INFO] Processing feed: {$feedKey}\n";

    $rss_content = fetchRssContent($feed['url']);
    if ($rss_content === false) {
        echo "[WARNING] Failed to fetch RSS content for {$feedKey}.\n";
        continue;
    }

    libxml_use_internal_errors(true);
    $rss = simplexml_load_string($rss_content);
    if ($rss === false) {
        echo "[WARNING] Failed to parse RSS feed for {$feedKey}.\n";
        continue;
    }

    $items = $rss->channel->item ?? $rss->item ?? $rss->entry ?? [];
    echo "[INFO] Found " . count($items) . " items in feed.\n";

    foreach ($items as $item) {
        $url = (string)(($item->link->attributes()['href'] ?? $item->link) ?? $item->guid);
        $title = trim((string)$item->title);
        $pubDate = (string)($item->pubDate ?? $item->updated ?? 'now');
        $pubDate = date('Y-m-d H:i:sP', strtotime($pubDate));

        // Let the DB handle duplicates with ON CONFLICT
        echo "[INFO] Processing article: {$title}\n";

        try {
            $articleContent = fetchArticleContent($url, $scrapingApiKey);
            $aiAnalysis = getAiAnalysis($articleContent['text'], $geminiApiKey);

            if (empty($aiAnalysis['summary'])) {
                $description = strip_tags((string)($item->description ?? $item->summary));
                $aiAnalysis['summary'] = mb_substr($description, 0, 150) . '…';
            }
            
            $bubble = createFlexBubble(
                $feed['label'],
                $title,
                $url,
                $pubDate,
                $aiAnalysis['summary'],
                $aiAnalysis['tags'],
                $aiAnalysis['quiz'],
                $articleContent['image_url'],
                $feed['default_image_url'] ?? null
            );

            $stmt = $pdo->prepare(
                "INSERT INTO articles (source, title, url, summary, tags, quiz_question, quiz_options, quiz_correct_index, image_url, published_at, flex_message_json, is_archived)\n                 VALUES (:source, :title, :url, :summary, :tags, :quiz_question, :quiz_options, :quiz_correct_index, :image_url, :published_at, :flex_message_json, false)\n                 ON CONFLICT (url) DO NOTHING"
            );

            $result = $stmt->execute([
                ':source' => $feedKey,
                ':title' => $title,
                ':url' => $url,
                ':summary' => $aiAnalysis['summary'],
                ':tags' => implode(',', $aiAnalysis['tags']),
                ':quiz_question' => $aiAnalysis['quiz']['question'] ?? null,
                ':quiz_options' => isset($aiAnalysis['quiz']['options']) ? json_encode($aiAnalysis['quiz']['options']) : null,
                ':quiz_correct_index' => $aiAnalysis['quiz']['correct_index'] ?? null,
                ':image_url' => $articleContent['image_url'],
                ':published_at' => $pubDate,
                ':flex_message_json' => json_encode($bubble)
            ]);

            if ($stmt->rowCount() > 0) {
                echo "[SUCCESS] New article added to DB: {$title}\n";
            } else {
                echo "[INFO] Article already exists in DB, skipping: {$title}\n";
            }

        } catch (Exception $e) {
            error_log("Article processing error for {$url}: " . $e->getMessage());
            echo "[ERROR] Failed to process article {$url}: " . $e->getMessage() . "\n";
        }
    }
}

echo "--------------------------------------------------\n";
echo "[INFO] All feeds processed. Exiting.\n";
exit(0);


/**
 * Creates a LINE Flex Message bubble.
 */
function createFlexBubble(string $label, string $title, string $url, string $pubDate, string $summary, array $tags, ?array $quizData, ?string $imageUrl, ?string $defaultImageUrl): array
{
    $bodyContents = [];
    $bodyContents[] = ['type' => 'text', 'text' => $title, 'weight' => 'bold', 'size' => 'xl', 'wrap' => true, 'color' => '#FFFFFF'];

    if (!empty($tags)) {
        $tagItems = [];
        foreach ($tags as $tag) {
            $tagItems[] = ['type' => 'box', 'layout' => 'vertical', 'backgroundColor' => '#4A5568', 'cornerRadius' => 'md', 'paddingAll' => '6px', 'contents' => [['type' => 'text', 'text' => $tag, 'color' => '#FFFFFF', 'size' => 'xs', 'weight' => 'bold', 'align' => 'center']], 'action' => ['type' => 'message', 'label' => $tag, 'text' => '最新情報 ' . $tag]];
        }
        $bodyContents[] = ['type' => 'box', 'layout' => 'horizontal', 'contents' => $tagItems, 'spacing' => 'sm', 'margin' => 'lg'];
    }

    $bodyContents[] = ['type' => 'text', 'text' => date('Y/m/d H:i', strtotime($pubDate)), 'wrap' => true, 'size' => 'sm', 'color' => '#A0AEC0', 'margin' => 'lg'];

    if (!empty($summary)) {
        $bodyContents[] = ['type' => 'box', 'layout' => 'vertical', 'margin' => 'lg', 'spacing' => 'sm', 'contents' => [['type' => 'box', 'layout' => 'baseline', 'spacing' => 'sm', 'contents' => [['type' => 'text', 'text' => 'Summary', 'color' => '#A0AEC0', 'size' => 'sm', 'flex' => 0]]], ['type' => 'text', 'text' => $summary, 'wrap' => true, 'size' => 'sm', 'margin' => 'md', 'color' => '#E2E8F0']]];
    }

    if ($quizData) {
        $bodyContents[] = ['type' => 'separator', 'margin' => 'xl'];
        $bodyContents[] = ['type' => 'text', 'text' => '今日のテッククイズ💡', 'weight' => 'bold', 'size' => 'md', 'margin' => 'lg', 'color' => '#1DB446'];
        $bodyContents[] = ['type' => 'text', 'text' => $quizData['question'], 'wrap' => true, 'size' => 'sm', 'color' => '#E2E8F0', 'margin' => 'md'];
        $quizOptions = [];
        foreach ($quizData['options'] as $index => $option) {
            $isCorrect = ($index === $quizData['correct_index']);
            
            // LINE APIの制限に対応するため、ラベルとデータを切り詰める
            $label = mb_substr($option, 0, 40);
            $correctAnswerText = $quizData['options'][$quizData['correct_index']];
            $truncatedCorrectAnswer = mb_substr($correctAnswerText, 0, 100);

            $postbackData = http_build_query(['action' => 'quiz_answer', 'is_correct' => $isCorrect ? '1' : '0', 'correct_answer' => $truncatedCorrectAnswer]);
            $quizOptions[] = ['type' => 'button', 'action' => ['type' => 'postback', 'label' => $label, 'data' => $postbackData, 'displayText' => $label], 'style' => 'secondary', 'height' => 'sm', 'margin' => 'sm'];
        }
        $bodyContents[] = ['type' => 'box', 'layout' => 'vertical', 'spacing' => 'sm', 'margin' => 'md', 'contents' => $quizOptions];
    }

    $bubble = ['type' => 'bubble', 'styles' => ['header' => ['backgroundColor' => '#1E2A38'], 'body' => ['backgroundColor' => '#2D3748'], 'footer' => ['backgroundColor' => '#2D3748', 'separator' => true, 'separatorColor' => '#4A5568']], 'header' => ['type' => 'box', 'layout' => 'vertical', 'contents' => [['type' => 'text', 'text' => sprintf('[ %s ]', $label), 'weight' => 'bold', 'color' => '#1DB446', 'size' => 'sm']], 'paddingAll' => '12px'], 'body' => ['type' => 'box', 'layout' => 'vertical', 'spacing' => 'md', 'contents' => $bodyContents], 'footer' => ['type' => 'box', 'layout' => 'vertical', 'spacing' => 'sm', 'contents' => [['type' => 'button', 'action' => ['type' => 'uri', 'label' => '記事を読む', 'uri' => $url], 'style' => 'primary', 'height' => 'sm', 'color' => '#4A5568']], 'flex' => 0]];

    $finalImageUrl = '';
    if (!empty($imageUrl) && filter_var($imageUrl, FILTER_VALIDATE_URL) && strlen($imageUrl) <= 2000) {
        $finalImageUrl = $imageUrl;
    } elseif (!empty($defaultImageUrl) && filter_var($defaultImageUrl, FILTER_VALIDATE_URL) && strlen($defaultImageUrl) <= 2000) {
        $finalImageUrl = $defaultImageUrl;
    }

    if (!empty($finalImageUrl)) {
        $bubble['hero'] = ['type' => 'image', 'url' => $finalImageUrl, 'size' => 'full', 'aspectRatio' => '20:13', 'aspectMode' => 'cover'];
    }

    return $bubble;
}
