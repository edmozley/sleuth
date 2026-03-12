<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';

try {
    $profileId = Auth::requireProfile();
    $pdo = Database::getConnection();

    $stmt = $pdo->query("
        SELECT g.id, g.title, g.summary, g.cover_image, g.status, g.created_at,
               g.profile_id,
               pr.name as profile_name, pr.color as profile_color,
               ps.moves_taken, ps.accusations_remaining,
               p.setting_description, p.time_period, p.victim_name, p.backstory,
               (SELECT COUNT(*) FROM notebook_entries WHERE game_id = g.id) as clues_found,
               (SELECT COUNT(*) FROM locations WHERE game_id = g.id) as location_count,
               (SELECT COUNT(*) FROM characters_game WHERE game_id = g.id) as character_count
        FROM games g
        LEFT JOIN player_state ps ON ps.game_id = g.id
        LEFT JOIN plots p ON p.game_id = g.id
        LEFT JOIN profiles pr ON pr.id = g.profile_id
        WHERE g.status IN ('active', 'won', 'lost')
        ORDER BY g.updated_at DESC
        LIMIT 40
    ");

    $games = $stmt->fetchAll();

    // Mark which games belong to the current profile
    foreach ($games as &$g) {
        $g['is_own'] = ((int)$g['profile_id'] === (int)$profileId) || ($g['profile_id'] === null);
    }
    unset($g);

    echo json_encode(['success' => true, 'games' => $games, 'profile_id' => $profileId]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
