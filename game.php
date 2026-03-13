<?php
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Auth.php';
Auth::requireProfile();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include __DIR__ . '/includes/pwa-head.php'; ?>
    <title>Sleuth - Murder Mystery</title>
    <link rel="stylesheet" href="assets/css/game.css">
    <link rel="stylesheet" href="assets/css/game-mobile.css">
</head>
<body class="page-game">

<!-- Game UI -->
<div id="game-ui">
    <!-- Title Bar -->
    <div class="title-bar" id="title-bar">
        <div class="title-left">
            <span class="game-title" id="game-title"></span>
            <div class="music-inline" id="music-inline">
                <button class="music-toggle" id="music-toggle" onclick="toggleMusic()" title="Toggle ambient music">&#9835;</button>
                <button class="music-skip" id="music-skip" onclick="skipTrack()" title="Next song">&#9197;</button>
                <span class="music-title" id="music-title"></span>
            </div>
        </div>
        <div class="controls">
            <span id="move-counter">Moves: 0</span>
            <span id="probe-counter">Probes: 5</span>
            <span id="accusation-counter">Accusations: 3</span>
            <button class="btn btn-danger" onclick="showAccuseModal()">Accuse</button>
            <button class="btn btn-secondary" onclick="exitToHome()">Home</button>
            <button class="btn btn-secondary btn-small" onclick="resetCurrentGame()" title="Restart this case">Restart</button>
            <button class="btn btn-secondary btn-small" onclick="openDebug()" title="Debug (spoilers!)">Debug</button>
        </div>
    </div>

    <!-- Main Layout -->
    <div class="game-layout">
        <!-- Left: Narrative -->
        <div class="main-panel">
            <div class="location-image-bar" id="location-image-bar"></div>
            <div class="narrative-area" id="narrative"></div>
            <div class="input-area">
                <form onsubmit="return submitAction(event)">
                    <input type="text" id="action-input" placeholder="What do you do? (look around, go north, pick up letter, search desk...)" autocomplete="off">
                    <button type="submit" class="btn btn-primary" id="action-btn">Do</button>
                </form>
            </div>
        </div>

        <!-- Right: Sidebar -->
        <div class="sidebar">
            <div class="sidebar-tabs">
                <button class="active" onclick="switchTab('chat')">Chat</button>
                <button onclick="switchTab('inventory')">Inventory</button>
                <button onclick="switchTab('notebook')">Notebook</button>
                <button onclick="openMapOverlay()">Map</button>
            </div>
            <div class="sidebar-content">
                <!-- Chat Tab -->
                <div id="tab-chat" class="tab-panel active">
                    <div class="chat-select">
                        <select id="chat-character" onchange="loadChatHistory()">
                            <option value="">-- Select someone to talk to --</option>
                        </select>
                        <div class="chat-portrait" id="chat-portrait" onclick="showCharacterInfo()">
                            <div class="chat-portrait-name" id="chat-portrait-name"></div>
                        </div>
                        <div class="trust-bar-container" id="trust-bar-container" style="display:none;">
                            <span class="trust-label" id="trust-label">Trust</span>
                            <div class="trust-bar"><div class="trust-bar-fill" id="trust-bar-fill"></div></div>
                        </div>
                    </div>
                    <div class="chat-messages" id="chat-messages"></div>
                    <div class="chat-input">
                        <input type="text" id="chat-input" placeholder="Say something..." autocomplete="off" onkeydown="if(event.key==='Enter')sendChat()">
                        <button class="btn btn-primary" onclick="sendChat()">Say</button>
                        <button class="btn btn-probe" id="probe-btn" onclick="sendProbe()" title="Press this person hard to reveal something (limited uses)">Probe</button>
                    </div>
                </div>

                <!-- Inventory Tab -->
                <div id="tab-inventory" class="tab-panel">
                    <div id="inventory-list"></div>
                </div>

                <!-- Notebook Tab -->
                <div id="tab-notebook" class="tab-panel">
                    <div id="notebook-list"></div>
                </div>

                <!-- Map Tab -->
                <div id="tab-map" class="tab-panel">
                    <div class="map-canvas-container">
                        <canvas id="map-canvas" width="370" height="370"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Accuse Modal -->
