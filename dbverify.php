<?php
require_once __DIR__ . '/includes/Database.php';

$config = Database::getConfig();
$results = [];

$tables = [
    'profiles' => "CREATE TABLE IF NOT EXISTS profiles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(50) NOT NULL,
        avatar VARCHAR(20) DEFAULT 'detective',
        color VARCHAR(7) DEFAULT '#e94560',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'games' => "CREATE TABLE IF NOT EXISTS games (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        summary TEXT DEFAULT NULL,
        cover_image VARCHAR(500) DEFAULT NULL,
        status ENUM('generating', 'active', 'won', 'lost', 'abandoned') DEFAULT 'generating',
        difficulty ENUM('easy', 'medium', 'hard') DEFAULT 'medium',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'plots' => "CREATE TABLE IF NOT EXISTS plots (
        id INT AUTO_INCREMENT PRIMARY KEY,
        game_id INT NOT NULL,
        victim_name VARCHAR(100) NOT NULL,
        killer_name VARCHAR(100) NOT NULL,
        weapon VARCHAR(100) NOT NULL,
        motive TEXT NOT NULL,
        backstory TEXT NOT NULL,
        setting_description TEXT NOT NULL,
        time_period VARCHAR(100) DEFAULT 'present day',
        art_style VARCHAR(500) DEFAULT NULL,
        FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'locations' => "CREATE TABLE IF NOT EXISTS locations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        game_id INT NOT NULL,
        name VARCHAR(150) NOT NULL,
        description TEXT NOT NULL,
        short_description VARCHAR(255) NOT NULL,
        is_locked TINYINT(1) DEFAULT 0,
        lock_reason VARCHAR(255) DEFAULT NULL,
        discovered TINYINT(1) DEFAULT 0,
        x_pos INT DEFAULT 0,
        y_pos INT DEFAULT 0,
        image VARCHAR(500) DEFAULT NULL,
        FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'location_connections' => "CREATE TABLE IF NOT EXISTS location_connections (
        id INT AUTO_INCREMENT PRIMARY KEY,
        game_id INT NOT NULL,
        from_location_id INT NOT NULL,
        to_location_id INT NOT NULL,
        direction VARCHAR(20) NOT NULL,
        FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
        FOREIGN KEY (from_location_id) REFERENCES locations(id) ON DELETE CASCADE,
        FOREIGN KEY (to_location_id) REFERENCES locations(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'characters_game' => "CREATE TABLE IF NOT EXISTS characters_game (
        id INT AUTO_INCREMENT PRIMARY KEY,
        game_id INT NOT NULL,
        name VARCHAR(100) NOT NULL,
        description TEXT NOT NULL,
        personality TEXT NOT NULL,
        backstory TEXT NOT NULL,
        secrets TEXT NOT NULL,
        knowledge TEXT NOT NULL,
        role ENUM('victim', 'killer', 'suspect', 'witness', 'bystander') NOT NULL,
        location_id INT DEFAULT NULL,
        is_alive TINYINT(1) DEFAULT 1,
        trust_level INT DEFAULT 50,
        image VARCHAR(500) DEFAULT NULL,
        FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
        FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'objects' => "CREATE TABLE IF NOT EXISTS objects (
        id INT AUTO_INCREMENT PRIMARY KEY,
        game_id INT NOT NULL,
        name VARCHAR(150) NOT NULL,
        description TEXT NOT NULL,
        inspect_text TEXT DEFAULT NULL,
        location_id INT DEFAULT NULL,
        character_id INT DEFAULT NULL,
        is_pickupable TINYINT(1) DEFAULT 0,
        is_hidden TINYINT(1) DEFAULT 0,
        is_evidence TINYINT(1) DEFAULT 0,
        image VARCHAR(500) DEFAULT NULL,
        FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
        FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL,
        FOREIGN KEY (character_id) REFERENCES characters_game(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'clues' => "CREATE TABLE IF NOT EXISTS clues (
        id INT AUTO_INCREMENT PRIMARY KEY,
        game_id INT NOT NULL,
        description TEXT NOT NULL,
        category ENUM('physical', 'testimony', 'document', 'observation', 'forensic') NOT NULL,
        importance ENUM('critical', 'major', 'minor', 'red_herring') NOT NULL,
        linked_object_id INT DEFAULT NULL,
        linked_character_id INT DEFAULT NULL,
        linked_location_id INT DEFAULT NULL,
        discovered TINYINT(1) DEFAULT 0,
        discovery_method VARCHAR(255) DEFAULT NULL,
        FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
        FOREIGN KEY (linked_object_id) REFERENCES objects(id) ON DELETE SET NULL,
        FOREIGN KEY (linked_character_id) REFERENCES characters_game(id) ON DELETE SET NULL,
        FOREIGN KEY (linked_location_id) REFERENCES locations(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'player_state' => "CREATE TABLE IF NOT EXISTS player_state (
        id INT AUTO_INCREMENT PRIMARY KEY,
        game_id INT NOT NULL UNIQUE,
        current_location_id INT DEFAULT NULL,
        inventory JSON DEFAULT NULL,
        moves_taken INT DEFAULT 0,
        accusations_remaining INT DEFAULT 3,
        game_phase ENUM('investigation', 'accusation', 'resolved') DEFAULT 'investigation',
        FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
        FOREIGN KEY (current_location_id) REFERENCES locations(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'notebook_entries' => "CREATE TABLE IF NOT EXISTS notebook_entries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        game_id INT NOT NULL,
        entry_text TEXT NOT NULL,
        entry_type ENUM('clue', 'observation', 'testimony', 'deduction') NOT NULL,
        source VARCHAR(255) DEFAULT NULL,
        clue_id INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
        FOREIGN KEY (clue_id) REFERENCES clues(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'chat_log' => "CREATE TABLE IF NOT EXISTS chat_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        game_id INT NOT NULL,
        character_id INT NOT NULL,
        role ENUM('player', 'character') NOT NULL,
        message TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
        FOREIGN KEY (character_id) REFERENCES characters_game(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'action_log' => "CREATE TABLE IF NOT EXISTS action_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        game_id INT NOT NULL,
        action_text TEXT NOT NULL,
        result_text TEXT NOT NULL,
        action_type VARCHAR(50) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'settings' => "CREATE TABLE IF NOT EXISTS settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value VARCHAR(2000) NOT NULL DEFAULT ''
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
];

$overallSuccess = true;

try {
    $pdo = Database::getConnection();
    $results[] = ['name' => 'Database Connection', 'status' => 'ok', 'message' => 'Connected to "' . $config['dbname'] . '"'];

    // Migrations - add new columns to existing tables (MySQL-compatible)
    $columnChecks = [
        ['games', 'summary', "ALTER TABLE games ADD COLUMN summary TEXT DEFAULT NULL AFTER title"],
        ['games', 'cover_image', "ALTER TABLE games ADD COLUMN cover_image VARCHAR(500) DEFAULT NULL AFTER summary"],
        ['plots', 'art_style', "ALTER TABLE plots ADD COLUMN art_style VARCHAR(500) DEFAULT NULL"],
        ['locations', 'image', "ALTER TABLE locations ADD COLUMN image VARCHAR(500) DEFAULT NULL"],
        ['characters_game', 'image', "ALTER TABLE characters_game ADD COLUMN image VARCHAR(500) DEFAULT NULL"],
        ['objects', 'image', "ALTER TABLE objects ADD COLUMN image VARCHAR(500) DEFAULT NULL"],
        ['objects', 'original_location_id', "ALTER TABLE objects ADD COLUMN original_location_id INT DEFAULT NULL"],
        ['objects', 'original_is_hidden', "ALTER TABLE objects ADD COLUMN original_is_hidden TINYINT(1) DEFAULT NULL"],
        ['objects', 'parent_object_id', "ALTER TABLE objects ADD COLUMN parent_object_id INT DEFAULT NULL"],
        ['characters_game', 'has_met', "ALTER TABLE characters_game ADD COLUMN has_met TINYINT(1) DEFAULT 0"],
        ['objects', 'is_weapon', "ALTER TABLE objects ADD COLUMN is_weapon TINYINT(1) DEFAULT 0"],
        ['plots', 'weapon_object_id', "ALTER TABLE plots ADD COLUMN weapon_object_id INT DEFAULT NULL"],
        ['player_state', 'probes_remaining', "ALTER TABLE player_state ADD COLUMN probes_remaining INT DEFAULT 5"],
        ['plots', 'motive_options', "ALTER TABLE plots ADD COLUMN motive_options JSON DEFAULT NULL"],
        ['locations', 'z_pos', "ALTER TABLE locations ADD COLUMN z_pos INT DEFAULT 0 AFTER y_pos"],
        ['games', 'profile_id', "ALTER TABLE games ADD COLUMN profile_id INT DEFAULT NULL AFTER id"],
    ];
    foreach ($columnChecks as [$table, $column, $sql]) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $check->execute([$config['dbname'], $table, $column]);
        if ($check->fetchColumn() == 0) {
            try { $pdo->exec($sql); } catch (PDOException $e) {}
        }
    }

    // Fix settings.setting_value from TEXT to VARCHAR (TEXT can't have DEFAULT in strict mode)
    try {
        $check = $pdo->prepare("SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'settings' AND COLUMN_NAME = 'setting_value'");
        $check->execute([$config['dbname']]);
        $type = $check->fetchColumn();
        if ($type === 'text') {
            $pdo->exec("ALTER TABLE settings MODIFY COLUMN setting_value VARCHAR(2000) NOT NULL DEFAULT ''");
        }
    } catch (PDOException $e) {}

    // Backfill original_location_id and original_is_hidden for existing objects
    try {
        $pdo->exec("UPDATE objects SET original_location_id = location_id WHERE original_location_id IS NULL AND location_id IS NOT NULL");
        $pdo->exec("UPDATE objects SET original_is_hidden = is_hidden WHERE original_is_hidden IS NULL");
    } catch (PDOException $e) {}

    // Backfill has_met for characters with existing chat history
    try {
        $pdo->exec("UPDATE characters_game cg SET has_met = 1 WHERE has_met = 0 AND EXISTS (SELECT 1 FROM chat_log cl WHERE cl.character_id = cg.id AND cl.game_id = cg.game_id)");
    } catch (PDOException $e) {}

    // Backfill probes_remaining for existing games
    try {
        $pdo->exec("UPDATE player_state SET probes_remaining = 5 WHERE probes_remaining IS NULL");
    } catch (PDOException $e) {}

    // Assign orphaned games to the first profile (if any)
    try {
        $firstProfile = $pdo->query("SELECT id FROM profiles ORDER BY id ASC LIMIT 1")->fetch();
        if ($firstProfile) {
            $pdo->prepare("UPDATE games SET profile_id = ? WHERE profile_id IS NULL")
                ->execute([$firstProfile['id']]);
        }
    } catch (PDOException $e) {}

    foreach ($tables as $tableName => $createSQL) {
        try {
            $pdo->exec($createSQL);

            // Verify table exists and get row count
            $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM $tableName");
            $count = $stmt->fetch()['cnt'];

            // Get column count
            $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = " . $pdo->quote($config['dbname']) . " AND TABLE_NAME = " . $pdo->quote($tableName));
            $cols = $stmt->fetch()['cnt'];

            $results[] = [
                'name' => $tableName,
                'status' => 'ok',
                'message' => "$cols columns, $count rows"
            ];
        } catch (PDOException $e) {
            $results[] = ['name' => $tableName, 'status' => 'error', 'message' => $e->getMessage()];
            $overallSuccess = false;
        }
    }

    // Migrate API keys from config.json to DB settings table (one-time)
    $configJsonPath = __DIR__ . '/config.json';
    if (file_exists($configJsonPath)) {
        $configFile = json_decode(file_get_contents($configJsonPath), true) ?: [];
        $migrateKeys = ['anthropic_api_key', 'openai_api_key', 'venice_api_key', 'image_provider', 'freesound_api_key'];
        $migrated = false;
        foreach ($migrateKeys as $key) {
            if (!empty($configFile[$key])) {
                $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = IF(setting_value = '', VALUES(setting_value), setting_value)");
                $stmt->execute([$key, $configFile[$key]]);
                $migrated = true;
            }
        }
        if ($migrated) {
            // Clean API keys from config.json, keep only DB connection fields
            $cleanConfig = array_intersect_key($configFile, array_flip(['host', 'port', 'dbname', 'username', 'password']));
            file_put_contents($configJsonPath, json_encode($cleanConfig, JSON_PRETTY_PRINT));
            $results[] = ['name' => 'Settings Migration', 'status' => 'ok', 'message' => 'API keys migrated from config.json to database'];
        }
    }

    // Check Anthropic API key
    $dbSettings = Database::getDbSettings($pdo);
    if (!empty($dbSettings['anthropic_api_key']) && str_starts_with($dbSettings['anthropic_api_key'], 'sk-ant-')) {
        $results[] = ['name' => 'Anthropic API Key', 'status' => 'ok', 'message' => 'Key configured (starts with sk-ant-)'];
    } else {
        $results[] = ['name' => 'Anthropic API Key', 'status' => 'warning', 'message' => 'No valid API key found. Set it in Settings'];
    }

} catch (PDOException $e) {
    $results[] = ['name' => 'Database Connection', 'status' => 'error', 'message' => $e->getMessage()];
    $overallSuccess = false;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sleuth - Database Verification</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #1a1a2e;
            color: #e0e0e0;
            min-height: 100vh;
            padding: 0;
        }
        .sticky-header {
            position: fixed; top: 0; left: 0; right: 0; z-index: 100;
            background: #12122a; border-bottom: 1px solid #0f3460;
            padding: 15px 30px;
        }
        .header-row {
            display: flex; align-items: center; gap: 20px;
            max-width: 1400px; margin: 0 auto;
        }
        .header-row h1 { color: #e94560; font-size: 1.5em; white-space: nowrap; margin: 0; }
        .header-links {
            display: flex; gap: 6px; margin-left: auto;
        }
        .header-links a {
            padding: 7px 14px; border-radius: 6px; font-size: 13px; font-weight: 500;
            color: #adb5bd; background: #16213e; border: 1px solid #0f3460;
            text-decoration: none; transition: all 0.15s;
        }
        .header-links a:hover { color: #fff; border-color: #e94560; background: #1a2744; }
        .header-links a.active { color: #fff; border-color: #e94560; background: rgba(233, 69, 96, 0.15); }
        .verify-container {
            background: #16213e;
            border: 1px solid #0f3460;
            border-radius: 12px;
            padding: 40px;
            width: 700px;
            max-width: 95vw;
            margin: 90px auto 40px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
        }
        .page-title {
            text-align: center;
            color: #e94560;
            margin-bottom: 8px;
            font-size: 1.4em;
        }
        .subtitle {
            text-align: center;
            color: #888;
            margin-bottom: 30px;
            font-size: 0.9em;
        }
        .overall {
            text-align: center;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 25px;
            font-weight: 600;
            font-size: 1.1em;
        }
        .overall.success {
            background: rgba(46, 213, 115, 0.15);
            border: 1px solid #2ed573;
            color: #2ed573;
        }
        .overall.failure {
            background: rgba(233, 69, 96, 0.15);
            border: 1px solid #e94560;
            color: #e94560;
        }
        .table-list {
            list-style: none;
        }
        .table-list li {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 14px;
            border-bottom: 1px solid #0f3460;
            font-size: 0.9em;
        }
        .table-list li:last-child { border-bottom: none; }
        .table-name {
            font-weight: 600;
            min-width: 180px;
        }
        .table-message {
            color: #888;
            flex: 1;
            text-align: right;
            margin-right: 12px;
            font-size: 0.85em;
        }
        .status-badge {
            padding: 3px 10px;
            border-radius: 4px;
            font-size: 0.8em;
            font-weight: 600;
            text-transform: uppercase;
            min-width: 65px;
            text-align: center;
        }
        .status-ok {
            background: rgba(46, 213, 115, 0.2);
            color: #2ed573;
        }
        .status-error {
            background: rgba(233, 69, 96, 0.2);
            color: #e94560;
        }
        .status-warning {
            background: rgba(255, 199, 0, 0.2);
            color: #ffc700;
        }
    </style>
</head>
<body>
    <div class="sticky-header">
        <div class="header-row">
            <h1>Sleuth</h1>
            <div class="header-links">
                <a href="index.php">Home</a>
                <a href="debug.php">Debug</a>
                <a href="settings.php">Settings</a>
                <a href="dbverify.php" class="active">Database</a>
            </div>
        </div>
    </div>

    <div class="verify-container">
        <h2 class="page-title">Database Verification</h2>
        <p class="subtitle">Table Builder & Migration Check</p>

        <div class="overall <?= $overallSuccess ? 'success' : 'failure' ?>">
            <?= $overallSuccess ? 'All tables verified successfully' : 'Some checks failed - see details below' ?>
        </div>

        <ul class="table-list">
            <?php foreach ($results as $result): ?>
                <li>
                    <span class="table-name"><?= htmlspecialchars($result['name']) ?></span>
                    <span class="table-message"><?= htmlspecialchars($result['message']) ?></span>
                    <span class="status-badge status-<?= $result['status'] ?>"><?= $result['status'] ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</body>
</html>
