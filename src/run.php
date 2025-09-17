<?php
// ----------------------------------------------------------------------------
// 設定 (環境変数から取得)
// ----------------------------------------------------------------------------
$channelAccessToken = getenv('LINE_CHANNEL_ACCESS_TOKEN');
$userId = getenv('LINE_USER_ID');
$apiKey = getenv('AI_API_KEY');
$scrapingApiKey = getenv('SCRAPING_API_KEY');
$force_delivery = getenv('FORCE_DELIVERY') === 'true'; // 強制実行モード

if (!$channelAccessToken || !$userId) {
    die("[ERROR] Environment variables LINE_CHANNEL_ACCESS_TOKEN and LINE_USER_ID must be set.\n");
}

// ----------------------------------------------------------------------------
// 定数
// ----------------------------------------------------------------------------
$feeds = [
    [
        'name' => 'mdn',
        'url' => 'https://developer.mozilla.org/en-US/blog/rss.xml',
        'label' => 'MDN新着記事'
    ],
    [
        'name' => 'tech',
        'url' => 'https://yamadashy.github.io/tech-blog-rss-feed/feeds/rss.xml',
        'label' => '企業テックブログ新着記事'
    ],
    [
        'name' => 'php',
        'url' => 'https://php.net/feed.atom',
        'label' => 'PHP公式ニュース'
    ],
    [
        'name' => 'freek_dev',
        'url' => 'https://freek.dev/feed',
        'label' => 'Freek.dev Blog'
    ],
    [
        'name' => 'publickey',
        'url' => 'https://www.publickey1.jp/atom.xml',
        'label' => 'Publickey'
    ],
    [
        'name' => 'aws_arch',
        'url' => 'https://aws.amazon.com/blogs/architecture/feed/',
        'label' => 'AWS Architecture Blog'
    ],
    [
        'name' => 'hacker_news',
        'url' => 'https://thehackernews.com/feeds/posts/default',
        'label' => 'The Hacker News'
    ],
    [
        'name' => 'css_tricks',
        'url' => 'https://css-tricks.com/feed/',
        'label' => 'CSS-Tricks'
    ],
    [
        'name' => 'qiita',
        'url' => 'https://qiita.com/popular-items/feed',
        'label' => 'Qiita トレンド'
    ],
    [
        'name' => 'ipa_alert',
        'url' => 'https://www.ipa.go.jp/security/rss/alert.rdf',
        'label' => 'IPA 重要なセキュリティ情報'
    ],
    [
        'name' => 'jvn',
        'url' => 'https://jvndb.jvn.jp/ja/rss/jvndb.rdf',
        'label' => 'JVN 新着脆弱性情報'
    ],

];

define('LINE_API_URL', 'https://api.line.me/v2/bot/message/push');
define('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash-latest:generateContent');
define('BROWSERLESS_API_URL', 'https://chrome.browserless.io/content');
define('WEEKLY_ARTICLES_FILE', dirname(__DIR__) . '/data/weekly_articles.json');

// ----------------------------------------------------------------------------
// ヘルパー関数
// ----------------------------------------------------------------------------

/**
 * URLから記事の本文とog:imageを取得する
 * SCRAPING_API_KEYが設定されていればBrowserless.ioを、なければcURLを直接使う
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

    // og:imageを抽出
    $imageUrl = '';
    if (preg_match('/<meta\s+property=(?P<quote>["\'])og:image(?P=quote)\s+content=(?P<quote2>["\'])(.*?)(?P=quote2)\s*\/?>/i', $html, $matches)) {
        $imageUrl = html_entity_decode($matches[3]);
        echo "[INFO] Found og:image: {$imageUrl}\n";
    } else {
        echo "[INFO] og:image not found.\n";
    }

    // 簡単なHTMLクリーンアップ
    $text = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $html);
    $text = preg_replace('#<style(.*?)>(.*?)</style>#is', '', $text);
    $text = strip_tags($text);
    $text = preg_replace('/\s+/s', ' ', $text); // 複数の空白・改行を1つに
    $text = trim($text);

    return ['text' => $text, 'image_url' => $imageUrl];
}


/**
 * Gemini APIを呼び出してテキストを要約する (リトライ機能付き)
 */
