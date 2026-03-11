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
    <title>Sleuth - Help</title>
    <link rel="stylesheet" href="assets/css/game.css">
    <style>
        body {
            display: flex;
            height: 100vh;
            overflow: hidden;
        }

        /* ---- Sidebar Navigation ---- */
        .help-sidebar {
            width: 260px;
            min-width: 260px;
            background: var(--bg-panel);
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .help-sidebar-header {
            padding: 24px 20px 16px;
            border-bottom: 1px solid var(--border);
        }

        .help-sidebar-header h2 {
            color: var(--accent);
            font-size: 1.4em;
            font-weight: 800;
            letter-spacing: 1px;
            text-transform: uppercase;
            margin-bottom: 4px;
        }

        .help-sidebar-header p {
            color: var(--text-dim);
            font-size: 0.85em;
        }

        .help-nav {
            flex: 1;
            overflow-y: auto;
            padding: 12px 0;
        }

        .help-nav a {
            display: block;
            padding: 10px 20px;
            color: var(--text);
            text-decoration: none;
            font-size: 0.92em;
            border-left: 3px solid transparent;
            transition: all 0.15s;
        }

        .help-nav a:hover {
            background: rgba(233, 69, 96, 0.08);
            color: var(--text-bright);
            border-left-color: var(--accent-dim);
        }

        .help-nav a.active {
            background: rgba(233, 69, 96, 0.12);
            color: var(--accent);
            border-left-color: var(--accent);
            font-weight: 600;
        }

        .help-nav .nav-section {
            padding: 16px 20px 6px;
            font-size: 0.7em;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: var(--text-dim);
            font-weight: 700;
        }

        .help-sidebar-footer {
            padding: 12px 20px;
            border-top: 1px solid var(--border);
        }

        .help-sidebar-footer a {
            color: var(--accent);
            text-decoration: none;
            font-size: 0.9em;
        }

        .help-sidebar-footer a:hover {
            text-decoration: underline;
        }

        /* ---- Main Content ---- */
        .help-main {
            flex: 1;
            overflow-y: auto;
            padding: 40px 60px 80px;
            scroll-behavior: smooth;
        }

        .help-section {
            max-width: 780px;
            margin-bottom: 56px;
        }

        .help-section h2 {
            color: var(--accent);
            font-size: 1.5em;
            font-weight: 700;
            margin-bottom: 16px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--border);
        }

        .help-section h3 {
            color: var(--text-bright);
            font-size: 1.15em;
            margin: 24px 0 10px;
        }

        .help-section p {
            color: var(--text);
            line-height: 1.7;
            margin-bottom: 12px;
        }

        .help-section ul, .help-section ol {
            margin: 8px 0 16px 20px;
            line-height: 1.8;
            color: var(--text);
        }

        .help-section li {
            margin-bottom: 4px;
        }

        .help-tip {
            background: rgba(233, 69, 96, 0.08);
            border-left: 3px solid var(--accent);
            padding: 12px 16px;
            margin: 16px 0;
            border-radius: 0 6px 6px 0;
            color: var(--text);
            line-height: 1.6;
        }

        .help-tip strong {
            color: var(--accent);
        }

        .help-keys {
            display: inline-flex;
            gap: 4px;
        }

        kbd {
            display: inline-block;
            background: var(--bg-input);
            border: 1px solid var(--border);
            border-radius: 4px;
            padding: 2px 8px;
            font-size: 0.85em;
            font-family: inherit;
            color: var(--text-bright);
            box-shadow: 0 1px 2px rgba(0,0,0,0.3);
        }

        .help-table {
            width: 100%;
            border-collapse: collapse;
            margin: 12px 0 20px;
        }

        .help-table th, .help-table td {
            text-align: left;
            padding: 10px 14px;
            border-bottom: 1px solid var(--border);
            color: var(--text);
        }

        .help-table th {
            color: var(--text-bright);
            font-weight: 600;
            font-size: 0.85em;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .help-table tr:hover td {
            background: rgba(255,255,255,0.02);
        }

        .help-hero {
            max-width: 780px;
            margin-bottom: 48px;
        }

        .help-hero h1 {
            color: var(--accent);
            font-size: 2em;
            font-weight: 800;
            letter-spacing: 1px;
            margin-bottom: 8px;
        }

        .help-hero p {
            color: var(--text-dim);
            font-size: 1.05em;
            line-height: 1.6;
        }

        .help-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 10px;
            font-size: 0.8em;
            font-weight: 600;
        }

        .badge-limited {
            background: rgba(255, 199, 0, 0.15);
            color: var(--warning);
        }

        .badge-unlimited {
            background: rgba(46, 213, 115, 0.15);
            color: var(--success);
        }

        .example-commands {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 10px 0 16px;
        }

        .example-cmd {
            background: var(--bg-input);
            border: 1px solid var(--border);
            padding: 5px 12px;
            border-radius: 16px;
            font-size: 0.88em;
            color: var(--text);
        }

        @media (max-width: 900px) {
            .help-sidebar { width: 220px; min-width: 220px; }
            .help-main { padding: 24px 28px 60px; }
        }

        @media (max-width: 680px) {
            body { flex-direction: column; }
            .help-sidebar {
                width: 100%; min-width: 100%; height: auto; max-height: 50vh;
                border-right: none; border-bottom: 1px solid var(--border);
            }
            .help-main { padding: 20px 16px 60px; }
        }
    </style>
