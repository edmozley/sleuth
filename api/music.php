<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';

function searchFreesound(string $query, string $apiKey): array {
    $url = 'https://freesound.org/apiv2/search/text/?' . http_build_query([
        'query' => $query,
        'fields' => 'id,name,duration,previews,tags',
        'filter' => 'duration:[60 TO *]',
        'sort' => 'rating_desc',
        'page_size' => 20,
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

// Extract the most relevant place/country from a setting description
// "British Embassy in Shanghai" -> ["shanghai", "china", "chinese"]
// "Ancient Egyptian temple along the Nile" -> ["egypt", "egyptian"]
// "A jazz club in 1920s Chicago" -> ["chicago", "jazz"]
function extractSearchTerms(string $setting, string $period): array {
    $text = strtolower($setting . ' ' . $period);

    // City -> country mapping for better music searches
    $cities = [
        'shanghai' => 'chinese', 'beijing' => 'chinese', 'hong kong' => 'chinese',
        'tokyo' => 'japanese', 'kyoto' => 'japanese', 'osaka' => 'japanese',
        'cairo' => 'egyptian', 'alexandria' => 'egyptian',
        'mumbai' => 'indian', 'delhi' => 'indian', 'calcutta' => 'indian',
        'paris' => 'french', 'marseille' => 'french',
        'rome' => 'italian', 'venice' => 'italian', 'florence' => 'italian', 'naples' => 'italian',
        'london' => 'english', 'oxford' => 'english', 'cambridge' => 'english',
        'havana' => 'cuban', 'rio' => 'brazilian',
        'istanbul' => 'turkish', 'marrakech' => 'moroccan',
        'moscow' => 'russian', 'st petersburg' => 'russian',
        'berlin' => 'german', 'vienna' => 'austrian',
        'chicago' => 'jazz', 'new orleans' => 'jazz', 'new york' => 'jazz',
        'bangkok' => 'thai', 'hanoi' => 'vietnamese', 'seoul' => 'korean',
        'mexico city' => 'mexican', 'buenos aires' => 'argentinian',
        'athens' => 'greek', 'lisbon' => 'portuguese',
        'dublin' => 'irish', 'edinburgh' => 'scottish',
        'stockholm' => 'nordic', 'oslo' => 'nordic', 'copenhagen' => 'nordic',
    ];

    // Country/culture adjectives that work well as music search terms
    $cultures = [
        'egyptian', 'chinese', 'japanese', 'indian', 'french', 'italian',
        'spanish', 'mexican', 'cuban', 'brazilian', 'caribbean',
        'turkish', 'persian', 'arabic', 'arabian', 'moroccan',
        'russian', 'irish', 'scottish', 'celtic', 'nordic', 'viking',
        'greek', 'german', 'austrian', 'portuguese',
        'thai', 'vietnamese', 'korean', 'indonesian',
        'tibetan', 'mongolian', 'hawaiian', 'polynesian',
        'african', 'english', 'british', 'american', 'argentinian',
    ];

    // Genre words
    $genres = ['jazz', 'blues', 'ragtime', 'swing', 'cabaret'];

    $terms = [];

    // Check cities first (most specific)
    foreach ($cities as $city => $culture) {
        if (strpos($text, $city) !== false) {
            $terms[] = $city;
            $terms[] = $culture;
            break;
        }
    }

    // Check culture adjectives
    foreach ($cultures as $word) {
        if (strpos($text, $word) !== false && !in_array($word, $terms)) {
            $terms[] = $word;
        }
    }

    // Check genres
    foreach ($genres as $word) {
        if (strpos($text, $word) !== false && !in_array($word, $terms)) {
            $terms[] = $word;
        }
    }

    // Check country names and map to adjective
    $countries = [
        'egypt' => 'egyptian', 'china' => 'chinese', 'japan' => 'japanese',
        'india' => 'indian', 'france' => 'french', 'italy' => 'italian',
        'spain' => 'spanish', 'mexico' => 'mexican', 'cuba' => 'cuban',
        'brazil' => 'brazilian', 'turkey' => 'turkish', 'iran' => 'persian',
        'russia' => 'russian', 'ireland' => 'irish', 'scotland' => 'scottish',
        'greece' => 'greek', 'germany' => 'german', 'morocco' => 'moroccan',
        'england' => 'english', 'britain' => 'british',
    ];
    foreach ($countries as $country => $adj) {
        if (strpos($text, $country) !== false && !in_array($adj, $terms)) {
            $terms[] = $adj;
        }
    }

    return array_unique($terms);
}

try {
    Auth::requireProfile();
    $config = Database::getConfig();

    $apiKey = $config['freesound_api_key'] ?? '';
    if (!$apiKey) {
        echo json_encode(['success' => false, 'error' => 'Freesound API key not configured']);
        exit;
    }

    $setting = trim($_GET['setting'] ?? '');
    $period = trim($_GET['period'] ?? '');

    $terms = extractSearchTerms($setting, $period);

    // Build queries — always include "music"
    $queries = [];
    if (!empty($terms)) {
        $primary = $terms[0];
        $queries[] = $primary . ' music';
        if (isset($terms[1]) && $terms[1] !== $primary) {
            $queries[] = $terms[1] . ' music';
        }
    }
    $queries[] = 'mystery suspense music';

    $tracks = [];
    $queryUsed = '';

    foreach ($queries as $query) {
        $tracks = searchFreesound($query, $apiKey);
        if (!empty($tracks)) {
            $queryUsed = $query;
            break;
        }
    }

    echo json_encode(['success' => true, 'tracks' => $tracks, 'query' => $queryUsed]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
