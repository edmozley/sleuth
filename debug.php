<?php
require_once __DIR__ . '/includes/Database.php';

$pdo = Database::getConnection();
$config = Database::getConfig();

$gameId = (int)($_GET['game_id'] ?? 0);

// Get all games for selector
$games = $pdo->query("SELECT id, title, status, created_at FROM games ORDER BY id DESC")->fetchAll();

$data = null;
$problems = [];
if ($gameId) {
    $data = [];

    // Game + plot
    $stmt = $pdo->prepare("SELECT g.*, p.victim_name, p.killer_name, p.weapon, p.weapon_object_id, p.motive, p.motive_options, p.backstory, p.setting_description, p.time_period, p.art_style FROM games g LEFT JOIN plots p ON p.game_id = g.id WHERE g.id = ?");
    $stmt->execute([$gameId]);
    $data['game'] = $stmt->fetch();

    // Weapon object name lookup
    $data['weapon_object'] = null;
    if (!empty($data['game']['weapon_object_id'])) {
        $stmt = $pdo->prepare("SELECT id, name, image FROM objects WHERE id = ?");
        $stmt->execute([$data['game']['weapon_object_id']]);
        $data['weapon_object'] = $stmt->fetch();
    }

    // Victim and killer character lookups for thumbnails
    $data['victim_char'] = null;
    $data['killer_char'] = null;
    if (!empty($data['game']['victim_name'])) {
        $stmt = $pdo->prepare("SELECT id, name, image FROM characters_game WHERE game_id = ? AND name = ?");
        $stmt->execute([$gameId, $data['game']['victim_name']]);
        $data['victim_char'] = $stmt->fetch();
    }
    if (!empty($data['game']['killer_name'])) {
        $stmt = $pdo->prepare("SELECT id, name, image FROM characters_game WHERE game_id = ? AND name = ?");
        $stmt->execute([$gameId, $data['game']['killer_name']]);
        $data['killer_char'] = $stmt->fetch();
    }

    // Player state
    $stmt = $pdo->prepare("SELECT ps.*, l.name as location_name FROM player_state ps LEFT JOIN locations l ON l.id = ps.current_location_id WHERE ps.game_id = ?");
    $stmt->execute([$gameId]);
    $data['player'] = $stmt->fetch();

    // Locations
    $stmt = $pdo->prepare("SELECT * FROM locations WHERE game_id = ? ORDER BY id");
    $stmt->execute([$gameId]);
    $data['locations'] = $stmt->fetchAll();

    // Connections
    $stmt = $pdo->prepare("
        SELECT lc.*, l1.name as from_name, l2.name as to_name
        FROM location_connections lc
        JOIN locations l1 ON l1.id = lc.from_location_id
        JOIN locations l2 ON l2.id = lc.to_location_id
        WHERE lc.game_id = ? ORDER BY lc.from_location_id, lc.direction
    ");
    $stmt->execute([$gameId]);
    $data['connections'] = $stmt->fetchAll();

    // Characters
    $stmt = $pdo->prepare("SELECT cg.*, l.name as location_name FROM characters_game cg LEFT JOIN locations l ON l.id = cg.location_id WHERE cg.game_id = ? ORDER BY cg.id");
    $stmt->execute([$gameId]);
    $data['characters'] = $stmt->fetchAll();

    // Objects
    $stmt = $pdo->prepare("SELECT o.*, l.name as location_name, ol.name as original_location_name, cg.name as character_name, po.name as parent_object_name FROM objects o LEFT JOIN locations l ON l.id = o.location_id LEFT JOIN locations ol ON ol.id = o.original_location_id LEFT JOIN characters_game cg ON cg.id = o.character_id LEFT JOIN objects po ON po.id = o.parent_object_id WHERE o.game_id = ? ORDER BY o.parent_object_id IS NULL DESC, o.parent_object_id, o.id");
    $stmt->execute([$gameId]);
    $data['objects'] = $stmt->fetchAll();

    // Clues
    $stmt = $pdo->prepare("SELECT c.*, o.name as object_name, cg.name as character_name, l.name as location_name FROM clues c LEFT JOIN objects o ON o.id = c.linked_object_id LEFT JOIN characters_game cg ON cg.id = c.linked_character_id LEFT JOIN locations l ON l.id = c.linked_location_id WHERE c.game_id = ? ORDER BY c.id");
    $stmt->execute([$gameId]);
    $data['clues'] = $stmt->fetchAll();

    // Notebook
    $stmt = $pdo->prepare("SELECT * FROM notebook_entries WHERE game_id = ? ORDER BY created_at ASC");
    $stmt->execute([$gameId]);
    $data['notebook'] = $stmt->fetchAll();

    // Action log (last 50)
    $stmt = $pdo->prepare("SELECT * FROM action_log WHERE game_id = ? ORDER BY created_at DESC LIMIT 50");
    $stmt->execute([$gameId]);
    $data['actions'] = $stmt->fetchAll();

    // Per-character motives
    $stmt = $pdo->prepare("SELECT cm.*, cg.name as character_name FROM character_motives cm JOIN characters_game cg ON cg.id = cm.character_id WHERE cm.game_id = ? ORDER BY cm.character_id, cm.id");
    $stmt->execute([$gameId]);
    $data['character_motives'] = $stmt->fetchAll();

    // Compute problems
    if ($data['game']) {
        $orphanedChars = array_filter($data['characters'], fn($c) => empty($c['location_id']));
        $orphanedObjs = array_filter($data['objects'], fn($o) => empty($o['location_id']) && empty($o['original_location_id']) && empty($o['character_id']));

        $artStats = [];
        foreach (['locations' => $data['locations'], 'characters' => $data['characters'], 'objects' => $data['objects']] as $cat => $items) {
            $total = count($items);
            $done = count(array_filter($items, fn($i) => !empty($i['image'])));
            $artStats[$cat] = ['total' => $total, 'done' => $done, 'missing' => $total - $done];
        }
        $totalMissingArt = $artStats['locations']['missing'] + $artStats['characters']['missing'] + $artStats['objects']['missing'];

        if (!$data['player']) $problems[] = 'No player state';
        if (count($orphanedChars) > 0) $problems[] = count($orphanedChars) . ' orphaned characters';
        if (count($orphanedObjs) > 0) $problems[] = count($orphanedObjs) . ' orphaned objects';
        if ($totalMissingArt > 0) $problems[] = $totalMissingArt . ' missing images';
        if (count($data['locations']) === 0) $problems[] = 'No locations';
        if (count($data['characters']) === 0) $problems[] = 'No characters';
        if (count($data['clues']) === 0) $problems[] = 'No clues';
        if (empty($data['game']['art_style'])) $problems[] = 'No art style';
        if (empty($data['game']['cover_image'])) $problems[] = 'No cover image';
        if (empty($data['game']['weapon_object_id'])) $problems[] = 'No weapon object linked';
        // Check per-character motives for all non-victim characters
        $charsNeedingMotives = array_filter($data['characters'], fn($c) => $c['role'] !== 'victim');
        $charsWithMotives = !empty($data['character_motives'])
            ? array_unique(array_column($data['character_motives'], 'character_id'))
            : [];
        $missingMotiveChars = [];
        foreach ($charsNeedingMotives as $c) {
            if (!in_array($c['id'], $charsWithMotives)) {
                $missingMotiveChars[] = $c['name'];
            }
        }
        if (count($missingMotiveChars) > 0) {
            $problems[] = count($missingMotiveChars) . ' character(s) missing motives: ' . implode(', ', $missingMotiveChars);
        }

        // Map validation
        $mapProblems = [];
        $opposites = [
            'north' => 'south', 'south' => 'north',
            'east' => 'west', 'west' => 'east',
            'up' => 'down', 'down' => 'up',
            'northeast' => 'southwest', 'southwest' => 'northeast',
            'northwest' => 'southeast', 'southeast' => 'northwest'
        ];
        // Check for duplicate directions from same location
        $connsByFrom = [];
        foreach ($data['connections'] as $c) {
            $connsByFrom[$c['from_location_id']][] = $c;
        }
        foreach ($connsByFrom as $fromId => $conns) {
            $dirs = array_column($conns, 'direction');
            $dupes = array_diff_assoc($dirs, array_unique($dirs));
            if (!empty($dupes)) {
                $fromName = $conns[0]['from_name'];
                $mapProblems[] = "Duplicate directions from {$fromName}: " . implode(', ', array_unique($dupes));
            }
        }
        // Check for missing reverse connections
        foreach ($data['connections'] as $c) {
            $reverseDir = $opposites[$c['direction']] ?? null;
            if (!$reverseDir) continue;
            $hasReverse = false;
            foreach ($data['connections'] as $r) {
                if ($r['from_location_id'] == $c['to_location_id'] && $r['to_location_id'] == $c['from_location_id'] && $r['direction'] === $reverseDir) {
                    $hasReverse = true;
                    break;
                }
            }
            if (!$hasReverse) {
                $mapProblems[] = "Missing reverse: {$c['to_name']} has no {$reverseDir} back to {$c['from_name']}";
            }
        }
        if (!empty($mapProblems)) {
            $problems[] = count($mapProblems) . ' map issues';
        }
        $data['map_problems'] = $mapProblems;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include __DIR__ . '/includes/pwa-head.php'; ?>
    <title>Sleuth - Debug View</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #1a1a2e;
            color: #dee2e6;
            padding: 0;
            padding-top: 80px; /* adjusted by JS to match sticky header height */
            font-size: 14px;
            line-height: 1.6;
        }
        .container { max-width: 100%; margin: 0 auto; padding: 20px 30px 60px; }
        a { color: #e94560; text-decoration: none; }
        a:hover { color: #ff6b81; text-decoration: underline; }

        /* Sticky header */
        .sticky-header {
            position: fixed; top: 0; left: 0; right: 0; z-index: 100;
            background: #12122a; border-bottom: 1px solid #0f3460;
            padding: 15px 30px;
        }
        .sticky-header .container { padding: 0; max-width: 100%; margin: 0 auto; }
        .header-row {
            display: flex; align-items: center; gap: 20px; margin-bottom: 12px;
        }
        .header-row h1 { color: #e94560; font-size: 1.5em; white-space: nowrap; margin: 0; }
        .header-row select, .header-row button {
            background: #16213e; color: #dee2e6; border: 1px solid #0f3460;
            padding: 7px 14px; border-radius: 6px; font-size: 14px;
        }
        .header-row select { min-width: 280px; }
        .header-row button { cursor: pointer; background: #e94560; border-color: #e94560; color: #fff; font-weight: 500; }
        .header-row button:hover { background: #c73a52; }
        .header-links {
            display: flex; gap: 6px; margin-left: auto;
        }
        .header-links a {
            padding: 7px 14px; border-radius: 6px; font-size: 13px; font-weight: 500;
            color: #adb5bd; background: #16213e; border: 1px solid #0f3460;
            text-decoration: none; transition: all 0.15s;
        }
        .header-links a:hover { color: #fff; border-color: #e94560; background: #1a2744; text-decoration: none; }

        /* Problem banner */
        .problem-banner {
            background: rgba(233, 69, 96, 0.1); border: 1px solid rgba(233, 69, 96, 0.3);
            border-radius: 8px; padding: 10px 16px; margin-bottom: 12px;
            display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
        }
        .problem-banner .problem-label { color: #e94560; font-weight: 700; font-size: 13px; }
        .problem-tag {
            background: rgba(233, 69, 96, 0.2); color: #e94560; padding: 4px 10px;
            border-radius: 4px; font-size: 12px; cursor: pointer; white-space: nowrap; transition: background 0.15s;
        }
        .problem-tag:hover { background: rgba(233, 69, 96, 0.35); }

        /* Section nav */
        .section-nav {
            display: flex; gap: 6px; flex-wrap: wrap;
        }
        .nav-link {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 14px; border-radius: 6px; font-size: 13px; font-weight: 500;
            color: #adb5bd; text-decoration: none; background: #16213e;
            border: 1px solid transparent; transition: all 0.15s;
        }
        .nav-link:hover, .nav-link.active { color: #fff; border-color: #0f3460; background: #1a2744; text-decoration: none; }
        .nav-link .nav-badge {
            background: #e94560; color: #fff; font-size: 11px; padding: 2px 7px;
            border-radius: 4px; font-weight: 700;
        }
        .nav-link .nav-badge.ok { background: #2ed573; color: #000; }

        /* Sections */
        .debug-section { margin-bottom: 24px; scroll-margin-top: 145px; }
        .section-header {
            display: flex; align-items: center; gap: 12px;
            color: #e94560; font-size: 1.15em; font-weight: 600;
            background: #16213e; border: 1px solid #0f3460; border-radius: 8px 8px 0 0;
            padding: 14px 20px;
            cursor: pointer; user-select: none;
        }
        .section-header:hover { background: #1a2744; }
        .section-header .toggle { font-size: 0.8em; color: #666; transition: transform 0.2s; }
        .section-header .toggle.collapsed { transform: rotate(-90deg); }
        .section-header .count { color: #6c757d; font-size: 0.85em; font-weight: 400; }
        .section-header .section-actions { margin-left: auto; display: flex; gap: 8px; }
        .section-header .section-actions button {
            background: #e94560; color: #fff; border: none; padding: 6px 14px;
            border-radius: 5px; cursor: pointer; font-size: 12px; font-weight: 500;
        }
        .section-header .section-actions button:hover { background: #c73a52; }
        .section-header .section-actions button.secondary {
            background: transparent; border: 1px solid #e94560; color: #e94560;
        }
        .section-header .section-actions button.secondary:hover { background: rgba(233, 69, 96, 0.1); }
        .section-body {
            background: #16213e; border: 1px solid #0f3460; border-top: none;
            border-radius: 0 0 8px 8px; padding: 20px 24px;
        }
        .section-body.collapsed { display: none; }

        .plot-detail { display: flex; gap: 12px; margin: 0; padding: 8px 0; line-height: 1.6; border-bottom: 1px solid rgba(15, 52, 96, 0.4); }
        .plot-detail:last-child { border-bottom: none; }
        .plot-detail b { color: #e94560; min-width: 140px; flex-shrink: 0; }
        .plot-detail .plot-value { flex: 1; min-width: 0; }
        .plot-detail .plot-value img { display: block; margin-top: 8px; }

        /* Tables */
        table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 13px; }
        th {
            background: #0f3460; color: #e94560; text-align: left;
            padding: 10px 12px; white-space: nowrap; font-weight: 600; font-size: 12px;
            text-transform: uppercase; letter-spacing: 0.5px;
        }
        td {
            padding: 10px 12px; border-bottom: 1px solid rgba(15, 52, 96, 0.6);
            vertical-align: top; max-width: 300px; overflow: hidden; text-overflow: ellipsis;
        }
        tr:hover td { background: rgba(233, 69, 96, 0.04); }

        /* Badges */
        .badge {
            display: inline-block; padding: 3px 10px; border-radius: 4px;
            font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px;
        }
        .badge-yes { background: rgba(46, 213, 115, 0.15); color: #2ed573; }
        .badge-no { background: rgba(233, 69, 96, 0.15); color: #e94560; }
        .badge-role { background: rgba(91, 155, 213, 0.15); color: #5b9bd5; }
        .badge-killer { background: rgba(233, 69, 96, 0.2); color: #e94560; }
        .badge-victim { background: rgba(255, 199, 0, 0.15); color: #ffc700; }
        .badge-evidence { background: rgba(255, 165, 0, 0.15); color: orange; }
        .badge-critical { background: rgba(233, 69, 96, 0.2); color: #e94560; }
        .badge-major { background: rgba(255, 165, 0, 0.15); color: orange; }
        .badge-minor { background: rgba(100, 100, 100, 0.15); color: #999; }
        .badge-red_herring { background: rgba(128, 0, 128, 0.15); color: #c77dff; }

        /* Image thumbs */
        .img-thumb {
            width: 48px; height: 48px; object-fit: cover; border-radius: 6px;
            cursor: pointer; border: 2px solid transparent; transition: border-color 0.15s, transform 0.15s;
        }
        .img-thumb:hover { border-color: #e94560; transform: scale(1.1); }

        /* Lightbox */
        .img-lightbox {
            position: fixed; inset: 0; background: rgba(0,0,0,0.9);
            display: flex; align-items: center; justify-content: center;
            z-index: 9999; cursor: pointer;
            opacity: 0; transition: opacity 0.25s; pointer-events: none;
        }
        .img-lightbox.active { opacity: 1; pointer-events: auto; }
        .img-lightbox img { max-width: 90vw; max-height: 90vh; border-radius: 8px; box-shadow: 0 0 60px rgba(0,0,0,0.6); }

        .toast-container {
            position: fixed; top: 20px; right: 20px; z-index: 10000;
            display: flex; flex-direction: column; gap: 8px; pointer-events: none;
        }
        .toast {
            pointer-events: auto;
            padding: 14px 20px; border-radius: 8px; font-size: 0.9em; max-width: 420px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.4); backdrop-filter: blur(8px);
            animation: toastIn 0.3s ease-out;
            display: flex; align-items: flex-start; gap: 10px; line-height: 1.4;
        }
        .toast.success { background: rgba(46,213,115,0.15); border: 1px solid #2ed573; color: #2ed573; }
        .toast.error { background: rgba(233,69,96,0.15); border: 1px solid #e94560; color: #e94560; }
        .toast.info { background: rgba(15,52,96,0.8); border: 1px solid #3498db; color: #8ec5fc; }
        .toast.warning { background: rgba(255,199,0,0.15); border: 1px solid #ffc700; color: #ffc700; }
        .toast-close {
            background: none; border: none; color: inherit; cursor: pointer;
            font-size: 16px; opacity: 0.6; padding: 0; margin-left: auto; flex-shrink: 0;
        }
        .toast-close:hover { opacity: 1; }
        .toast.fade-out { animation: toastOut 0.3s ease-in forwards; }
        @keyframes toastIn { from { opacity: 0; transform: translateX(40px); } to { opacity: 1; transform: translateX(0); } }
        @keyframes toastOut { from { opacity: 1; transform: translateX(0); } to { opacity: 0; transform: translateX(40px); } }

        .loc-modal-overlay {
            position: fixed; inset: 0; background: rgba(0,0,0,0.75); z-index: 10000;
            display: flex; align-items: center; justify-content: center;
            opacity: 0; transition: opacity 0.2s; pointer-events: none;
        }
        .loc-modal-overlay.active { opacity: 1; pointer-events: auto; }
        .loc-modal {
            background: #16213e; border: 1px solid #0f3460; border-radius: 12px;
            max-width: 560px; width: 92%; max-height: 85vh; overflow-y: auto;
            box-shadow: 0 16px 48px rgba(0,0,0,0.5);
            transform: scale(0.9); transition: transform 0.2s;
        }
        .loc-modal-overlay.active .loc-modal { transform: scale(1); }
        .loc-modal-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 18px 22px 14px; border-bottom: 1px solid #0f3460;
        }
        .loc-modal-header h3 { color: #e94560; font-size: 1.15em; margin: 0; }
        .loc-modal-close {
            background: none; border: none; color: #666; font-size: 22px; cursor: pointer; padding: 0 4px;
        }
        .loc-modal-close:hover { color: #e94560; }
        .loc-modal-body { padding: 18px 22px; }
        .loc-modal-img {
            width: 100%; border-radius: 8px; margin-bottom: 14px; cursor: pointer;
        }
        .loc-modal-meta { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
        .loc-modal-tag {
            padding: 3px 10px; border-radius: 4px; font-size: 0.78em; font-weight: 600;
        }
        .loc-modal-tag.floor { background: rgba(15,52,96,0.6); color: #8ec5fc; }
        .loc-modal-tag.coords { background: rgba(255,255,255,0.06); color: #888; }
        .loc-modal-tag.locked { background: rgba(233,69,96,0.15); color: #e94560; }
        .loc-modal-tag.discovered { background: rgba(46,213,115,0.15); color: #2ed573; }
        .loc-modal-tag.undiscovered { background: rgba(255,199,0,0.15); color: #ffc700; }
        .loc-modal-desc { color: #ccc; font-size: 0.9em; line-height: 1.5; margin-bottom: 14px; }
        .loc-modal-section { margin-bottom: 12px; }
        .loc-modal-section h4 { color: #adb5bd; font-size: 0.82em; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; }
        .loc-modal-list { list-style: none; padding: 0; }
        .loc-modal-list li {
            padding: 5px 10px; border-radius: 4px; font-size: 0.85em;
            background: rgba(255,255,255,0.03); margin-bottom: 3px; color: #ccc;
        }
        .loc-modal-list li .tag-sm {
            font-size: 0.75em; padding: 1px 6px; border-radius: 3px; margin-left: 6px;
        }

        .confirm-overlay {
            position: fixed; inset: 0; background: rgba(0,0,0,0.7); z-index: 10001;
            display: flex; align-items: center; justify-content: center;
            opacity: 0; transition: opacity 0.2s; pointer-events: none;
        }
        .confirm-overlay.active { opacity: 1; pointer-events: auto; }
        .confirm-box {
            background: #16213e; border: 1px solid #0f3460; border-radius: 12px;
            padding: 28px 32px; max-width: 440px; width: 90%;
            box-shadow: 0 16px 48px rgba(0,0,0,0.5);
            transform: scale(0.9); transition: transform 0.2s;
        }
        .confirm-overlay.active .confirm-box { transform: scale(1); }
        .confirm-box p { color: #ccc; font-size: 0.95em; line-height: 1.5; margin-bottom: 24px; }
        .confirm-buttons { display: flex; gap: 10px; justify-content: flex-end; }
        .confirm-buttons button {
            padding: 9px 22px; border: none; border-radius: 6px;
            font-size: 0.9em; font-weight: 600; cursor: pointer; transition: opacity 0.15s;
        }
        .confirm-buttons button:hover { opacity: 0.85; }
        .confirm-btn-cancel { background: #0f3460; color: #adb5bd; }
        .confirm-btn-ok { background: #e94560; color: #fff; }

        .text-muted { color: #6c757d; }
        .text-short { max-width: 280px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        h3 { color: #adb5bd; margin: 20px 0 10px; font-size: 1em; font-weight: 600; }
    </style>
</head>
<body>
    <!-- Sticky Header -->
    <div class="sticky-header">
      <div class="container" style="padding:0;">
        <div class="header-row">
            <h1>Debug Inspector</h1>
            <form method="get" style="display:flex;gap:8px;align-items:center;">
                <select name="game_id" onchange="this.form.submit()">
                    <option value="">-- Select Game --</option>
                    <?php foreach ($games as $g): ?>
                        <option value="<?= $g['id'] ?>" <?= $g['id'] == $gameId ? 'selected' : '' ?>>
                            #<?= $g['id'] ?> - <?= htmlspecialchars($g['title']) ?> (<?= $g['status'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit">Load</button>
            </form>
            <div class="header-links">
                <a href="index.php">Home</a>
                <?php if ($gameId): ?><a href="game.php?id=<?= $gameId ?>">Play</a><?php endif; ?>
                <a href="settings.php">Settings</a>
                <a href="dbverify.php">Database</a>
            </div>
        </div>

        <?php if ($data && $data['game']): ?>
        <?php if (!empty($problems)): ?>
        <div class="problem-banner">
            <span class="problem-label">ISSUES:</span>
            <?php foreach ($problems as $p):
                // Determine which section to jump to
                $target = 'plot';
                if (str_contains($p, 'characters')) $target = 'characters';
                elseif (str_contains($p, 'objects')) $target = 'objects';
                elseif (str_contains($p, 'images')) $target = 'artwork';
                elseif (str_contains($p, 'player')) $target = 'player';
                elseif (str_contains($p, 'locations')) $target = 'locations';
                elseif (str_contains($p, 'clues')) $target = 'clues';
                elseif (str_contains($p, 'motive')) $target = 'motives';
                elseif (str_contains($p, 'map')) $target = 'map';
                elseif (str_contains($p, 'art style')) $target = 'plot';
            ?>
                <span class="problem-tag" onclick="event.preventDefault();jumpTo('<?= $target ?>')"><?= $p ?></span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="section-nav">
            <a class="nav-link" href="#sec-plot" onclick="event.preventDefault();jumpTo('plot')">Plot</a>
            <a class="nav-link" href="#sec-player" onclick="event.preventDefault();jumpTo('player')">Player</a>
            <a class="nav-link" href="#sec-locations" onclick="event.preventDefault();jumpTo('locations')">
                Locations <span class="nav-badge ok"><?= count($data['locations']) ?></span>
            </a>
            <a class="nav-link" href="#sec-connections" onclick="event.preventDefault();jumpTo('connections')">
                Connections <span class="nav-badge ok"><?= count($data['connections']) ?></span>
            </a>
            <a class="nav-link" href="#sec-map" onclick="event.preventDefault();jumpTo('map')">
                Map <span class="nav-badge <?= empty($data['map_problems']) ? 'ok' : '' ?>"><?= empty($data['map_problems']) ? 'OK' : count($data['map_problems']) . '!' ?></span>
            </a>
            <a class="nav-link" href="#sec-characters" onclick="event.preventDefault();jumpTo('characters')">
                Characters
                <?php $oc = count(array_filter($data['characters'], fn($c) => empty($c['location_id']))); ?>
                <span class="nav-badge <?= $oc ? '' : 'ok' ?>"><?= count($data['characters']) ?><?= $oc ? " ({$oc}!)" : '' ?></span>
            </a>
            <a class="nav-link" href="#sec-objects" onclick="event.preventDefault();jumpTo('objects')">
                Objects
                <?php $oo = count(array_filter($data['objects'], fn($o) => empty($o['location_id']) && empty($o['original_location_id']) && empty($o['character_id']))); ?>
                <span class="nav-badge <?= $oo ? '' : 'ok' ?>"><?= count($data['objects']) ?><?= $oo ? " ({$oo}!)" : '' ?></span>
            </a>
            <a class="nav-link" href="#sec-clues" onclick="event.preventDefault();jumpTo('clues')">
                Clues <span class="nav-badge ok"><?= count($data['clues']) ?></span>
            </a>
            <?php
                $charMotiveCount = count($data['character_motives'] ?? []);
                $motivesBadgeOk = $charMotiveCount > 0 && empty($missingMotiveChars);
            ?>
            <a class="nav-link" href="#sec-motives" onclick="event.preventDefault();jumpTo('motives')">
                Motives <span class="nav-badge <?= $motivesBadgeOk ? 'ok' : '' ?>"><?= $charMotiveCount ?><?= !empty($missingMotiveChars) ? ' (' . count($missingMotiveChars) . '!)' : '' ?></span>
            </a>
            <a class="nav-link" href="#sec-notebook" onclick="event.preventDefault();jumpTo('notebook')">
                Notebook <span class="nav-badge ok"><?= count($data['notebook']) ?></span>
            </a>
            <a class="nav-link" href="#sec-artwork" onclick="event.preventDefault();jumpTo('artwork')">
                Artwork
                <?php
                $totalArt = count($data['locations']) + count($data['characters']) + count($data['objects']);
                $doneArt = count(array_filter($data['locations'], fn($i) => !empty($i['image'])))
                         + count(array_filter($data['characters'], fn($i) => !empty($i['image'])))
                         + count(array_filter($data['objects'], fn($i) => !empty($i['image'])));
                $missingArt = $totalArt - $doneArt;
                ?>
                <span class="nav-badge <?= $missingArt ? '' : 'ok' ?>"><?= $doneArt ?>/<?= $totalArt ?></span>
            </a>
            <a class="nav-link" href="#sec-actions" onclick="event.preventDefault();jumpTo('actions')">
                Actions <span class="nav-badge ok"><?= count($data['actions']) ?></span>
            </a>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <script>
    // Dynamically set body padding to match sticky header height
    function adjustBodyPadding() {
        const header = document.querySelector('.sticky-header');
        if (header) document.body.style.paddingTop = (header.offsetHeight + 16) + 'px';
    }
    adjustBodyPadding();
    window.addEventListener('resize', adjustBodyPadding);
    </script>

    <div class="container">
    <?php if (!$gameId): ?>
        <div class="section-body"><p>Select a game above to inspect.</p></div>
    <?php elseif (!$data['game']): ?>
        <div class="section-body"><p>Game #<?= $gameId ?> not found.</p></div>
    <?php else: ?>

    <!-- PLOT -->
    <div class="debug-section" id="sec-plot">
        <div class="section-header" onclick="toggleSection('plot')">
            <span class="toggle" id="toggle-plot">&#9660;</span>
            Game & Plot
        </div>
        <div class="section-body" id="body-plot">
            <div class="plot-detail"><b>Title:</b> <span class="plot-value"><?= htmlspecialchars($data['game']['title']) ?></span></div>
            <div class="plot-detail"><b>Status:</b> <span class="plot-value"><?= $data['game']['status'] ?></span></div>
            <div class="plot-detail"><b>Difficulty:</b> <span class="plot-value"><?= $data['game']['difficulty'] ?></span></div>
            <div class="plot-detail"><b>Victim:</b> <span class="plot-value"><?php if ($data['victim_char'] && $data['victim_char']['image']): ?><img class="img-thumb" src="<?= htmlspecialchars($data['victim_char']['image']) ?>" style="vertical-align:middle;margin-right:8px;"><?php endif; ?><?= htmlspecialchars($data['game']['victim_name'] ?? 'N/A') ?></span></div>
            <div class="plot-detail"><b>Killer:</b> <span class="plot-value" style="color:#e94560;font-weight:bold"><?php if ($data['killer_char'] && $data['killer_char']['image']): ?><img class="img-thumb" src="<?= htmlspecialchars($data['killer_char']['image']) ?>" style="vertical-align:middle;margin-right:8px;"><?php endif; ?><?= htmlspecialchars($data['game']['killer_name'] ?? 'N/A') ?></span></div>
            <div class="plot-detail"><b>Weapon:</b> <span class="plot-value"><?= htmlspecialchars($data['game']['weapon'] ?? 'N/A') ?>
                <?php if ($data['weapon_object']): ?>
                    <br>&rarr; <span style="color:#4ecca3;">Object #<?= $data['weapon_object']['id'] ?>: "<?= htmlspecialchars($data['weapon_object']['name']) ?>"</span>
                    <?php if ($data['weapon_object']['image']): ?>
                        <img class="img-thumb" src="<?= htmlspecialchars($data['weapon_object']['image']) ?>">
                    <?php endif; ?>
                <?php elseif (!empty($data['game']['weapon'])): ?>
                    <span class="badge badge-no" style="margin-left:8px;">no linked object</span>
                    <br>
                    <select id="weapon-fix-select" style="margin-top:6px;padding:5px 10px;font-size:13px;background:#1a1a2e;color:#dee2e6;border:1px solid #0f3460;border-radius:5px;">
                        <option value="">-- pick object --</option>
                        <?php foreach ($data['objects'] as $obj): ?>
                        <option value="<?= $obj['id'] ?>"><?= htmlspecialchars($obj['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button onclick="linkWeaponObject()" style="margin-left:6px;padding:5px 14px;font-size:13px;background:#e94560;color:#fff;border:none;border-radius:5px;cursor:pointer;">Link</button>
                    <span id="weapon-fix-status" style="margin-left:8px;"></span>
                <?php endif; ?>
            </span></div>
            <div class="plot-detail"><b>Motive:</b> <span class="plot-value"><?= htmlspecialchars($data['game']['motive'] ?? 'N/A') ?></span></div>
            <div class="plot-detail"><b>Setting:</b> <span class="plot-value"><?= htmlspecialchars($data['game']['setting_description'] ?? 'N/A') ?></span></div>
            <div class="plot-detail"><b>Time Period:</b> <span class="plot-value"><?= htmlspecialchars($data['game']['time_period'] ?? 'N/A') ?></span></div>
            <div class="plot-detail"><b>Backstory:</b> <span class="plot-value"><?= htmlspecialchars($data['game']['backstory'] ?? 'N/A') ?></span></div>
            <div class="plot-detail"><b>Art Style:</b> <span class="plot-value text-muted"><?= htmlspecialchars($data['game']['art_style'] ?? 'N/A') ?></span></div>
            <div class="plot-detail">
                <b>Cover Image:</b>
                <span class="plot-value">
                <?php if (!empty($data['game']['cover_image'])): ?>
                    <img class="img-thumb" src="<?= htmlspecialchars($data['game']['cover_image']) ?>">
                    <br><span class="text-muted"><?= htmlspecialchars($data['game']['cover_image']) ?></span>
                    <button onclick="regenerateCover()" id="btn-regen-cover" style="margin-left:10px;padding:5px 14px;font-size:13px;background:#e94560;color:#fff;border:none;border-radius:5px;cursor:pointer;">Regenerate</button>
                    <span id="cover-status" style="margin-left:8px;"></span>
                <?php else: ?>
                    <span class="badge badge-no">missing</span>
                    <button onclick="regenerateCover()" id="btn-regen-cover" style="margin-left:10px;padding:5px 14px;font-size:13px;background:#e94560;color:#fff;border:none;border-radius:5px;cursor:pointer;">Generate Cover</button>
                    <span id="cover-status" style="margin-left:8px;"></span>
                <?php endif; ?>
                </span>
            </div>
        </div>
    </div>

    <!-- PLAYER STATE -->
    <div class="debug-section" id="sec-player">
        <div class="section-header" onclick="toggleSection('player')">
            <span class="toggle" id="toggle-player">&#9660;</span>
            Player State
        </div>
        <div class="section-body" id="body-player">
            <?php if ($data['player']): ?>
            <div class="plot-detail"><b>Location:</b> <span class="plot-value"><?= htmlspecialchars($data['player']['location_name'] ?? 'None') ?> (id=<?= $data['player']['current_location_id'] ?>)</span></div>
            <div class="plot-detail"><b>Moves:</b> <span class="plot-value"><?= $data['player']['moves_taken'] ?></span></div>
            <div class="plot-detail"><b>Accusations Left:</b> <span class="plot-value"><?= $data['player']['accusations_remaining'] ?></span></div>
            <div class="plot-detail"><b>Phase:</b> <span class="plot-value"><?= $data['player']['game_phase'] ?></span></div>
            <?php else: ?>
            <p class="text-muted">No player state found.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- LOCATIONS -->
    <div class="debug-section" id="sec-locations">
        <div class="section-header" onclick="toggleSection('locations')">
            <span class="toggle" id="toggle-locations">&#9660;</span>
            Locations <span class="count">(<?= count($data['locations']) ?>)</span>
        </div>
        <div class="section-body" id="body-locations">
            <table>
                <tr><th>ID</th><th>Name</th><th>Description</th><th>Discovered</th><th>Locked</th><th>Pos</th><th>Image</th></tr>
                <?php foreach ($data['locations'] as $loc): ?>
                <tr>
                    <td><?= $loc['id'] ?></td>
                    <td><?= htmlspecialchars($loc['name']) ?></td>
                    <td class="text-short" title="<?= htmlspecialchars($loc['description']) ?>"><?= htmlspecialchars($loc['short_description']) ?></td>
                    <td><span class="badge <?= $loc['discovered'] ? 'badge-yes' : 'badge-no' ?>"><?= $loc['discovered'] ? 'yes' : 'no' ?></span></td>
                    <td><?= $loc['is_locked'] ? '<span class="badge badge-no">' . htmlspecialchars($loc['lock_reason']) . '</span>' : '-' ?></td>
                    <td><?= $loc['x_pos'] ?>,<?= $loc['y_pos'] ?>,<?= $loc['z_pos'] ?? 0 ?></td>
                    <td><?= $loc['image'] ? '<img class="img-thumb" src="' . htmlspecialchars($loc['image']) . '">' : '<span class="text-muted">none</span>' ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>

    <!-- CONNECTIONS -->
    <div class="debug-section" id="sec-connections">
        <div class="section-header" onclick="toggleSection('connections')">
            <span class="toggle" id="toggle-connections">&#9660;</span>
            Connections <span class="count">(<?= count($data['connections']) ?>)</span>
        </div>
        <div class="section-body" id="body-connections">
            <table>
                <tr><th>From</th><th>Direction</th><th>To</th></tr>
                <?php foreach ($data['connections'] as $c): ?>
                <tr>
                    <td><?= htmlspecialchars($c['from_name']) ?> (<?= $c['from_location_id'] ?>)</td>
                    <td><?= htmlspecialchars($c['direction']) ?></td>
                    <td><?= htmlspecialchars($c['to_name']) ?> (<?= $c['to_location_id'] ?>)</td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>

    <!-- MAP -->
    <div class="debug-section" id="sec-map">
        <div class="section-header" onclick="toggleSection('map')">
            <span class="toggle" id="toggle-map">&#9660;</span>
            Map
            <?php if (!empty($data['map_problems'])): ?>
                <span class="count" style="color:#e94560;">(<?= count($data['map_problems']) ?> issues)</span>
            <?php else: ?>
                <span class="count">(OK)</span>
            <?php endif; ?>
                <div class="section-actions" onclick="event.stopPropagation()">
                    <button onclick="regenerateMap()" id="btn-regen-map">Regenerate Map</button>
                </div>
        </div>
        <div class="section-body" id="body-map">
            <?php if (!empty($data['map_problems'])): ?>
            <div style="margin-bottom:16px;">
                <h3 style="margin-top:0;color:#e94560;">Problems</h3>
                <?php foreach ($data['map_problems'] as $mp): ?>
                    <div style="padding:4px 0;color:#e94560;font-size:13px;">&bull; <?= htmlspecialchars($mp) ?></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <canvas id="mapCanvas" style="width:100%;border-radius:8px;background:#111;"></canvas>
            <script>
            (function() {
                const locations = <?= json_encode(array_map(fn($l) => [
                    'id' => $l['id'],
                    'name' => $l['name'],
                    'x' => (int)$l['x_pos'],
                    'y' => (int)$l['y_pos'],
                    'z' => (int)($l['z_pos'] ?? 0),
                    'discovered' => (bool)$l['discovered'],
                    'locked' => (bool)$l['is_locked'],
                    'description' => $l['description'] ?? '',
                    'short_description' => $l['short_description'] ?? '',
                    'image' => $l['image'] ?? '',
                    'lock_reason' => $l['lock_reason'] ?? ''
                ], $data['locations'])) ?>;
                // Characters and objects by location for modal
                const charsByLoc = <?= json_encode(
                    array_reduce($data['characters'], function($carry, $c) {
                        $lid = (int)($c['location_id'] ?? 0);
                        if ($lid) $carry[$lid][] = ['name' => $c['name'], 'role' => $c['role'] ?? '', 'alive' => (bool)$c['is_alive']];
                        return $carry;
                    }, [])
                ) ?>;
                const objsByLoc = <?= json_encode(
                    array_reduce($data['objects'], function($carry, $o) {
                        $lid = (int)($o['location_id'] ?? 0);
                        if ($lid) $carry[$lid][] = ['name' => $o['name'], 'is_evidence' => (bool)$o['is_evidence'], 'is_hidden' => (bool)$o['is_hidden']];
                        return $carry;
                    }, [])
                ) ?>;
                const connections = <?= json_encode(array_map(fn($c) => [
                    'from' => (int)$c['from_location_id'],
                    'to' => (int)$c['to_location_id'],
                    'dir' => $c['direction']
                ], $data['connections'])) ?>;
                const playerId = <?= $data['player'] ? (int)$data['player']['current_location_id'] : 'null' ?>;

                const canvas = document.getElementById('mapCanvas');
                const ctx = canvas.getContext('2d');

                function drawMap() {
                    const dpr = window.devicePixelRatio || 1;
                    const rect = canvas.getBoundingClientRect();
                    const w = rect.width;

                    // Compute y-range to size canvas height dynamically
                    let yMin = Infinity, yMax = -Infinity;
                    locations.forEach(l => { if (l.y < yMin) yMin = l.y; if (l.y > yMax) yMax = l.y; });
                    const ySpan = Math.max(yMax - yMin, 0) + 1; // number of y rows
                    const rowHeight = 90; // compact vertical spacing per grid row
                    const computedH = Math.max(200, ySpan * rowHeight + 120); // 120 for top/bottom padding

                    canvas.width = w * dpr;
                    canvas.height = computedH * dpr;
                    canvas.style.height = computedH + 'px';
                    ctx.scale(dpr, dpr);
                    const h = computedH;

                    ctx.clearRect(0, 0, w, h);

                    if (locations.length === 0) {
                        ctx.fillStyle = '#666';
                        ctx.font = '14px sans-serif';
                        ctx.textAlign = 'center';
                        ctx.fillText('No locations', w / 2, h / 2);
                        return;
                    }

                    // Group locations by floor (z) to render floors side by side
                    const floors = {};
                    locations.forEach(l => {
                        if (!floors[l.z]) floors[l.z] = [];
                        floors[l.z].push(l);
                    });
                    const floorKeys = Object.keys(floors).map(Number).sort((a, b) => b - a); // upper first, then ground, then basement
                    const numFloors = floorKeys.length;

                    // Compute grid bounds across ALL locations
                    let minX = Infinity, maxX = -Infinity, minY = Infinity, maxY = -Infinity;
                    locations.forEach(l => {
                        if (l.x < minX) minX = l.x;
                        if (l.x > maxX) maxX = l.x;
                        if (l.y < minY) minY = l.y;
                        if (l.y > maxY) maxY = l.y;
                    });
                    const rangeX = Math.max(maxX - minX, 1);
                    const rangeY = Math.max(maxY - minY, 1);

                    const padding = 80;
                    const nodeW = 120;
                    const nodeH = 44;
                    const floorGap = 30; // gap between floor sections

                    // Compute per-floor x-range (number of unique x columns) to allocate width proportionally
                    const floorXSpan = {};
                    floorKeys.forEach(z => {
                        const xs = floors[z].map(l => l.x);
                        floorXSpan[z] = Math.max((Math.max(...xs) - Math.min(...xs)), 0) + 1; // +1 so single-location floors get weight 1
                    });
                    const totalSpan = floorKeys.reduce((sum, z) => sum + floorXSpan[z], 0);
                    const availableWidth = w - floorGap * (numFloors - 1);

                    // Each floor gets width proportional to its x-span (with a minimum)
                    const minFloorWidth = nodeW + padding * 2;
                    const floorWidths = {};
                    floorKeys.forEach(z => {
                        floorWidths[z] = Math.max(minFloorWidth, (floorXSpan[z] / totalSpan) * availableWidth);
                    });

                    // Compute cumulative offsets
                    const floorOffsets = {};
                    let offsetAccum = 0;
                    floorKeys.forEach(z => {
                        floorOffsets[z] = offsetAccum;
                        offsetAccum += floorWidths[z] + floorGap;
                    });

                    function pos(l) {
                        const fw = floorWidths[l.z];
                        const fo = floorOffsets[l.z];
                        // Per-floor x range
                        const floorLocs = floors[l.z];
                        const fMinX = Math.min(...floorLocs.map(fl => fl.x));
                        const fMaxX = Math.max(...floorLocs.map(fl => fl.x));
                        const fRangeX = Math.max(fMaxX - fMinX, 1);
                        return {
                            x: fo + padding + ((l.x - fMinX) / fRangeX) * (fw - padding * 2 - nodeW) + nodeW / 2,
                            y: padding + ((l.y - minY) / rangeY) * (h - padding * 2 - nodeH) + nodeH / 2
                        };
                    }

                    // Draw floor section labels and dividers
                    const floorLabels = { '-1': 'Basement', '0': 'Ground Floor', '1': 'Upper Floor', '2': '2nd Floor' };
                    floorKeys.forEach((z, idx) => {
                        const floorOffset = floorOffsets[z];
                        const floorWidth = floorWidths[z];
                        // Label
                        ctx.fillStyle = '#555';
                        ctx.font = 'bold 12px sans-serif';
                        ctx.textAlign = 'center';
                        ctx.fillText(floorLabels[z] || 'Floor ' + z, floorOffset + floorWidth / 2, 16);
                        // Divider line (between floors)
                        if (idx > 0) {
                            ctx.strokeStyle = 'rgba(255,255,255,0.15)';
                            ctx.lineWidth = 1;
                            ctx.setLineDash([4, 4]);
                            ctx.beginPath();
                            ctx.moveTo(floorOffset - floorGap / 2, 0);
                            ctx.lineTo(floorOffset - floorGap / 2, h);
                            ctx.stroke();
                            ctx.setLineDash([]);
                        }
                    });

                    const locById = {};
                    locations.forEach(l => locById[l.id] = l);

                    // Draw connections
                    const drawnPairs = new Set();
                    connections.forEach(c => {
                        const from = locById[c.from];
                        const to = locById[c.to];
                        if (!from || !to) return;
                        const pairKey = Math.min(c.from, c.to) + '-' + Math.max(c.from, c.to);

                        const p1 = pos(from);
                        const p2 = pos(to);

                        // Check if reverse exists
                        const hasReverse = connections.some(r =>
                            r.from === c.to && r.to === c.from
                        );

                        ctx.beginPath();
                        ctx.moveTo(p1.x, p1.y);
                        ctx.lineTo(p2.x, p2.y);
                        ctx.strokeStyle = hasReverse ? 'rgba(15, 52, 96, 0.8)' : '#e94560';
                        ctx.lineWidth = hasReverse ? 2 : 2;
                        if (!hasReverse) ctx.setLineDash([6, 4]);
                        else ctx.setLineDash([]);
                        ctx.stroke();
                        ctx.setLineDash([]);

                        // Direction label (only draw once per pair)
                        if (!drawnPairs.has(pairKey)) {
                            drawnPairs.add(pairKey);
                            const mx = (p1.x + p2.x) / 2;
                            const my = (p1.y + p2.y) / 2;
                            ctx.fillStyle = '#555';
                            ctx.font = '10px sans-serif';
                            ctx.textAlign = 'center';
                            ctx.textBaseline = 'middle';
                            ctx.fillText(c.dir, mx, my - 8);
                        }
                    });

                    // Draw nodes and store positions for hit testing
                    window._mapNodePositions = [];
                    locations.forEach(l => {
                        const p = pos(l);
                        window._mapNodePositions.push({ loc: l, x: p.x, y: p.y, w: nodeW, h: nodeH });
                        const isPlayer = l.id === playerId;
                        const isHovered = window._hoveredLocId === l.id;

                        // Node box — colour by floor
                        const floorColors = {
                            '-1': 'rgba(80, 50, 20, 0.95)',   // basement: brown
                            '0':  'rgba(22, 33, 62, 0.95)',   // ground: dark blue
                            '1':  'rgba(40, 20, 70, 0.95)'    // upper: purple
                        };
                        const floorBorders = {
                            '-1': '#8B6914',
                            '0':  '#0f3460',
                            '1':  '#6a1b9a'
                        };
                        if (isHovered) {
                            ctx.shadowColor = '#e94560';
                            ctx.shadowBlur = 14;
                        }
                        ctx.fillStyle = isHovered ? 'rgba(233, 69, 96, 0.2)' : (isPlayer ? 'rgba(233, 69, 96, 0.25)' : (floorColors[l.z] || floorColors['0']));
                        ctx.strokeStyle = isHovered ? '#e94560' : (isPlayer ? '#e94560' : (l.locked ? '#ff6b6b' : (floorBorders[l.z] || floorBorders['0'])));
                        ctx.lineWidth = isHovered ? 2.5 : (isPlayer ? 2.5 : 1.5);
                        const rx = nodeW / 2, ry = nodeH / 2;
                        ctx.beginPath();
                        ctx.roundRect(p.x - rx, p.y - ry, nodeW, nodeH, 6);
                        ctx.fill();
                        ctx.stroke();
                        ctx.shadowColor = 'transparent';
                        ctx.shadowBlur = 0;

                        // Name
                        ctx.fillStyle = isPlayer ? '#e94560' : '#dee2e6';
                        ctx.font = (isPlayer ? 'bold ' : '') + '11px sans-serif';
                        ctx.textAlign = 'center';
                        ctx.textBaseline = 'middle';

                        // Truncate long names
                        let name = l.name;
                        while (ctx.measureText(name).width > nodeW - 12 && name.length > 3) {
                            name = name.slice(0, -1);
                        }
                        if (name !== l.name) name += '...';

                        ctx.fillText(name, p.x, p.y - 4);

                        // Coordinates
                        ctx.fillStyle = '#555';
                        ctx.font = '9px sans-serif';
                        const floorLabel = l.z > 0 ? 'Upper' : l.z < 0 ? 'Basement' : 'Ground';
                        ctx.fillText(floorLabel + ' (' + l.x + ',' + l.y + ')', p.x, p.y + 11);

                        // Player marker
                        if (isPlayer) {
                            ctx.fillStyle = '#e94560';
                            ctx.beginPath();
                            ctx.arc(p.x + rx - 6, p.y - ry + 6, 4, 0, Math.PI * 2);
                            ctx.fill();
                        }
                    });

                    // Legend
                    ctx.font = '10px sans-serif';
                    ctx.textAlign = 'left';
                    let ly = h - 12;
                    ctx.fillStyle = '#0f3460'; ctx.fillRect(10, ly - 6, 20, 2);
                    ctx.fillStyle = '#666'; ctx.fillText('Bidirectional', 36, ly);
                    ctx.strokeStyle = '#e94560'; ctx.setLineDash([6, 4]); ctx.beginPath();
                    ctx.moveTo(130, ly - 5); ctx.lineTo(150, ly - 5); ctx.stroke(); ctx.setLineDash([]);
                    ctx.fillStyle = '#666'; ctx.fillText('One-way', 156, ly);
                    // Floor legend
                    let lx = 210;
                    ctx.fillStyle = '#0f3460'; ctx.fillRect(lx, ly - 8, 12, 12); lx += 16;
                    ctx.fillStyle = '#666'; ctx.fillText('Ground', lx, ly); lx += 48;
                    ctx.fillStyle = '#6a1b9a'; ctx.fillRect(lx, ly - 8, 12, 12); lx += 16;
                    ctx.fillStyle = '#666'; ctx.fillText('Upper', lx, ly); lx += 42;
                    ctx.fillStyle = '#8B6914'; ctx.fillRect(lx, ly - 8, 12, 12); lx += 16;
                    ctx.fillStyle = '#666'; ctx.fillText('Basement', lx, ly); lx += 60;
                    ctx.fillStyle = '#e94560'; ctx.beginPath(); ctx.arc(lx + 4, ly - 5, 4, 0, Math.PI * 2); ctx.fill();
                    ctx.fillStyle = '#666'; ctx.fillText('Player', lx + 14, ly);
                }

                window._hoveredLocId = null;
                window._mapLocations = locations;
                window._mapConnections = connections;
                window._mapCharsByLoc = charsByLoc;
                window._mapObjsByLoc = objsByLoc;
                window.drawMapGlobal = drawMap;
                drawMap();
                window.addEventListener('resize', drawMap);

                // Hit test helper
                function getLocAtPoint(mx, my) {
                    const nodes = window._mapNodePositions || [];
                    for (let i = nodes.length - 1; i >= 0; i--) {
                        const n = nodes[i];
                        if (mx >= n.x - n.w/2 && mx <= n.x + n.w/2 && my >= n.y - n.h/2 && my <= n.y + n.h/2) {
                            return n.loc;
                        }
                    }
                    return null;
                }

                canvas.addEventListener('mousemove', function(e) {
                    const rect = canvas.getBoundingClientRect();
                    const loc = getLocAtPoint(e.clientX - rect.left, e.clientY - rect.top);
                    const newId = loc ? loc.id : null;
                    canvas.style.cursor = loc ? 'pointer' : 'default';
                    if (newId !== window._hoveredLocId) {
                        window._hoveredLocId = newId;
                        drawMap();
                    }
                });

                canvas.addEventListener('mouseleave', function() {
                    if (window._hoveredLocId) {
                        window._hoveredLocId = null;
                        drawMap();
                    }
                });

                canvas.addEventListener('click', function(e) {
                    const rect = canvas.getBoundingClientRect();
                    const loc = getLocAtPoint(e.clientX - rect.left, e.clientY - rect.top);
                    if (loc) showLocationModal(loc);
                });
            })();
            </script>
        </div>
    </div>

    <!-- CHARACTERS -->
    <?php $orphanedChars = array_filter($data['characters'], fn($c) => empty($c['location_id'])); ?>
    <div class="debug-section" id="sec-characters">
        <div class="section-header" onclick="toggleSection('characters')">
            <span class="toggle" id="toggle-characters">&#9660;</span>
            Characters <span class="count">(<?= count($data['characters']) ?>)</span>
            <div class="section-actions" onclick="event.stopPropagation()">
                <?php if (!empty($orphanedChars)): ?>
                <button onclick="repairAll()">Fix <?= count($orphanedChars) ?> orphaned</button>
                <?php endif; ?>
                <button onclick="resetAllTrust()">Reset All Trust to 50</button>
                <button onclick="maxAllTrust()">Max All Trust to 100</button>
                <button onclick="resetAllMet()">Reset All Met</button>
            </div>
        </div>
        <div class="section-body" id="body-characters">
            <table>
                <tr><th>ID</th><th>Name</th><th>Role</th><th>Alive</th><th>Location</th><th>Met</th><th>Trust</th><th>Description</th><th>Secrets</th><th>Knowledge</th><th>Image</th></tr>
                <?php foreach ($data['characters'] as $ch): ?>
                <tr>
                    <td><?= $ch['id'] ?></td>
                    <td><b><?= htmlspecialchars($ch['name']) ?></b></td>
                    <td>
                        <?php
                        $roleClass = 'badge-role';
                        if ($ch['role'] === 'killer') $roleClass = 'badge-killer';
                        elseif ($ch['role'] === 'victim') $roleClass = 'badge-victim';
                        ?>
                        <span class="badge <?= $roleClass ?>"><?= $ch['role'] ?></span>
                    </td>
                    <td><span class="badge <?= $ch['is_alive'] ? 'badge-yes' : 'badge-no' ?>"><?= $ch['is_alive'] ? 'yes' : 'dead' ?></span></td>
                    <td><?= htmlspecialchars($ch['location_name'] ?? 'None') ?> (<?= $ch['location_id'] ?>)</td>
                    <td><span class="badge <?= !empty($ch['has_met']) ? 'badge-yes' : 'badge-no' ?>"><?= !empty($ch['has_met']) ? 'yes' : 'no' ?></span></td>
                    <td>
                        <?php
                        $tl = (int)($ch['trust_level'] ?? 50);
                        $trustColor = $tl >= 80 ? '#22c55e' : ($tl >= 60 ? '#5b9bd5' : ($tl >= 40 ? '#eab308' : '#ef4444'));
                        ?>
                        <span style="color:<?= $trustColor ?>;font-weight:bold;"><?= $tl ?></span>
                        <button style="font-size:0.7em;padding:1px 5px;margin-left:4px;" onclick="setCharTrust(<?= $ch['id'] ?>, prompt('Set trust for <?= htmlspecialchars($ch['name']) ?>:', <?= $tl ?>))">Edit</button>
                    </td>
                    <td class="text-short" title="<?= htmlspecialchars($ch['description']) ?>"><?= htmlspecialchars(mb_substr($ch['description'], 0, 80)) ?>...</td>
                    <td class="text-short" title="<?= htmlspecialchars($ch['secrets']) ?>"><?= htmlspecialchars(mb_substr($ch['secrets'], 0, 80)) ?>...</td>
                    <td class="text-short" title="<?= htmlspecialchars($ch['knowledge']) ?>"><?= htmlspecialchars(mb_substr($ch['knowledge'], 0, 80)) ?>...</td>
                    <td><?= $ch['image'] ? '<img class="img-thumb" src="' . htmlspecialchars($ch['image']) . '">' : '<span class="text-muted">none</span>' ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>

    <!-- OBJECTS -->
    <?php $orphanedObjs = array_filter($data['objects'], fn($o) => empty($o['location_id']) && empty($o['original_location_id']) && empty($o['character_id'])); ?>
    <div class="debug-section" id="sec-objects">
        <div class="section-header" onclick="toggleSection('objects')">
            <span class="toggle" id="toggle-objects">&#9660;</span>
            Objects <span class="count">(<?= count($data['objects']) ?>)</span>
            <?php if (!empty($orphanedObjs)): ?>
            <div class="section-actions" onclick="event.stopPropagation()">
                <button onclick="repairAll()">Fix <?= count($orphanedObjs) ?> orphaned</button>
            </div>
            <?php endif; ?>
        </div>
        <div class="section-body" id="body-objects">
            <table>
                <tr><th>ID</th><th>Name</th><th>Inside</th><th>Start Location</th><th>Current Location</th><th>Held By</th><th>Pickup</th><th>Hidden</th><th>Evidence</th><th>Weapon</th><th>Description</th><th>Inspect</th><th>Image</th></tr>
                <?php foreach ($data['objects'] as $obj): ?>
                <tr>
                    <td><?= $obj['id'] ?></td>
                    <td><b><?= $obj['parent_object_id'] ? '&nbsp;&nbsp;&#8627; ' : '' ?><?= htmlspecialchars($obj['name']) ?></b></td>
                    <td><?= $obj['parent_object_name'] ? htmlspecialchars($obj['parent_object_name']) : '-' ?></td>
                    <td><?= $obj['original_location_name'] ? htmlspecialchars($obj['original_location_name']) : '<span class="text-muted">none</span>' ?></td>
                    <td><?= $obj['location_name'] ? htmlspecialchars($obj['location_name']) . ($obj['location_id'] != $obj['original_location_id'] ? ' <span class="text-muted">(moved)</span>' : '') : '<span class="text-muted">inventory</span>' ?></td>
                    <td><?= $obj['character_name'] ? htmlspecialchars($obj['character_name']) : '-' ?></td>
                    <td><span class="badge <?= $obj['is_pickupable'] ? 'badge-yes' : 'badge-no' ?>"><?= $obj['is_pickupable'] ? 'yes' : 'no' ?></span></td>
                    <td><span class="badge <?= $obj['is_hidden'] ? 'badge-no' : 'badge-yes' ?>"><?= $obj['is_hidden'] ? 'hidden' : 'visible' ?></span></td>
                    <td><?= $obj['is_evidence'] ? '<span class="badge badge-evidence">evidence</span>' : '-' ?></td>
                    <td><?= !empty($obj['is_weapon']) ? '<span class="badge" style="background:rgba(233,69,96,0.2);color:#e94560;">WEAPON</span>' : '-' ?></td>
                    <td class="text-short" title="<?= htmlspecialchars($obj['description']) ?>"><?= htmlspecialchars(mb_substr($obj['description'], 0, 80)) ?>...</td>
                    <td class="text-short" title="<?= htmlspecialchars($obj['inspect_text'] ?? '') ?>"><?= htmlspecialchars(mb_substr($obj['inspect_text'] ?? '', 0, 60)) ?></td>
                    <td><?= $obj['image'] ? '<img class="img-thumb" src="' . htmlspecialchars($obj['image']) . '">' : '<span class="text-muted">none</span>' ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>

    <!-- CLUES -->
    <div class="debug-section" id="sec-clues">
        <div class="section-header" onclick="toggleSection('clues')">
            <span class="toggle" id="toggle-clues">&#9660;</span>
            Clues <span class="count">(<?= count($data['clues']) ?>)</span>
        </div>
        <div class="section-body" id="body-clues">
            <table>
                <tr><th>ID</th><th>Category</th><th>Importance</th><th>Discovered</th><th>Description</th><th>Discovery Method</th><th>Linked To</th></tr>
                <?php foreach ($data['clues'] as $cl): ?>
                <tr>
                    <td><?= $cl['id'] ?></td>
                    <td><?= $cl['category'] ?></td>
                    <td><span class="badge badge-<?= $cl['importance'] ?>"><?= $cl['importance'] ?></span></td>
                    <td><span class="badge <?= $cl['discovered'] ? 'badge-yes' : 'badge-no' ?>"><?= $cl['discovered'] ? 'yes' : 'no' ?></span></td>
                    <td class="text-short" title="<?= htmlspecialchars($cl['description']) ?>"><?= htmlspecialchars(mb_substr($cl['description'], 0, 100)) ?></td>
                    <td><?= htmlspecialchars($cl['discovery_method'] ?? '') ?></td>
                    <td>
                        <?php
                        $links = [];
                        if ($cl['object_name']) $links[] = 'Obj: ' . $cl['object_name'];
                        if ($cl['character_name']) $links[] = 'Char: ' . $cl['character_name'];
                        if ($cl['location_name']) $links[] = 'Loc: ' . $cl['location_name'];
                        echo htmlspecialchars(implode(', ', $links) ?: '-');
                        ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>

    <!-- MOTIVES -->
    <div class="debug-section" id="sec-motives">
        <div class="section-header" onclick="toggleSection('motives')">
            <span class="toggle" id="toggle-motives">&#9660;</span>
            Character Motives <span class="count">(<?= $charMotiveCount ?>)</span>
            <div class="section-actions">
                <button onclick="event.stopPropagation(); generateMotives()">Generate Motives</button>
                <button class="secondary" onclick="event.stopPropagation(); clearMotives()">Clear</button>
            </div>
        </div>
        <div class="section-body" id="body-motives">
            <?php if ($charMotiveCount > 0): ?>
            <?php
                // Group by character
                $motivesByChar = [];
                foreach ($data['character_motives'] as $cm) {
                    $motivesByChar[$cm['character_name']][] = $cm;
                }
            ?>
            <?php foreach ($motivesByChar as $charName => $charMotives): ?>
            <h4 style="color:var(--accent);margin:16px 0 8px 0;"><?= htmlspecialchars($charName) ?></h4>
            <table>
                <tr><th>#</th><th>Category</th><th>Motive Text</th><th>Correct?</th></tr>
                <?php foreach ($charMotives as $i => $m): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><span class="badge badge-role"><?= htmlspecialchars($m['category'] ?? '?') ?></span></td>
                    <td><?= htmlspecialchars($m['motive_text'] ?? '') ?></td>
                    <td>
                        <?php if (!empty($m['is_correct'])): ?>
                            <span class="badge badge-yes">CORRECT</span>
                        <?php else: ?>
                            <span class="text-muted">decoy</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php endforeach; ?>
            <p style="margin-top:12px;"><b style="color:#e94560;">Plot motive:</b> <?= htmlspecialchars($data['game']['motive'] ?? 'N/A') ?></p>
            <?php else: ?>
            <p class="text-muted">No character motives generated for this game. Click "Generate Motives" to create them.</p>
            <p style="margin-top:8px;"><b style="color:#e94560;">Plot motive:</b> <?= htmlspecialchars($data['game']['motive'] ?? 'N/A') ?></p>
            <?php endif; ?>
        </div>
    </div>

    <!-- NOTEBOOK -->
    <div class="debug-section" id="sec-notebook">
        <div class="section-header" onclick="toggleSection('notebook')">
            <span class="toggle" id="toggle-notebook">&#9660;</span>
            Notebook <span class="count">(<?= count($data['notebook']) ?>)</span>
        </div>
        <div class="section-body" id="body-notebook">
            <?php if (empty($data['notebook'])): ?>
                <p class="text-muted">No entries yet.</p>
            <?php else: ?>
            <table>
                <tr><th>ID</th><th>Type</th><th>Source</th><th>Entry</th><th>Clue ID</th><th>Time</th></tr>
                <?php foreach ($data['notebook'] as $nb): ?>
                <tr>
                    <td><?= $nb['id'] ?></td>
                    <td><?= $nb['entry_type'] ?></td>
                    <td><?= htmlspecialchars($nb['source'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($nb['entry_text']) ?></td>
                    <td><?= $nb['clue_id'] ?? '-' ?></td>
                    <td><?= $nb['created_at'] ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- ARTWORK STATUS -->
    <?php
    $artStats = [];
    foreach (['locations' => $data['locations'], 'characters' => $data['characters'], 'objects' => $data['objects']] as $cat => $items) {
        $total = count($items);
        $done = count(array_filter($items, fn($i) => !empty($i['image'])));
        $artStats[$cat] = ['total' => $total, 'done' => $done, 'missing' => $total - $done];
    }
    $totalMissing = $artStats['locations']['missing'] + $artStats['characters']['missing'] + $artStats['objects']['missing'];
    ?>
    <div class="debug-section" id="sec-artwork">
        <div class="section-header" onclick="toggleSection('artwork')">
            <span class="toggle" id="toggle-artwork">&#9660;</span>
            Artwork <span class="count">(<?= ($totalArt - $missingArt) ?>/<?= $totalArt ?>)</span>
            <?php if ($totalMissing > 0): ?>
            <div class="section-actions" onclick="event.stopPropagation()">
                <button onclick="testGenerateImage();" id="btn-test-art">Generate Next</button>
                <button class="secondary" onclick="generateAllImages();" id="btn-gen-all">Generate All (<?= $totalMissing ?>)</button>
            </div>
            <?php endif; ?>
        </div>
        <div class="section-body" id="body-artwork">
            <table>
                <tr><th>Category</th><th>Total</th><th>Done</th><th>Missing</th></tr>
                <?php foreach ($artStats as $cat => $s): ?>
                <tr>
                    <td><?= ucfirst($cat) ?></td>
                    <td><?= $s['total'] ?></td>
                    <td><span class="badge badge-yes"><?= $s['done'] ?></span></td>
                    <td><?= $s['missing'] > 0 ? '<span class="badge badge-no">' . $s['missing'] . '</span>' : '-' ?></td>
                </tr>
                <?php endforeach; ?>
            </table>

            <?php if ($totalMissing > 0): ?>
            <h3>Missing Images</h3>
            <table>
                <tr><th>Type</th><th>ID</th><th>Name</th><th>Image Value</th></tr>
                <?php foreach ($data['locations'] as $loc): if (!empty($loc['image'])) continue; ?>
                <tr>
                    <td>Location</td>
                    <td><?= $loc['id'] ?></td>
                    <td><?= htmlspecialchars($loc['name']) ?></td>
                    <td class="text-muted"><?= $loc['image'] === null ? 'NULL' : 'empty string' ?></td>
                </tr>
                <?php endforeach; ?>
                <?php foreach ($data['characters'] as $ch): if (!empty($ch['image'])) continue; ?>
                <tr>
                    <td>Character</td>
                    <td><?= $ch['id'] ?></td>
                    <td><?= htmlspecialchars($ch['name']) ?></td>
                    <td class="text-muted"><?= $ch['image'] === null ? 'NULL' : 'empty string' ?></td>
                </tr>
                <?php endforeach; ?>
                <?php foreach ($data['objects'] as $obj): if (!empty($obj['image'])) continue; ?>
                <tr>
                    <td>Object</td>
                    <td><?= $obj['id'] ?></td>
                    <td><?= htmlspecialchars($obj['name']) ?></td>
                    <td class="text-muted"><?= $obj['image'] === null ? 'NULL' : 'empty string' ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php endif; ?>

            <div id="art-debug-output" style="margin-top:10px;"></div>
        </div>
    </div>

    <!-- ACTION LOG -->
    <div class="debug-section" id="sec-actions">
        <div class="section-header" onclick="toggleSection('actions')">
            <span class="toggle" id="toggle-actions">&#9660;</span>
            Action Log <span class="count">(last 50)</span>
        </div>
        <div class="section-body" id="body-actions">
            <?php if (empty($data['actions'])): ?>
                <p class="text-muted">No actions yet.</p>
            <?php else: ?>
            <table>
                <tr><th>ID</th><th>Type</th><th>Action</th><th>Result</th><th>Time</th></tr>
                <?php foreach ($data['actions'] as $act): ?>
                <tr>
                    <td><?= $act['id'] ?></td>
                    <td><?= $act['action_type'] ?></td>
                    <td><?= htmlspecialchars($act['action_text']) ?></td>
                    <td class="text-short" title="<?= htmlspecialchars($act['result_text']) ?>"><?= htmlspecialchars(mb_substr($act['result_text'], 0, 120)) ?></td>
                    <td><?= $act['created_at'] ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <?php endif; ?>
    </div><!-- /.container -->

    <script>
    function toggleSection(name) {
        const body = document.getElementById('body-' + name);
        const toggle = document.getElementById('toggle-' + name);
        body.classList.toggle('collapsed');
        toggle.classList.toggle('collapsed');
        // Redraw map canvas when its section is revealed
        if (name === 'map' && !body.classList.contains('collapsed') && typeof drawMapGlobal === 'function') {
            setTimeout(drawMapGlobal, 50);
        }
    }

    function jumpTo(name) {
        const el = document.getElementById('sec-' + name);
        if (!el) return;
        // Ensure section is open
        const body = document.getElementById('body-' + name);
        const toggle = document.getElementById('toggle-' + name);
        if (body && body.classList.contains('collapsed')) {
            body.classList.remove('collapsed');
            toggle.classList.remove('collapsed');
        }
        if (name === 'map' && typeof drawMapGlobal === 'function') {
            setTimeout(drawMapGlobal, 50);
        }
        const header = document.querySelector('.sticky-header');
        const offset = header ? header.offsetHeight + 16 : 0;
        const top = el.getBoundingClientRect().top + window.scrollY - offset;
        window.scrollTo({ top, behavior: 'smooth' });
        // Flash highlight
        el.style.transition = 'background 0.3s';
        el.style.background = 'rgba(233, 69, 96, 0.1)';
        setTimeout(() => el.style.background = '', 1500);
    }

    // Highlight active nav link on scroll
    const sections = document.querySelectorAll('.debug-section');
    const navLinks = document.querySelectorAll('.nav-link');
    window.addEventListener('scroll', () => {
        let current = '';
        sections.forEach(sec => {
            if (sec.getBoundingClientRect().top < 160) current = sec.id.replace('sec-', '');
        });
        navLinks.forEach(link => {
            link.classList.toggle('active', link.getAttribute('href') === '#sec-' + current);
        });
    });

    async function regenerateCover() {
        const btn = document.getElementById('btn-regen-cover');
        const status = document.getElementById('cover-status');
        btn.disabled = true;
        btn.textContent = 'Generating...';
        status.textContent = '';
        try {
            const res = await fetch('api/generate_cover.php?game_id=<?= $gameId ?>');
            const data = await res.json();
            if (data.success) {
                status.innerHTML = '<span style="color:#2ed573">Done!</span> Reloading...';
                setTimeout(() => location.reload(), 1000);
            } else {
                status.innerHTML = '<span style="color:#e94560">Error: ' + (data.error || 'Unknown') + '</span>';
                if (data.prompt) status.innerHTML += '<br><small style="color:#cca700">' + data.prompt + '</small>';
                btn.disabled = false;
                btn.textContent = 'Generate Cover';
            }
        } catch (e) {
            status.innerHTML = '<span style="color:#e94560">Fetch error: ' + e.message + '</span>';
            btn.disabled = false;
            btn.textContent = 'Generate Cover';
        }
    }

    async function testGenerateImage() {
        const out = document.getElementById('art-debug-output');
        const btn = document.getElementById('btn-test-art');
        btn.disabled = true;
        btn.textContent = 'Working...';
        out.innerHTML = '<p style="color:#ffc700;">Calling generate_image.php...</p>';

        try {
            const res = await fetch('api/generate_image.php?game_id=<?= $gameId ?>');
            const text = await res.text();
            let data;
            try { data = JSON.parse(text); } catch(e) {
                out.innerHTML = '<p style="color:#e94560;">Failed to parse response:</p><pre style="background:#0a0a1a;padding:10px;border-radius:4px;font-size:12px;white-space:pre-wrap;max-height:300px;overflow:auto;">' + escHtml(text) + '</pre>';
                return;
            }

            let html = '<p><b>HTTP ' + res.status + '</b></p>';
            if (data.success && data.generated) {
                html += '<p style="color:#2ed573;">Generated: ' + escHtml(data.generated.type) + ' "' + escHtml(data.generated.name) + '" (id=' + data.generated.id + ')</p>';
                if (data.generated.image) {
                    html += '<img src="' + escHtml(data.generated.image) + '" style="max-width:150px;border-radius:6px;margin:5px 0;">';
                } else {
                    html += '<p style="color:#e94560;">Image generation failed.</p>';
                    if (data.image_error) {
                        html += '<p style="color:#e94560;font-size:12px;">' + escHtml(data.image_error) + '</p>';
                    }
                }
            } else if (data.done) {
                html += '<p style="color:#2ed573;">All images already generated.</p>';
            } else {
                html += '<p style="color:#e94560;">Error: ' + escHtml(data.error || 'Unknown') + '</p>';
            }
            if (data.prompt) {
                html += '<details style="margin-top:8px;" open><summary style="cursor:pointer;color:#ffc700;">DALL-E Prompt</summary><pre style="background:#0a0a1a;padding:10px;border-radius:4px;font-size:12px;white-space:pre-wrap;max-height:200px;overflow:auto;color:#ffc700;">' + escHtml(data.prompt) + '</pre></details>';
            }
            html += '<details style="margin-top:8px;"><summary style="cursor:pointer;color:#999;">Raw Response</summary><pre style="background:#0a0a1a;padding:10px;border-radius:4px;font-size:12px;white-space:pre-wrap;max-height:300px;overflow:auto;">' + escHtml(JSON.stringify(data, null, 2)) + '</pre></details>';
            out.innerHTML = html;
        } catch (e) {
            out.innerHTML = '<p style="color:#e94560;">Fetch error: ' + escHtml(e.message) + '</p>';
        } finally {
            btn.disabled = false;
            btn.textContent = 'Generate Next';
        }
    }

    async function generateAllImages() {
        const out = document.getElementById('art-debug-output');
        const btn = document.getElementById('btn-gen-all');
        btn.disabled = true;
        let generated = 0, failures = 0;

        while (failures < 3) {
            btn.textContent = 'Working... (' + generated + ' done)';
            try {
                const res = await fetch('api/generate_image.php?game_id=<?= $gameId ?>');
                const data = await res.json();

                if (!data.success || data.done) {
                    out.innerHTML = '<p style="color:#2ed573;">Finished! Generated ' + generated + ' images.</p>';
                    break;
                }

                if (data.generated?.image) {
                    generated++;
                    failures = 0;
                    out.innerHTML = '<p style="color:#2ed573;">Generated ' + generated + ': ' + escHtml(data.generated.type) + ' "' + escHtml(data.generated.name) + '"</p>';
                } else {
                    failures++;
                    let msg = 'Failed to generate ' + escHtml(data.generated?.name || 'unknown');
                    if (data.image_error) msg += ': ' + escHtml(data.image_error);
                    out.innerHTML = '<p style="color:#e94560;">' + msg + ' (attempt ' + failures + '/3)</p>';
                }
            } catch (e) {
                failures++;
                out.innerHTML = '<p style="color:#e94560;">Error: ' + escHtml(e.message) + ' (attempt ' + failures + '/3)</p>';
            }
        }

        if (failures >= 3) {
            out.innerHTML += '<p style="color:#e94560;">Stopped after 3 consecutive failures. Generated ' + generated + ' images total.</p>';
        }

        btn.disabled = false;
        btn.textContent = 'Generate All';
        if (generated > 0) setTimeout(() => location.reload(), 2000);
    }

    function escHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    async function repairAll() {
        const res = await fetch('api/repair.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({game_id: <?= $gameId ?: 0 ?>})
        });
        const data = await res.json();
        if (data.success) {
            toast(data.message + (data.details ? '<br>' + data.details.join('<br>') : ''), 'success', 3000);
            setTimeout(() => location.reload(), 1500);
        } else {
            toast(data.error, 'error');
        }
    }
    async function linkWeaponObject() {
        const sel = document.getElementById('weapon-fix-select');
        const objectId = parseInt(sel.value);
        if (!objectId) { toast('Select an object first', 'warning'); return; }
        const status = document.getElementById('weapon-fix-status');
        status.textContent = 'Linking...';
        try {
            const res = await fetch('api/link_weapon.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({game_id: <?= $gameId ?: 0 ?>, object_id: objectId})
            });
            const data = await res.json();
            if (data.success) {
                status.innerHTML = '<span style="color:#2ed573;">Linked!</span>';
                setTimeout(() => location.reload(), 1000);
            } else {
                status.innerHTML = '<span style="color:#e94560;">' + escHtml(data.error) + '</span>';
            }
        } catch (e) {
            status.innerHTML = '<span style="color:#e94560;">' + escHtml(e.message) + '</span>';
        }
    }

    function showLightbox(src) {
        const lb = document.getElementById('img-lightbox');
        lb.querySelector('img').src = src;
        lb.classList.add('active');
    }

    // Make ALL img-thumb elements open lightbox on click
    document.addEventListener('click', function(e) {
        const thumb = e.target.closest('.img-thumb');
        if (thumb && thumb.src) {
            e.stopPropagation();
            showLightbox(thumb.src);
        }
    });

    async function resetAllTrust() {
        if (!await styledConfirm('Reset all character trust levels to 50?')) return;
        await charBulkAction('reset_trust', 50);
    }

    async function maxAllTrust() {
        if (!await styledConfirm('Set all character trust levels to 100?')) return;
        await charBulkAction('reset_trust', 100);
    }

    async function resetAllMet() {
        if (!await styledConfirm('Reset all characters to not met?')) return;
        await charBulkAction('reset_met');
    }

    async function setCharTrust(charId, value) {
        if (value === null) return;
        value = parseInt(value);
        if (isNaN(value) || value < 0 || value > 100) { toast('Trust must be 0-100', 'warning'); return; }
        try {
            const res = await fetch('api/fix_trust.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({game_id: <?= $gameId ?: 0 ?>, character_id: charId, trust_level: value})
            });
            const data = await res.json();
            if (data.success) { toast('Trust updated', 'success'); setTimeout(() => location.reload(), 1000); }
            else toast(data.error, 'error');
        } catch (e) { toast(e.message, 'error'); }
    }

    async function generateMotives() {
        if (!await styledConfirm('Generate 5 motives per character for this game? This will replace any existing motives.')) return;
        const body = document.getElementById('body-motives');
        body.innerHTML = '<p style="color:#ffc700;">Generating per-character motives via AI...</p>';
        try {
            const res = await fetch('api/fix_motives.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({game_id: <?= $gameId ?: 0 ?>, action: 'generate'})
            });
            const data = await res.json();
            if (data.success) {
                toast(data.message, 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                toast(data.error, 'error');
                body.innerHTML = '<p style="color:#e94560;">Error: ' + escHtml(data.error) + '</p>';
                if (data.raw) body.innerHTML += '<details><summary style="cursor:pointer;color:#999;">Raw</summary><pre style="background:#0a0a1a;padding:10px;border-radius:4px;font-size:12px;white-space:pre-wrap;max-height:200px;overflow:auto;">' + escHtml(typeof data.raw === 'string' ? data.raw : JSON.stringify(data.raw, null, 2)) + '</pre></details>';
            }
        } catch (e) {
            toast(e.message, 'error');
            body.innerHTML = '<p style="color:#e94560;">Fetch error: ' + escHtml(e.message) + '</p>';
        }
    }

    async function clearMotives() {
        if (!await styledConfirm('Clear all character motives for this game?')) return;
        try {
            const res = await fetch('api/fix_motives.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({game_id: <?= $gameId ?: 0 ?>, action: 'clear'})
            });
            const data = await res.json();
            if (data.success) { toast('Motives cleared', 'success'); setTimeout(() => location.reload(), 1000); }
            else toast(data.error, 'error');
        } catch (e) { toast(e.message, 'error'); }
    }

    async function regenerateMap() {
        if (!await styledConfirm('Regenerate all map connections?\n\nThis will delete all existing connections and use AI to create new ones that follow spatial rules.\n\nLocations are kept — only connections change.')) return;
        const btn = document.getElementById('btn-regen-map');
        btn.disabled = true;
        btn.textContent = 'Regenerating...';
        try {
            const res = await fetch('api/regenerate_map.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({game_id: <?= $gameId ?: 0 ?>})
            });
            const data = await res.json();
            if (data.success) {
                toast(data.message, 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                toast(data.error, 'error');
                btn.disabled = false;
                btn.textContent = 'Regenerate Map';
            }
        } catch (e) {
            toast(e.message, 'error');
            btn.disabled = false;
            btn.textContent = 'Regenerate Map';
        }
    }

    async function charBulkAction(action, value) {
        try {
            const res = await fetch('api/fix_trust.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({game_id: <?= $gameId ?: 0 ?>, action: action, value: value})
            });
            const data = await res.json();
            if (data.success) {
                toast(data.message, 'success');
                setTimeout(() => location.reload(), 1500);
            } else toast(data.error, 'error');
        } catch (e) { toast(e.message, 'error'); }
    }
    </script>
<div class="img-lightbox" id="img-lightbox" onclick="this.classList.remove('active')">
    <img src="" alt="Full size">
</div>
<div class="loc-modal-overlay" id="loc-modal-overlay" onclick="if(event.target===this)this.classList.remove('active')">
    <div class="loc-modal">
        <div class="loc-modal-header">
            <h3 id="loc-modal-title"></h3>
            <button class="loc-modal-close" onclick="document.getElementById('loc-modal-overlay').classList.remove('active')">&times;</button>
        </div>
        <div class="loc-modal-body" id="loc-modal-body"></div>
    </div>
</div>
<div class="toast-container" id="toast-container"></div>
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
function showLocationModal(loc) {
    document.getElementById('loc-modal-title').textContent = loc.name;
    const floorName = loc.z > 0 ? 'Upper Floor' : loc.z < 0 ? 'Basement' : 'Ground Floor';
    let html = '';

    if (loc.image) {
        html += '<img class="loc-modal-img" src="' + loc.image + '" alt="' + escHtml(loc.name) + '" onclick="showLightbox(this.src)">';
    }

    html += '<div class="loc-modal-meta">';
    html += '<span class="loc-modal-tag floor">' + floorName + '</span>';
    html += '<span class="loc-modal-tag coords">(' + loc.x + ', ' + loc.y + ', ' + loc.z + ')</span>';
    html += loc.discovered ? '<span class="loc-modal-tag discovered">Discovered</span>' : '<span class="loc-modal-tag undiscovered">Undiscovered</span>';
    if (loc.locked) html += '<span class="loc-modal-tag locked">Locked</span>';
    html += '</div>';

    if (loc.lock_reason) {
        html += '<p class="loc-modal-desc" style="color:#e94560;font-style:italic;">' + escHtml(loc.lock_reason) + '</p>';
    }

    html += '<p class="loc-modal-desc">' + escHtml(loc.description) + '</p>';

    // Characters at this location
    const chars = (window._mapCharsByLoc || {})[loc.id] || [];
    if (chars.length) {
        html += '<div class="loc-modal-section"><h4>Characters Here</h4><ul class="loc-modal-list">';
        chars.forEach(c => {
            html += '<li>' + escHtml(c.name);
            if (c.role) html += ' <span class="tag-sm" style="background:rgba(15,52,96,0.6);color:#8ec5fc;">' + escHtml(c.role) + '</span>';
            if (!c.alive) html += ' <span class="tag-sm" style="background:rgba(233,69,96,0.15);color:#e94560;">Dead</span>';
            html += '</li>';
        });
        html += '</ul></div>';
    }

    // Objects at this location
    const objs = (window._mapObjsByLoc || {})[loc.id] || [];
    if (objs.length) {
        html += '<div class="loc-modal-section"><h4>Objects Here</h4><ul class="loc-modal-list">';
        objs.forEach(o => {
            html += '<li>' + escHtml(o.name);
            if (o.is_evidence) html += ' <span class="tag-sm" style="background:rgba(233,69,96,0.15);color:#e94560;">Evidence</span>';
            if (o.is_hidden) html += ' <span class="tag-sm" style="background:rgba(255,199,0,0.15);color:#ffc700;">Hidden</span>';
            html += '</li>';
        });
        html += '</ul></div>';
    }

    // Connections from this location
    const allConns = window._mapConnections || [];
    const allLocs = window._mapLocations || [];
    const conns = allConns.filter(c => c.from === loc.id);
    if (conns.length) {
        const locById = {};
        allLocs.forEach(l => locById[l.id] = l);
        html += '<div class="loc-modal-section"><h4>Exits</h4><ul class="loc-modal-list">';
        conns.forEach(c => {
            const dest = locById[c.to];
            html += '<li>' + escHtml(c.dir) + ' &rarr; ' + escHtml(dest ? dest.name : '?') + '</li>';
        });
        html += '</ul></div>';
    }

    document.getElementById('loc-modal-body').innerHTML = html;
    document.getElementById('loc-modal-overlay').classList.add('active');
}

function styledConfirm(message) {
    return new Promise(resolve => {
        const overlay = document.getElementById('confirm-overlay');
        const msgEl = document.getElementById('confirm-message');
        msgEl.innerHTML = message.replace(/\n/g, '<br>');
        overlay.classList.add('active');
        const ok = document.getElementById('confirm-ok');
        const cancel = document.getElementById('confirm-cancel');
        function cleanup(result) {
            overlay.classList.remove('active');
            ok.replaceWith(ok.cloneNode(true));
            cancel.replaceWith(cancel.cloneNode(true));
            resolve(result);
        }
        ok.addEventListener('click', () => cleanup(true), { once: true });
        cancel.addEventListener('click', () => cleanup(false), { once: true });
        overlay.addEventListener('click', (e) => { if (e.target === overlay) cleanup(false); }, { once: true });
    });
}

function toast(message, type = 'info', duration = 4000) {
    const container = document.getElementById('toast-container');
    const el = document.createElement('div');
    el.className = 'toast ' + type;
    el.innerHTML = '<span>' + message + '</span><button class="toast-close" onclick="this.parentElement.remove()">&times;</button>';
    container.appendChild(el);
    if (duration > 0) {
        setTimeout(() => {
            el.classList.add('fade-out');
            setTimeout(() => el.remove(), 300);
        }, duration);
    }
}
</script>
</body>
</html>
