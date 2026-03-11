<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/Database.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $gameId = (int)($input['game_id'] ?? 0);
    if (!$gameId) throw new Exception('No game_id');

    $pdo = Database::getConnection();

    $opposites = [
        'north' => 'south', 'south' => 'north',
        'east' => 'west', 'west' => 'east',
        'up' => 'down', 'down' => 'up',
        'northeast' => 'southwest', 'southwest' => 'northeast',
        'northwest' => 'southeast', 'southeast' => 'northwest'
    ];
    $allDirs = array_keys($opposites);

    // Load all locations
    $stmt = $pdo->prepare("SELECT id, name FROM locations WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $locations = $stmt->fetchAll();
    $locNames = [];
    foreach ($locations as $l) $locNames[$l['id']] = $l['name'];

    // Load all connections
    $connQuery = $pdo->prepare("
        SELECT lc.*, l1.name as from_name, l2.name as to_name
        FROM location_connections lc
        JOIN locations l1 ON l1.id = lc.from_location_id
        JOIN locations l2 ON l2.id = lc.to_location_id
        WHERE lc.game_id = ?
    ");
    $connQuery->execute([$gameId]);
    $connections = $connQuery->fetchAll();

    $fixes = [];
    $insertStmt = $pdo->prepare("INSERT INTO location_connections (game_id, from_location_id, to_location_id, direction) VALUES (?, ?, ?, ?)");

    // 1. Remove duplicate directions from same location (keep first)
    // But first, track which locations the dupes connect to so we can check for orphans
    $connsByFrom = [];
    foreach ($connections as $c) {
        $connsByFrom[$c['from_location_id']][] = $c;
    }
    $dupeIds = [];
    $dupeTargets = []; // location IDs that lost an incoming connection
    foreach ($connsByFrom as $fromId => $conns) {
        $seenDirs = [];
        foreach ($conns as $c) {
            if (in_array($c['direction'], $seenDirs)) {
                $dupeIds[] = $c['id'];
                $dupeTargets[] = $c['to_location_id'];
                $fixes[] = "Removed duplicate {$c['direction']} from {$c['from_name']} (to {$c['to_name']})";
            } else {
                $seenDirs[] = $c['direction'];
            }
        }
    }
    if (!empty($dupeIds)) {
        $placeholders = implode(',', array_fill(0, count($dupeIds), '?'));
        $pdo->prepare("DELETE FROM location_connections WHERE id IN ({$placeholders})")->execute($dupeIds);
    }

    // Reload after removing dupes
    $connQuery->execute([$gameId]);
    $connections = $connQuery->fetchAll();

    // Helper: rebuild used-directions lookup
    $rebuildUsedDirs = function() use (&$connections) {
        $used = [];
        foreach ($connections as $c) {
            $used[$c['from_location_id']][] = $c['direction'];
        }
        return $used;
    };

    // Helper: get all connected location IDs (reachable in either direction)
    $getConnectedLocIds = function() use (&$connections) {
        $connected = [];
        foreach ($connections as $c) {
            $connected[$c['from_location_id']] = true;
            $connected[$c['to_location_id']] = true;
        }
        return $connected;
    };

    $usedDirs = $rebuildUsedDirs();

    // 2. Check for orphaned locations after dupe removal and reconnect them
    $dupeTargets = array_unique($dupeTargets);
    foreach ($dupeTargets as $targetId) {
        // Is this location still reachable? (has any incoming connection)
        $hasIncoming = false;
        foreach ($connections as $c) {
            if ($c['to_location_id'] == $targetId) {
                $hasIncoming = true;
                break;
            }
        }
        if ($hasIncoming) continue;

        // Also check outgoing — if it has outgoing, the reverse of those should give it incoming
        $hasOutgoing = false;
        foreach ($connections as $c) {
            if ($c['from_location_id'] == $targetId) {
                $hasOutgoing = true;
                break;
            }
        }
        if (!$hasOutgoing && !$hasIncoming) {
            // Completely disconnected — connect to nearest location by finding one with a free direction
            $targetName = $locNames[$targetId] ?? "id={$targetId}";
            $reconnected = false;

            foreach ($connections as $c) {
                // Try to connect from an existing location to this orphan
                $fromId = $c['from_location_id'];
                $fromUsed = $usedDirs[$fromId] ?? [];
                foreach ($allDirs as $dir) {
                    if (in_array($dir, $fromUsed)) continue;
                    $reverseDir = $opposites[$dir];
                    $targetUsed = $usedDirs[$targetId] ?? [];
                    if (in_array($reverseDir, $targetUsed)) continue;

                    // Found a free pair — connect them
                    $fromName = $locNames[$fromId] ?? "id={$fromId}";
                    $insertStmt->execute([$gameId, $fromId, $targetId, $dir]);
                    $insertStmt->execute([$gameId, $targetId, $fromId, $reverseDir]);
                    $usedDirs[$fromId][] = $dir;
                    $usedDirs[$targetId][] = $reverseDir;
                    // Add to connections array for subsequent checks
                    $connections[] = ['from_location_id' => $fromId, 'to_location_id' => $targetId, 'direction' => $dir, 'from_name' => $fromName, 'to_name' => $targetName];
                    $connections[] = ['from_location_id' => $targetId, 'to_location_id' => $fromId, 'direction' => $reverseDir, 'from_name' => $targetName, 'to_name' => $fromName];
                    $fixes[] = "Reconnected orphan {$targetName}: {$dir} from {$fromName}, {$reverseDir} back";
                    $reconnected = true;
                    break 2;
                }
            }
            if (!$reconnected) {
                $fixes[] = "WARNING: Could not reconnect orphaned location {$targetName} — no free directions available";
            }
        }
    }

    // 3. Add missing reverse connections
    // Reload to get fresh state including orphan reconnections
    $connQuery->execute([$gameId]);
    $connections = $connQuery->fetchAll();
    $usedDirs = $rebuildUsedDirs();

    foreach ($connections as $c) {
        $reverseDir = $opposites[$c['direction']] ?? null;
        if (!$reverseDir) continue;

        // Check if reverse exists
        $hasReverse = false;
        foreach ($connections as $r) {
            if ($r['from_location_id'] == $c['to_location_id'] && $r['to_location_id'] == $c['from_location_id'] && $r['direction'] === $reverseDir) {
                $hasReverse = true;
                break;
            }
        }
        if ($hasReverse) continue;

        // Check the reverse direction isn't already used for a different destination
        if (in_array($reverseDir, $usedDirs[$c['to_location_id']] ?? [])) {
            $fixes[] = "Cannot add {$reverseDir} from {$c['to_name']} to {$c['from_name']} — direction already used";
            continue;
        }

        $insertStmt->execute([$gameId, $c['to_location_id'], $c['from_location_id'], $reverseDir]);
        $usedDirs[$c['to_location_id']][] = $reverseDir;
        $connections[] = ['from_location_id' => $c['to_location_id'], 'to_location_id' => $c['from_location_id'], 'direction' => $reverseDir, 'from_name' => $c['to_name'], 'to_name' => $c['from_name']];
        $fixes[] = "Added {$reverseDir} from {$c['to_name']} to {$c['from_name']}";
    }

    if (empty($fixes)) {
        echo json_encode(['success' => true, 'message' => 'No map issues to fix.', 'fixes' => []]);
    } else {
        echo json_encode(['success' => true, 'message' => count($fixes) . ' fixes applied.', 'fixes' => $fixes]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