</head>
<body>

<!-- Sidebar -->
<div class="help-sidebar">
    <div class="help-sidebar-header">
        <h2>Sleuth Help</h2>
        <p>Your guide to solving murders</p>
    </div>
    <nav class="help-nav" id="help-nav">
        <div class="nav-section">Getting Started</div>
        <a href="#overview">What is Sleuth?</a>
        <a href="#profiles">Profiles</a>
        <a href="#new-game">Starting a new game</a>

        <div class="nav-section">Playing the Game</div>
        <a href="#actions">Actions & movement</a>
        <a href="#chat">Talking to characters</a>
        <a href="#trust">Trust system</a>
        <a href="#probes">Probes</a>
        <a href="#inventory">Inventory & objects</a>
        <a href="#notebook">Notebook & clues</a>
        <a href="#map">Map</a>
        <a href="#accusation">Making an accusation</a>

        <div class="nav-section">Interface</div>
        <a href="#music">Music</a>
        <a href="#images">Image viewer</a>
        <a href="#keyboard">Keyboard shortcuts</a>
        <a href="#game-management">Saving & managing games</a>
        <a href="#export-import">Export & import</a>

        <div class="nav-section">Administration</div>
        <a href="#settings">Settings & API keys</a>
        <a href="#debug">Debug tools</a>
        <a href="#database">Database</a>

        <div class="nav-section">Reference</div>
        <a href="#limits">Limits & defaults</a>
        <a href="#tips">Tips & strategy</a>
    </nav>
    <div class="help-sidebar-footer">
        <a href="index.php">&larr; Back to home</a>
    </div>
</div>

