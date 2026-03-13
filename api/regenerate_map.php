<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/Claude.php';

try {
    $profileId = Auth::requireProfile();
    $input = json_decode(file_get_contents('php://input'), true);
    $gameId = (int)($input['game_id'] ?? 0);
    if (!$gameId) throw new Exception('No game_id');

    $config = Database::getConfig();
    if (empty($config['anthropic_api_key'])) {
        throw new Exception('Anthropic API key not configured');
    }

    $pdo = Database::getConnection();
    $claude = new Claude($config['anthropic_api_key']);

    // Load plot context
    $stmt = $pdo->prepare("SELECT p.*, g.title FROM plots p JOIN games g ON g.id = p.game_id WHERE p.game_id = ?");
    $stmt->execute([$gameId]);
    $plotRow = $stmt->fetch();
    if (!$plotRow) throw new Exception('Game not found');

    $plotContext = "Title: {$plotRow['title']}. Setting: {$plotRow['setting_description']}. Time: {$plotRow['time_period']}. Victim: {$plotRow['victim_name']}. Killer: {$plotRow['killer_name']}. Weapon: {$plotRow['weapon']}. Motive: {$plotRow['motive']}.";

    // Load existing locations (keep locations, only regenerate connections)
    $stmt = $pdo->prepare("SELECT id, name, x_pos, y_pos FROM locations WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $locations = $stmt->fetchAll();
    if (empty($locations)) throw new Exception('No locations found for this game');

    $locNames = [];
    $locIds = [];
    foreach ($locations as $l) {
        $locNames[$l['id']] = $l['name'];
        $locIds[$l['name']] = (int)$l['id'];
    }

    // Build location list for the prompt (just names — AI will assign coordinates)
    $locList = [];
    foreach ($locations as $l) {
        $locList[] = "- {$l['name']}";
    }
    $locListStr = implode("\n", $locList);
    $locationNames = implode(', ', array_column($locations, 'name'));

    $seed = bin2hex(random_bytes(4));

    $prompt = <<<PROMPT
You are designing a map for a murder mystery text adventure game.

PLOT CONTEXT:
{$plotContext}

Generate a logical map using the rooms listed below. The map should feel like a real place — a building with corridors, a hub room (like a hallway or lobby) connecting to multiple rooms, and realistic spatial relationships.

ROOMS:
{$locListStr}

CONSTRAINTS:

1. Connections must be bidirectional.
   - If Room A connects east to Room B, then Room B MUST connect west to Room A.
   - Always use exact opposite directions: north/south, east/west, up/down, northeast/southwest, northwest/southeast.

2. Directions must make spatial sense.
   - north/south/east/west are horizontal connections on the same floor.
   - up/down are vertical connections (stairs, ladders, elevators).

3. Indoor/outdoor logic must be respected.
   - Outdoor areas (gardens, courtyards, paths, grounds) should be on the edges of the map, not sandwiched between indoor rooms.
   - Outdoor areas connect to the building via doorways (e.g. a garden connects to a hallway or lobby).

4. STRICT floor logic (very important):
   - Assign every room a floor: "ground" or "upper" (or "basement"/"outdoor").
   - Ground-floor rooms may ONLY connect horizontally (north/south/east/west) to OTHER ground-floor rooms.
   - Upper-floor rooms may ONLY connect horizontally to OTHER upper-floor rooms.
   - The ONLY way to connect different floors is via "up"/"down" directions.
   - "up"/"down" connections MUST go through a transitional room — a staircase, landing, hallway, or corridor. You CANNOT go "up" from a Drawing Room directly to a Bedroom. Instead: Drawing Room → east → Main Hallway → up → Upper Landing → east → Bedroom.
   - If any room uses an "up" or "down" connection, there MUST be a room that logically contains stairs (e.g. "Main Hallway", "Staircase", "Landing") at one or both ends.
   - A terrace or balcony is an upper-floor outdoor area — it connects horizontally to upper-floor rooms only.

5. Avoid impossible adjacency.
   - Rooms on different floors NEVER connect horizontally.
   - Think about what rooms would realistically be next to each other.

6. Every room must be reachable from every other room by following connections.

7. Create an interesting graph, NOT a straight line.
   - At least one room should have 3+ connections (a hub like a hallway, lobby, or corridor).
   - Most rooms should have 2-3 connections.
   - The map should branch and loop where it makes sense — like a real building floor plan.

8. No duplicate directions from the same room.
   - A room can only have ONE exit in each direction.

Return JSON with connections AND updated coordinates for each room:
{
    "rooms": [
        {"name": "exact room name", "x": 0, "y": 0, "z": 0}
    ],
    "connections": [
        {"from": "exact room name", "to": "exact room name", "direction": "north/south/east/west/up/down"}
    ]
}

COORDINATES — use three axes:
- x: 0-4 (east = higher x). Rooms connected east/west must differ in x.
- y: 0-4 (south = higher y). Rooms connected north/south must differ in y.
- z: the floor level. 0 = ground floor, 1 = upper floor, -1 = basement/cellar. Outdoor areas at ground level use z 0.
- Rooms connected by up/down MUST share the same x,y but differ in z.
- Rooms connected horizontally (north/south/east/west) MUST have the same z.

COMMON-SENSE FLOOR DEFAULTS:
- Bedrooms, bathrooms, balconies, terraces → usually upper floor (z: 1)
- Kitchens, dining rooms, lounges, lobbies, hallways, studies → usually ground floor (z: 0)
- Cellars, wine caves, dungeons, crypts, basements → below ground (z: -1)
- Gardens, courtyards, paths, grounds → ground level (z: 0)

- List BOTH directions for every pair. If Kitchen connects north to Hallway, list BOTH:
  {"from": "Kitchen", "to": "Hallway", "direction": "north"}
  {"from": "Hallway", "to": "Kitchen", "direction": "south"}
- Use ONLY the exact room names from the list above.
PROMPT;

    $result = $claude->sendJson($prompt, "Design a map for these rooms: {$locationNames}. Seed: {$seed}", 0.8, 8192);
    if (isset($result['error'])) {
        throw new Exception('AI generation failed: ' . $result['error']);
    }

    $data = $result['data'];
    $rawConns = $data['connections'] ?? [];
    if (empty($rawConns)) throw new Exception('AI returned no connections');

    // Update coordinates if AI provided room positions
    $rooms = $data['rooms'] ?? [];
    if (!empty($rooms)) {
        $coordStmt = $pdo->prepare("UPDATE locations SET x_pos = ?, y_pos = ?, z_pos = ? WHERE game_id = ? AND name = ?");
        $roomZ = []; // Track z values for validation
        foreach ($rooms as $room) {
            $name = $room['name'] ?? '';
            if (isset($locIds[$name])) {
                $z = (int)($room['z'] ?? 0);
                $coordStmt->execute([(int)($room['x'] ?? 0), (int)($room['y'] ?? 0), $z, $gameId, $name]);
                $roomZ[$name] = $z;
            }
        }
    }

    // --- Validate and repair ---
    $opposites = [
        'north' => 'south', 'south' => 'north',
        'east' => 'west', 'west' => 'east',
        'up' => 'down', 'down' => 'up',
        'northeast' => 'southwest', 'southwest' => 'northeast',
        'northwest' => 'southeast', 'southeast' => 'northwest'
    ];

    // Normalize: collect as [fromName => [{to, direction}]]
    $allConns = [];
    foreach (array_keys($locIds) as $name) {
        $allConns[$name] = [];
    }
    foreach ($rawConns as $conn) {
        $from = $conn['from'] ?? '';
        $to = $conn['to'] ?? '';
        $dir = strtolower($conn['direction'] ?? '');
        if (isset($locIds[$from]) && isset($locIds[$to]) && isset($opposites[$dir])) {
            $allConns[$from][] = ['to' => $to, 'direction' => $dir];
        }
    }

    // Remove duplicate directions from same location (keep first)
    foreach ($allConns as $from => &$conns) {
        $usedDirs = [];
        $filtered = [];
        foreach ($conns as $c) {
            if (!in_array($c['direction'], $usedDirs)) {
                $usedDirs[] = $c['direction'];
                $filtered[] = $c;
            }
        }
        $conns = $filtered;
    }
    unset($conns);

    // Reject horizontal connections between rooms on different floors
    $verticalDirs = ['up', 'down'];
    if (!empty($roomZ)) {
        foreach ($allConns as $from => &$conns) {
            $conns = array_filter($conns, function($c) use ($from, $roomZ, $verticalDirs) {
                $fromZ = $roomZ[$from] ?? 0;
                $toZ = $roomZ[$c['to']] ?? 0;
                if ($fromZ !== $toZ && !in_array($c['direction'], $verticalDirs)) {
                    return false; // Remove horizontal connection between different floors
                }
                if ($fromZ === $toZ && in_array($c['direction'], $verticalDirs)) {
                    return false; // Remove up/down between rooms on same floor
                }
                return true;
            });
            $conns = array_values($conns);
        }
        unset($conns);
    }

    // Add missing reverse connections
    foreach ($allConns as $from => $conns) {
        foreach ($conns as $c) {
            $reverseDir = $opposites[$c['direction']];
            // Check if reverse exists
            $hasReverse = false;
            foreach ($allConns[$c['to']] ?? [] as $rc) {
                if ($rc['to'] === $from && $rc['direction'] === $reverseDir) {
                    $hasReverse = true;
                    break;
                }
            }
            if (!$hasReverse) {
                // Check the target doesn't already use that direction
                $dirTaken = false;
                foreach ($allConns[$c['to']] ?? [] as $rc) {
                    if ($rc['direction'] === $reverseDir) {
                        $dirTaken = true;
                        break;
                    }
                }
                if (!$dirTaken) {
                    $allConns[$c['to']][] = ['to' => $from, 'direction' => $reverseDir];
                }
            }
        }
    }

    // Remove any remaining one-way connections (no matching reverse)
    foreach ($allConns as $from => &$conns) {
        $conns = array_filter($conns, function($c) use ($from, &$allConns, $opposites) {
            $reverseDir = $opposites[$c['direction']];
            foreach ($allConns[$c['to']] ?? [] as $rc) {
                if ($rc['to'] === $from && $rc['direction'] === $reverseDir) {
                    return true;
                }
            }
            return false;
        });
        $conns = array_values($conns);
    }
    unset($conns);

    // Check all locations are connected (simple BFS)
    $firstLoc = array_key_first($allConns);
    $visited = [$firstLoc => true];
    $queue = [$firstLoc];
    while (!empty($queue)) {
        $current = array_shift($queue);
        foreach ($allConns[$current] ?? [] as $c) {
            if (!isset($visited[$c['to']])) {
                $visited[$c['to']] = true;
                $queue[] = $c['to'];
            }
        }
    }
    $disconnected = array_diff(array_keys($locIds), array_keys($visited));

    // Fix orphaned locations — connect each to a reachable room on the same floor
    $horizontalDirs = ['north', 'south', 'east', 'west', 'northeast', 'northwest', 'southeast', 'southwest'];
    foreach ($disconnected as $orphan) {
        $orphanZ = $roomZ[$orphan] ?? 0;
        // Find a reachable room on the same floor with a free direction pair
        $connected = false;
        foreach (array_keys($visited) as $candidate) {
            $candZ = $roomZ[$candidate] ?? 0;
            if ($candZ !== $orphanZ) continue;

            // Find a direction pair that's free on both sides
            $usedByOrphan = array_column($allConns[$orphan] ?? [], 'direction');
            $usedByCandidate = array_column($allConns[$candidate] ?? [], 'direction');

            foreach ($horizontalDirs as $dir) {
                $rev = $opposites[$dir] ?? null;
                if (!$rev) continue;
                if (!in_array($dir, $usedByCandidate) && !in_array($rev, $usedByOrphan)) {
                    $allConns[$candidate][] = ['to' => $orphan, 'direction' => $dir];
                    $allConns[$orphan][] = ['to' => $candidate, 'direction' => $rev];
                    $visited[$orphan] = true;
                    $connected = true;
                    break;
                }
            }
            if ($connected) break;
        }
        // If same-floor failed, try up/down to any reachable room
        if (!$connected) {
            foreach (array_keys($visited) as $candidate) {
                $usedByOrphan = array_column($allConns[$orphan] ?? [], 'direction');
                $usedByCandidate = array_column($allConns[$candidate] ?? [], 'direction');
                $dir = ($orphanZ > ($roomZ[$candidate] ?? 0)) ? 'up' : 'down';
                $rev = $opposites[$dir];
                if (!in_array($dir, $usedByCandidate) && !in_array($rev, $usedByOrphan)) {
                    $allConns[$candidate][] = ['to' => $orphan, 'direction' => $dir];
                    $allConns[$orphan][] = ['to' => $candidate, 'direction' => $rev];
                    $visited[$orphan] = true;
                    break;
                }
            }
        }
    }
    // Recheck for any still-disconnected
    $disconnected = array_diff(array_keys($locIds), array_keys($visited));

    // Delete old connections
    $pdo->prepare("DELETE FROM location_connections WHERE game_id = ?")->execute([$gameId]);

    // Store new connections
    $connStmt = $pdo->prepare("INSERT INTO location_connections (game_id, from_location_id, to_location_id, direction) VALUES (?, ?, ?, ?)");
    $connCount = 0;
    foreach ($allConns as $from => $conns) {
        $fromId = $locIds[$from] ?? null;
        if (!$fromId) continue;
        foreach ($conns as $c) {
            $toId = $locIds[$c['to']] ?? null;
            if ($toId) {
                $connStmt->execute([$gameId, $fromId, $toId, $c['direction']]);
                $connCount++;
            }
        }
    }

    $message = "Map regenerated: {$connCount} connections across " . count($locations) . " locations.";
    if (!empty($disconnected)) {
        $message .= " WARNING: " . count($disconnected) . " location(s) may be disconnected: " . implode(', ', $disconnected);
    }

    echo json_encode(['success' => true, 'message' => $message, 'connection_count' => $connCount]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
