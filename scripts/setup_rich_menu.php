<?php
// scripts/setup_rich_menu.php

echo "[INFO] Rich Menu Setup Script Started.\n";

// ----------------------------------------------------------------------------
// Setup
// ----------------------------------------------------------------------------
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

$channelAccessToken = getenv('LINE_CHANNEL_ACCESS_TOKEN');
if (empty($channelAccessToken)) {
    die("[ERROR] LINE_CHANNEL_ACCESS_TOKEN is not set. Please set it in your .env file or as an environment variable.\n");
}

$richMenuImagePath = ROOT_PATH . '/richmenu.png';
if (!file_exists($richMenuImagePath)) {
    die("[ERROR] richmenu.png not found in the project root directory.\n");
}

// --- DIAGNOSTIC STEP ---
$fileSize = filesize($richMenuImagePath);
$fileSizeInKB = round($fileSize / 1024, 2);
echo "[INFO] Found richmenu.png. File size: {$fileSizeInKB} KB.\n";

if ($fileSize > 1048576) { // 1MB in bytes
    die("[ERROR] richmenu.png is larger than the 1MB limit.\n");
}
// --- END DIAGNOSTIC STEP ---


// ----------------------------------------------------------------------------
// 1. Create Rich Menu Object
// ----------------------------------------------------------------------------
echo "[INFO] Step 1: Creating Rich Menu Object...\n";

$richMenuObject = [
    'size' => ['width' => 2500, 'height' => 1686],
    'selected' => true, // Default state
    'name' => 'Primary Rich Menu',
    'chatBarText' => 'メニュー',
    'areas' => [
        [
            'bounds' => ['x' => 0, 'y' => 0, 'width' => 2500, 'height' => 1686],
            'action' => ['type' => 'message', 'text' => '最新情報']
        ]
    ]
];

$ch = curl_init('https://api.line.me/v2/bot/richmenu');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($richMenuObject));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $channelAccessToken,
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code !== 200) {
    die("[ERROR] Failed to create rich menu object. HTTP Status: {$http_code} | Response: {$response}\n");
}

$result = json_decode($response, true);
$richMenuId = $result['richMenuId'];
echo "[SUCCESS] Rich Menu Object created. richMenuId: {$richMenuId}\n";

// ----------------------------------------------------------------------------
// 2. Upload Rich Menu Image (with Retry Logic)
// ----------------------------------------------------------------------------
echo "[INFO] Step 2: Uploading Rich Menu Image...\n";

$maxRetries = 3;
$retryDelay = 5; // Initial delay in seconds
$uploadSuccess = false;

for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
    echo "[INFO] Upload attempt #{$attempt}...\n";

    $ch = curl_init("https://api.line.me/v2/bot/richmenu/{$richMenuId}/content");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents($richMenuImagePath));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: image/png',
        'Authorization: Bearer ' . $channelAccessToken,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200) {
        $uploadSuccess = true;
        echo "[SUCCESS] Rich Menu Image uploaded.\n";
        break; // Success, exit loop
    }

    if ($http_code === 404 && $attempt < $maxRetries) {
        echo "[WARNING] Got 404 Not Found on attempt #{$attempt}. Retrying in {$retryDelay} seconds...\n";
        sleep($retryDelay);
        $retryDelay *= 2; // Exponential backoff
    } else {
        // On last attempt or for non-404 errors, fail permanently
        echo "[WARNING] Failed to upload image. Attempting to clean up created rich menu object...\n";
        $ch_delete = curl_init("https://api.line.me/v2/bot/richmenu/{$richMenuId}");
        curl_setopt($ch_delete, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch_delete, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $channelAccessToken]);
        curl_exec($ch_delete);
        curl_close($ch_delete);
        die("[ERROR] Failed to upload rich menu image after {$attempt} attempts. Final HTTP Status: {$http_code} | Response: {$response}\n");
    }
}

if (!$uploadSuccess) {
    // This part should not be reached if die() is called, but as a fallback.
    die("[ERROR] Could not upload rich menu image after all retries.\n");
}

// ----------------------------------------------------------------------------
// 3. Set as Default Rich Menu
// ----------------------------------------------------------------------------
echo "[INFO] Step 3: Setting as Default Rich Menu...\n";

$ch = curl_init("https://api.line.me/v2/bot/user/all/richmenu/{$richMenuId}");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $channelAccessToken,
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code !== 200) {
    die("[ERROR] Failed to set default rich menu. HTTP Status: {$http_code} | Response: {$response}\n");
}

echo "[SUCCESS] Rich Menu has been set as the default for all users.\n";
echo "[INFO] Rich Menu Setup Script Finished.\n";

?>