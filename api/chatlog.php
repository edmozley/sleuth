<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';

try {
    $profileId = Auth::requireProfile();
    $gameId = (int)($_GET['game_id'] ?? 0);
    $characterId = (int)($_GET['character_id'] ?? 0);
    if (!$gameId || !$characterId) throw new Exception('Missing params');

    $pdo = Database::getConnection();

    $stmt = $pdo->prepare("
        SELECT cl.role, cl.message, cl.created_at, cg.name as character_name
        FROM chat_log cl
        JOIN characters_game cg ON cg.id = cl.character_id
        WHERE cl.game_id = ? AND cl.character_id = ?
        ORDER BY cl.created_at ASC
    ");
    $stmt->execute([$gameId, $characterId]);

    echo json_encode(['success' => true, 'messages' => $stmt->fetchAll()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
