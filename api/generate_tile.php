<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';

try {
    Auth::requireProfile();
    $config = Database::getConfig();
    $pdo = Database::getConnection();

    $gameId = (int)($_GET['game_id'] ?? 0);
    if (!$gameId) throw new Exception('No game_id');

    $tilePath = 'assets/images/game_' . $gameId . '/toolbar_tile.png';
    $fullPath = __DIR__ . '/../' . $tilePath;

    // Return cached tile if it exists
    if (file_exists($fullPath)) {
        echo json_encode(['success' => true, 'path' => $tilePath]);
        exit;
    }

    // Get game setting for themed tile
    $stmt = $pdo->prepare("SELECT setting_description, time_period FROM plots WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $plot = $stmt->fetch();
    if (!$plot) throw new Exception('No plot found');

    $apiKey = $config['venice_api_key'] ?? '';
    if (!$apiKey) throw new Exception('Venice API key not configured');

    $setting = $plot['setting_description'] ?? '';
    $period = $plot['time_period'] ?? '';

    $prompt = "Seamless tileable abstract pattern inspired by: {$setting}, {$period}. "
        . "Dark moody color palette with deep blacks, dark blues, and subtle accent colors. "
        . "Abstract geometric or organic shapes, NOT a picture or scene. "
        . "Must tile seamlessly when repeated. Subtle and understated, suitable as a background texture. "
        . "256x256 pixel tile pattern.";

    $ch = curl_init('https://api.venice.ai/api/v1/images/generations');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'fluently-xl',
            'prompt' => $prompt,
            'n' => 1,
            'size' => '1024x1024'
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) throw new Exception('cURL error: ' . $curlError);
    if ($httpCode !== 200) {
        $data = json_decode($response, true);
        $msg = $data['error']['message'] ?? ($data['error'] ?? substr($response, 0, 500));
        if (is_array($msg)) $msg = json_encode($msg);
        throw new Exception("Venice HTTP {$httpCode}: {$msg}");
    }

    $data = json_decode($response, true);
    $b64 = $data['data'][0]['b64_json'] ?? null;
    $imageUrl = $data['data'][0]['url'] ?? null;

    if ($b64) {
        $imageData = base64_decode($b64);
    } elseif ($imageUrl) {
        $imageData = @file_get_contents($imageUrl);
    } else {
        throw new Exception('No image data in response');
    }

    if (!$imageData) throw new Exception('Failed to decode image');

    $dir = dirname($fullPath);
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    // Resize to 256x256 for efficient tiling
    $src = imagecreatefromstring($imageData);
    if ($src) {
        $tile = imagecreatetruecolor(256, 256);
        imagecopyresampled($tile, $src, 0, 0, 0, 0, 256, 256, imagesx($src), imagesy($src));
        imagepng($tile, $fullPath);
        imagedestroy($src);
        imagedestroy($tile);
    } else {
        file_put_contents($fullPath, $imageData);
    }

    echo json_encode(['success' => true, 'path' => $tilePath]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
