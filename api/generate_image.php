<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';

try {
    $profileId = Auth::requireProfile();
    $config = Database::getConfig();
    $imageProvider = $config['image_provider'] ?? 'openai';

    if ($imageProvider === 'venice' && empty($config['venice_api_key'])) {
        echo json_encode(['success' => false, 'error' => 'No Venice.ai API key configured', 'done' => true]);
        exit;
    }
    if ($imageProvider === 'openai' && empty($config['openai_api_key'])) {
        echo json_encode(['success' => false, 'error' => 'No OpenAI API key configured', 'done' => true]);
        exit;
    }

    $pdo = Database::getConnection();
    $gameId = (int)($_GET['game_id'] ?? 0);
    if (!$gameId) throw new Exception('No game_id');

    // Get the art style for this game
    $stmt = $pdo->prepare("SELECT art_style, setting_description, time_period FROM plots WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $plot = $stmt->fetch();
    if (!$plot) throw new Exception('Game not found');

    $artStyle = $plot['art_style'] ?: "Dark, moody, atmospheric illustration. Setting: {$plot['setting_description']}. Period: {$plot['time_period']}. Painterly style, dramatic lighting, rich detail.";

    // Condition: NULL = never attempted, '' = failed attempt
    // By default pick up both NULL and empty string (retry failures)
    $needsImage = "(image IS NULL OR image = '')";

    // Find next entity without an image, in order: locations, characters, objects
    $entity = null;
    $entityType = null;

    // 1. Check locations
    $stmt = $pdo->prepare("SELECT id, name, description FROM locations WHERE game_id = ? AND {$needsImage} LIMIT 1");
    $stmt->execute([$gameId]);
    $row = $stmt->fetch();
    if ($row) {
        $entity = $row;
        $entityType = 'location';
    }

    // 2. Check characters
    if (!$entity) {
        $stmt = $pdo->prepare("SELECT id, name, description, role FROM characters_game WHERE game_id = ? AND {$needsImage} LIMIT 1");
        $stmt->execute([$gameId]);
        $row = $stmt->fetch();
        if ($row) {
            $entity = $row;
            $entityType = 'character';
        }
    }

    // 3. Check objects
    if (!$entity) {
        $stmt = $pdo->prepare("SELECT id, name, description FROM objects WHERE game_id = ? AND {$needsImage} LIMIT 1");
        $stmt->execute([$gameId]);
        $row = $stmt->fetch();
        if ($row) {
            $entity = $row;
            $entityType = 'object';
        }
    }

    // Count remaining per category
    $stmt = $pdo->prepare("
        SELECT
            (SELECT COUNT(*) FROM locations WHERE game_id = ? AND {$needsImage}) as locations_remaining,
            (SELECT COUNT(*) FROM locations WHERE game_id = ?) as locations_total,
            (SELECT COUNT(*) FROM characters_game WHERE game_id = ? AND {$needsImage}) as characters_remaining,
            (SELECT COUNT(*) FROM characters_game WHERE game_id = ?) as characters_total,
            (SELECT COUNT(*) FROM objects WHERE game_id = ? AND {$needsImage}) as objects_remaining,
            (SELECT COUNT(*) FROM objects WHERE game_id = ?) as objects_total
    ");
    $stmt->execute([$gameId, $gameId, $gameId, $gameId, $gameId, $gameId]);
    $counts = $stmt->fetch();
    $remaining = (int)$counts['locations_remaining'] + (int)$counts['characters_remaining'] + (int)$counts['objects_remaining'];
    $total = (int)$counts['locations_total'] + (int)$counts['characters_total'] + (int)$counts['objects_total'];

    if (!$entity) {
        echo json_encode([
            'success' => true,
            'done' => true,
            'remaining' => 0,
            'total' => $total,
            'counts' => [
                'locations' => ['done' => (int)$counts['locations_total'], 'total' => (int)$counts['locations_total']],
                'characters' => ['done' => (int)$counts['characters_total'], 'total' => (int)$counts['characters_total']],
                'objects' => ['done' => (int)$counts['objects_total'], 'total' => (int)$counts['objects_total']],
            ]
        ]);
        exit;
    }

    // Build the prompt based on entity type
    // Venice has fewer content restrictions, only sanitize for OpenAI/DALL-E
    $needsSanitize = ($imageProvider === 'openai');
    $stylePrefix = ($needsSanitize ? sanitizeForDallE($artStyle) : $artStyle) . " Purely visual, painterly, no typography.";
    $safeDesc = $needsSanitize ? sanitizeForDallE($entity['description']) : $entity['description'];

    if ($entityType === 'location') {
        $prompt = "{$stylePrefix} Scene illustration of a place: {$entity['name']}. {$safeDesc}";
    } elseif ($entityType === 'character') {
        $role = $entity['role'];
        if ($role === 'victim') {
            // Don't depict death — just a portrait
            $prompt = "{$stylePrefix} Character portrait, upper body, painterly style: {$entity['name']}. {$safeDesc}";
        } else {
            $prompt = "{$stylePrefix} Character portrait, upper body: {$entity['name']}. {$safeDesc}";
        }
    } else {
        $prompt = "{$stylePrefix} Still life close-up of an object: {$entity['name']}. {$safeDesc}";
    }

    // Truncate prompt to DALL-E limit (4000 chars)
    $prompt = mb_substr($prompt, 0, 3900);

    // Generate image via selected provider
    if ($imageProvider === 'venice') {
        $dalleResult = callVenice($config['venice_api_key'], $prompt, $gameId, $entityType, $entity['id']);
    } else {
        $dalleResult = callDallE($config['openai_api_key'], $prompt, $gameId, $entityType, $entity['id']);
    }
    $image = $dalleResult['path'] ?? null;

    // Update image - only save if successful, leave NULL on failure so it can be retried
    $table = $entityType === 'character' ? 'characters_game' : ($entityType === 'location' ? 'locations' : 'objects');
    if ($image) {
        $stmt = $pdo->prepare("UPDATE {$table} SET image = ? WHERE id = ?");
        $stmt->execute([$image, $entity['id']]);
    }

    // Recalculate after update
    $locDone = (int)$counts['locations_total'] - (int)$counts['locations_remaining'] + ($entityType === 'location' ? 1 : 0);
    $charDone = (int)$counts['characters_total'] - (int)$counts['characters_remaining'] + ($entityType === 'character' ? 1 : 0);
    $objDone = (int)$counts['objects_total'] - (int)$counts['objects_remaining'] + ($entityType === 'object' ? 1 : 0);

    $response = [
        'success' => true,
        'done' => false,
        'generated' => [
            'type' => $entityType,
            'id' => (int)$entity['id'],
            'name' => $entity['name'],
            'image' => $image
        ],
        'remaining' => max(0, $remaining - 1),
        'total' => $total,
        'counts' => [
            'locations' => ['done' => $locDone, 'total' => (int)$counts['locations_total']],
            'characters' => ['done' => $charDone, 'total' => (int)$counts['characters_total']],
            'objects' => ['done' => $objDone, 'total' => (int)$counts['objects_total']],
        ]
    ];

    $response['prompt'] = $prompt;
    if (!$image && isset($dalleResult['error'])) {
        $response['image_error'] = $dalleResult['error'];
    }

    echo json_encode($response);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'done' => true]);
}

