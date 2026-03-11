<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';

try {
    $profileId = Auth::requireProfile();
    $pdo = Database::getConnection();

    $input = json_decode(file_get_contents('php://input'), true);
    $sourceGameId = (int)($input['game_id'] ?? 0);
    if (!$sourceGameId) throw new Exception('Missing game_id');

    // Verify source game exists
    $stmt = $pdo->prepare("SELECT * FROM games WHERE id = ?");
    $stmt->execute([$sourceGameId]);
    $sourceGame = $stmt->fetch();
    if (!$sourceGame) throw new Exception('Game not found');

    $pdo->beginTransaction();

    // 1. Clone the game record
    $stmt = $pdo->prepare("INSERT INTO games (title, summary, cover_image, status, difficulty, profile_id) VALUES (?, ?, ?, 'active', ?, ?)");
    $stmt->execute([
        $sourceGame['title'],
        $sourceGame['summary'],
        $sourceGame['cover_image'],
        $sourceGame['difficulty'],
        $profileId
    ]);
    $newGameId = (int)$pdo->lastInsertId();

    // 2. Clone plot
    $stmt = $pdo->prepare("SELECT * FROM plots WHERE game_id = ?");
    $stmt->execute([$sourceGameId]);
    $plot = $stmt->fetch();
    if ($plot) {
        $stmt = $pdo->prepare("INSERT INTO plots (game_id, victim_name, killer_name, weapon, motive, backstory, setting_description, time_period, art_style, motive_options, weapon_object_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $newGameId, $plot['victim_name'], $plot['killer_name'], $plot['weapon'],
            $plot['motive'], $plot['backstory'], $plot['setting_description'],
            $plot['time_period'], $plot['art_style'], $plot['motive_options'], null // weapon_object_id mapped later
        ]);
    }

    // 3. Clone locations (map old IDs to new IDs)
    $stmt = $pdo->prepare("SELECT * FROM locations WHERE game_id = ?");
    $stmt->execute([$sourceGameId]);
    $locations = $stmt->fetchAll();
    $locIdMap = [];
    $startLocId = null;

    $insertLoc = $pdo->prepare("INSERT INTO locations (game_id, name, description, short_description, is_locked, lock_reason, discovered, x_pos, y_pos, z_pos, image) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?)");
    foreach ($locations as $loc) {
        $insertLoc->execute([
            $newGameId, $loc['name'], $loc['description'], $loc['short_description'],
            $loc['is_locked'], $loc['lock_reason'],
            $loc['x_pos'], $loc['y_pos'], $loc['z_pos'] ?? 0, $loc['image']
        ]);
        $newLocId = (int)$pdo->lastInsertId();
        $locIdMap[$loc['id']] = $newLocId;

        // Check if this was the starting location (discovered=1 with lowest id)
        if ($loc['discovered'] && ($startLocId === null)) {
            $startLocId = $newLocId;
        }
    }

    // Mark starting location as discovered
    if ($startLocId) {
        $pdo->prepare("UPDATE locations SET discovered = 1 WHERE id = ?")->execute([$startLocId]);
    }

    // 4. Clone connections
    $stmt = $pdo->prepare("SELECT * FROM location_connections WHERE game_id = ?");
    $stmt->execute([$sourceGameId]);
    $connections = $stmt->fetchAll();
    $insertConn = $pdo->prepare("INSERT INTO location_connections (game_id, from_location_id, to_location_id, direction) VALUES (?, ?, ?, ?)");
    foreach ($connections as $conn) {
        $fromId = $locIdMap[$conn['from_location_id']] ?? null;
        $toId = $locIdMap[$conn['to_location_id']] ?? null;
        if ($fromId && $toId) {
            $insertConn->execute([$newGameId, $fromId, $toId, $conn['direction']]);
        }
    }

    // 5. Clone characters (map old IDs to new IDs)
    $stmt = $pdo->prepare("SELECT * FROM characters_game WHERE game_id = ?");
    $stmt->execute([$sourceGameId]);
    $characters = $stmt->fetchAll();
    $charIdMap = [];

    // Find the original starting location from the source game to place characters correctly
    $insertChar = $pdo->prepare("INSERT INTO characters_game (game_id, name, description, role, location_id, is_alive, image, trust_level, has_met) VALUES (?, ?, ?, ?, ?, ?, ?, 50, 0)");
    foreach ($characters as $ch) {
        $newLocId = $locIdMap[$ch['location_id']] ?? ($startLocId ?? null);
        $insertChar->execute([
            $newGameId, $ch['name'], $ch['description'], $ch['role'],
            $newLocId, $ch['is_alive'], $ch['image']
        ]);
        $charIdMap[$ch['id']] = (int)$pdo->lastInsertId();
    }

    // 6. Clone objects (map old IDs to new IDs, reset to original locations)
    $stmt = $pdo->prepare("SELECT * FROM objects WHERE game_id = ?");
    $stmt->execute([$sourceGameId]);
    $objects = $stmt->fetchAll();
    $objIdMap = [];

    $insertObj = $pdo->prepare("INSERT INTO objects (game_id, name, description, inspect_text, location_id, character_id, is_hidden, is_pickupable, is_evidence, is_weapon, image, parent_object_id, original_location_id, original_is_hidden) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($objects as $obj) {
        // Use original location if available, otherwise current location
        $origLocId = $obj['original_location_id'] ?? $obj['location_id'];
        $newLocId = $locIdMap[$origLocId] ?? ($locIdMap[$obj['location_id']] ?? null);
        $origIsHidden = $obj['original_is_hidden'] ?? $obj['is_hidden'];

        // Character-held objects stay with their character
        $newCharId = null;
        if ($obj['character_id']) {
            $newCharId = $charIdMap[$obj['character_id']] ?? null;
            $newLocId = null; // Character-held, not at a location
        }

        $insertObj->execute([
            $newGameId, $obj['name'], $obj['description'], $obj['inspect_text'],
            $newLocId, $newCharId,
            $origIsHidden, $obj['is_pickupable'], $obj['is_evidence'], $obj['is_weapon'],
            $obj['image'], null, // parent_object_id mapped later
            $newLocId, $origIsHidden
        ]);
        $objIdMap[$obj['id']] = (int)$pdo->lastInsertId();
    }

    // Fix parent_object_id references
    foreach ($objects as $obj) {
        if ($obj['parent_object_id'] && isset($objIdMap[$obj['id']]) && isset($objIdMap[$obj['parent_object_id']])) {
            $pdo->prepare("UPDATE objects SET parent_object_id = ? WHERE id = ?")
                ->execute([$objIdMap[$obj['parent_object_id']], $objIdMap[$obj['id']]]);
        }
    }

    // Fix weapon_object_id in plot
    if ($plot && $plot['weapon_object_id'] && isset($objIdMap[$plot['weapon_object_id']])) {
        $pdo->prepare("UPDATE plots SET weapon_object_id = ? WHERE game_id = ?")
            ->execute([$objIdMap[$plot['weapon_object_id']], $newGameId]);
    }

    // 7. Clone clues (reset to undiscovered)
    $stmt = $pdo->prepare("SELECT * FROM clues WHERE game_id = ?");
    $stmt->execute([$sourceGameId]);
    $clues = $stmt->fetchAll();
    $insertClue = $pdo->prepare("INSERT INTO clues (game_id, location_id, object_id, character_id, clue_text, discovery_method, is_red_herring, discovered) VALUES (?, ?, ?, ?, ?, ?, ?, 0)");
    foreach ($clues as $clue) {
        $insertClue->execute([
            $newGameId,
            $locIdMap[$clue['location_id']] ?? null,
            $objIdMap[$clue['object_id']] ?? null,
            $charIdMap[$clue['character_id']] ?? null,
            $clue['clue_text'], $clue['discovery_method'], $clue['is_red_herring']
        ]);
    }

    // 8. Create fresh player state at starting location
    $stmt = $pdo->prepare("INSERT INTO player_state (game_id, current_location_id, moves_taken, accusations_remaining, probes_remaining) VALUES (?, ?, 0, 3, 5)");
    $stmt->execute([$newGameId, $startLocId]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'game_id' => $newGameId,
        'message' => 'Game cloned successfully'
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
