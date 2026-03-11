<?php
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Auth.php';
$profileId = Auth::requireProfile();
$profile = Auth::getProfile();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sleuth - Murder Mystery</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Permanent+Marker&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/game.css">
</head>
<body>

<!-- Splash Screen -->
<div id="splash" class="splash-home">
    <div class="home-nav">
        <a href="profiles.php" class="profile-badge" title="Switch profile" style="display:flex;align-items:center;gap:6px;text-decoration:none;color:<?= htmlspecialchars($profile['color'] ?? '#e94560') ?>;">
            <?= htmlspecialchars($profile['name'] ?? 'Profile') ?>
        </a>
        <a href="help.php">Help</a>
        <a href="debug.php">Debug</a>
        <a href="settings.php">Settings</a>
        <a href="dbverify.php">Database</a>
    </div>
    <div class="home-header">
        <h1>Sleuth</h1>
        <p>AI-powered murder mystery adventures</p>
    </div>

    <div class="home-new-game">
        <div id="theme-section" class="theme-section" style="display:none;">
            <textarea id="theme-input"></textarea>
        </div>
        <div class="new-game-buttons" id="new-game-buttons">
            <button class="btn btn-primary btn-new" id="btn-random-game" onclick="newGame()">Random Mystery</button>
            <button class="btn btn-secondary btn-new" id="btn-custom-game" onclick="showCustomModal()">Custom Mystery</button>
        </div>
        <div id="generating" class="generating">
            <div id="progress-steps"></div>
            <div id="art-progress" class="art-progress" style="display:none;"></div>
        </div>
    </div>

    <!-- Custom Mystery Modal -->
    <div class="modal-overlay" id="custom-modal">
        <div class="custom-modal-content">
            <h3>Describe Your Mystery</h3>
            <textarea id="custom-theme-input" placeholder="e.g. A jazz club in 1920s Chicago, ancient Egypt with poison and rituals, a tech billionaire dead on his yacht..."></textarea>
            <div class="custom-modal-buttons">
                <button class="btn btn-secondary" onclick="hideCustomModal()">Cancel</button>
                <button class="btn btn-primary" onclick="startCustomGame()">Start Mystery</button>
            </div>
        </div>
    </div>

    <div id="saved-games" class="game-grid"></div>

    <!-- Game Detail Modal -->
    <div class="modal-overlay" id="game-detail-modal">
        <div class="game-detail">
            <button class="detail-close" onclick="hideGameDetail()">&times;</button>
            <div class="detail-top">
                <div class="detail-cover" id="detail-cover"></div>
                <div class="detail-info">
                    <h2 id="detail-title"></h2>
                    <p class="detail-setting" id="detail-setting"></p>
                    <div class="detail-stats" id="detail-stats"></div>
                    <div class="detail-status" id="detail-status"></div>
                </div>
            </div>
            <p class="detail-summary" id="detail-summary"></p>
            <div class="detail-actions">
                <button class="btn btn-primary" id="detail-resume" onclick="resumeFromDetail()">Resume</button>
                <button class="btn btn-danger" id="detail-delete" onclick="deleteFromDetail()">Delete</button>
                <a class="btn btn-secondary" id="detail-debug" href="#" target="_blank" style="text-decoration:none;display:inline-flex;align-items:center;justify-content:center;">Debug</a>
            </div>
        </div>
    </div>
</div>

<!-- Error Modal -->
<div class="modal-overlay" id="error-modal">
    <div class="modal-content error-modal-content">
        <h3 id="error-modal-title">Error</h3>
        <p id="error-modal-message"></p>
        <details id="error-modal-details" style="display:none;">
            <summary>Raw Response</summary>
            <pre id="error-modal-raw"></pre>
        </details>
        <div style="text-align:right;margin-top:15px;">
            <button class="btn btn-secondary" onclick="document.getElementById('error-modal').style.display='none'">Close</button>
        </div>
    </div>
</div>

<!-- Styled Confirm Modal -->
<div class="confirm-overlay" id="confirm-overlay">
    <div class="confirm-box">
        <p id="confirm-message"></p>
        <div class="confirm-buttons">
            <button class="confirm-btn-cancel" id="confirm-cancel">Cancel</button>
            <button class="confirm-btn-ok" id="confirm-ok">OK</button>
        </div>
    </div>
</div>

<script src="assets/js/home.js"></script>
</body>
</html>
