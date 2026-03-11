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
        $stmt = $pdo->prepare("UPDATE plots SET motive_options = NULL WHERE game_id = ?");
        $stmt->execute([$gameId]);
        echo json_encode(['success' => true, 'message' => 'Motive options cleared']);
        exit;
    }

    if ($action === 'generate') {
        $stmt = $pdo->prepare("SELECT * FROM plots WHERE game_id = ?");
        $stmt->execute([$gameId]);
        $plot = $stmt->fetch();
        if (!$plot) throw new Exception('Plot not found');

        $config = Database::getConfig();
        if (empty($config['anthropic_api_key'])) throw new Exception('No API key configured');

        $claude = new Claude($config['anthropic_api_key']);

        // 3-step approach: AI categorises true motive, PHP picks decoy categories, AI writes decoys
        $motives = generateMotiveOptions($claude, $plot);

        $stmt = $pdo->prepare("UPDATE plots SET motive_options = ? WHERE game_id = ?");
        $stmt->execute([json_encode($motives), $gameId]);

        echo json_encode(['success' => true, 'message' => 'Generated ' . count($motives) . ' motive options']);
        exit;
    }

    throw new Exception('Unknown action: ' . $action);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