function callDallE(string $apiKey, string $prompt, int $gameId, string $type, int $entityId): array
{
    $ch = curl_init('https://api.openai.com/v1/images/generations');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'dall-e-3',
            'prompt' => $prompt,
            'n' => 1,
            'size' => '1024x1024',
            'quality' => 'standard'
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['error' => 'cURL error: ' . $curlError];
    }

    if ($httpCode !== 200) {
        $data = json_decode($response, true);
        $msg = $data['error']['message'] ?? $response;
        return ['error' => "DALL-E HTTP {$httpCode}: {$msg}"];
    }

    $data = json_decode($response, true);
    $imageUrl = $data['data'][0]['url'] ?? null;
    if (!$imageUrl) {
        return ['error' => 'No image URL in response: ' . substr($response, 0, 500)];
    }

    $imageData = @file_get_contents($imageUrl);
    if (!$imageData) {
        return ['error' => 'Failed to download image from URL'];
    }

    $dir = __DIR__ . '/../assets/images/game_' . $gameId;
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $filename = $type . '_' . $entityId . '.png';
    file_put_contents($dir . '/' . $filename, $imageData);

    return ['path' => 'assets/images/game_' . $gameId . '/' . $filename];
}

function callVenice(string $apiKey, string $prompt, int $gameId, string $type, int $entityId): array
{
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

    if ($curlError) {
        return ['error' => 'cURL error: ' . $curlError];
    }

    if ($httpCode !== 200) {
        $data = json_decode($response, true);
        $msg = $data['error']['message'] ?? ($data['error'] ?? substr($response, 0, 500));
        if (is_array($msg)) $msg = json_encode($msg);
        return ['error' => "Venice HTTP {$httpCode}: {$msg}"];
    }

    $data = json_decode($response, true);

    // Venice returns b64_json by default, or url
    $b64 = $data['data'][0]['b64_json'] ?? null;
    $imageUrl = $data['data'][0]['url'] ?? null;

    if ($b64) {
        $imageData = base64_decode($b64);
    } elseif ($imageUrl) {
        $imageData = @file_get_contents($imageUrl);
    } else {
        return ['error' => 'No image data in response: ' . substr($response, 0, 500)];
    }

    if (!$imageData) {
        return ['error' => 'Failed to decode/download image'];
    }

    $dir = __DIR__ . '/../assets/images/game_' . $gameId;
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $filename = $type . '_' . $entityId . '.png';
    file_put_contents($dir . '/' . $filename, $imageData);

    return ['path' => 'assets/images/game_' . $gameId . '/' . $filename];
}

