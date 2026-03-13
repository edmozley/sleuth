<?php
require_once __DIR__ . '/includes/Database.php';

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    $config = [
        'host' => trim($_POST['host'] ?? 'localhost'),
        'port' => (int)($_POST['port'] ?? 3306),
        'dbname' => trim($_POST['dbname'] ?? 'quests'),
        'username' => trim($_POST['username'] ?? 'root'),
        'password' => $_POST['password'] ?? '',
        'anthropic_api_key' => trim($_POST['anthropic_api_key'] ?? ''),
        'openai_api_key' => trim($_POST['openai_api_key'] ?? ''),
        'venice_api_key' => trim($_POST['venice_api_key'] ?? ''),
        'image_provider' => $_POST['image_provider'] ?? 'openai',
        'freesound_api_key' => trim($_POST['freesound_api_key'] ?? '')
    ];

    if ($action === 'test') {
        $result = Database::testConnection($config);
        $message = $result['message'];
        $messageType = $result['success'] ? 'success' : 'error';
    } elseif ($action === 'save') {
        $result = Database::testConnection($config);
        if ($result['success']) {
            Database::saveConfig($config);
            $message = 'Configuration saved successfully. ' . $result['message'];
            $messageType = 'success';
        } else {
            $message = 'Cannot save - connection test failed: ' . $result['message'];
            $messageType = 'error';
        }
    }
} else {
    $config = Database::getConfig();
}
// Ensure all keys present for the form
$config += ['anthropic_api_key' => '', 'openai_api_key' => '', 'venice_api_key' => '', 'image_provider' => 'openai', 'freesound_api_key' => ''];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include __DIR__ . '/includes/pwa-head.php'; ?>
    <title>Sleuth - Settings</title>
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
        .config-container {
            background: #16213e;
            border: 1px solid #0f3460;
            border-radius: 12px;
            padding: 40px;
            width: 600px;
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
        .form-group {
            margin-bottom: 18px;
        }
        label {
            display: block;
            margin-bottom: 6px;
            color: #aaa;
            font-size: 0.85em;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        input[type="text"],
        input[type="number"],
        input[type="password"],
        select {
            width: 100%;
            padding: 10px 14px;
            background: #1a1a2e;
            border: 1px solid #0f3460;
            border-radius: 6px;
            color: #e0e0e0;
            font-size: 0.95em;
            transition: border-color 0.2s;
        }
        input:focus, select:focus {
            outline: none;
            border-color: #e94560;
        }
        .row {
            display: flex;
            gap: 15px;
        }
        .row .form-group {
            flex: 1;
        }
        .buttons {
            display: flex;
            gap: 12px;
            margin-top: 25px;
        }
        button {
            flex: 1;
            padding: 12px;
            border: none;
            border-radius: 6px;
            font-size: 0.95em;
            cursor: pointer;
            font-weight: 600;
            transition: opacity 0.2s;
        }
        button:hover { opacity: 0.85; }
        .btn-test {
            background: #0f3460;
            color: #e0e0e0;
        }
        .btn-save {
            background: #e94560;
            color: #fff;
        }
        .message {
            margin-top: 20px;
            padding: 12px 16px;
            border-radius: 6px;
            font-size: 0.9em;
        }
        .message.success {
            background: rgba(46, 213, 115, 0.15);
            border: 1px solid #2ed573;
            color: #2ed573;
        }
        .message.error {
            background: rgba(233, 69, 96, 0.15);
            border: 1px solid #e94560;
            color: #e94560;
        }
        .separator {
            border: none;
            border-top: 1px solid #0f3460;
            margin: 25px 0;
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
                <a href="settings.php" class="active">Settings</a>
                <a href="dbverify.php">Database</a>
            </div>
        </div>
    </div>

    <div class="config-container">
        <h2 class="page-title">Settings</h2>
        <p class="subtitle">Database & API Configuration</p>

        <form method="POST">
            <div class="row">
                <div class="form-group">
                    <label>Host</label>
                    <input type="text" name="host" value="<?= htmlspecialchars($config['host']) ?>">
                </div>
                <div class="form-group" style="max-width: 120px;">
                    <label>Port</label>
                    <input type="number" name="port" value="<?= htmlspecialchars($config['port']) ?>">
                </div>
            </div>

            <div class="form-group">
                <label>Database Name</label>
                <input type="text" name="dbname" value="<?= htmlspecialchars($config['dbname']) ?>">
            </div>

            <div class="row">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" value="<?= htmlspecialchars($config['username']) ?>">
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" value="<?= htmlspecialchars($config['password']) ?>">
                </div>
            </div>

            <hr class="separator">

            <div class="form-group">
                <label>Anthropic API Key (<a href="https://console.anthropic.com/settings/keys" target="_blank" style="color:#e94560;">get key</a>)</label>
                <input type="password" name="anthropic_api_key" value="<?= htmlspecialchars($config['anthropic_api_key']) ?>" placeholder="sk-ant-...">
            </div>

            <div class="form-group">
                <label>Image Provider</label>
                <select name="image_provider">
                    <option value="openai" <?= ($config['image_provider'] ?? 'openai') === 'openai' ? 'selected' : '' ?>>OpenAI DALL-E 3</option>
                    <option value="venice" <?= ($config['image_provider'] ?? '') === 'venice' ? 'selected' : '' ?>>Venice.ai (cheaper, fewer restrictions)</option>
                </select>
            </div>

            <div class="form-group">
                <label>OpenAI API Key (<a href="https://platform.openai.com/api-keys" target="_blank" style="color:#e94560;">get key</a>)</label>
                <input type="password" name="openai_api_key" value="<?= htmlspecialchars($config['openai_api_key'] ?? '') ?>" placeholder="sk-...">
            </div>

            <div class="form-group">
                <label>Venice.ai API Key (<a href="https://venice.ai/settings/api" target="_blank" style="color:#e94560;">get key</a>)</label>
                <input type="password" name="venice_api_key" value="<?= htmlspecialchars($config['venice_api_key'] ?? '') ?>" placeholder="venice-...">
            </div>

            <hr class="separator">

            <div class="form-group">
                <label>Freesound API Key (<a href="https://freesound.org/apiv2/apply/" target="_blank" style="color:#e94560;">get key</a>)</label>
                <input type="password" name="freesound_api_key" value="<?= htmlspecialchars($config['freesound_api_key'] ?? '') ?>" placeholder="Freesound API key for ambient music">
            </div>

            <div class="buttons">
                <button type="submit" name="action" value="test" class="btn-test">Test Connection</button>
                <button type="submit" name="action" value="save" class="btn-save">Save Configuration</button>
            </div>
        </form>

        <?php if ($message): ?>
            <div class="message <?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
    </div>
</body>
</html>
