<?php
// ----------------------------------------------------------------------------
// リッチメニューを作成し、ユーザーに設定するスクリプト
// ----------------------------------------------------------------------------

// ----------------------------------------------------------------------------
// 使い方
// ----------------------------------------------------------------------------
// 1. LINE Channel Access Tokenを環境変数 `LINE_CHANNEL_ACCESS_TOKEN` に設定してください。
// 2. このファイルと同じ階層に、2500x1686pxのPNGファイル `richmenu.png` を配置してください。
// 3. 下記の `define('GITHUB_URL', ...)` のURLをご自身のものに変更してください。
// 4. コマンドラインから `php create_rich_menu.php` を実行してください。
// ----------------------------------------------------------------------------

// ----------------------------------------------------------------------------
// 設定
// ----------------------------------------------------------------------------
$channelAccessToken = getenv('LINE_CHANNEL_ACCESS_TOKEN');
if (!$channelAccessToken) {
    die("[ERROR] Environment variable LINE_CHANNEL_ACCESS_TOKEN must be set.\n");
}

// --- DEBUG: トークンの読み取り状態を確認 ---
echo "[DEBUG] Token read by PHP.\n";
echo "[DEBUG]   - Length: " . strlen($channelAccessToken) . "\n";
echo "[DEBUG]   - First 5 chars: '" . substr($channelAccessToken, 0, 5) . "'\n";
echo "[DEBUG]   - Last 5 chars: '" . substr($channelAccessToken, -5) . "'\n";
echo "--------------------------------------------------\n\n";
// --- END DEBUG ---



// ★★★ ご自身のGitHubリポジトリのURLに変更してください ★★★
define('GITHUB_URL', 'https://github.com/hirokichi1992/LineBotForDeveloppers');

$imagePath = __DIR__ . '/richmenu.png';
if (!file_exists($imagePath)) {
    die("[ERROR] richmenu.png not found. Please create and place it in the same directory.\n");
}

// ----------------------------------------------------------------------------
// APIエンドポイント
// ----------------------------------------------------------------------------
define('LINE_API_BASE_URL', 'https://api.line.me/v2/bot');

// ----------------------------------------------------------------------------
// ヘルパー関数
// ----------------------------------------------------------------------------
function sendCurlRequest(string $url, string $method, string $token, $body = null, string $contentType = 'application/json') {
    $headers = [
        'Content-Type: ' . $contentType,
        'Authorization: Bearer ' . $token,
    ];

    $ch = curl_init($url);
    // SSL証明書の検証をスキップするオプションを追加
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        echo "[ERROR] cURL request failed: " . $curl_error . "\n";
        return null;
    }

    if ($http_code >= 300) {
        echo "[ERROR] API request failed with HTTP status {$http_code}.\n";
        echo "Response: {$response}\n";
        return null;
    }

    return json_decode($response, true);
}

// ----------------------------------------------------------------------------
// メイン処理
// ----------------------------------------------------------------------------

// 1. リッチメニューの構造を定義
$richMenu = [
    'size' => ['width' => 2500, 'height' => 1686],
    'selected' => false,
    'name' => 'Developer Feeds Menu',
    'chatBarText' => 'メニュー',
    'areas' => [
        // Row 1
        [
            'bounds' => ['x' => 0, 'y' => 0, 'width' => 833, 'height' => 843],
            'action' => ['type' => 'uri', 'label' => 'Qiita', 'uri' => 'https://qiita.com/']
        ],
        [
            'bounds' => ['x' => 833, 'y' => 0, 'width' => 833, 'height' => 843],
            'action' => ['type' => 'uri', 'label' => 'MDN Blog', 'uri' => 'https://developer.mozilla.org/en-US/blog/']
        ],
        [
            'bounds' => ['x' => 1666, 'y' => 0, 'width' => 834, 'height' => 843],
            'action' => ['type' => 'uri', 'label' => 'Tech Blogs', 'uri' => 'https://yamadashy.github.io/tech-blog-rss-feed/']
        ],
        // Row 2
        [
            'bounds' => ['x' => 0, 'y' => 843, 'width' => 833, 'height' => 843],
            'action' => ['type' => 'uri', 'label' => 'AWS Blog', 'uri' => 'https://aws.amazon.com/blogs/architecture/']
        ],
        [
            'bounds' => ['x' => 833, 'y' => 843, 'width' => 833, 'height' => 843],
            'action' => ['type' => 'uri', 'label' => 'GitHub Repo', 'uri' => GITHUB_URL]
        ],
        [
            'bounds' => ['x' => 1666, 'y' => 843, 'width' => 834, 'height' => 843],
            'action' => ['type' => 'message', 'label' => 'Help', 'text' => 'このボットは開発者向けの技術記事を要約して通知します。メニューから各サイトにアクセスできます。']
        ],
    ]
];

// 2. リッチメニューを作成
echo "[INFO] 1/3: Creating rich menu object...\n";
$createResponse = sendCurlRequest(LINE_API_BASE_URL . '/richmenu', 'POST', $channelAccessToken, json_encode($richMenu));
if (!$createResponse || !isset($createResponse['richMenuId'])) {
    die("[FATAL] Failed to create rich menu.\n");
}
$richMenuId = trim($createResponse['richMenuId']);
echo "[SUCCESS] Rich menu created successfully. ID: {$richMenuId}\n";

// 3. リッチメニューに画像をアップロード
echo "[INFO] 2/3: Uploading image to rich menu...\n";
$imageData = file_get_contents($imagePath);
$uploadResponse = sendCurlRequest("https://api-data.line.me/v2/bot/richmenu/{$richMenuId}/content", 'POST', $channelAccessToken, $imageData, 'image/png');
if ($uploadResponse === null) { // On success, response body is empty
    die("[FATAL] Failed to upload rich menu image.\n");
}
echo "[SUCCESS] Image uploaded successfully.\n";

// 4. デフォルトのリッチメニューとして設定
echo "[INFO] 3/3: Setting rich menu as default for all users...\n";
$setDefaultResponse = sendCurlRequest(LINE_API_BASE_URL . "/user/all/richmenu/{$richMenuId}", 'POST', $channelAccessToken);
if ($setDefaultResponse === null) { // On success, response body is empty
    die("[FATAL] Failed to set default rich menu.\n");
}
echo "[SUCCESS] Rich menu has been set as default for all users.\n";

echo "\n[COMPLETE] All steps finished successfully!\n";

?>
