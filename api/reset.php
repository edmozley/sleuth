<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';

try {
    $profileId = Auth::requireProfile();
    $input = json_decode(file_get_contents('php://input'), true);
    $gameId = (int)($input['game_id'] ?? 0);
    if (!$gameId) throw new Exception('No game_id');

    $pdo = Database::getConnection();

    // Verify game exists
    $stmt = $pdo->prepare("SELECT id FROM games WHERE id = ?");
    $stmt->execute([$gameId]);
    if (!$stmt->fetch()) throw new Exception('Game not found');

    // Find starting location (first discovered location, or first location)
    $stmt = $pdo->prepare("SELECT id FROM locations WHERE game_id = ? ORDER BY discovered DESC, id ASC LIMIT 1");
    $stmt->execute([$gameId]);
    $startLoc = $stmt->fetch();
    $startLocId = $startLoc ? $startLoc['id'] : null;

    $pdo->beginTransaction();

    // Reset player state
    $stmt = $pdo->prepare("UPDATE player_state SET current_location_id = ?, moves_taken = 0, accusations_remaining = 3, probes_remaining = 5, game_phase = 'investigation' WHERE game_id = ?");
    $stmt->execute([$startLocId, $gameId]);

    // Reset game status
    $stmt = $pdo->prepare("UPDATE games SET status = 'active' WHERE id = ?");
    $stmt->execute([$gameId]);

    // Clear progress logs
    $stmt = $pdo->prepare("DELETE FROM action_log WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $stmt = $pdo->prepare("DELETE FROM notebook_entries WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $stmt = $pdo->prepare("DELETE FROM chat_log WHERE game_id = ?");
    $stmt->execute([$gameId]);

    // Reset clues
    $stmt = $pdo->prepare("UPDATE clues SET discovered = 0 WHERE game_id = ?");
    $stmt->execute([$gameId]);

    // Reset locations - only starting location discovered
    $stmt = $pdo->prepare("UPDATE locations SET discovered = 0 WHERE game_id = ?");
    $stmt->execute([$gameId]);
    if ($startLocId) {
        $stmt = $pdo->prepare("UPDATE locations SET discovered = 1 WHERE id = ? AND game_id = ?");
        $stmt->execute([$startLocId, $gameId]);
    }

    // Reset objects to original locations and hidden state
    // Use original columns if available, otherwise leave as-is
    $stmt = $pdo->prepare("
        UPDATE objects SET
            location_id = COALESCE(original_location_id, location_id),
            character_id = NULL,
            is_hidden = COALESCE(original_is_hidden, is_hidden)
        WHERE game_id = ?
    ");
    $stmt->execute([$gameId]);

    // Reset character trust levels and met status
    $stmt = $pdo->prepare("UPDATE characters_game SET trust_level = 50, has_met = 0 WHERE game_id = ?");
    $stmt->execute([$gameId]);

    $pdo->commit();

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
