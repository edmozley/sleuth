<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';

try {
    $profileId = Auth::requireProfile();
    $gameId = (int)($_GET['game_id'] ?? 0);
    if (!$gameId) throw new Exception('No game_id');
    $metOnly = ($_GET['met_only'] ?? '') === '1';

    $pdo = Database::getConnection();

    $where = "game_id = ? AND role != 'victim'";
    if ($metOnly) $where .= " AND has_met = 1";

    $stmt = $pdo->prepare("
        SELECT id, name, role, description, is_alive, image
        FROM characters_game
        WHERE $where
        ORDER BY name
    ");
    $stmt->execute([$gameId]);

    echo json_encode(['success' => true, 'characters' => $stmt->fetchAll()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