function getAiSummary(string $text, string $apiKey): string {
    if (empty($apiKey)) {
        echo "[INFO] AI_API_KEY is not set. Skipping AI summary.\n";
        return '';
    }
    if (empty($text)) {
        echo "[INFO] Article text is empty. Skipping AI summary.\n";
        return '';
    }

    $max_retries = 3;
    $retry_delay_seconds = 2; // 初回待機時間

    for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
        echo "[INFO] Requesting AI summary (Attempt {$attempt}/{$max_retries})...";
        $prompt = "以下の記事を日本語で200文字程度に要約してください。顧客ごとに合わせたスクラッチ開発をしているWeb系のエンジニアに対する要約であることも踏まえて単なる要約ではない業務に応用できるような提案も含めた形でお願いします。:";
        $prompt .= "\n\n" . mb_substr($text, 0, 15000); // 長すぎるテキストを切り詰める

        $data = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'maxOutputTokens' => 256,
            ]
        ];

        $ch = curl_init(GEMINI_API_URL . '?key=' . $apiKey);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // SSL検証をスキップ

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error) {
            echo "[ERROR] cURL error on AI summary request: {$curl_error}\n";
            return ''; // cURL自体のエラーではリトライしない
        }

        if ($http_code === 200) {
            $result = json_decode($response, true);
            $summary = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
            return trim($summary); // 成功
        }

        // 503エラーの場合のみリトライ
        if ($http_code === 503) {
            echo "[WARNING] AI summary request failed with HTTP Status 503 (Service Unavailable).\n";
            if ($attempt < $max_retries) {
                echo "[INFO] Retrying in {$retry_delay_seconds} seconds...\n";
                sleep($retry_delay_seconds);
                $retry_delay_seconds *= 2; // 次の待機時間を倍にする
            } 
        } else {
            // 4xxエラーやその他の5xxエラーではリトライしない
            echo "[WARNING] AI summary request failed with non-retriable HTTP Status: {$http_code}\nResponse: {$response}\n";
            return '';
        }
    }

    echo "[ERROR] AI summary failed after {$max_retries} attempts.\n";
    return ''; // すべてのリトライが失敗
}


/**
 * Gemini APIを呼び出してカテゴリを分類する
 */