<div class="accuse-overlay" id="accuse-modal">
    <div class="accuse-screen">
        <button class="accuse-close" onclick="hideAccuseModal()">&times;</button>

        <!-- Step indicators -->
        <div class="accuse-steps">
            <div class="accuse-step-dot active" data-step="0">1</div>
            <div class="accuse-step-line"></div>
            <div class="accuse-step-dot" data-step="1">2</div>
            <div class="accuse-step-line" id="accuse-step-line-motive"></div>
            <div class="accuse-step-dot" data-step="2" id="accuse-step-dot-motive">3</div>
        </div>

        <!-- Step 1: Suspect -->
        <div class="accuse-page active" id="accuse-page-suspect">
            <h2 class="accuse-title">Who is the killer?</h2>
            <div class="accuse-grid-large" id="accuse-suspects"></div>
            <div class="accuse-empty" id="accuse-suspects-empty" style="display:none;">You haven't spoken to anyone yet. Talk to suspects before making an accusation.</div>
            <div class="accuse-nav">
                <button class="btn btn-secondary" onclick="hideAccuseModal()">Cancel</button>
                <button class="btn btn-primary" id="accuse-next-suspect" onclick="accuseNext()" disabled>Next</button>
            </div>
        </div>

        <!-- Step 2: Weapon -->
        <div class="accuse-page" id="accuse-page-weapon">
            <h2 class="accuse-title">What was the murder weapon?</h2>
            <div class="accuse-grid-large" id="accuse-weapons"></div>
            <div class="accuse-empty" id="accuse-weapons-empty" style="display:none;">Your inventory is empty. Find and pick up evidence first.</div>
            <div class="accuse-nav">
                <button class="btn btn-secondary" onclick="accuseBack()">Back</button>
                <button class="btn btn-primary" id="accuse-next-weapon" onclick="accuseNext()" disabled>Next</button>
            </div>
        </div>

        <!-- Step 3: Motive -->
        <div class="accuse-page" id="accuse-page-motive">
            <h2 class="accuse-title">What was the motive?</h2>
            <div class="accuse-motive-grid" id="accuse-motives"></div>
            <div class="accuse-nav">
                <button class="btn btn-secondary" onclick="accuseBack()">Back</button>
                <button class="btn btn-primary" id="accuse-next-motive" onclick="accuseNext()" disabled>Next</button>
            </div>
        </div>

        <!-- Confirm step -->
        <div class="accuse-page" id="accuse-page-confirm">
            <h2 class="accuse-title">Confirm Your Accusation</h2>
            <div class="accuse-confirm-summary" id="accuse-summary"></div>
            <div class="accuse-nav">
                <button class="btn btn-secondary" onclick="accuseBack()">Back</button>
                <button class="btn btn-danger btn-large" id="accuse-confirm-btn" onclick="submitAccusation()">Confirm Accusation</button>
            </div>
        </div>
    </div>
</div>

<!-- Game Over Overlay -->
<div class="game-over-overlay" id="game-over">
    <div class="game-over-content">
        <h2 id="game-over-title"></h2>
        <div class="result-text" id="game-over-text"></div>
        <div class="solution" id="game-over-solution"></div>
        <button class="btn btn-primary" onclick="window.location='index.php'" style="font-size:1em; padding:10px 30px;">Play Again</button>
    </div>
</div>

<!-- Image Viewer Modal -->
<div class="image-viewer-overlay" id="image-viewer" onclick="closeImageViewer()">
    <div class="image-viewer-content" onclick="event.stopPropagation()">
        <img id="image-viewer-img" src="" alt="">
        <div class="image-viewer-caption" id="image-viewer-caption"></div>
        <div class="image-viewer-actions" id="image-viewer-actions" style="display:none;">
            <button class="btn btn-primary" onclick="imageViewerAction('inspect')">Inspect</button>
            <button class="btn btn-secondary" onclick="imageViewerAction('pickup')">Pick Up</button>
            <button class="btn btn-secondary" onclick="closeImageViewer()">Close</button>
        </div>
    </div>
</div>

<!-- Character Info Modal -->
<div class="modal-overlay" id="character-info-modal">
    <div class="character-info">
        <button class="detail-close" onclick="hideCharacterInfo()">&times;</button>
        <div class="char-info-top">
            <div class="char-info-image" id="char-info-image"></div>
            <div class="char-info-details">
                <h2 id="char-info-name"></h2>
                <p class="char-info-desc" id="char-info-desc"></p>
            </div>
        </div>
    </div>
</div>


<!-- Art Generation Toast -->
<div class="art-toast" id="art-toast">
    <span class="spinner" style="width:12px;height:12px;border-width:2px;margin-right:8px;"></span>
    <span id="art-toast-text">Generating artwork...</span>
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

<!-- Fullscreen Map Overlay -->
<div class="map-overlay" id="map-overlay">
    <button class="map-overlay-close" onclick="closeMapOverlay()">&times;</button>
    <canvas id="map-fullscreen-canvas"></canvas>
    <!-- Location detail popup -->
    <div class="map-detail" id="map-detail" style="display:none;">
        <div class="map-detail-header">
            <h3 id="map-detail-name"></h3>
            <button class="map-detail-close" onclick="closeMapDetail()">&times;</button>
        </div>
        <div id="map-detail-image-wrap" style="display:none;">
            <img id="map-detail-image" src="" alt="">
        </div>
        <p id="map-detail-desc" class="map-detail-desc"></p>
        <div id="map-detail-chars"></div>
        <div id="map-detail-objects"></div>
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

<script>
// Detect mobile: cookie override, PWA standalone, or narrow viewport
if (document.cookie.includes('force_mobile=1') || window.navigator.standalone || window.matchMedia('(display-mode: standalone)').matches || window.innerWidth <= 768) {
    document.documentElement.classList.add('is-mobile');
}
</script>
<script src="assets/js/motive-icons.js"></script>
<script src="assets/js/game.js"></script>
<script src="assets/js/game-mobile.js"></script>
</body>
</html>
