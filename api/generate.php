<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Claude.php';
require_once __DIR__ . '/../includes/MotiveCategories.php';
require_once __DIR__ . '/../includes/Auth.php';

try {
    $profileId = Auth::requireProfile();
    $config = Database::getConfig();
    if (empty($config['anthropic_api_key'])) {
        throw new Exception('Anthropic API key not configured');
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $step = $input['step'] ?? 'all';
    $theme = trim($input['theme'] ?? '');
    $gameId = (int)($input['game_id'] ?? 0);

    $claude = new Claude($config['anthropic_api_key']);
    $pdo = Database::getConnection();

    // Add randomness
    $settings = ['mansion', 'hotel', 'cruise ship', 'village', 'theatre', 'university', 'hospital', 'art gallery', 'ski lodge', 'vineyard', 'casino', 'monastery', 'train', 'island resort', 'embassy', 'film set', 'opera house', 'bazaar', 'circus', 'palace', 'cathedral', 'harbour', 'marketplace', 'bathhouse', 'fortress', 'tavern', 'plantation', 'trading post', 'temple'];
    $motives = ['jealousy', 'greed', 'revenge', 'betrayal', 'blackmail', 'inheritance', 'love triangle', 'cover-up', 'power struggle', 'obsession', 'political conspiracy', 'religious fanaticism', 'stolen treasure', 'forbidden knowledge', 'family honour'];
    $countries = ['England', 'France', 'Italy', 'Spain', 'Japan', 'Egypt', 'India', 'China', 'Greece', 'Turkey', 'Morocco', 'Russia', 'Brazil', 'Mexico', 'Persia', 'Scotland', 'Germany', 'Austria', 'Portugal', 'the Netherlands', 'Sweden', 'Ireland', 'Argentina', 'Cuba', 'Thailand', 'Kenya', 'South Africa'];
    $centuries = ['16th century', '17th century', '18th century', '19th century', 'early 20th century', '1920s', '1930s', '1940s', '1950s', '1960s', '1970s', 'present day'];
    $seed = bin2hex(random_bytes(4));

    $themeInstruction = $theme
        ? "The player has requested a specific setting/theme: \"{$theme}\". Incorporate this into the mystery."
        : "Choose an interesting and varied setting.";

    $userSeed = $theme
        ? "Theme: {$theme}. Seed: {$seed}"
        : "Setting: {$centuries[array_rand($centuries)]} {$countries[array_rand($countries)]}, in a {$settings[array_rand($settings)]}. Motive: {$motives[array_rand($motives)]}. Seed: {$seed}";

    // ========== STEP 1: PLOT (title, setting, backstory, victim, killer, weapon, motive) ==========
    if ($step === 'plot') {
        $prompt = <<<PROMPT
You are a murder mystery game designer. Generate the core plot for a murder mystery.

{$themeInstruction}

Return JSON:
{
    "title": "compelling title",
    "setting_description": "brief atmospheric description of the overall setting",
    "time_period": "e.g. 1920s, present day, Victorian era",
    "backstory": "the hidden true story of what happened and why (2-3 sentences)",
    "victim": {
        "name": "full name",
        "description": "physical appearance (1 sentence)",
        "personality": "key traits",
        "backstory": "their background (1-2 sentences)",
        "location": "where the body is found (a location name)"
    },
    "killer": {
        "name": "full name",
        "description": "physical appearance (1 sentence)",
        "personality": "key traits",
        "backstory": "their background (1-2 sentences)",
        "secrets": "what they are hiding",
        "knowledge": "what they know about the crime",
        "alibi": "their claimed alibi",
        "location": "where they can initially be found"
    },
    "weapon": "the murder weapon",
    "motive": "why the killer did it (1 sentence)"
}
PROMPT;
        $result = $claude->sendJson($prompt, $userSeed, 1.0, 4096);
        if (isset($result['error'])) {
            echo json_encode(['success' => false, 'error' => 'Plot generation failed: ' . $result['error'], 'raw' => $result['raw'] ?? null]);
            exit;
        }
        $plot = $result['data'];

        // Create game record
        $stmt = $pdo->prepare("INSERT INTO games (title, status, profile_id) VALUES (?, 'generating', ?)");
        $stmt->execute([$plot['title'], $profileId]);
        $newGameId = (int)$pdo->lastInsertId();

        $timePeriod = $plot['time_period'] ?? 'present day';
        $artStyle = "Dark, moody, atmospheric illustration in the style of {$timePeriod} art. Setting: {$plot['setting_description']}. Painterly, dramatic lighting, rich detail, noir aesthetic.";

        // Insert plot first (without motive_options)
        $stmt = $pdo->prepare("INSERT INTO plots (game_id, victim_name, killer_name, weapon, motive, backstory, setting_description, time_period, art_style) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$newGameId, $plot['victim']['name'], $plot['killer']['name'], $plot['weapon'], $plot['motive'], $plot['backstory'], $plot['setting_description'], $timePeriod, $artStyle]);

        // Generate motive options using 3-step approach (AI categorises, PHP picks decoys, AI writes decoy texts)
        $plotForMotives = [
            'setting_description' => $plot['setting_description'],
            'time_period' => $timePeriod,
            'victim_name' => $plot['victim']['name'],
            'killer_name' => $plot['killer']['name'],
            'weapon' => $plot['weapon'],
            'motive' => $plot['motive'],
            'backstory' => $plot['backstory']
        ];
        try {
            $motives = generateMotiveOptions($claude, $plotForMotives);
            $stmt = $pdo->prepare("UPDATE plots SET motive_options = ? WHERE game_id = ?");
            $stmt->execute([json_encode($motives), $newGameId]);
            $plot['motive_options'] = $motives;
        } catch (Exception $e) {
            // Non-fatal: game still works without motives, debug page can regenerate
            $plot['motive_options'] = null;
        }

        echo json_encode(['success' => true, 'game_id' => $newGameId, 'title' => $plot['title'], 'plot' => $plot]);
        exit;
    }

    // For subsequent steps, game_id is required
    if (!$gameId) throw new Exception('Missing game_id for step: ' . $step);

    // Load plot context for subsequent steps
    $stmt = $pdo->prepare("SELECT p.*, g.title FROM plots p JOIN games g ON g.id = p.game_id WHERE p.game_id = ?");
    $stmt->execute([$gameId]);
    $plotRow = $stmt->fetch();
    if (!$plotRow) throw new Exception('Game not found');

    $plotContext = "Title: {$plotRow['title']}. Setting: {$plotRow['setting_description']}. Time: {$plotRow['time_period']}. Victim: {$plotRow['victim_name']}. Killer: {$plotRow['killer_name']}. Weapon: {$plotRow['weapon']}. Motive: {$plotRow['motive']}. Backstory: {$plotRow['backstory']}.";

    // ========== STEP 2: LOCATIONS ==========
    if ($step === 'locations') {
        $prompt = <<<PROMPT
You are designing locations for a murder mystery text adventure game.

PLOT CONTEXT:
{$plotContext}

Create 6-8 locations that connect logically as a map of a real place. Include where the body is found and where suspects can be found.

Think of the map as a floor plan of a real building/place. Design it like an architect would:
- A central hub (hallway, lobby, corridor) that connects to multiple rooms
- Rooms branch off the hub — kitchens next to dining rooms, bedrooms near bathrooms
- Outdoor areas (gardens, courtyards) on the edges, connecting to the building via doors
- If there are upper floors, you MUST include a staircase/landing/hallway room and use up/down ONLY from that transitional room

CONSTRAINTS:
1. Connections must be bidirectional. If Room A connects east to Room B, Room B MUST connect west to Room A.
2. No duplicate directions from the same room. Each direction leads to only ONE place.
3. Directions must make spatial sense: north/south/east/west are same-floor, up/down are between floors.
4. Indoor/outdoor logic: outdoor areas on the perimeter, not sandwiched between indoor rooms.
5. STRICT floor logic (very important):
   - Assign every room a floor: "ground" or "upper" (or "basement"/"outdoor").
   - Ground-floor rooms may ONLY connect horizontally (north/south/east/west) to OTHER ground-floor rooms.
   - Upper-floor rooms may ONLY connect horizontally to OTHER upper-floor rooms.
   - The ONLY way to connect different floors is via "up"/"down" directions.
   - "up"/"down" connections MUST go through a transitional room — a staircase, landing, hallway, or corridor. You CANNOT go "up" from a Drawing Room directly to a Bedroom. Instead: Drawing Room → east → Main Hallway → up → Upper Landing → east → Bedroom.
   - If any room uses "up"/"down", there MUST be a room that logically contains stairs (e.g. "Main Hallway", "Staircase", "Landing") at one or both ends.
   - A terrace or balcony is an upper-floor outdoor area — it connects horizontally to upper-floor rooms only.
   - Rooms on different floors NEVER connect horizontally.
6. Every location must be reachable from every other.
7. Create an interesting graph, NOT a straight line. At least one hub room with 3+ connections. Most rooms should have 2-3 connections.

Return JSON:
{
    "locations": [
        {
            "name": "string",
            "description": "full atmospheric description (2-3 sentences)",
            "short_description": "one line summary",
            "x_pos": 0,
            "y_pos": 0,
            "z_pos": 0,
            "connections": [{"to": "other location name", "direction": "north/south/east/west/up/down"}]
        }
    ],
    "starting_location": "name of location the player starts in"
}

COORDINATES — use three axes:
- x: 0-4 (east = higher x). Rooms connected east/west must differ in x.
- y: 0-4 (south = higher y). Rooms connected north/south must differ in y.
- z_pos: the floor level. 0 = ground floor, 1 = upper floor, -1 = basement/cellar. Outdoor areas at ground level use z_pos 0.
- Rooms connected by up/down MUST share the same x,y but differ in z_pos.
- Rooms connected horizontally (north/south/east/west) MUST have the same z_pos.

COMMON-SENSE FLOOR DEFAULTS:
- Bedrooms, bathrooms, balconies, terraces → usually upper floor (z_pos: 1)
- Kitchens, dining rooms, lounges, lobbies, hallways, studies → usually ground floor (z_pos: 0)
- Cellars, wine caves, dungeons, crypts, basements → below ground (z_pos: -1)
- Gardens, courtyards, paths, grounds → ground level (z_pos: 0)

EVERY connection listed must have its reverse on the other location. Do not skip any.
PROMPT;
        $result = $claude->sendJson($prompt, "Generate locations for this mystery. Seed: {$seed}", 0.8, 4096);
        if (isset($result['error'])) {
            echo json_encode(['success' => false, 'error' => 'Location generation failed: ' . $result['error'], 'raw' => $result['raw'] ?? null]);
            exit;
        }
        $data = $result['data'];

        // Store locations
        $locationIds = [];
        $stmt = $pdo->prepare("INSERT INTO locations (game_id, name, description, short_description, x_pos, y_pos, z_pos, discovered) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $startingLocation = $data['starting_location'] ?? $data['locations'][0]['name'];

        foreach ($data['locations'] as $loc) {
            $isStart = ($loc['name'] === $startingLocation) ? 1 : 0;
            $stmt->execute([$gameId, $loc['name'], $loc['description'], $loc['short_description'], $loc['x_pos'] ?? 0, $loc['y_pos'] ?? 0, $loc['z_pos'] ?? 0, $isStart]);
            $locationIds[$loc['name']] = (int)$pdo->lastInsertId();
        }

        // --- Validate and repair connections before storing ---
        $opposites = [
            'north' => 'south', 'south' => 'north',
            'east' => 'west', 'west' => 'east',
            'up' => 'down', 'down' => 'up',
            'northeast' => 'southwest', 'southwest' => 'northeast',
            'northwest' => 'southeast', 'southeast' => 'northwest'
        ];

        // Collect all connections and floor levels
        $allConns = [];
        $roomZ = [];
        foreach ($data['locations'] as $loc) {
            $allConns[$loc['name']] = [];
            $roomZ[$loc['name']] = (int)($loc['z_pos'] ?? 0);
            foreach ($loc['connections'] ?? [] as $conn) {
                if (isset($locationIds[$conn['to']])) {
                    $allConns[$loc['name']][] = ['to' => $conn['to'], 'direction' => strtolower($conn['direction'])];
                }
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
                        return false;
                    }
                    if ($fromZ === $toZ && in_array($c['direction'], $verticalDirs)) {
                        return false;
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
                $reverseDir = $opposites[$c['direction']] ?? null;
                if (!$reverseDir) continue;
                // Check if reverse exists
                $hasReverse = false;
                foreach ($allConns[$c['to']] ?? [] as $rc) {
                    if ($rc['to'] === $from && $rc['direction'] === $reverseDir) {
                        $hasReverse = true;
                        break;
                    }
                }
                if (!$hasReverse) {
                    // Check the target doesn't already use that direction for something else
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

        // Store validated connections
        $connStmt = $pdo->prepare("INSERT INTO location_connections (game_id, from_location_id, to_location_id, direction) VALUES (?, ?, ?, ?)");
        foreach ($allConns as $from => $conns) {
            $fromId = $locationIds[$from] ?? null;
            if (!$fromId) continue;
            foreach ($conns as $c) {
                $toId = $locationIds[$c['to']] ?? null;
                if ($toId) {
                    $connStmt->execute([$gameId, $fromId, $toId, $c['direction']]);
                }
            }
        }

        echo json_encode(['success' => true, 'locations' => array_keys($locationIds)]);
        exit;
    }

    // Helper: load locations for fuzzy matching
    $stmt = $pdo->prepare("SELECT id, name FROM locations WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $locationIds = [];
    foreach ($stmt->fetchAll() as $loc) {
        $locationIds[$loc['name']] = (int)$loc['id'];
    }
    $locationNames = implode(', ', array_keys($locationIds));

    $findLocationId = function($name) use ($locationIds) {
        if (!$name) return null;
        if (isset($locationIds[$name])) return $locationIds[$name];
        foreach ($locationIds as $locName => $locId) {
            if (strcasecmp($locName, $name) === 0) return $locId;
        }
        foreach ($locationIds as $locName => $locId) {
            if (stripos($locName, $name) !== false || stripos($name, $locName) !== false) return $locId;
        }
        return null;
    };

    // ========== STEP 3: CHARACTERS ==========
    if ($step === 'characters') {
        $prompt = <<<PROMPT
You are creating characters for a murder mystery game.

PLOT CONTEXT:
{$plotContext}

AVAILABLE LOCATIONS: {$locationNames}

Create 3-5 additional characters (suspects, witnesses, bystanders). The victim and killer are already created.
Each character needs a location from the list above.

Return JSON:
{
    "characters": [
        {
            "name": "full name",
            "description": "physical appearance (1 sentence)",
            "personality": "key personality traits",
            "backstory": "their background (1-2 sentences)",
            "role": "suspect/witness/bystander",
            "secrets": "what they are hiding",
            "knowledge": "what they know or saw relevant to the crime",
            "location": "exact location name from the available locations"
        }
    ]
}
PROMPT;
        // Also pass victim/killer info from the plot row
        $result = $claude->sendJson($prompt, "Create characters. Victim: {$plotRow['victim_name']}, Killer: {$plotRow['killer_name']}. Seed: {$seed}", 0.8, 4096);
        if (isset($result['error'])) {
            echo json_encode(['success' => false, 'error' => 'Character generation failed: ' . $result['error'], 'raw' => $result['raw'] ?? null]);
            exit;
        }
        $data = $result['data'];

        // We need victim and killer details - fetch from the plot step input data
        // Since we stored them in plots table, reconstruct from there
        $charStmt = $pdo->prepare("INSERT INTO characters_game (game_id, name, description, personality, backstory, secrets, knowledge, role, location_id, is_alive) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        // Victim (from input data passed by frontend)
        $victimData = $input['victim'] ?? null;
        if ($victimData) {
            $charStmt->execute([$gameId, $victimData['name'], $victimData['description'], $victimData['personality'], $victimData['backstory'], '', '', 'victim', $findLocationId($victimData['location']), 0]);
        }

        // Killer
        $killerData = $input['killer'] ?? null;
        if ($killerData) {
            $charStmt->execute([$gameId, $killerData['name'], $killerData['description'], $killerData['personality'], $killerData['backstory'], $killerData['secrets'], $killerData['knowledge'], 'killer', $findLocationId($killerData['location']), 1]);
        }

        // Other characters
        $charIds = [];
        foreach ($data['characters'] as $char) {
            $role = $char['role'] ?? 'suspect';
            if (!in_array($role, ['suspect', 'witness', 'bystander'])) $role = 'suspect';
            $charStmt->execute([$gameId, $char['name'], $char['description'], $char['personality'], $char['backstory'], $char['secrets'] ?? '', $char['knowledge'] ?? '', $role, $findLocationId($char['location']), 1]);
            $charIds[$char['name']] = (int)$pdo->lastInsertId();
        }

        echo json_encode(['success' => true, 'character_count' => count($data['characters']) + 2]);
        exit;
    }

    // ========== STEP 4: OBJECTS ==========
    if ($step === 'objects') {
        // Load character names for context
        $stmt = $pdo->prepare("SELECT name, role FROM characters_game WHERE game_id = ?");
        $stmt->execute([$gameId]);
        $charNames = [];
        foreach ($stmt->fetchAll() as $c) { $charNames[] = $c['name'] . ' (' . $c['role'] . ')'; }
        $charNamesStr = implode(', ', $charNames);

        $prompt = <<<PROMPT
You are creating objects for a murder mystery game.

PLOT CONTEXT:
{$plotContext}

AVAILABLE LOCATIONS: {$locationNames}
CHARACTERS: {$charNamesStr}

Create 8-15 objects including containers and nested items. For example: a desk that contains a drawer, the drawer contains a letter. Use "contained_in" to reference the parent object by name. Top-level objects have contained_in as null.
Hidden nested objects are only discoverable after the player interacts with the parent.
Include the murder weapon and key evidence items. Mark EXACTLY ONE object as "is_weapon": true — this must be the actual murder weapon used to kill the victim.

Return JSON:
{
    "objects": [
        {
            "name": "object name",
            "description": "what you see when looking at it (1 sentence)",
            "inspect_text": "what detailed examination reveals (1-2 sentences)",
            "location": "exact location name from available locations",
            "contained_in": "parent object name or null",
            "is_pickupable": true,
            "is_hidden": false,
            "is_evidence": false,
            "is_weapon": false
        }
    ]
}
PROMPT;
        $result = $claude->sendJson($prompt, "Create objects and evidence. Seed: {$seed}", 0.8, 6144);
        if (isset($result['error'])) {
            echo json_encode(['success' => false, 'error' => 'Object generation failed: ' . $result['error'], 'raw' => $result['raw'] ?? null]);
            exit;
        }
        $data = $result['data'];

        // Insert objects
        $objectIds = [];
        $objectParents = [];
        $weaponObjectId = null;
        $objStmt = $pdo->prepare("INSERT INTO objects (game_id, name, description, inspect_text, location_id, original_location_id, is_pickupable, is_hidden, original_is_hidden, is_evidence, is_weapon) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        foreach ($data['objects'] as $obj) {
            $objLocId = $findLocationId($obj['location'] ?? null);
            $hidden = !empty($obj['is_hidden']) ? 1 : 0;
            $isWeapon = !empty($obj['is_weapon']) ? 1 : 0;
            $objStmt->execute([
                $gameId, $obj['name'], $obj['description'], $obj['inspect_text'] ?? null,
                $objLocId, $objLocId,
                !empty($obj['is_pickupable']) ? 1 : 0, $hidden, $hidden,
                !empty($obj['is_evidence']) ? 1 : 0,
                $isWeapon
            ]);
            $objId = (int)$pdo->lastInsertId();
            $objectIds[$obj['name']] = $objId;
            if ($isWeapon) $weaponObjectId = $objId;
            if (!empty($obj['contained_in'])) {
                $objectParents[$objId] = $obj['contained_in'];
            }
        }

        // Link weapon object to plot
        if ($weaponObjectId) {
            $pdo->prepare("UPDATE plots SET weapon_object_id = ? WHERE game_id = ?")->execute([$weaponObjectId, $gameId]);
        }

        // Link parents
        if (!empty($objectParents)) {
            $parentStmt = $pdo->prepare("UPDATE objects SET parent_object_id = ? WHERE id = ? AND game_id = ?");
            $locFixStmt = $pdo->prepare("UPDATE objects SET location_id = ?, original_location_id = ? WHERE id = ? AND game_id = ?");
            foreach ($objectParents as $childId => $parentName) {
                $parentId = $objectIds[$parentName] ?? null;
                if (!$parentId) {
                    foreach ($objectIds as $oName => $oId) {
                        if (strcasecmp($oName, $parentName) === 0 || stripos($oName, $parentName) !== false || stripos($parentName, $oName) !== false) {
                            $parentId = $oId;
                            break;
                        }
                    }
                }
                if ($parentId) {
                    $parentStmt->execute([$parentId, $childId, $gameId]);
                    $pStmt = $pdo->prepare("SELECT location_id FROM objects WHERE id = ?");
                    $pStmt->execute([$parentId]);
                    $parentLoc = $pStmt->fetchColumn();
                    if ($parentLoc) {
                        $locFixStmt->execute([$parentLoc, $parentLoc, $childId, $gameId]);
                    }
                }
            }
        }

        echo json_encode(['success' => true, 'object_count' => count($data['objects'])]);
        exit;
    }

    // ========== STEP 5: CLUES + FINALIZE ==========
    if ($step === 'clues') {
        // Load objects and characters for context
        $stmt = $pdo->prepare("SELECT id, name FROM objects WHERE game_id = ?");
        $stmt->execute([$gameId]);
        $objectIds = [];
        foreach ($stmt->fetchAll() as $o) { $objectIds[$o['name']] = (int)$o['id']; }
        $objectNames = implode(', ', array_keys($objectIds));

        $stmt = $pdo->prepare("SELECT id, name FROM characters_game WHERE game_id = ?");
        $stmt->execute([$gameId]);
        $charIds = [];
        foreach ($stmt->fetchAll() as $c) { $charIds[$c['name']] = (int)$c['id']; }
        $charNamesStr = implode(', ', array_keys($charIds));

        $prompt = <<<PROMPT
You are creating clues for a murder mystery game.

PLOT CONTEXT:
{$plotContext}

LOCATIONS: {$locationNames}
CHARACTERS: {$charNamesStr}
OBJECTS: {$objectNames}

Create 8-12 clues that help the player solve the mystery. Include:
- 2-3 critical clues (essential to solve the case)
- 3-4 major clues (important supporting evidence)
- 2-3 minor clues (helpful context)
- 1-2 red herrings (misleading)

Each clue must link to an existing object, character, or location by exact name.

Return JSON:
{
    "clues": [
        {
            "description": "what the clue reveals (1-2 sentences)",
            "category": "physical/testimony/document/observation/forensic",
            "importance": "critical/major/minor/red_herring",
            "discovery_method": "how the player discovers this (e.g. 'inspect the diary', 'talk to the butler', 'search the garden')",
            "linked_to_type": "object/character/location",
            "linked_to_name": "exact name of the linked entity"
        }
    ]
}
PROMPT;
        $result = $claude->sendJson($prompt, "Create clues. Seed: {$seed}", 0.8, 4096);
        if (isset($result['error'])) {
            echo json_encode(['success' => false, 'error' => 'Clue generation failed: ' . $result['error'], 'raw' => $result['raw'] ?? null]);
            exit;
        }
        $data = $result['data'];

        // Insert clues
        $clueStmt = $pdo->prepare("INSERT INTO clues (game_id, description, category, importance, discovery_method, linked_object_id, linked_character_id, linked_location_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($data['clues'] as $clue) {
            $linkedObj = null; $linkedChar = null; $linkedLoc = null;
            $linkedType = $clue['linked_to_type'] ?? '';
            $linkedName = $clue['linked_to_name'] ?? '';

            if ($linkedType === 'object') $linkedObj = $objectIds[$linkedName] ?? null;
            elseif ($linkedType === 'character') $linkedChar = $charIds[$linkedName] ?? null;
            elseif ($linkedType === 'location') $linkedLoc = $findLocationId($linkedName);

            $clueStmt->execute([$gameId, $clue['description'], $clue['category'], $clue['importance'], $clue['discovery_method'] ?? null, $linkedObj, $linkedChar, $linkedLoc]);
        }

        // Create player state
        $stmt = $pdo->prepare("SELECT id FROM locations WHERE game_id = ? AND discovered = 1 LIMIT 1");
        $stmt->execute([$gameId]);
        $startLoc = $stmt->fetch();
        $startLocId = $startLoc ? $startLoc['id'] : null;

        $stmt = $pdo->prepare("INSERT INTO player_state (game_id, current_location_id, inventory) VALUES (?, ?, '[]')");
        $stmt->execute([$gameId, $startLocId]);

        // Build summary
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM characters_game WHERE game_id = ?");
        $stmt->execute([$gameId]);
        $charCount = (int)$stmt->fetchColumn();
        $locCount = count($locationIds);

        $summary = $plotRow['setting_description'] . ' ' . $plotRow['time_period'] . '. '
            . $plotRow['victim_name'] . ' has been found dead. '
            . $charCount . ' suspects, ' . $locCount . ' locations to explore.';

        $stmt = $pdo->prepare("UPDATE games SET status = 'active', summary = ? WHERE id = ?");
        $stmt->execute([$summary, $gameId]);

        // Generate cover image (non-blocking)
        $coverImage = null;
        $imageProvider = $config['image_provider'] ?? 'openai';
        $hasImageKey = ($imageProvider === 'venice' && !empty($config['venice_api_key']))
                    || ($imageProvider === 'openai' && !empty($config['openai_api_key']));
        if ($hasImageKey) {
            try {
                $imagePrompt = "Dark, moody, atmospheric painting of a mysterious scene. Setting: {$plotRow['setting_description']}. Style: oil painting, noir, dramatic lighting, cinematic composition, detailed brushwork, no people.";
                $coverImage = generateCoverImage($config, $imagePrompt, $gameId);
                if ($coverImage) {
                    $stmt = $pdo->prepare("UPDATE games SET cover_image = ? WHERE id = ?");
                    $stmt->execute([$coverImage, $gameId]);
                }
            } catch (Exception $e) {}
        }

        echo json_encode(['success' => true, 'clue_count' => count($data['clues']), 'title' => $plotRow['title']]);
        exit;
    }

    throw new Exception('Unknown step: ' . $step);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function generateCoverImage(array $config, string $prompt, int $gameId): ?string
{
    $imageProvider = $config['image_provider'] ?? 'openai';

    if ($imageProvider === 'venice') {
        $apiUrl = 'https://api.venice.ai/api/v1/images/generations';
        $apiKey = $config['venice_api_key'];
        $body = ['model' => 'fluently-xl', 'prompt' => $prompt, 'n' => 1, 'size' => '1024x1024'];
        $timeout = 120;
    } else {
        $apiUrl = 'https://api.openai.com/v1/images/generations';
        $apiKey = $config['openai_api_key'];
        $body = ['model' => 'dall-e-3', 'prompt' => $prompt, 'n' => 1, 'size' => '1024x1024', 'quality' => 'standard'];
        $timeout = 60;
    }

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) return null;

    $data = json_decode($response, true);

    // Venice returns b64_json, OpenAI returns url
    $b64 = $data['data'][0]['b64_json'] ?? null;
    $imageUrl = $data['data'][0]['url'] ?? null;

    if ($b64) {
        $imageData = base64_decode($b64);
    } elseif ($imageUrl) {
        $imageData = @file_get_contents($imageUrl);
    } else {
        return null;
    }

    if (!$imageData) return null;

    $dir = __DIR__ . '/../assets/covers';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $filename = 'cover_' . $gameId . '.png';
    file_put_contents($dir . '/' . $filename, $imageData);

    return 'assets/covers/' . $filename;
}
