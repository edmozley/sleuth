<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';

try {
    $profileId = Auth::requireProfile();
    $pdo = Database::getConnection();
    $gameId = (int)($_GET['game_id'] ?? 0);

    if (!$gameId) {
        throw new Exception('No game_id provided');
    }

    // Game info
    $stmt = $pdo->prepare("SELECT * FROM games WHERE id = ?");
    $stmt->execute([$gameId]);
    $game = $stmt->fetch();
    if (!$game) throw new Exception('Game not found');

    // Plot info (for intro)
    $stmt = $pdo->prepare("SELECT setting_description, time_period, victim_name, backstory FROM plots WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $plot = $stmt->fetch();

    // Player state
    $stmt = $pdo->prepare("SELECT * FROM player_state WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $player = $stmt->fetch();

    // Current location (include image)
    $location = null;
    if ($player['current_location_id']) {
        $stmt = $pdo->prepare("SELECT id, game_id, name, description, short_description, is_locked, lock_reason, discovered, x_pos, y_pos, image FROM locations WHERE id = ?");
        $stmt->execute([$player['current_location_id']]);
        $location = $stmt->fetch();
    }

    // Available exits from current location
    $exits = [];
    if ($location) {
        $stmt = $pdo->prepare("
            SELECT lc.direction, l.name, l.id, l.is_locked, l.lock_reason, l.image
            FROM location_connections lc
            JOIN locations l ON l.id = lc.to_location_id
            WHERE lc.from_location_id = ? AND lc.game_id = ?
        ");
        $stmt->execute([$location['id'], $gameId]);
        $exits = $stmt->fetchAll();
    }

    // Characters in current location (alive only)
    $characters = [];
    if ($location) {
        $stmt = $pdo->prepare("
            SELECT id, name, description, role, is_alive, image, trust_level
            FROM characters_game
            WHERE game_id = ? AND location_id = ? AND is_alive = 1
        ");
        $stmt->execute([$gameId, $location['id']]);
        $characters = $stmt->fetchAll();

        // Also show the victim's body if in this location
        $stmt = $pdo->prepare("
            SELECT id, name, description, role, is_alive, image
            FROM characters_game
            WHERE game_id = ? AND location_id = ? AND is_alive = 0
        ");
        $stmt->execute([$gameId, $location['id']]);
        $deadChars = $stmt->fetchAll();
        $characters = array_merge($characters, $deadChars);
    }

    // Visible objects in current location (not hidden, not picked up)
    $objects = [];
    if ($location) {
        $stmt = $pdo->prepare("
            SELECT id, name, description, is_pickupable, is_evidence, image
            FROM objects
            WHERE game_id = ? AND location_id = ? AND is_hidden = 0
        ");
        $stmt->execute([$gameId, $location['id']]);
        $objects = $stmt->fetchAll();
    }

    // Inventory (objects the player has picked up - location_id IS NULL and character_id IS NULL)
    $stmt = $pdo->prepare("
        SELECT id, name, description, inspect_text, image
        FROM objects
        WHERE game_id = ? AND location_id IS NULL AND character_id IS NULL AND is_pickupable = 1
    ");
    $stmt->execute([$gameId]);
    $inventory = $stmt->fetchAll();

    // Notebook entries
    $stmt = $pdo->prepare("SELECT * FROM notebook_entries WHERE game_id = ? ORDER BY created_at ASC");
    $stmt->execute([$gameId]);
    $notebook = $stmt->fetchAll();

    // Discovered locations for the map
    $stmt = $pdo->prepare("
        SELECT id, name, short_description, x_pos, y_pos, z_pos, discovered, image
        FROM locations WHERE game_id = ?
    ");
    $stmt->execute([$gameId]);
    $allLocations = $stmt->fetchAll();

    // All connections for map drawing
    $stmt = $pdo->prepare("
        SELECT lc.from_location_id, lc.to_location_id, lc.direction
        FROM location_connections lc
        JOIN locations l1 ON l1.id = lc.from_location_id AND l1.discovered = 1
        JOIN locations l2 ON l2.id = lc.to_location_id AND l2.discovered = 1
        WHERE lc.game_id = ?
    ");
    $stmt->execute([$gameId]);
    $mapConnections = $stmt->fetchAll();

    // Characters with their locations (for map display)
    $stmt = $pdo->prepare("
        SELECT cg.name, cg.image, cg.location_id, cg.is_alive, cg.has_met
        FROM characters_game cg
        JOIN locations l ON l.id = cg.location_id AND l.discovered = 1
        WHERE cg.game_id = ?
    ");
    $stmt->execute([$gameId]);
    $mapCharacters = $stmt->fetchAll();

    // Visible objects at all discovered locations (for map detail popup)
    $stmt = $pdo->prepare("
        SELECT o.name, o.image, o.location_id, o.is_evidence
        FROM objects o
        JOIN locations l ON l.id = o.location_id AND l.discovered = 1
        WHERE o.game_id = ? AND o.is_hidden = 0
    ");
    $stmt->execute([$gameId]);
    $mapObjects = $stmt->fetchAll();

    // Action log (full history for resume)
    $stmt = $pdo->prepare("SELECT * FROM action_log WHERE game_id = ? ORDER BY created_at DESC LIMIT 200");
    $stmt->execute([$gameId]);
    $actionLog = array_reverse($stmt->fetchAll());

    // Motive options for accusation screen
    $motiveOptions = null;
    $stmt = $pdo->prepare("SELECT motive_options FROM plots WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $motiveRow = $stmt->fetch();
    if ($motiveRow && $motiveRow['motive_options']) {
        $motiveOptions = json_decode($motiveRow['motive_options'], true);
    }

    echo json_encode([
        'success' => true,
        'game' => $game,
        'plot' => $plot,
        'player' => $player,
        'location' => $location,
        'exits' => $exits,
        'characters' => $characters,
        'objects' => $objects,
        'inventory' => $inventory,
        'notebook' => $notebook,
        'map_locations' => $allLocations,
        'map_connections' => $mapConnections,
        'map_characters' => $mapCharacters,
        'map_objects' => $mapObjects,
        'action_log' => $actionLog,
        'motive_options' => $motiveOptions
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
