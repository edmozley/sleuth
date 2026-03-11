<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/Database.php';

// No auth required — this serves the profile picker and home screen

function searchFreesound(string $query, string $apiKey): array {
    $url = 'https://freesound.org/apiv2/search/text/?' . http_build_query([
        'query' => $query,
        'fields' => 'id,name,duration,previews,tags',
        'filter' => 'duration:[60 TO *]',
        'sort' => 'rating_desc',
        'page_size' => 15,
        'token' => $apiKey
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 10
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) return [];

    $data = json_decode($response, true);
    $tracks = [];

    foreach (($data['results'] ?? []) as $sound) {
        $preview = $sound['previews']['preview-hq-mp3'] ?? $sound['previews']['preview-lq-mp3'] ?? null;
        if ($preview) {
            $tracks[] = [
                'id' => $sound['id'],
                'name' => $sound['name'],
                'duration' => round($sound['duration']),
                'url' => $preview
            ];
        }
    }

    return $tracks;
}

try {
    $config = Database::getConfig();
    $apiKey = $config['freesound_api_key'] ?? '';
    if (!$apiKey) {
        echo json_encode(['success' => false, 'error' => 'No API key']);
        exit;
    }

    $queries = [
        'haunting mystery ambient music',
        'dark ambient suspense',
        'mystery noir atmosphere'
    ];

    $tracks = [];
    $queryUsed = '';

    foreach ($queries as $query) {
        $tracks = searchFreesound($query, $apiKey);
        if (!empty($tracks)) {
            $queryUsed = $query;
            break;
        }
    }

    // Shuffle so it's not the same order every time
    shuffle($tracks);

    echo json_encode(['success' => true, 'tracks' => $tracks, 'query' => $queryUsed]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
