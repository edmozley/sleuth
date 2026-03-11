<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';

try {
    $pdo = Database::getConnection();

    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        // List all profiles
        $stmt = $pdo->query("SELECT id, name, avatar, color, created_at FROM profiles ORDER BY name");
        $profiles = $stmt->fetchAll();

        // Get current profile
        $currentId = Auth::getProfileId();

        echo json_encode(['success' => true, 'profiles' => $profiles, 'current_profile_id' => $currentId]);
        exit;
    }

    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = $input['action'] ?? '';

        switch ($action) {
            case 'create':
                $name = trim($input['name'] ?? '');
                if (!$name || strlen($name) > 50) {
                    echo json_encode(['success' => false, 'error' => 'Name is required (max 50 chars)']);
                    exit;
                }
                $avatar = $input['avatar'] ?? 'detective';
                $color = $input['color'] ?? '#e94560';

                $stmt = $pdo->prepare("INSERT INTO profiles (name, avatar, color) VALUES (?, ?, ?)");
                $stmt->execute([$name, $avatar, $color]);
                $profileId = (int)$pdo->lastInsertId();

                // Auto-login as the new profile
                Auth::setProfile($profileId);

                echo json_encode(['success' => true, 'profile_id' => $profileId]);
                break;

            case 'select':
                $profileId = (int)($input['profile_id'] ?? 0);
                if (!$profileId) {
                    echo json_encode(['success' => false, 'error' => 'Invalid profile']);
                    exit;
                }
                // Verify profile exists
                $stmt = $pdo->prepare("SELECT id FROM profiles WHERE id = ?");
                $stmt->execute([$profileId]);
                if (!$stmt->fetch()) {
                    echo json_encode(['success' => false, 'error' => 'Profile not found']);
                    exit;
                }
                Auth::setProfile($profileId);
                echo json_encode(['success' => true]);
                break;

            case 'delete':
                $profileId = (int)($input['profile_id'] ?? 0);
                if (!$profileId) {
                    echo json_encode(['success' => false, 'error' => 'Invalid profile']);
                    exit;
                }
                // Don't delete if it's the last profile
                $count = $pdo->query("SELECT COUNT(*) FROM profiles")->fetchColumn();
                if ($count <= 1) {
                    echo json_encode(['success' => false, 'error' => 'Cannot delete the only profile']);
                    exit;
                }
                $pdo->prepare("DELETE FROM profiles WHERE id = ?")->execute([$profileId]);

                // If we deleted the current profile, log out
                if (Auth::getProfileId() === $profileId) {
                    Auth::logout();
                }
                echo json_encode(['success' => true]);
                break;

            case 'logout':
                Auth::logout();
                echo json_encode(['success' => true]);
                break;

            default:
                echo json_encode(['success' => false, 'error' => 'Unknown action']);
        }
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
