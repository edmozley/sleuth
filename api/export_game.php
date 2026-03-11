<?php
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';

try {
    Auth::requireProfile();
    $pdo = Database::getConnection();
    $gameId = (int)($_GET['game_id'] ?? 0);
    if (!$gameId) throw new Exception('Missing game_id');

    // Game
    $stmt = $pdo->prepare("SELECT title, summary, status, difficulty FROM games WHERE id = ?");
    $stmt->execute([$gameId]);
    $game = $stmt->fetch();
    if (!$game) throw new Exception('Game not found');

    // Plot
    $stmt = $pdo->prepare("SELECT victim_name, killer_name, weapon, motive, backstory, setting_description, time_period, art_style, motive_options FROM plots WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $plot = $stmt->fetch();

    // Locations — build index map
    $stmt = $pdo->prepare("SELECT * FROM locations WHERE game_id = ? ORDER BY id");
    $stmt->execute([$gameId]);
    $locRows = $stmt->fetchAll();
    $locIdToIndex = [];
    $locations = [];
    foreach ($locRows as $i => $loc) {
        $locIdToIndex[$loc['id']] = $i;
        $locations[] = [
            'name' => $loc['name'],
            'description' => $loc['description'],
            'short_description' => $loc['short_description'],
            'is_locked' => (int)$loc['is_locked'],
            'lock_reason' => $loc['lock_reason'],
            'discovered' => (int)$loc['discovered'],
            'x_pos' => (int)$loc['x_pos'],
            'y_pos' => (int)$loc['y_pos'],
            'z_pos' => (int)($loc['z_pos'] ?? 0),
            'image' => $loc['image'] ? basename($loc['image']) : null
        ];
    }

    // Connections
    $stmt = $pdo->prepare("SELECT from_location_id, to_location_id, direction FROM location_connections WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $connRows = $stmt->fetchAll();
    $connections = [];
    foreach ($connRows as $conn) {
        $from = $locIdToIndex[$conn['from_location_id']] ?? null;
        $to = $locIdToIndex[$conn['to_location_id']] ?? null;
        if ($from !== null && $to !== null) {
            $connections[] = [
                'from_location' => $from,
                'to_location' => $to,
                'direction' => $conn['direction']
            ];
        }
    }

    // Characters — build index map
    $stmt = $pdo->prepare("SELECT * FROM characters_game WHERE game_id = ? ORDER BY id");
    $stmt->execute([$gameId]);
    $charRows = $stmt->fetchAll();
    $charIdToIndex = [];
    $characters = [];
    foreach ($charRows as $i => $ch) {
        $charIdToIndex[$ch['id']] = $i;
        $characters[] = [
            'name' => $ch['name'],
            'description' => $ch['description'],
            'role' => $ch['role'],
            'location' => $locIdToIndex[$ch['location_id']] ?? null,
            'is_alive' => (int)$ch['is_alive'],
            'trust_level' => (int)($ch['trust_level'] ?? 50),
            'has_met' => (int)($ch['has_met'] ?? 0),
            'image' => $ch['image'] ? basename($ch['image']) : null
        ];
    }

    // Objects — build index map
    $stmt = $pdo->prepare("SELECT * FROM objects WHERE game_id = ? ORDER BY id");
    $stmt->execute([$gameId]);
    $objRows = $stmt->fetchAll();
    $objIdToIndex = [];
    $objects = [];
    foreach ($objRows as $i => $obj) {
        $objIdToIndex[$obj['id']] = $i;
        $objects[] = [
            'name' => $obj['name'],
            'description' => $obj['description'],
            'inspect_text' => $obj['inspect_text'],
            'location' => $locIdToIndex[$obj['location_id']] ?? null,
            'character' => $charIdToIndex[$obj['character_id']] ?? null,
            'is_hidden' => (int)$obj['is_hidden'],
            'is_pickupable' => (int)$obj['is_pickupable'],
            'is_evidence' => (int)$obj['is_evidence'],
            'is_weapon' => (int)($obj['is_weapon'] ?? 0),
            'parent_object' => null, // filled below
            'original_location' => $locIdToIndex[$obj['original_location_id'] ?? $obj['location_id']] ?? null,
            'original_is_hidden' => (int)($obj['original_is_hidden'] ?? $obj['is_hidden']),
            'image' => $obj['image'] ? basename($obj['image']) : null
        ];
    }
    // Fix parent_object references
    foreach ($objRows as $i => $obj) {
        if ($obj['parent_object_id'] && isset($objIdToIndex[$obj['parent_object_id']])) {
            $objects[$i]['parent_object'] = $objIdToIndex[$obj['parent_object_id']];
        }
    }

    // Weapon object index for plot
    $weaponIndex = null;
    if ($plot) {
        $stmt2 = $pdo->prepare("SELECT weapon_object_id FROM plots WHERE game_id = ?");
        $stmt2->execute([$gameId]);
        $plotFull = $stmt2->fetch();
        if ($plotFull['weapon_object_id'] && isset($objIdToIndex[$plotFull['weapon_object_id']])) {
            $weaponIndex = $objIdToIndex[$plotFull['weapon_object_id']];
        }
    }

    // Clues
    $stmt = $pdo->prepare("SELECT description, category, importance, discovery_method, discovered, linked_location_id, linked_object_id, linked_character_id FROM clues WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $clueRows = $stmt->fetchAll();
    $clues = [];
    foreach ($clueRows as $clue) {
        $clues[] = [
            'description' => $clue['description'],
            'category' => $clue['category'],
            'importance' => $clue['importance'],
            'discovery_method' => $clue['discovery_method'],
            'discovered' => (int)$clue['discovered'],
            'location' => $locIdToIndex[$clue['linked_location_id']] ?? null,
            'object' => $objIdToIndex[$clue['linked_object_id']] ?? null,
            'character' => $charIdToIndex[$clue['linked_character_id']] ?? null
        ];
    }

    // Player state
    $stmt = $pdo->prepare("SELECT current_location_id, moves_taken, accusations_remaining, probes_remaining FROM player_state WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $ps = $stmt->fetch();
    $playerState = $ps ? [
        'current_location' => $locIdToIndex[$ps['current_location_id']] ?? 0,
        'moves_taken' => (int)$ps['moves_taken'],
        'accusations_remaining' => (int)$ps['accusations_remaining'],
        'probes_remaining' => (int)($ps['probes_remaining'] ?? 5)
    ] : null;

    // Build export data
    $export = [
        'version' => 1,
        'exported_at' => date('c'),
        'game' => $game,
        'plot' => $plot,
        'weapon_object' => $weaponIndex,
        'locations' => $locations,
        'connections' => $connections,
        'characters' => $characters,
        'objects' => $objects,
        'clues' => $clues,
        'player_state' => $playerState
    ];

    // Decode motive_options from JSON string
    if ($export['plot'] && $export['plot']['motive_options']) {
        $export['plot']['motive_options'] = json_decode($export['plot']['motive_options'], true);
    }

    // Build ZIP
    $zipFile = tempnam(sys_get_temp_dir(), 'sleuth_export_');
    $zip = new ZipArchive();
    if ($zip->open($zipFile, ZipArchive::OVERWRITE) !== true) {
        throw new Exception('Failed to create ZIP');
    }

    $zip->addFromString('game.json', json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    // Add cover image
    $coverPath = __DIR__ . '/../assets/covers/cover_' . $gameId . '.png';
    if (file_exists($coverPath)) {
        $zip->addFile($coverPath, 'images/cover.png');
    }

    // Add game images
    $imageDir = __DIR__ . '/../assets/images/game_' . $gameId;
    if (is_dir($imageDir)) {
        $files = scandir($imageDir);
        foreach ($files as $f) {
            if ($f === '.' || $f === '..') continue;
            $zip->addFile($imageDir . '/' . $f, 'images/' . $f);
        }
    }

    $zip->close();

    // Serve ZIP
    $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $game['title']);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="sleuth_' . $safeName . '.zip"');
    header('Content-Length: ' . filesize($zipFile));
    readfile($zipFile);
    unlink($zipFile);

} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