function getAiCategories(string $text, string $apiKey): array {
    if (empty($apiKey)) {
        echo "[INFO] AI_API_KEY is not set. Skipping AI categorization.\n";
        return [];
    }
    if (empty($text)) {
        echo "[INFO] Article text is empty. Skipping AI categorization.\n";
        return [];
    }

    $prompt = "以下の記事は、ITエンジニアにとってどのようなカテゴリに分類されるか、指定されたタグの中から最も関連性の高いものを最大3つまで選び、カンマ区切りで出力してください。\n\n利用可能なタグ:\nセキュリティ, Web開発, アプリ開発, クラウド, インフラ, AI, プログラミング言語, キャリア, ハードウェア, マーケティング, マネジメント, その他\n\n記事:
" . mb_substr($text, 0, 8000) . "\n\n出力形式:\nタグ1,タグ2,タグ3";

    $data = [
        'contents' => [
            ['parts' => [['text' => $prompt]]]
        ],
        'generationConfig' => [
            'maxOutputTokens' => 64,
            'temperature' => 0.1
        ]
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

    if ($http_code === 200) {
        $result = json_decode($response, true);
        $category_string = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if (!empty($category_string)) {
            $tags = array_map('trim', explode(',', $category_string));
            return array_filter($tags);
        }
    } else {
        echo "[WARNING] AI categorization request failed with HTTP Status: {$http_code}\nResponse: {$response}\n";
    }

    return [];
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
        'contents' => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => [
            'maxOutputTokens' => 512,
            'temperature' => 0.3,
            'responseMimeType' => 'application/json', // Ask for JSON response directly
        ]
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
        echo "[WARNING] AI analysis request failed with HTTP Status: {$http_code}\nResponse: {$response}\n";
        return $defaultResponse;
    }

    $result = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "[WARNING] Failed to parse AI analysis JSON response.\nResponse: {$response}\n";
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
    // 一般的なユーザーエージェントを設定してブラウザを模倣する
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
    // 明示的にXMLコンテンツを要求する
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


// ----------------------------------------------------------------------------
// メイン処理
// ----------------------------------------------------------------------------

foreach ($feeds as $feed) {
    $feed_name = $feed['name'];
    $rss_url = $feed['url'];
    $message_label = $feed['label'];
    $last_url_file = dirname(__DIR__) . '/data/last_notified_url_' . $feed_name . '.txt';

    echo "--------------------------------------------------\n";
    echo "[INFO] Processing feed: {$feed_name}\n";

    $rss_content = fetchRssContent($rss_url);
    if ($rss_content === false) {
        continue;
    }

    // XMLパースエラーを内部で処理するように設定
    libxml_use_internal_errors(true);
    $rss = simplexml_load_string($rss_content);

    if ($rss === false) {
        echo "[WARNING] Failed to parse RSS feed for {$feed_name}. Skipping.\n";
        continue;
    }

    // 最新の記事を取得 (RSS 2.0 / Atom両対応)
    $latest_item = $rss->channel->item[0] ?? $rss->item[0] ?? $rss->entry[0] ?? null;
    if (!$latest_item) {
        echo "[WARNING] Could not find any items in the RSS feed for {$feed_name}. Skipping.\n";
        continue;
    }

    // 各要素を取得 (RSS 2.0 / Atom両対応)
    $latest_url = (string)($latest_item->link['href'] ?? $latest_item->link);
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

    // --- コンテンツ取得と要約 ---
    $articleContent = fetchArticleContent($latest_url, $scrapingApiKey);
    $articleText = $articleContent['text'];
    $imageUrl = $articleContent['image_url'];

    // AIで要約とカテゴリ分類を同時に行う
    $analysisResult = getAiAnalysis($articleText, $apiKey);
    $tags = $analysisResult['tags'];
    $summary = $analysisResult['summary'];

    echo "[INFO] AI generated tags: " . implode(', ', $tags) . "\n";

    // AIの要約が失敗した場合のフォールバック
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
    // --- コンテンツ取得と要約ここまで ---

    // --- メッセージ本文の組み立て ---
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

    // 画像があればHeroブロックを追加し、URLが有効かどうかも確認
    if (!empty($imageUrl) && filter_var($imageUrl, FILTER_VALIDATE_URL) && strlen($imageUrl) <= 2000) {
        $bubble['hero'] = [
            'type' => 'image',
            'url' => $imageUrl,
            'size' => 'full',
            'aspectRatio' => '20:13',
            'aspectMode' => 'cover',
        ];
    }

    // タグをaltTextに追加
    $altTextTags = !empty($tags) ? '[' . implode('][', $tags) . '] ' : '';
    $flexMessage = [
        'type' => 'flex',
        'altText' => sprintf('%s【%s】%s', $altTextTags, $message_label, $latest_title),
        'contents' => $bubble,
    ];

    $body = [
        'to' => $userId,
        'messages' => [$flexMessage],
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
        echo "[SUCCESS] Notification sent successfully for {$feed_name}.\n";
        file_put_contents($last_url_file, $latest_url);
        echo "[INFO] Updated last notified URL to: {$latest_url}\n";

        // --- 週間サマリーのために記事情報を保存 ---
        $articles = file_exists(WEEKLY_ARTICLES_FILE) ? json_decode(file_get_contents(WEEKLY_ARTICLES_FILE), true) : [];
        if (json_last_error() !== JSON_ERROR_NONE) { $articles = []; } // JSONが壊れている場合は初期化
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
        // --- ここまで ---

    } else {
        echo "[ERROR] Failed to send LINE notification for {$feed_name}. HTTP Status: {$http_code}\n";
        echo "[ERROR] Response: {$result}\n";
    }
    
    sleep(4);
}

echo "--------------------------------------------------\n";
echo "[INFO] All feeds processed. Exiting.\n";
exit(0);


