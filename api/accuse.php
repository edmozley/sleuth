<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';

try {
    $profileId = Auth::requireProfile();
    $input = json_decode(file_get_contents('php://input'), true);
    $gameId = (int)($input['game_id'] ?? 0);
    $accusedId = (int)($input['accused_id'] ?? 0);
    $weaponId = (int)($input['weapon_id'] ?? 0);

    if (!$gameId || !$accusedId || !$weaponId) {
        throw new Exception('You must select both a suspect and a weapon');
    }

    $pdo = Database::getConnection();

    // Get player state
    $stmt = $pdo->prepare("SELECT * FROM player_state WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $player = $stmt->fetch();
    if (!$player) throw new Exception('Game not found');

    // Validate accused: must be a met character
    $stmt = $pdo->prepare("SELECT id, name FROM characters_game WHERE id = ? AND game_id = ? AND has_met = 1 AND role != 'victim'");
    $stmt->execute([$accusedId, $gameId]);
    $accused = $stmt->fetch();
    if (!$accused) throw new Exception('You can only accuse someone you have spoken to');

    // Validate weapon: must be in player inventory
    $stmt = $pdo->prepare("SELECT id, name FROM objects WHERE id = ? AND game_id = ? AND location_id IS NULL AND character_id IS NULL AND is_pickupable = 1");
    $stmt->execute([$weaponId, $gameId]);
    $weapon = $stmt->fetch();
    if (!$weapon) throw new Exception('The weapon must be in your inventory');

    $accusedName = $accused['name'];
    $weaponName = $weapon['name'];

    if ($player['accusations_remaining'] <= 0) {
        $stmt = $pdo->prepare("UPDATE games SET status = 'lost' WHERE id = ?");
        $stmt->execute([$gameId]);

        $stmt = $pdo->prepare("SELECT * FROM plots WHERE game_id = ?");
        $stmt->execute([$gameId]);
        $plot = $stmt->fetch();

        echo json_encode([
            'success' => true,
            'correct' => false,
            'game_over' => true,
            'message' => "You've run out of accusations. The case goes cold.",
            'solution' => [
                'killer' => $plot['killer_name'],
                'weapon' => $plot['weapon'],
                'motive' => $plot['motive']
            ]
        ]);
        return;
    }

    // Get the solution
    $stmt = $pdo->prepare("SELECT * FROM plots WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $plot = $stmt->fetch();

    // Check accusation - killer, weapon, AND motive (if available) must be correct
    $killerCorrect = (strcasecmp($accusedName, $plot['killer_name']) === 0);

    // Weapon check: use definitive weapon_object_id if available, else fuzzy match
    if (!empty($plot['weapon_object_id'])) {
        $weaponCorrect = ($weaponId === (int)$plot['weapon_object_id']);
    } else {
        // Fuzzy fallback for older games without weapon_object_id
        $wLower = strtolower($weaponName);
        $pLower = strtolower($plot['weapon']);
        $weaponCorrect = ($wLower === $pLower)
            || (strpos($pLower, $wLower) !== false)
            || (strpos($wLower, $pLower) !== false);
        if (!$weaponCorrect) {
            $stopWords = ['a','an','the','of','with','and','in','on','old','small','large','broken'];
            $wWords = array_diff(preg_split('/[\s\-_]+/', $wLower), $stopWords);
            $pWords = array_diff(preg_split('/[\s\-_]+/', $pLower), $stopWords);
            $common = array_intersect($wWords, $pWords);
            $weaponCorrect = count($common) > 0 && count($common) >= min(count($wWords), count($pWords)) * 0.5;
        }
    }

    // Motive check: per-character motives
    $motiveCorrect = true; // Default true for games without motives yet
    $hasMotives = false;

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM character_motives WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $hasMotives = (int)$stmt->fetchColumn() > 0;

    if ($hasMotives) {
        $motiveId = isset($input['motive_id']) ? (int)$input['motive_id'] : 0;
        if ($motiveId > 0) {
            // Validate the motive belongs to the accused character in this game
            $stmt = $pdo->prepare("SELECT is_correct FROM character_motives WHERE id = ? AND game_id = ? AND character_id = ?");
            $stmt->execute([$motiveId, $gameId, $accusedId]);
            $motiveRow = $stmt->fetch();
            if ($motiveRow) {
                $motiveCorrect = (bool)$motiveRow['is_correct'];
            } else {
                $motiveCorrect = false;
            }
        } else {
            $motiveCorrect = false;
        }
    }

    $fullyCorrect = $killerCorrect && $weaponCorrect && $motiveCorrect;

    if ($fullyCorrect) {
        // Won!
        $stmt = $pdo->prepare("UPDATE games SET status = 'won' WHERE id = ?");
        $stmt->execute([$gameId]);
        $stmt = $pdo->prepare("UPDATE player_state SET game_phase = 'resolved' WHERE game_id = ?");
        $stmt->execute([$gameId]);

        $stmt = $pdo->prepare("INSERT INTO action_log (game_id, action_text, result_text, action_type) VALUES (?, ?, ?, 'accusation')");
        $stmt->execute([$gameId, "Accused: $accusedName with $weaponName", "CORRECT! Case solved."]);

        $winMsg = "Brilliant detective work! You correctly identified {$plot['killer_name']} as the killer with the {$plot['weapon']}.";
        if ($hasMotives) {
            $winMsg = "Brilliant detective work! You correctly identified {$plot['killer_name']} as the killer, the {$plot['weapon']} as the murder weapon, and deduced the true motive.";
        }

        echo json_encode([
            'success' => true,
            'correct' => true,
            'game_over' => true,
            'message' => $winMsg,
            'solution' => [
                'killer' => $plot['killer_name'],
                'weapon' => $plot['weapon'],
                'motive' => $plot['motive'],
                'backstory' => $plot['backstory']
            ],
            'moves_taken' => $player['moves_taken'],
            'probes_used' => 5 - ($player['probes_remaining'] ?? 5)
        ]);
    } else {
        // Wrong - decrement accusations
        $remaining = $player['accusations_remaining'] - 1;
        $stmt = $pdo->prepare("UPDATE player_state SET accusations_remaining = ? WHERE game_id = ?");
        $stmt->execute([$remaining, $gameId]);

        // Build feedback message with hints about what's right/wrong
        $correctParts = [];
        $wrongParts = [];
        if ($killerCorrect) $correctParts[] = 'suspect';
        else $wrongParts[] = 'suspect';
        if ($weaponCorrect) $correctParts[] = 'weapon';
        else $wrongParts[] = 'weapon';
        if ($hasMotives) {
            if ($motiveCorrect) $correctParts[] = 'motive';
            else $wrongParts[] = 'motive';
        }

        if (count($correctParts) === 0) {
            $feedback = "Your accusation was completely wrong.";
        } elseif (count($wrongParts) === 1) {
            $wrongLabel = $wrongParts[0];
            if ($wrongLabel === 'suspect') $feedback = "You may have the right weapon" . ($hasMotives && $motiveCorrect ? " and motive" : "") . ", but accused the wrong person.";
            elseif ($wrongLabel === 'weapon') $feedback = "You may be onto the right person" . ($hasMotives && $motiveCorrect ? " with the right motive" : "") . ", but that's not the murder weapon.";
            else $feedback = "You identified the killer and weapon correctly, but the motive is wrong.";
        } else {
            if (count($correctParts)) {
                $feedback = "You got the " . implode(' and ', $correctParts) . " right, but the " . implode(' and ', $wrongParts) . (count($wrongParts) > 1 ? " are wrong." : " is wrong.");
            } else {
                $feedback = "Your accusation was completely wrong.";
            }
        }
        $feedback .= " You have $remaining accusation(s) remaining.";

        $stmt = $pdo->prepare("INSERT INTO action_log (game_id, action_text, result_text, action_type) VALUES (?, ?, ?, 'accusation')");
        $stmt->execute([$gameId, "Accused: $accusedName with $weaponName", "Wrong. $remaining attempts remaining."]);

        $gameOver = ($remaining <= 0);
        if ($gameOver) {
            $stmt = $pdo->prepare("UPDATE games SET status = 'lost' WHERE id = ?");
            $stmt->execute([$gameId]);
        }

        echo json_encode([
            'success' => true,
            'correct' => false,
            'game_over' => $gameOver,
            'message' => $feedback,
            'accusations_remaining' => $remaining,
            'solution' => $gameOver ? [
                'killer' => $plot['killer_name'],
                'weapon' => $plot['weapon'],
                'motive' => $plot['motive']
            ] : null
        ]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
