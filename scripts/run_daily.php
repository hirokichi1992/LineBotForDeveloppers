<?php
// ----------------------------------------------------------------------------
// Setup
// ----------------------------------------------------------------------------
require_once __DIR__ . '/../src/lib.php';

// Define root path for consistent file access
define('ROOT_PATH', dirname(__DIR__));

// Load .env file if it exists for local development
$dotenv_path = ROOT_PATH . '/.env';
if (file_exists($dotenv_path)) {
    $lines = file($dotenv_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        if (preg_match('/^\s*([^=]+)\s*=\s*(.*?)?\s*$/', $line, $matches)) {
            $name = $matches[1];
            $value = $matches[2];
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// Load environment variables
$channelAccessToken = getenv('LINE_CHANNEL_ACCESS_TOKEN');
$userId = getenv('LINE_USER_ID');
$apiKey = getenv('AI_API_KEY');
$scrapingApiKey = getenv('SCRAPING_API_KEY');
$force_delivery = getenv('FORCE_DELIVERY') === 'true';

if (!$channelAccessToken || !$userId) {
    die("[ERROR] Environment variables LINE_CHANNEL_ACCESS_TOKEN and LINE_USER_ID must be set.\n");
}

// Load feed configuration
$feeds = require ROOT_PATH . '/config/feeds.php';


define('WEEKLY_ARTICLES_FILE', ROOT_PATH . '/data/weekly_articles.json');

// ----------------------------------------------------------------------------
// Main Processing
// ----------------------------------------------------------------------------

foreach ($feeds as $feed) {
    $feed_name = $feed['name'];
    $rss_url = $feed['url'];
    $message_label = $feed['label'];
    $last_url_file = ROOT_PATH . '/data/last_notified_url_' . $feed_name . '.txt';

    echo "--------------------------------------------------\n";
    echo "[INFO] Processing feed: {$feed_name}\n";

    $rss_content = fetchRssContent($rss_url);
    if ($rss_content === false) {
        continue;
    }

    libxml_use_internal_errors(true);
    $rss = simplexml_load_string($rss_content);

    if ($rss === false) {
        echo "[WARNING] Failed to parse RSS feed for {$feed_name}. Skipping.\n";
        continue;
    }

    $latest_item = $rss->channel->item[0] ?? $rss->item[0] ?? $rss->entry[0] ?? null;
    if (!$latest_item) {
        echo "[WARNING] Could not find any items in the RSS feed for {$feed_name}. Skipping.\n";
        continue;
    }

    $latest_url = '';
    if (isset($latest_item->link)) {
        if (is_array($latest_item->link) || ($latest_item->link instanceof SimpleXMLElement && count($latest_item->link) > 1)) {
            foreach ($latest_item->link as $link) {
                $attributes = $link->attributes();
                if (isset($attributes['rel']) && (string)$attributes['rel'] === 'alternate' && isset($attributes['href'])) {
                    $latest_url = (string)$attributes['href'];
                    break;
                }
            }
        }
        if (empty($latest_url)) {
            $attributes = $latest_item->link->attributes();
            if (isset($attributes['href'])) {
                $latest_url = (string)$attributes['href'];
            } else {
                $latest_url = (string)$latest_item->link;
            }
        }
    }

    if (empty($latest_url) && isset($latest_item->guid)) {
        $latest_url = (string)$latest_item->guid;
    }

    $latest_title = (string)$latest_item->title;
    $latest_pubDate = (string)($latest_item->pubDate ?? $latest_item->updated);

    echo "[INFO] Latest article found: {$latest_title} ({$latest_url})\n";

    $last_notified_url = file_exists($last_url_file) ? file_get_contents($last_url_file) : '';

    if ($force_delivery) {
        echo "[INFO] FORCE_DELIVERY mode is active. Sending notification regardless of last URL.\n";
    } else if ($latest_url === $last_notified_url) {
        echo "[INFO] No new articles found for {$feed_name}.\n";
        continue;
    }

    echo "[INFO] New article detected! Preparing to send LINE notification.\n";

    $articleContent = fetchArticleContent($latest_url, $scrapingApiKey);
    $articleText = $articleContent['text'];
    $imageUrl = $articleContent['image_url'];

    $analysisResult = getAiAnalysis($articleText, $apiKey);
    $tags = $analysisResult['tags'];
    $summary = $analysisResult['summary'];

    if (empty($summary)) {
        echo "[INFO] AI summary failed or was empty. Falling back to description snippet.\n";
        $description = strip_tags((string)($latest_item->description ?? $latest_item->summary));
        $summary = mb_substr($description, 0, 100);
        if (mb_strlen($description) > 100) {
            $summary .= '…';
        }
        $summary = trim($summary);
    } else {
        echo "[INFO] AI summary generated successfully.\n";
    }

    // --- Generate Quiz ---
    $quizData = null;
    if (!empty($articleText) && !empty($apiKey)) {
        echo "[INFO] Attempting to generate a quiz...\n";
        $quizData = generateQuizFromArticle($articleText, $apiKey);
    }

    // --- Build Flex Message ---
    $bodyContents = [];
    $bodyContents[] = [
        'type' => 'text',
        'text' => $latest_title,
        'weight' => 'bold',
        'size' => 'xl',
        'wrap' => true,
        'color' => '#FFFFFF',
    ];

    if (!empty($tags)) {
        $tagItems = [];
        foreach ($tags as $tag) {
            $tagItems[] = [
                'type' => 'box',
                'layout' => 'vertical',
                'backgroundColor' => '#4A5568',
                'cornerRadius' => 'md',
                'paddingAll' => '6px',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => $tag,
                        'color' => '#FFFFFF',
                        'size' => 'xs',
                        'weight' => 'bold',
                        'align' => 'center',
                    ]
                ],
                'action' => [
                    'type' => 'message',
                    'label' => $tag,
                    'text' => $tag . 'に関する記事'
                ]
            ];
        }
        $bodyContents[] = [
            'type' => 'box',
            'layout' => 'horizontal',
            'contents' => $tagItems,
            'spacing' => 'sm',
            'margin' => 'lg',
        ];
    }

    $bodyContents[] = [
        'type' => 'text',
        'text' => date('Y/m/d H:i', strtotime($latest_pubDate)),
        'wrap' => true,
        'size' => 'sm',
        'color' => '#A0AEC0',
        'margin' => 'lg',
    ];

    if (!empty($summary)) {
        $bodyContents[] = [
            'type' => 'box',
            'layout' => 'vertical',
            'margin' => 'lg',
            'spacing' => 'sm',
            'contents' => [
                [
                    'type' => 'box',
                    'layout' => 'baseline',
                    'spacing' => 'sm',
                    'contents' => [
                        [
                            'type' => 'text',
                            'text' => 'Summary',
                            'color' => '#A0AEC0',
                            'size' => 'sm',
                            'flex' => 0
                        ],
                    ]
                ],
                [
                    'type' => 'text',
                    'text' => $summary,
                    'wrap' => true,
                    'size' => 'sm',
                    'margin' => 'md',
                    'color' => '#E2E8F0',
                ],
            ]
        ];
    }

    // --- Add Quiz to Flex Message ---
    if ($quizData && isset($quizData['question']) && isset($quizData['options']) && count($quizData['options']) === 3 && isset($quizData['correct_index'])) {
        echo "[INFO] Adding quiz to the message.\n";

        $bodyContents[] = ['type' => 'separator', 'margin' => 'xl'];
        $bodyContents[] = [
            'type' => 'text',
            'text' => '今日のテッククイズ💡',
            'weight' => 'bold',
            'size' => 'md',
            'margin' => 'lg',
            'color' => '#1DB446'
        ];
        $bodyContents[] = [
            'type' => 'text',
            'text' => $quizData['question'],
            'wrap' => true,
            'size' => 'sm',
            'color' => '#E2E8F0',
            'margin' => 'md'
        ];

        $quizOptions = [];
        foreach ($quizData['options'] as $index => $option) {
            $isCorrect = ($index === $quizData['correct_index']);
            $postbackData = http_build_query([
                'action' => 'quiz_answer',
                'is_correct' => $isCorrect ? '1' : '0',
                'correct_answer' => $quizData['options'][$quizData['correct_index']]
            ]);

            $quizOptions[] = [
                'type' => 'button',
                'action' => [
                    'type' => 'postback',
                    'label' => $option,
                    'data' => $postbackData,
                    'displayText' => $option
                ],
                'style' => 'secondary',
                'height' => 'sm',
                'margin' => 'sm'
            ];
        }
        $bodyContents[] = [
            'type' => 'box',
            'layout' => 'vertical',
            'spacing' => 'sm',
            'margin' => 'md',
            'contents' => $quizOptions
        ];
    } else {
        echo "[INFO] Quiz data was not valid or not generated. Skipping quiz in message.\n";
    }

    $bubble = [
        'type' => 'bubble',
        'styles' => [
            'header' => ['backgroundColor' => '#1E2A38'],
            'body'   => ['backgroundColor' => '#2D3748'],
            'footer' => ['backgroundColor' => '#2D3748', 'separator' => true, 'separatorColor' => '#4A5568'],
        ],
        'header' => [
            'type' => 'box',
            'layout' => 'vertical',
            'contents' => [
                [
                    'type' => 'text',
                    'text' => sprintf('[ %s ]', $message_label),
                    'weight' => 'bold',
                    'color' => '#1DB446',
                    'size' => 'sm',
                ],
            ],
            'paddingAll' => '12px',
        ],
        'body' => [
            'type' => 'box',
            'layout' => 'vertical',
            'spacing' => 'md',
            'contents' => $bodyContents,
        ],
        'footer' => [
            'type' => 'box',
            'layout' => 'vertical',
            'spacing' => 'sm',
            'contents' => [
                [
                    'type' => 'button',
                    'action' => [
                        'type' => 'uri',
                        'label' => '記事を読む',
                        'uri' => $latest_url,
                    ],
                    'style' => 'primary',
                    'height' => 'sm',
                    'color' => '#4A5568',
                ],
            ],
            'flex' => 0,
        ],
    ];

    $isImageUrlValid = !empty($imageUrl) && filter_var($imageUrl, FILTER_VALIDATE_URL) && strlen($imageUrl) <= 2000;

    if (!$isImageUrlValid && isset($feed['default_image_url']) && filter_var($feed['default_image_url'], FILTER_VALIDATE_URL) && strlen($feed['default_image_url']) <= 2000) {
        $imageUrl = $feed['default_image_url'];
        $isImageUrlValid = true;
    }

    if ($isImageUrlValid) {
        $bubble['hero'] = [
            'type' => 'image',
            'url' => $imageUrl,
            'size' => 'full',
            'aspectRatio' => '20:13',
            'aspectMode' => 'cover',
        ];
    }

    $altTextTags = !empty($tags) ? '[' . implode('][', $tags) . '] ' : '';
    $flexMessage = [
        'type' => 'flex',
        'altText' => sprintf('%s【%s】%s', $altTextTags, $message_label, $latest_title),
        'contents' => $bubble,
    ];

    if (sendLineMessage($channelAccessToken, $userId, [$flexMessage])) {
        $dataDir = dirname($last_url_file);
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }
        file_put_contents($last_url_file, $latest_url);
        echo "[INFO] Updated last notified URL to: {" . $latest_url . "}\n";

        $articles = file_exists(WEEKLY_ARTICLES_FILE) ? json_decode(file_get_contents(WEEKLY_ARTICLES_FILE), true) : [];
        if (json_last_error() !== JSON_ERROR_NONE) { $articles = []; }
        $articles[] = [
            'title' => $latest_title,
            'url' => $latest_url,
            'summary' => $summary,
            'tags' => $tags,
            'source' => $message_label,
            'date' => $latest_pubDate,
        ];
        file_put_contents(WEEKLY_ARTICLES_FILE, json_encode($articles, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo "[INFO] Saved article to weekly summary file.\n";
    }
    
    sleep(4);
}

echo "--------------------------------------------------\n";
echo "[INFO] All feeds processed. Exiting.\n";
exit(0);