function sanitizeForDallE(string $text): string
{
    // Remove sentences that describe death/crime discovery (e.g. "This is where X lies slumped...")
    $text = preg_replace('/[^.]*\b(lies\s+(slumped|dead|motionless|face\s*down)|body\s+(was\s+)?(discovered|found)|discovered\s+(the\s+)?(body|corpse)|found\s+(dead|lifeless|slumped))[^.]*/i', '', $text);

    // Remove individual words/phrases that trigger DALL-E safety filters
    $removals = [
        '/\b(murder|murdered|murderer|murdering|killing|killed|killer|strangl\w*|stabb\w*|stabbing|poison\w*|blood|bloody|bleed\w*|corpse|dead\s*body|death|dead|died|dying|slain|slash\w*|wound\w*|injur\w*|assault\w*|violen\w*|weapon\w*|gun|guns|knife|knives|dagger|pistol|rifle|shoot\w*|shot|bullet\w*|suffocat\w*|chok\w*|bludgeon\w*|beaten|beating|torture\w*|victim|crime\s*scene|forensic\w*|autopsy|homicid\w*|suicide|lethal|fatal|slay|slaughter|dismember|decapitat|execution|execut\w*|lifeless|motionless|slumped|terror\w*|horrif\w*)\b/i',
    ];
    $cleaned = preg_replace($removals, '', $text);
    // Collapse multiple spaces and stray punctuation
    $cleaned = preg_replace('/\s{2,}/', ' ', trim($cleaned));
    $cleaned = preg_replace('/\s+([,.])\s*/', '$1 ', $cleaned);
    // If we stripped too much, return a minimal description
    if (strlen($cleaned) < 10) {
        return 'Mysterious and atmospheric scene';
    }
    return $cleaned;
}
