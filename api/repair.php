<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/Database.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $gameId = (int)($input['game_id'] ?? 0);
    if (!$gameId) throw new Exception('No game_id');

    $pdo = Database::getConnection();

    // Get all locations for this game
    $stmt = $pdo->prepare("SELECT id, name FROM locations WHERE game_id = ? ORDER BY id");
    $stmt->execute([$gameId]);
    $locations = $stmt->fetchAll();

    if (empty($locations)) {
        throw new Exception('No locations found for this game');
    }

    $locCount = count($locations);
    $fixed = 0;
    $details = [];

    // Fix orphaned characters
    $stmt = $pdo->prepare("SELECT id, name, role FROM characters_game WHERE game_id = ? AND location_id IS NULL");
    $stmt->execute([$gameId]);
    $orphanChars = $stmt->fetchAll();

    $updateCharStmt = $pdo->prepare("UPDATE characters_game SET location_id = ? WHERE id = ? AND game_id = ?");

    foreach ($orphanChars as $i => $char) {
        if ($char['role'] === 'victim') {
            $locIdx = min(1, $locCount - 1);
        } else {
            $locIdx = ($i % max(1, $locCount - 1)) + 1;
            if ($locIdx >= $locCount) $locIdx = $locCount - 1;
        }
        $targetLoc = $locations[$locIdx];
        $updateCharStmt->execute([$targetLoc['id'], $char['id'], $gameId]);
        $fixed++;
        $details[] = 'Character: ' . $char['name'] . ' -> ' . $targetLoc['name'];
    }

    // Fix orphaned objects (location_id IS NULL AND original_location_id IS NULL — never had a location)
    $stmt = $pdo->prepare("SELECT id, name FROM objects WHERE game_id = ? AND location_id IS NULL AND original_location_id IS NULL AND character_id IS NULL");
    $stmt->execute([$gameId]);
    $orphanObjs = $stmt->fetchAll();

    $updateObjStmt = $pdo->prepare("UPDATE objects SET location_id = ?, original_location_id = ? WHERE id = ? AND game_id = ?");

    foreach ($orphanObjs as $i => $obj) {
        // Spread objects across locations
        $locIdx = $i % $locCount;
        $targetLoc = $locations[$locIdx];
        $updateObjStmt->execute([$targetLoc['id'], $targetLoc['id'], $obj['id'], $gameId]);
        $fixed++;
        $details[] = 'Object: ' . $obj['name'] . ' -> ' . $targetLoc['name'];
    }

    if ($fixed === 0) {
        echo json_encode(['success' => true, 'message' => 'Nothing to fix — no orphaned characters or objects found', 'fixed' => 0]);
    } else {
        echo json_encode([
            'success' => true,
            'message' => "Fixed {$fixed} orphaned items",
            'fixed' => $fixed,
            'details' => $details
        ]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
