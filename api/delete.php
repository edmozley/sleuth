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

    // Delete cover image file if exists
    $stmt = $pdo->prepare("SELECT cover_image FROM games WHERE id = ?");
    $stmt->execute([$gameId]);
    $game = $stmt->fetch();
    if ($game && $game['cover_image']) {
        $path = __DIR__ . '/../' . $game['cover_image'];
        if (file_exists($path)) unlink($path);
    }

    // Delete game images directory if exists
    $imageDir = __DIR__ . '/../assets/images/game_' . $gameId;
    if (is_dir($imageDir)) {
        $files = glob($imageDir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) unlink($file);
        }
        rmdir($imageDir);
    }

    // CASCADE will handle all related tables
    $stmt = $pdo->prepare("DELETE FROM games WHERE id = ?");
    $stmt->execute([$gameId]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
