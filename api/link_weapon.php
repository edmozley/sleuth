<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/Database.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $gameId = (int)($input['game_id'] ?? 0);
    $objectId = (int)($input['object_id'] ?? 0);

    if (!$gameId || !$objectId) throw new Exception('Missing game_id or object_id');

    $pdo = Database::getConnection();

    // Verify the object belongs to this game
    $stmt = $pdo->prepare("SELECT id, name FROM objects WHERE id = ? AND game_id = ?");
    $stmt->execute([$objectId, $gameId]);
    $obj = $stmt->fetch();
    if (!$obj) throw new Exception('Object not found in this game');

    // Clear any previous weapon flag for this game
    $pdo->prepare("UPDATE objects SET is_weapon = 0 WHERE game_id = ?")->execute([$gameId]);

    // Set the new weapon
    $pdo->prepare("UPDATE objects SET is_weapon = 1 WHERE id = ? AND game_id = ?")->execute([$objectId, $gameId]);
    $pdo->prepare("UPDATE plots SET weapon_object_id = ? WHERE game_id = ?")->execute([$objectId, $gameId]);

    echo json_encode(['success' => true, 'message' => "Linked weapon to \"{$obj['name']}\" (#{$obj['id']})"]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
