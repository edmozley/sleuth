<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';

try {
    $profileId = Auth::requireProfile();
    $config = Database::getConfig();
    $imageProvider = $config['image_provider'] ?? 'openai';

    if ($imageProvider === 'venice' && empty($config['venice_api_key'])) {
        echo json_encode(['success' => false, 'error' => 'No Venice.ai API key configured']);
        exit;
    }
    if ($imageProvider === 'openai' && empty($config['openai_api_key'])) {
        echo json_encode(['success' => false, 'error' => 'No OpenAI API key configured']);
        exit;
    }

    $pdo = Database::getConnection();
    $gameId = (int)($_GET['game_id'] ?? 0);
    if (!$gameId) throw new Exception('No game_id');

    $stmt = $pdo->prepare("SELECT g.title, p.setting_description, p.time_period FROM games g JOIN plots p ON p.game_id = g.id WHERE g.id = ?");
    $stmt->execute([$gameId]);
    $row = $stmt->fetch();
    if (!$row) throw new Exception('Game not found');

    $prompt = "Dark, moody, atmospheric painting of a mysterious scene. Setting: {$row['setting_description']}. Style: oil painting, noir, dramatic lighting, cinematic composition, detailed brushwork, no people.";

    if ($imageProvider === 'venice') {
        $apiUrl = 'https://api.venice.ai/api/v1/images/generations';
        $apiKey = $config['venice_api_key'];
        $body = ['model' => 'fluently-xl', 'prompt' => $prompt, 'n' => 1, 'size' => '1024x1024'];
        $timeout = 120;
    } else {
        $apiUrl = 'https://api.openai.com/v1/images/generations';
        $apiKey = $config['openai_api_key'];
        $body = ['model' => 'dall-e-3', 'prompt' => $prompt, 'n' => 1, 'size' => '1024x1024', 'quality' => 'standard'];
        $timeout = 60;
    }

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        echo json_encode(['success' => false, 'error' => 'cURL error: ' . $curlError, 'prompt' => $prompt]);
        exit;
    }

    if ($httpCode !== 200) {
        $data = json_decode($response, true);
        $msg = $data['error']['message'] ?? ($data['error'] ?? substr($response, 0, 500));
        if (is_array($msg)) $msg = json_encode($msg);
        echo json_encode(['success' => false, 'error' => "HTTP {$httpCode}: {$msg}", 'prompt' => $prompt]);
        exit;
    }

    $data = json_decode($response, true);
    $b64 = $data['data'][0]['b64_json'] ?? null;
    $imageUrl = $data['data'][0]['url'] ?? null;

    if ($b64) {
        $imageData = base64_decode($b64);
    } elseif ($imageUrl) {
        $imageData = @file_get_contents($imageUrl);
    } else {
        echo json_encode(['success' => false, 'error' => 'No image data in response', 'prompt' => $prompt]);
        exit;
    }

    if (!$imageData) {
        echo json_encode(['success' => false, 'error' => 'Failed to decode/download image', 'prompt' => $prompt]);
        exit;
    }

    $dir = __DIR__ . '/../assets/covers';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $filename = 'cover_' . $gameId . '.png';
    file_put_contents($dir . '/' . $filename, $imageData);
    $coverPath = 'assets/covers/' . $filename;

    $stmt = $pdo->prepare("UPDATE games SET cover_image = ? WHERE id = ?");
    $stmt->execute([$coverPath, $gameId]);

    echo json_encode(['success' => true, 'cover_image' => $coverPath, 'prompt' => $prompt]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