<!-- Main Content -->
<div class="help-main" id="help-main">
    <div class="help-hero">
        <h1>How to play Sleuth</h1>
        <p>Sleuth is an AI-powered murder mystery game. Every case is unique &mdash; generated on the fly by AI, complete with characters, locations, clues, and a killer to catch. Explore crime scenes, interrogate suspects, collect evidence, and make your accusation before the trail goes cold.</p>
    </div>

    <!-- Overview -->
    <div class="help-section" id="overview">
        <h2>What is Sleuth?</h2>
        <p>Each game drops you into a freshly generated murder mystery. A victim has been killed, and it's your job to figure out <strong>who did it</strong>, <strong>what weapon</strong> they used, and <strong>why</strong>.</p>
        <p>You investigate by typing natural-language commands &mdash; move between locations, search rooms, pick up objects, and talk to characters. The AI interprets your actions and drives the story forward, so no two playthroughs are ever the same.</p>
        <p>You have a limited number of accusations, so gather enough evidence before pointing the finger. Run out of accusations and the case goes cold.</p>
    </div>

    <!-- Profiles -->
    <div class="help-section" id="profiles">
        <h2>Profiles</h2>
        <p>Sleuth uses a Netflix-style profile system. No passwords &mdash; just click your name to play. Each profile tracks its own games independently.</p>
        <h3>Creating a profile</h3>
        <p>On the profile screen, click the <strong>+</strong> card. Pick a name, choose an avatar emoji, and select a colour. Hit create and you're in.</p>
        <h3>Switching profiles</h3>
        <p>Click your name in the top-left corner of the home screen to return to the profile picker.</p>
        <h3>Other players' games</h3>
        <p>You can see games created by other profiles on the home screen &mdash; they appear slightly greyed out with the owner's name. Click one and choose <strong>Clone</strong> to create your own fresh copy of that mystery with your own progress.</p>
    </div>

    <!-- New Game -->
    <div class="help-section" id="new-game">
        <h2>Starting a new game</h2>
        <h3>Random mystery</h3>
        <p>Click <strong>Random Mystery</strong> and the AI will generate a completely original case from scratch &mdash; plot, setting, characters, locations, objects, clues, and artwork. Sit back and watch the progress steps tick off.</p>
        <h3>Custom mystery</h3>
        <p>Click <strong>Custom Mystery</strong> to describe the kind of case you want. You can be as specific or vague as you like.</p>
        <div class="example-commands">
            <span class="example-cmd">A jazz club in 1920s Chicago</span>
            <span class="example-cmd">Ancient Egypt with poison and rituals</span>
            <span class="example-cmd">A tech billionaire found dead on his yacht</span>
            <span class="example-cmd">Victorian London haunted mansion</span>
        </div>
        <p>The AI uses your description to shape the setting, characters, and plot while still keeping the solution a surprise.</p>
        <h3>Generation steps</h3>
        <p>Creating a new mystery takes a little while as the AI builds everything piece by piece:</p>
        <ol>
            <li>Plot and backstory</li>
            <li>Locations and map layout</li>
            <li>Characters and suspects</li>
            <li>Objects and evidence</li>
            <li>Clues and red herrings</li>
            <li>Artwork for all locations, characters, and objects</li>
        </ol>
    </div>

    <!-- Actions & Movement -->
    <div class="help-section" id="actions">
        <h2>Actions & movement</h2>
        <p>The text input at the bottom of the screen is your main way of interacting with the world. Type what you want to do in plain English and press <kbd>Enter</kbd>.</p>
        <h3>Movement</h3>
        <p>Move between locations using direction commands, or click the exit badges shown below the narrative.</p>
        <div class="example-commands">
            <span class="example-cmd">go north</span>
            <span class="example-cmd">go upstairs</span>
            <span class="example-cmd">head to the kitchen</span>
            <span class="example-cmd">go east</span>
        </div>
        <p>Available directions include: north, south, east, west, up, down, northeast, northwest, southeast, and southwest. Exit badges show you where each direction leads, with a thumbnail of the destination.</p>
        <h3>Exploring</h3>
        <div class="example-commands">
            <span class="example-cmd">look around</span>
            <span class="example-cmd">search the desk</span>
            <span class="example-cmd">examine the bookshelf</span>
            <span class="example-cmd">inspect the letter</span>
            <span class="example-cmd">open the drawer</span>
        </div>
        <h3>Interacting with objects</h3>
        <div class="example-commands">
            <span class="example-cmd">pick up the knife</span>
            <span class="example-cmd">read the note</span>
            <span class="example-cmd">smell the glass</span>
            <span class="example-cmd">look under the rug</span>
        </div>
        <div class="help-tip">
            <strong>Tip:</strong> Be creative! The AI understands natural language, so you're not limited to rigid commands. Try things like "check if the window was forced open" or "look for fingerprints on the handle."
        </div>
        <h3>Locked locations</h3>
        <p>Some areas may be locked when you first discover them. Continue investigating &mdash; you may find keys, convince characters to let you in, or discover alternative routes.</p>
    </div>

    <!-- Chat -->
    <div class="help-section" id="chat">
        <h2>Talking to characters</h2>
        <p>The <strong>Chat</strong> tab in the right sidebar lets you have conversations with characters you've encountered. Select a character from the dropdown, type your message, and press <kbd>Enter</kbd> or click <strong>Say</strong>.</p>
        <p>Characters respond based on their personality, what they know, and how much they trust you. Each character has their own memory of your conversations, so they'll remember what you've discussed.</p>
        <h3>Character portraits</h3>
        <p>Click the character portrait next to the dropdown to view more details about them &mdash; their name, image, and description.</p>
        <h3>Emotional responses</h3>
        <p>After each response, you'll see an emotional tag showing how the character is feeling &mdash; things like <em>[Nervous]</em>, <em>[Defensive]</em>, <em>[Honest]</em>, or <em>[Scared]</em>. Pay attention to these &mdash; they can reveal when someone is hiding something.</p>
    </div>

    <!-- Trust -->
    <div class="help-section" id="trust">
        <h2>Trust system</h2>
        <p>Every character has a <strong>trust level</strong> between 0% and 100%, shown as a coloured bar beneath their portrait. Trust affects how willing characters are to share information with you.</p>
        <table class="help-table">
            <tr><th>Trust level</th><th>Status</th><th>What it means</th></tr>
            <tr><td>Below 30%</td><td style="color:#e94560">Guarded</td><td>Evasive, may lie or withhold information</td></tr>
            <tr><td>30% &ndash; 60%</td><td style="color:#ffc700">Cautious</td><td>Will answer questions but won't volunteer much</td></tr>
            <tr><td>60% &ndash; 80%</td><td style="color:#5b9bd5">Warm</td><td>Open and cooperative</td></tr>
            <tr><td>Above 80%</td><td style="color:#2ed573">Trusting</td><td>May reveal critical details willingly</td></tr>
        </table>
        <p>Trust changes based on how you treat characters. Aggressive questioning or false accusations can lower trust, while empathy and careful questioning can raise it.</p>
    </div>

    <!-- Probes -->
    <div class="help-section" id="probes">
        <h2>Probes</h2>
        <p>Probes are a special interrogation technique &mdash; a way to press a character harder than normal conversation allows. Click the <strong>Probe</strong> button next to the chat input to use one.</p>
        <p>When you probe someone, the AI pushes them to reveal deeper information they wouldn't share in casual conversation. This can break open a case, but use them wisely.</p>
        <div class="help-tip">
            <strong>Limit:</strong> You get <strong>5 probes per game</strong> <span class="help-badge badge-limited">limited</span> shared across all characters. The counter in the title bar shows how many you have left. Once they're gone, they're gone.
        </div>
        <p>Save your probes for moments when you're stuck or when a character's emotional responses suggest they're hiding something important.</p>
    </div>

    <!-- Inventory -->
    <div class="help-section" id="inventory">
        <h2>Inventory & objects</h2>
        <p>The <strong>Inventory</strong> tab shows everything you've picked up during your investigation. Items can be collected by typing commands like "pick up the knife" or by clicking the <strong>Pick Up</strong> button in the image viewer.</p>
        <h3>Object interactions</h3>
        <p>When objects appear in a room, you can:</p>
        <ul>
            <li><strong>Inspect</strong> them for hidden details (some objects reveal extra information)</li>
            <li><strong>Pick up</strong> items marked as collectible</li>
            <li><strong>Click their image</strong> to open the image viewer for a closer look</li>
        </ul>
        <h3>Evidence</h3>
        <p>Some objects are flagged as evidence &mdash; these are particularly important to the case. You'll need items in your inventory when it comes time to make an accusation, since you must select the murder weapon from what you've collected.</p>
        <div class="help-tip">
            <strong>Tip:</strong> Pick up everything that looks suspicious. You can't accuse someone with a weapon you haven't collected. Some objects are hidden inside other objects &mdash; try searching containers, opening drawers, and looking under things.
        </div>
    </div>

    <!-- Notebook -->
    <div class="help-section" id="notebook">
        <h2>Notebook & clues</h2>
        <p>The <strong>Notebook</strong> tab automatically records important clues and observations as you discover them. Each entry includes:</p>
        <ul>
            <li>The clue or observation itself</li>
            <li>Where or who it came from (the source)</li>
        </ul>
        <p>Clues are added whenever you discover something significant &mdash; inspecting evidence, having revealing conversations, or finding hidden objects. Check your notebook regularly to piece together the full picture before making an accusation.</p>
    </div>

    <!-- Map -->
    <div class="help-section" id="map">
        <h2>Map</h2>
        <p>Click the <strong>Map</strong> button in the sidebar to open a fullscreen map of all discovered locations.</p>
        <h3>Layout</h3>
        <p>The map arranges locations by floor &mdash; basement, ground floor, and upper floor are displayed side by side. Each location appears as a large node with its artwork, name, and any characters present shown as small avatars.</p>
        <h3>Interacting with the map</h3>
        <ul>
            <li><strong>Click a location node</strong> to see its description, who's there, and what objects are visible</li>
            <li>Your current location is highlighted with a <strong>"YOU"</strong> marker</li>
            <li>Connection lines show which rooms link to each other, with direction labels</li>
            <li>Undiscovered locations linked to places you've been appear as <strong>?</strong> nodes</li>
        </ul>
        <p>Press <kbd>Escape</kbd> or click the <strong>&times;</strong> button to close the map.</p>
    </div>

    <!-- Accusation -->
    <div class="help-section" id="accusation">
        <h2>Making an accusation</h2>
        <p>When you think you've cracked the case, click the red <strong>Accuse</strong> button in the title bar. The accusation wizard walks you through three steps:</p>
        <ol>
            <li><strong>Who did it?</strong> &mdash; Select a suspect from the characters you've met</li>
            <li><strong>What weapon?</strong> &mdash; Choose the murder weapon from your inventory</li>
            <li><strong>Why?</strong> &mdash; Pick the motive from a list of possibilities</li>
        </ol>
        <p>You'll see a confirmation summary before submitting. Get it right and the case is <strong>Solved</strong>. Get it wrong and you lose one accusation.</p>
        <div class="help-tip">
            <strong>Warning:</strong> You only get <strong>3 accusations per game</strong> <span class="help-badge badge-limited">limited</span>. Run out and the case goes cold &mdash; game over. Make sure you've gathered enough evidence before accusing anyone.
        </div>
        <h3>Requirements</h3>
        <ul>
            <li>You can only accuse characters you've <strong>spoken to</strong></li>
            <li>You must have at least one item in your <strong>inventory</strong> to select as the weapon</li>
            <li>The victim cannot be accused</li>
        </ul>
    </div>

    <!-- Music -->
    <div class="help-section" id="music">
        <h2>Music</h2>
        <p>Each game features ambient music sourced from Freesound, matched to the setting and time period of your mystery. The controls sit next to the game title in the top bar:</p>
        <ul>
            <li><strong>&#9835;</strong> &mdash; Play or pause the current track</li>
            <li><strong>&#9197;</strong> &mdash; Skip to the next track</li>
            <li>The current song title is displayed alongside the controls</li>
        </ul>
        <p>Music is entirely optional and plays at a low volume. If your browser blocks autoplay, just click the play button to start it manually.</p>
    </div>

    <!-- Image Viewer -->
    <div class="help-section" id="images">
        <h2>Image viewer</h2>
        <p>Click any object or location image to open the full-size image viewer. For objects, the viewer includes action buttons:</p>
        <ul>
            <li><strong>Inspect</strong> &mdash; Examine the object more closely</li>
            <li><strong>Pick Up</strong> &mdash; Add it to your inventory</li>
            <li><strong>Close</strong> &mdash; Dismiss the viewer</li>
        </ul>
        <p>Click outside the image or press <kbd>Escape</kbd> to close.</p>
    </div>

    <!-- Keyboard Shortcuts -->
    <div class="help-section" id="keyboard">
        <h2>Keyboard shortcuts</h2>
        <table class="help-table">
            <tr><th>Key</th><th>Action</th></tr>
            <tr><td><kbd>Enter</kbd></td><td>Submit action or send chat message</td></tr>
            <tr><td><kbd>Escape</kbd></td><td>Close any open modal, map, or overlay</td></tr>
        </table>
    </div>

    <!-- Game Management -->
    <div class="help-section" id="game-management">
        <h2>Saving & managing games</h2>
        <p>Your progress is saved automatically after every action. Close the browser and come back any time &mdash; your game will be right where you left it.</p>
        <h3>Home screen</h3>
        <p>The home screen shows all your saved games as cards with cover artwork, title, status, and basic stats. Click any card to see details and options:</p>
        <ul>
            <li><strong>Resume</strong> / <strong>Play</strong> &mdash; Continue an active game</li>
            <li><strong>Review</strong> &mdash; Look back at a completed case</li>
            <li><strong>Delete</strong> &mdash; Permanently remove a game (requires confirmation)</li>
            <li><strong>Debug</strong> &mdash; Inspect the game internals (spoiler warning!)</li>
        </ul>
        <h3>Game status</h3>
        <table class="help-table">
            <tr><th>Status</th><th>Meaning</th></tr>
            <tr><td><strong>Active</strong></td><td>Investigation in progress</td></tr>
            <tr><td><strong>Solved</strong></td><td>You correctly identified the killer</td></tr>
            <tr><td><strong>Cold Case</strong></td><td>You ran out of accusations</td></tr>
        </table>
        <h3>Restarting</h3>
        <p>In-game, click <strong>Restart</strong> to reset your progress on the current case. This clears your moves, inventory, and notebook but keeps the same mystery &mdash; useful if you want a fresh attempt at the same case.</p>
    </div>

    <!-- Export & Import -->
    <div class="help-section" id="export-import">
        <h2>Export & import</h2>
        <p>You can share games between devices or back them up using the export/import feature.</p>
        <h3>Exporting</h3>
        <p>Click any of your games on the home screen to open the detail modal, then click <strong>Export</strong>. This downloads a <code>.zip</code> file containing:</p>
        <ul>
            <li>All game data (plot, characters, locations, objects, clues, progress) as JSON</li>
            <li>All generated artwork (cover, location images, character portraits, object images)</li>
        </ul>
        <p>The button shows "Exporting..." while the file is being prepared.</p>
        <h3>Importing</h3>
        <p>On the home screen, you'll see a drop zone between the new game buttons and your saved games. You can:</p>
        <ul>
            <li><strong>Drag and drop</strong> a <code>.zip</code> file onto the drop zone</li>
            <li><strong>Click "browse"</strong> to pick a file from your computer</li>
        </ul>
        <p>The game is imported with fresh IDs and assigned to your current profile. All artwork is restored automatically.</p>
        <div class="help-tip">
            <strong>Tip:</strong> Export is a great way to move games between a local setup and a Docker instance, or to share a particularly good mystery with a friend.
        </div>
    </div>

    <!-- Settings -->
    <div class="help-section" id="settings">
        <h2>Settings & API keys</h2>
        <p>Sleuth needs a few external services to work. Configure these on the <a href="settings.php" style="color:var(--accent)">Settings</a> page.</p>
        <h3>Required</h3>
        <table class="help-table">
            <tr><th>Service</th><th>What it does</th></tr>
            <tr><td><strong>Anthropic (Claude)</strong></td><td>Powers all AI &mdash; plot generation, character dialogue, action resolution, clue logic</td></tr>
            <tr><td><strong>MySQL Database</strong></td><td>Stores all game data (configured on first setup)</td></tr>
        </table>
        <h3>Optional</h3>
        <table class="help-table">
            <tr><th>Service</th><th>What it does</th></tr>
            <tr><td><strong>OpenAI (DALL-E 3)</strong></td><td>Generates artwork for locations, characters, and objects</td></tr>
            <tr><td><strong>Venice.ai</strong></td><td>Alternative image provider (cheaper, fewer restrictions)</td></tr>
            <tr><td><strong>Freesound</strong></td><td>Sources ambient music tracks matched to each game's setting</td></tr>
        </table>
        <p>The game works without image or music APIs &mdash; you just won't get artwork or ambient audio.</p>
    </div>

    <!-- Debug -->
    <div class="help-section" id="debug">
        <h2>Debug tools</h2>
        <p>The <a href="debug.php" style="color:var(--accent)">Debug page</a> is an admin tool for inspecting the internals of any game. It reveals <strong>everything</strong> &mdash; including the killer, weapon, and motive &mdash; so only use it if something seems broken.</p>
        <h3>What it shows</h3>
        <ul>
            <li>Full plot details (victim, killer, weapon, motive, backstory)</li>
            <li>All locations with coordinates and connections</li>
            <li>All characters with their roles, trust levels, and chat histories</li>
            <li>All objects with pickup/evidence flags and hidden status</li>
            <li>All clues and whether they've been discovered</li>
            <li>Player state and full action log</li>
            <li>Interactive map canvas</li>
            <li>Map validation (checks for broken connections, orphaned items, missing images)</li>
        </ul>
        <h3>Repair tools</h3>
        <p>The debug page can also fix common issues: repair orphaned characters, regenerate map connections, fix motive options, and link missing weapons.</p>
    </div>

    <!-- Database -->
    <div class="help-section" id="database">
        <h2>Database</h2>
        <p>The <a href="dbverify.php" style="color:var(--accent)">Database page</a> is an auto-migration tool that keeps your database schema up to date. It checks for missing tables and columns and adds them automatically. Run it after updating Sleuth to ensure everything is in sync.</p>
    </div>

    <!-- Limits -->
    <div class="help-section" id="limits">
        <h2>Limits & defaults</h2>
        <table class="help-table">
            <tr><th>Feature</th><th>Limit</th><th>Notes</th></tr>
            <tr><td>Probes</td><td>5 per game</td><td>Shared across all characters</td></tr>
            <tr><td>Accusations</td><td>3 per game</td><td>Game over when exhausted</td></tr>
            <tr><td>Moves</td><td>Unlimited</td><td>Tracked for stats only</td></tr>
            <tr><td>Chat messages</td><td>Unlimited</td><td>Last 20 shown per character as AI context</td></tr>
            <tr><td>Clues</td><td>Unlimited</td><td>All added to notebook when discovered</td></tr>
            <tr><td>Trust range</td><td>0% &ndash; 100%</td><td>Affects character cooperation</td></tr>
            <tr><td>Inventory</td><td>Unlimited</td><td>Pick up as many items as you find</td></tr>
        </table>
    </div>

    <!-- Tips -->
    <div class="help-section" id="tips">
        <h2>Tips & strategy</h2>
        <h3>Early game</h3>
        <ul>
            <li>Explore every room you can reach before talking to anyone &mdash; know the lay of the land</li>
            <li>Pick up everything. You never know what'll turn out to be the murder weapon</li>
            <li>Read object descriptions carefully &mdash; the AI plants subtle hints in them</li>
        </ul>
        <h3>Questioning suspects</h3>
        <ul>
            <li>Build trust before asking sensitive questions &mdash; start with small talk</li>
            <li>Watch emotional tags closely. If someone suddenly gets <em>[Defensive]</em> when you mention the victim, that's a lead</li>
            <li>Ask different characters about the same events &mdash; inconsistencies reveal lies</li>
            <li>Save your probes for characters with low trust who seem to be hiding something</li>
        </ul>
        <h3>Making the accusation</h3>
        <ul>
            <li>Don't guess. You only get three shots</li>
            <li>Make sure you have the likely murder weapon in your inventory before accusing</li>
            <li>Cross-reference your notebook clues before committing</li>
            <li>The motive matters &mdash; consider what each suspect would gain from the victim's death</li>
        </ul>
        <div class="help-tip">
            <strong>Remember:</strong> Every mystery is solvable. All the clues you need are there &mdash; hidden in conversations, objects, and locations. If you're stuck, go back and re-examine places you've been, or talk to characters you haven't spoken to in a while. New evidence can change what people are willing to tell you.
        </div>
    </div>
</div>

<script>
// Highlight active nav link on scroll
const main = document.getElementById('help-main');
const links = document.querySelectorAll('.help-nav a[href^="#"]');

function updateActiveLink() {
    const sections = document.querySelectorAll('.help-section, .help-hero');
    let current = '';
    sections.forEach(s => {
        const rect = s.getBoundingClientRect();
        if (rect.top <= 120) current = s.id;
    });
    links.forEach(a => {
        a.classList.toggle('active', a.getAttribute('href') === '#' + current);
    });
}

main.addEventListener('scroll', updateActiveLink);
updateActiveLink();

// Smooth scroll on nav click
links.forEach(a => {
    a.addEventListener('click', (e) => {
        e.preventDefault();
        const id = a.getAttribute('href').slice(1);
        const el = document.getElementById(id);
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
});
</script>
</body>
</html>
