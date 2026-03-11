<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/Database.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $gameId = (int)($input['game_id'] ?? 0);
    if (!$gameId) throw new Exception('No game_id');

    $pdo = Database::getConnection();
    $action = $input['action'] ?? '';

    if ($action === 'reset_trust') {
        $value = max(0, min(100, (int)($input['value'] ?? 50)));
        $stmt = $pdo->prepare("UPDATE characters_game SET trust_level = ? WHERE game_id = ?");
        $stmt->execute([$value, $gameId]);
        $count = $stmt->rowCount();
        echo json_encode(['success' => true, 'message' => "Set trust to $value for $count characters"]);
    } elseif ($action === 'reset_met') {
        $stmt = $pdo->prepare("UPDATE characters_game SET has_met = 0 WHERE game_id = ?");
        $stmt->execute([$gameId]);
        $count = $stmt->rowCount();
        echo json_encode(['success' => true, 'message' => "Reset met status for $count characters"]);
    } else {
        // Single character trust update
        $charId = (int)($input['character_id'] ?? 0);
        $trustLevel = (int)($input['trust_level'] ?? 50);
        if (!$charId) throw new Exception('No character_id');
        $trustLevel = max(0, min(100, $trustLevel));

        $stmt = $pdo->prepare("UPDATE characters_game SET trust_level = ? WHERE id = ? AND game_id = ?");
        $stmt->execute([$trustLevel, $charId, $gameId]);
        echo json_encode(['success' => true, 'message' => "Trust set to $trustLevel"]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
