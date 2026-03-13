<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/Claude.php';

try {
    $profileId = Auth::requireProfile();
    $input = json_decode(file_get_contents('php://input'), true);
    $gameId = (int)($input['game_id'] ?? 0);
    $actionText = trim($input['action'] ?? '');

    if (!$gameId || !$actionText) {
        throw new Exception('Missing game_id or action');
    }

    $config = Database::getConfig();
    $pdo = Database::getConnection();
    $claude = new Claude($config['anthropic_api_key']);

    // Get current game state
    $stmt = $pdo->prepare("SELECT * FROM player_state WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $player = $stmt->fetch();
    if (!$player) throw new Exception('Game not found');

    // Current location
    $stmt = $pdo->prepare("SELECT * FROM locations WHERE id = ?");
    $stmt->execute([$player['current_location_id']]);
    $location = $stmt->fetch();

    // Exits
    $stmt = $pdo->prepare("
        SELECT lc.direction, l.name, l.id, l.is_locked, l.lock_reason
        FROM location_connections lc
        JOIN locations l ON l.id = lc.to_location_id
        WHERE lc.from_location_id = ? AND lc.game_id = ?
    ");
    $stmt->execute([$location['id'], $gameId]);
    $exits = $stmt->fetchAll();

    // Characters here
    $stmt = $pdo->prepare("SELECT id, name, description, role, is_alive FROM characters_game WHERE game_id = ? AND location_id = ?");
    $stmt->execute([$gameId, $location['id']]);
    $characters = $stmt->fetchAll();

    // All characters in the game (for context - Claude needs to know who exists elsewhere)
    $stmt = $pdo->prepare("SELECT id, name, role, is_alive, location_id FROM characters_game WHERE game_id = ? AND location_id != ?");
    $stmt->execute([$gameId, $location['id']]);
    $otherCharacters = $stmt->fetchAll();

    // All objects at this location (including hidden, with parent info)
    $stmt = $pdo->prepare("SELECT id, name, description, inspect_text, is_pickupable, is_hidden, is_evidence, parent_object_id FROM objects WHERE game_id = ? AND location_id = ?");
    $stmt->execute([$gameId, $location['id']]);
    $allLocationObjects = $stmt->fetchAll();

    // Filter out children whose parent is still hidden (player hasn't found the parent yet)
    $objectsById = [];
    foreach ($allLocationObjects as $o) {
        $objectsById[$o['id']] = $o;
    }
    $objects = [];
    foreach ($allLocationObjects as $o) {
        if ($o['parent_object_id']) {
            $parent = $objectsById[$o['parent_object_id']] ?? null;
            // Hide child if parent is hidden or parent not at this location
            if (!$parent || $parent['is_hidden']) continue;
        }
        $objects[] = $o;
    }

    // Inventory
    $stmt = $pdo->prepare("SELECT id, name, description, inspect_text FROM objects WHERE game_id = ? AND location_id IS NULL AND character_id IS NULL AND is_pickupable = 1");
    $stmt->execute([$gameId]);
    $inventory = $stmt->fetchAll();

    // Undiscovered clues linked to this location or objects/characters here
    $stmt = $pdo->prepare("
        SELECT c.id, c.description, c.category, c.importance, c.discovery_method,
               c.linked_object_id, c.linked_character_id, c.linked_location_id
        FROM clues c
        WHERE c.game_id = ? AND c.discovered = 0
    ");
    $stmt->execute([$gameId]);
    $undiscoveredClues = $stmt->fetchAll();

    // --- Deterministic movement shortcut ---
    // Handle movement commands in PHP without calling the AI.
    // This prevents the AI from getting confused about revisiting locations.
    // Matches: "go north", "walk to the drawing room", "enter kitchen", "go to library", etc.
    $deterministicTarget = null;

    // Pattern 1: Direction-based — "go north", "head west", etc.
    if (preg_match('/^(?:go|walk|head|move)\s+(north|south|east|west|up|down|northeast|northwest|southeast|southwest)$/i', $actionText, $dirMatch)) {
        $requestedDir = strtolower($dirMatch[1]);
        foreach ($exits as $exit) {
            if (strcasecmp($exit['direction'], $requestedDir) === 0) {
                $deterministicTarget = $exit;
                break;
            }
        }
    }

    // Pattern 2: Name-based — "go to the drawing room", "walk to kitchen", "enter the library", etc.
    if (!$deterministicTarget && preg_match('/^(?:go\s+to|walk\s+to|head\s+to|move\s+to|enter|visit)\s+(?:the\s+)?(.+)$/i', $actionText, $nameMatch)) {
        $requestedName = strtolower(trim($nameMatch[1]));
        foreach ($exits as $exit) {
            $exitName = strtolower($exit['name']);
            // Exact match or substring match (e.g. "drawing room" matches "The Drawing Room")
            if ($exitName === $requestedName || strpos($exitName, $requestedName) !== false || strpos($requestedName, $exitName) !== false) {
                $deterministicTarget = $exit;
                break;
            }
        }
    }

    if ($deterministicTarget) {
        if ($deterministicTarget['is_locked']) {
            $narrative = "The way to " . $deterministicTarget['name'] . " is blocked. " . ($deterministicTarget['lock_reason'] ?: 'It seems to be locked.');
            $stmt = $pdo->prepare("UPDATE player_state SET moves_taken = moves_taken + 1 WHERE game_id = ?");
            $stmt->execute([$gameId]);
            $stmt = $pdo->prepare("INSERT INTO action_log (game_id, action_text, result_text, action_type) VALUES (?, ?, ?, 'move')");
            $stmt->execute([$gameId, $actionText, $narrative]);
            echo json_encode(['success' => true, 'narrative' => $narrative, 'action_type' => 'move', 'allowed' => false]);
            exit;
        }

        // Move player
        $stmt = $pdo->prepare("UPDATE player_state SET current_location_id = ?, moves_taken = moves_taken + 1 WHERE game_id = ?");
        $stmt->execute([$deterministicTarget['id'], $gameId]);

        // Discover new location
        $stmt = $pdo->prepare("UPDATE locations SET discovered = 1 WHERE id = ? AND game_id = ?");
        $stmt->execute([$deterministicTarget['id'], $gameId]);

        // Discover adjacent locations on the map
        $stmt = $pdo->prepare("SELECT to_location_id FROM location_connections WHERE from_location_id = ? AND game_id = ?");
        $stmt->execute([$deterministicTarget['id'], $gameId]);
        $adjacent = $stmt->fetchAll();
        foreach ($adjacent as $adj) {
            $stmt2 = $pdo->prepare("UPDATE locations SET discovered = 1 WHERE id = ? AND game_id = ?");
            $stmt2->execute([$adj['to_location_id'], $gameId]);
        }

        $narrative = "You make your way to " . $deterministicTarget['name'] . ".";
        $stmt = $pdo->prepare("INSERT INTO action_log (game_id, action_text, result_text, action_type) VALUES (?, ?, ?, 'move')");
        $stmt->execute([$gameId, $actionText, $narrative]);

        echo json_encode(['success' => true, 'narrative' => $narrative, 'action_type' => 'move', 'allowed' => true]);
        exit;
    }
    // No deterministic match — fall through to AI

    // Build context for Claude
    $exitList = array_map(fn($e) => $e['direction'] . ' -> [loc_id=' . $e['id'] . '] ' . $e['name'] . ($e['is_locked'] ? ' (LOCKED: ' . $e['lock_reason'] . ')' : ''), $exits);
    $charList = array_map(fn($c) => '[id=' . $c['id'] . '] ' . $c['name'] . ' (' . ($c['is_alive'] ? $c['role'] : 'dead - the victim') . ')', $characters);
    // Build object list with containment info
    $parentNames = [];
    foreach ($objects as $o) {
        $parentNames[$o['id']] = $o['name'];
    }
    $objList = array_map(function($o) use ($parentNames) {
        $line = '[id=' . $o['id'] . '] ' . $o['name'];
        if ($o['parent_object_id'] && isset($parentNames[$o['parent_object_id']])) {
            $line .= ' (inside ' . $parentNames[$o['parent_object_id']] . ')';
        }
        if ($o['is_hidden']) $line .= ' [HIDDEN]';
        if ($o['is_pickupable']) $line .= ' [CAN TAKE]';
        $line .= ' - ' . $o['description'];
        return $line;
    }, $objects);
    $invList = array_map(fn($i) => $i['name'], $inventory);

    $otherCharList = array_map(fn($c) => '[id=' . $c['id'] . '] ' . $c['name'] . ' (' . ($c['is_alive'] ? $c['role'] : 'dead') . ') - elsewhere', $otherCharacters);

    $clueContext = [];
    foreach ($undiscoveredClues as $clue) {
        $clueContext[] = [
            'id' => $clue['id'],
            'discovery_method' => $clue['discovery_method'],
            'linked_object_id' => $clue['linked_object_id'],
            'linked_character_id' => $clue['linked_character_id'],
            'linked_location_id' => $clue['linked_location_id'],
            'importance' => $clue['importance']
        ];
    }

    // Recent action history for consistency
    $stmt = $pdo->prepare("SELECT action_text, result_text FROM action_log WHERE game_id = ? ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([$gameId]);
    $recentActions = array_reverse($stmt->fetchAll());
    $historyLines = '';
    foreach ($recentActions as $ra) {
        $historyLines .= "> " . $ra['action_text'] . "\n" . $ra['result_text'] . "\n\n";
    }

    $exitListStr = implode(', ', $exitList) ?: 'none';
    $charListStr = implode(', ', $charList) ?: 'nobody';
    $otherCharListStr = implode(', ', $otherCharList) ?: 'none';
    $objListStr = implode('; ', $objList) ?: 'nothing visible';
    $invListStr = implode(', ', $invList) ?: 'empty';
    $clueJson = json_encode($clueContext);
    $locName = $location['name'];
    $locDesc = $location['description'];

    $systemPrompt = <<<PROMPT
You are the game engine for a murder mystery text adventure. Parse the player's action and determine the result.

CURRENT STATE:
- Location: [loc_id={$location['id']}] {$locName} - {$locDesc}
- Exits: {$exitListStr}
- People here: {$charListStr}
- People elsewhere: {$otherCharListStr}
- Visible objects: {$objListStr}
- Inventory: {$invListStr}
- Hidden objects at this location: (objects marked [HIDDEN] can be found if the player searches carefully)

UNDISCOVERED CLUES (hidden from player - reveal if their action matches the discovery method):
{$clueJson}

RECENT HISTORY (your previous responses - stay consistent with what you already described):
{$historyLines}

RULES:
1. Only allow actions that make sense in the current context
2. The player cannot leave through locked exits
3. MOVEMENT: If the player wants to go a direction that matches a valid exit, ALWAYS allow it and set action_type to "move" with move_to set to the destination name. The player can revisit any location as many times as they want — never refuse movement to a valid unlocked exit, even if they just came from there
4. If the player searches/looks around, they might find hidden objects - reveal them
5. If the player's action matches a clue's discovery_method, mark that clue as discovered
6. Keep descriptions atmospheric but concise (2-3 sentences max)
7. If an action is impossible or nonsensical, explain why briefly
8. Stay consistent with your previous responses in RECENT HISTORY, but do NOT use history as a reason to block valid movement
8. If the player tries to interact with something NOT listed in the objects or clues above, give a plausible but brief response that makes clear it holds nothing of interest to the investigation. For example: "You flip through the pages but find nothing relevant to the case." Do NOT invent new leads, clues, or interactable sub-items that don't exist in the game data
9. When describing objects from the list, use their description and inspect_text. Do not add invented sub-details that the player might try to follow up on
10. IMPORTANT: Only describe characters listed in "People here" as being present. Do NOT describe characters from "People elsewhere" as being in this location unless you are moving them here via move_characters. Characters can realistically move around (e.g. an inspector patrolling, a servant doing rounds) - use move_characters to make this happen in the database when it makes narrative sense
11. CONTAINERS: Objects marked "(inside X)" are contained within object X. When the player inspects or opens a container, reveal its hidden children via reveal_hidden_objects. Only objects whose parent is visible and unhidden are shown above - more items may appear as containers are opened

Respond with JSON:
{
    "allowed": true/false,
    "action_type": "move/pickup/inspect/look/search/talk/use/examine_body/other",
    "narrative": "What happens as a result (atmospheric, 2-3 sentences)",
    "move_to": "location name if moving, null otherwise",
    "pickup_object_ids": [object_ids to pick up] or null,
    "move_characters": [{"character_id": id, "to_location_id": location_id}] or null,
    "reveal_hidden_objects": [object_ids to unhide],
    "discover_clues": [clue_ids that are discovered by this action],
    "clue_notebook_entries": [{"clue_id": id, "entry_text": "what the player learned", "entry_type": "clue/observation/deduction", "source": "where/how they learned it"}]
}
PROMPT;

    $result = $claude->sendJson($systemPrompt, "Player action: " . $actionText);

    if (isset($result['error'])) {
        throw new Exception('AI error: ' . $result['error']);
    }

    $response = $result['data'];

    // Process the action results
    $narrative = $response['narrative'] ?? 'Nothing happens.';

    // Move player
    if ($response['action_type'] === 'move' && !empty($response['move_to'])) {
        $targetExit = null;
        foreach ($exits as $exit) {
            if (strcasecmp($exit['name'], $response['move_to']) === 0) {
                $targetExit = $exit;
                break;
            }
        }
        if ($targetExit && !$targetExit['is_locked']) {
            // Move player
            $stmt = $pdo->prepare("UPDATE player_state SET current_location_id = ?, moves_taken = moves_taken + 1 WHERE game_id = ?");
            $stmt->execute([$targetExit['id'], $gameId]);

            // Discover new location
            $stmt = $pdo->prepare("UPDATE locations SET discovered = 1 WHERE id = ? AND game_id = ?");
            $stmt->execute([$targetExit['id'], $gameId]);

            // Also discover adjacent locations on the map
            $stmt = $pdo->prepare("
                SELECT to_location_id FROM location_connections WHERE from_location_id = ? AND game_id = ?
            ");
            $stmt->execute([$targetExit['id'], $gameId]);
            $adjacent = $stmt->fetchAll();
            foreach ($adjacent as $adj) {
                $stmt2 = $pdo->prepare("UPDATE locations SET discovered = 1 WHERE id = ? AND game_id = ?");
                $stmt2->execute([$adj['to_location_id'], $gameId]);
            }
        }
    }

    // Pick up objects - support both single ID (legacy) and array of IDs
    $pickupIds = [];
    if (!empty($response['pickup_object_ids'])) {
        $pickupIds = is_array($response['pickup_object_ids']) ? $response['pickup_object_ids'] : [$response['pickup_object_ids']];
    } elseif (!empty($response['pickup_object_id'])) {
        $pickupIds = [$response['pickup_object_id']];
    }

    if (!empty($pickupIds)) {
        foreach ($pickupIds as $pickupId) {
            // If Claude returned a name instead of an ID, look it up
            if (!is_numeric($pickupId)) {
                $stmt = $pdo->prepare("SELECT id FROM objects WHERE game_id = ? AND location_id = ? AND is_pickupable = 1 AND name LIKE ?");
                $stmt->execute([$gameId, $location['id'], '%' . $pickupId . '%']);
                $found = $stmt->fetch();
                $pickupId = $found ? $found['id'] : null;
            }
            if ($pickupId) {
                $stmt = $pdo->prepare("UPDATE objects SET location_id = NULL, character_id = NULL WHERE id = ? AND game_id = ? AND is_pickupable = 1");
                $stmt->execute([$pickupId, $gameId]);
            }
        }
    } elseif ($response['action_type'] === 'pickup') {
        // Claude said it's a pickup but didn't give IDs - try matching by action text
        $stmt = $pdo->prepare("SELECT id, name FROM objects WHERE game_id = ? AND location_id = ? AND is_pickupable = 1");
        $stmt->execute([$gameId, $location['id']]);
        $pickupables = $stmt->fetchAll();
        foreach ($pickupables as $obj) {
            if (stripos($actionText, $obj['name']) !== false || stripos($obj['name'], $actionText) !== false) {
                $stmt = $pdo->prepare("UPDATE objects SET location_id = NULL, character_id = NULL WHERE id = ? AND game_id = ?");
                $stmt->execute([$obj['id'], $gameId]);
            }
        }
    }

    // Reveal hidden objects
    if (!empty($response['reveal_hidden_objects'])) {
        foreach ($response['reveal_hidden_objects'] as $objId) {
            $stmt = $pdo->prepare("UPDATE objects SET is_hidden = 0 WHERE id = ? AND game_id = ?");
            $stmt->execute([$objId, $gameId]);
        }
    }

    // Discover clues
    if (!empty($response['discover_clues'])) {
        foreach ($response['discover_clues'] as $clueId) {
            $stmt = $pdo->prepare("UPDATE clues SET discovered = 1 WHERE id = ? AND game_id = ?");
            $stmt->execute([$clueId, $gameId]);
        }
    }

    // Add notebook entries
    if (!empty($response['clue_notebook_entries'])) {
        $nbStmt = $pdo->prepare("INSERT INTO notebook_entries (game_id, entry_text, entry_type, source, clue_id) VALUES (?, ?, ?, ?, ?)");
        $clueCheck = $pdo->prepare("SELECT id FROM clues WHERE id = ? AND game_id = ?");
        foreach ($response['clue_notebook_entries'] as $entry) {
            $clueId = isset($entry['clue_id']) && is_numeric($entry['clue_id']) && (int)$entry['clue_id'] > 0
                ? (int)$entry['clue_id'] : null;
            if ($clueId) {
                $clueCheck->execute([$clueId, $gameId]);
                if (!$clueCheck->fetch()) $clueId = null;
            }
            $nbStmt->execute([
                $gameId,
                $entry['entry_text'],
                $entry['entry_type'] ?? 'clue',
                $entry['source'] ?? null,
                $clueId
            ]);
        }
    }

    // Move characters
    if (!empty($response['move_characters'])) {
        $moveCharStmt = $pdo->prepare("UPDATE characters_game SET location_id = ? WHERE id = ? AND game_id = ?");
        foreach ($response['move_characters'] as $move) {
            $charId = $move['character_id'] ?? null;
            $toLocId = $move['to_location_id'] ?? null;
            if ($charId && $toLocId) {
                $moveCharStmt->execute([$toLocId, $charId, $gameId]);
            }
        }
    }

    // Increment moves
    if ($response['action_type'] !== 'move') {
        $stmt = $pdo->prepare("UPDATE player_state SET moves_taken = moves_taken + 1 WHERE game_id = ?");
        $stmt->execute([$gameId]);
    }

    // Log action
    $stmt = $pdo->prepare("INSERT INTO action_log (game_id, action_text, result_text, action_type) VALUES (?, ?, ?, ?)");
    $stmt->execute([$gameId, $actionText, $narrative, $response['action_type'] ?? 'other']);

    echo json_encode([
        'success' => true,
        'narrative' => $narrative,
        'action_type' => $response['action_type'] ?? 'other',
        'allowed' => $response['allowed'] ?? true
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
