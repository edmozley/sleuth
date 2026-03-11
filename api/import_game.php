<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';

try {
    $profileId = Auth::requireProfile();
    $pdo = Database::getConnection();

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No file uploaded or upload error');
    }

    $zip = new ZipArchive();
    if ($zip->open($_FILES['file']['tmp_name']) !== true) {
        throw new Exception('Invalid ZIP file');
    }

    $jsonStr = $zip->getFromName('game.json');
    if ($jsonStr === false) {
        $zip->close();
        throw new Exception('ZIP does not contain game.json');
    }

    $data = json_decode($jsonStr, true);
    if (!$data || !isset($data['game']) || !isset($data['locations'])) {
        $zip->close();
        throw new Exception('Invalid game data in ZIP');
    }

    $pdo->beginTransaction();

    // 1. Create game
    $stmt = $pdo->prepare("INSERT INTO games (title, summary, status, difficulty, profile_id) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([
        $data['game']['title'],
        $data['game']['summary'] ?? null,
        $data['game']['status'] ?? 'active',
        $data['game']['difficulty'] ?? null,
        $profileId
    ]);
    $gameId = (int)$pdo->lastInsertId();

    // 2. Create plot
    $plot = $data['plot'] ?? null;
    if ($plot) {
        $motiveOptions = $plot['motive_options'] ?? null;
        if (is_array($motiveOptions)) {
            $motiveOptions = json_encode($motiveOptions);
        }
        $stmt = $pdo->prepare("INSERT INTO plots (game_id, victim_name, killer_name, weapon, motive, backstory, setting_description, time_period, art_style, motive_options) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $gameId,
            $plot['victim_name'] ?? null,
            $plot['killer_name'] ?? null,
            $plot['weapon'] ?? null,
            $plot['motive'] ?? null,
            $plot['backstory'] ?? null,
            $plot['setting_description'] ?? null,
            $plot['time_period'] ?? null,
            $plot['art_style'] ?? null,
            $motiveOptions
        ]);
    }

    // 3. Create locations (index → new ID map)
    $locIds = [];
    $imageDir = __DIR__ . '/../assets/images/game_' . $gameId;
    if (!is_dir($imageDir)) mkdir($imageDir, 0755, true);

    $insertLoc = $pdo->prepare("INSERT INTO locations (game_id, name, description, short_description, is_locked, lock_reason, discovered, x_pos, y_pos, z_pos, image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach (($data['locations'] ?? []) as $i => $loc) {
        $imagePath = null;
        if ($loc['image']) {
            $imgData = $zip->getFromName('images/' . $loc['image']);
            if ($imgData !== false) {
                $imagePath = 'assets/images/game_' . $gameId . '/' . $loc['image'];
                file_put_contents(__DIR__ . '/../' . $imagePath, $imgData);
            }
        }
        $insertLoc->execute([
            $gameId, $loc['name'], $loc['description'], $loc['short_description'] ?? null,
            $loc['is_locked'] ?? 0, $loc['lock_reason'] ?? null, $loc['discovered'] ?? 0,
            $loc['x_pos'] ?? 0, $loc['y_pos'] ?? 0, $loc['z_pos'] ?? 0,
            $imagePath
        ]);
        $locIds[$i] = (int)$pdo->lastInsertId();
    }

    // 4. Create connections
    $insertConn = $pdo->prepare("INSERT INTO location_connections (game_id, from_location_id, to_location_id, direction) VALUES (?, ?, ?, ?)");
    foreach (($data['connections'] ?? []) as $conn) {
        $fromId = $locIds[$conn['from_location']] ?? null;
        $toId = $locIds[$conn['to_location']] ?? null;
        if ($fromId && $toId) {
            $insertConn->execute([$gameId, $fromId, $toId, $conn['direction']]);
        }
    }

    // 5. Create characters (index → new ID map)
    $charIds = [];
    $insertChar = $pdo->prepare("INSERT INTO characters_game (game_id, name, description, role, location_id, is_alive, trust_level, has_met, image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach (($data['characters'] ?? []) as $i => $ch) {
        $imagePath = null;
        if ($ch['image'] ?? null) {
            $imgData = $zip->getFromName('images/' . $ch['image']);
            if ($imgData !== false) {
                $imagePath = 'assets/images/game_' . $gameId . '/' . $ch['image'];
                file_put_contents(__DIR__ . '/../' . $imagePath, $imgData);
            }
        }
        $insertChar->execute([
            $gameId, $ch['name'], $ch['description'], $ch['role'],
            $locIds[$ch['location']] ?? null,
            $ch['is_alive'] ?? 1,
            $ch['trust_level'] ?? 50,
            $ch['has_met'] ?? 0,
            $imagePath
        ]);
        $charIds[$i] = (int)$pdo->lastInsertId();
    }

    // 6. Create objects (index → new ID map)
    $objIds = [];
    $insertObj = $pdo->prepare("INSERT INTO objects (game_id, name, description, inspect_text, location_id, character_id, is_hidden, is_pickupable, is_evidence, is_weapon, parent_object_id, original_location_id, original_is_hidden, image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach (($data['objects'] ?? []) as $i => $obj) {
        $imagePath = null;
        if ($obj['image'] ?? null) {
            $imgData = $zip->getFromName('images/' . $obj['image']);
            if ($imgData !== false) {
                $imagePath = 'assets/images/game_' . $gameId . '/' . $obj['image'];
                file_put_contents(__DIR__ . '/../' . $imagePath, $imgData);
            }
        }
        $insertObj->execute([
            $gameId, $obj['name'], $obj['description'], $obj['inspect_text'] ?? null,
            $locIds[$obj['location']] ?? null,
            $charIds[$obj['character']] ?? null,
            $obj['is_hidden'] ?? 0, $obj['is_pickupable'] ?? 0,
            $obj['is_evidence'] ?? 0, $obj['is_weapon'] ?? 0,
            null, // parent_object_id fixed below
            $locIds[$obj['original_location']] ?? ($locIds[$obj['location']] ?? null),
            $obj['original_is_hidden'] ?? ($obj['is_hidden'] ?? 0),
            $imagePath
        ]);
        $objIds[$i] = (int)$pdo->lastInsertId();
    }

    // Fix parent_object_id
    foreach (($data['objects'] ?? []) as $i => $obj) {
        if (($obj['parent_object'] ?? null) !== null && isset($objIds[$obj['parent_object']])) {
            $pdo->prepare("UPDATE objects SET parent_object_id = ? WHERE id = ?")
                ->execute([$objIds[$obj['parent_object']], $objIds[$i]]);
        }
    }

    // Fix weapon_object_id in plot
    if ($plot && ($data['weapon_object'] ?? null) !== null && isset($objIds[$data['weapon_object']])) {
        $pdo->prepare("UPDATE plots SET weapon_object_id = ? WHERE game_id = ?")
            ->execute([$objIds[$data['weapon_object']], $gameId]);
    }

    // 7. Create clues
    $insertClue = $pdo->prepare("INSERT INTO clues (game_id, location_id, object_id, character_id, clue_text, discovery_method, is_red_herring, discovered) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    foreach (($data['clues'] ?? []) as $clue) {
        $insertClue->execute([
            $gameId,
            $locIds[$clue['location']] ?? null,
            $objIds[$clue['object']] ?? null,
            $charIds[$clue['character']] ?? null,
            $clue['clue_text'],
            $clue['discovery_method'] ?? null,
            $clue['is_red_herring'] ?? 0,
            $clue['discovered'] ?? 0
        ]);
    }

    // 8. Create player state
    $ps = $data['player_state'] ?? null;
    $startLocId = $ps ? ($locIds[$ps['current_location']] ?? null) : null;
    if (!$startLocId && !empty($locIds)) $startLocId = reset($locIds);

    $stmt = $pdo->prepare("INSERT INTO player_state (game_id, current_location_id, moves_taken, accusations_remaining, probes_remaining) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([
        $gameId,
        $startLocId,
        $ps['moves_taken'] ?? 0,
        $ps['accusations_remaining'] ?? 3,
        $ps['probes_remaining'] ?? 5
    ]);

    // 9. Copy cover image
    $coverData = $zip->getFromName('images/cover.png');
    if ($coverData !== false) {
        $coverDir = __DIR__ . '/../assets/covers';
        if (!is_dir($coverDir)) mkdir($coverDir, 0755, true);
        $coverPath = 'assets/covers/cover_' . $gameId . '.png';
        file_put_contents(__DIR__ . '/../' . $coverPath, $coverData);
        $pdo->prepare("UPDATE games SET cover_image = ? WHERE id = ?")
            ->execute([$coverPath, $gameId]);
    }

    // 10. Copy toolbar tile if present
    $tileData = $zip->getFromName('images/toolbar_tile.png');
    if ($tileData !== false) {
        file_put_contents($imageDir . '/toolbar_tile.png', $tileData);
    }

    $zip->close();
    $pdo->commit();

    echo json_encode([
        'success' => true,
        'game_id' => $gameId,
        'message' => 'Game imported successfully'
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if (isset($zip) && $zip instanceof ZipArchive) $zip->close();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
