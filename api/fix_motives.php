<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Claude.php';
require_once __DIR__ . '/../includes/MotiveCategories.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $gameId = (int)($input['game_id'] ?? 0);
    if (!$gameId) throw new Exception('No game_id');

    $pdo = Database::getConnection();
    $action = $input['action'] ?? '';

    if ($action === 'clear') {
        $stmt = $pdo->prepare("DELETE FROM character_motives WHERE game_id = ?");
        $stmt->execute([$gameId]);
        echo json_encode(['success' => true, 'message' => 'All motives cleared']);
        exit;
    }

    if ($action === 'generate') {
        $stmt = $pdo->prepare("SELECT * FROM plots WHERE game_id = ?");
        $stmt->execute([$gameId]);
        $plot = $stmt->fetch();
        if (!$plot) throw new Exception('Plot not found');

        $dbSettings = Database::getDbSettings($pdo);
        if (empty($dbSettings['anthropic_api_key'])) throw new Exception('No API key configured');

        $claude = new Claude($dbSettings['anthropic_api_key']);

        // Load characters
        $stmt = $pdo->prepare("SELECT id, name, role, description, backstory FROM characters_game WHERE game_id = ?");
        $stmt->execute([$gameId]);
        $characters = $stmt->fetchAll();

        if (empty($characters)) throw new Exception('No characters found for this game');

        // Clear existing per-character motives
        $stmt = $pdo->prepare("DELETE FROM character_motives WHERE game_id = ?");
        $stmt->execute([$gameId]);

        // Generate per-character motives
        $motivesByChar = generateCharacterMotives($claude, $plot, $characters);

        $insertStmt = $pdo->prepare("INSERT INTO character_motives (game_id, character_id, motive_text, category, is_correct) VALUES (?, ?, ?, ?, ?)");
        $totalMotives = 0;
        foreach ($motivesByChar as $charId => $motives) {
            foreach ($motives as $m) {
                $insertStmt->execute([$gameId, $charId, $m['text'], $m['category'], $m['is_correct'] ? 1 : 0]);
                $totalMotives++;
            }
        }

        echo json_encode(['success' => true, 'message' => "Generated {$totalMotives} motives for " . count($motivesByChar) . " characters"]);
        exit;
    }

    throw new Exception('Unknown action: ' . $action);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
