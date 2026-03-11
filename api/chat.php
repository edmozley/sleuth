<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/Claude.php';

try {
    $profileId = Auth::requireProfile();
    $input = json_decode(file_get_contents('php://input'), true);
    $gameId = (int)($input['game_id'] ?? 0);
    $characterId = (int)($input['character_id'] ?? 0);
    $message = trim($input['message'] ?? '');
    $isProbe = !empty($input['probe']);

    if (!$gameId || !$characterId || !$message) {
        throw new Exception('Missing game_id, character_id, or message');
    }

    $config = Database::getConfig();
    $pdo = Database::getConnection();
    $claude = new Claude($config['anthropic_api_key']);

    // Get character details
    $stmt = $pdo->prepare("SELECT * FROM characters_game WHERE id = ? AND game_id = ?");
    $stmt->execute([$characterId, $gameId]);
    $character = $stmt->fetch();
    if (!$character) throw new Exception('Character not found');
    if (!$character['is_alive']) throw new Exception('You cannot talk to the deceased');

    // Get player state (for probe count)
    $stmt = $pdo->prepare("SELECT * FROM player_state WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $player = $stmt->fetch();

    if ($isProbe) {
        $probesLeft = $player['probes_remaining'] ?? 5;
        if ($probesLeft <= 0) {
            throw new Exception('No probes remaining');
        }
    }

    // Get plot details
    $stmt = $pdo->prepare("SELECT * FROM plots WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $plot = $stmt->fetch();

    // Get conversation history with this character (last 20 messages)
    $stmt = $pdo->prepare("
        SELECT role, message FROM chat_log
        WHERE game_id = ? AND character_id = ?
        ORDER BY created_at DESC LIMIT 20
    ");
    $stmt->execute([$gameId, $characterId]);
    $history = array_reverse($stmt->fetchAll());

    // Get clues this character is linked to
    $stmt = $pdo->prepare("
        SELECT id, description, importance, discovered, discovery_method
        FROM clues
        WHERE game_id = ? AND linked_character_id = ?
    ");
    $stmt->execute([$gameId, $characterId]);
    $characterClues = $stmt->fetchAll();

    // Get what the player already knows (notebook)
    $stmt = $pdo->prepare("SELECT entry_text FROM notebook_entries WHERE game_id = ? ORDER BY created_at ASC");
    $stmt->execute([$gameId]);
    $knownFacts = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $trustLevel = (int)($character['trust_level'] ?? 50);
    $trustDesc = $trustLevel >= 80 ? 'very trusting' : ($trustLevel >= 60 ? 'somewhat trusting' : ($trustLevel >= 40 ? 'neutral' : ($trustLevel >= 20 ? 'suspicious' : 'very guarded')));

    $clueInstructions = "";
    foreach ($characterClues as $clue) {
        if ($clue['discovered']) continue;
        $clueInstructions .= "- HIDDEN CLUE #{$clue['id']} ({$clue['importance']}): {$clue['description']} - Can be revealed by: {$clue['discovery_method']}\n";
    }

    $probeInstruction = "";
    if ($isProbe) {
        $probeInstruction = <<<PROBE

SPECIAL INSTRUCTION - PROBE:
The detective is pressing you HARD. This is an intense interrogation moment.
You MUST reveal something significant that you have been holding back - a secret, a piece of knowledge, or a hidden clue.
If you have unrevealed clues, reveal at least one now. If you have secrets, let something slip under pressure.
Show visible stress, guilt, or emotional reaction. The player has earned this information.
Even if you are the killer, you should let your mask slip slightly - show a contradiction or reveal something that could be used against you.
Do NOT simply repeat what you've already said. This must be NEW, meaningful information.
PROBE;
    }

    $systemPrompt = <<<PROMPT
You are role-playing as {$character['name']} in a murder mystery game.

YOUR CHARACTER:
- Name: {$character['name']}
- Description: {$character['description']}
- Personality: {$character['personality']}
- Backstory: {$character['backstory']}
- Secrets: {$character['secrets']}
- What you know: {$character['knowledge']}
- Role: {$character['role']}
- Current trust toward detective: {$trustLevel}/100 ({$trustDesc})

THE CRIME:
- Victim: {$plot['victim_name']}
- Weapon: {$plot['weapon']} (you don't volunteer this)
- Motive: {$plot['motive']} (you don't volunteer this)
- What happened: {$plot['backstory']}

CLUES YOU CAN REVEAL (only when the player asks the right questions or presses you):
{$clueInstructions}

TRUST SYSTEM:
Your trust level affects how forthcoming you are. At low trust (0-30), you are evasive, give short answers, and deflect. At medium trust (30-60), you are cautious but will share non-critical information. At high trust (60-100), you are open and may volunteer useful details.
Adjust "trust_change" in your response: positive (+5 to +15) if the player is respectful, empathetic, or shows they know relevant facts. Negative (-5 to -15) if they are aggressive, accusatory without evidence, or rude. Zero if neutral.

RULES:
1. Stay completely in character at all times
2. Speak in a way that reflects your personality and current trust level
3. NEVER reveal you are an AI - you ARE this character
4. If you're the killer, maintain your cover. Lie if needed but be subtly inconsistent
5. Don't volunteer critical information freely - make the player work for it
6. If pressed on something you know, show reluctance before revealing
7. React emotionally when appropriate (grief, nervousness, anger)
8. Keep responses conversational and concise (2-4 sentences usually)
9. If a hidden clue should be revealed based on the player's questioning, naturally work it into your response
{$probeInstruction}

Respond with JSON:
{
    "dialogue": "Your in-character response",
    "emotion": "current emotional state (calm/nervous/angry/sad/evasive/helpful/fearful)",
    "trust_change": 0,
    "reveal_clue_ids": [],
    "clue_notebook_entries": [{"clue_id": null, "entry_text": "what was learned", "entry_type": "testimony", "source": "{$character['name']}"}]
}
PROMPT;

    // Build messages array with history
    $messages = [];
    foreach ($history as $msg) {
        $messages[] = [
            'role' => $msg['role'] === 'player' ? 'user' : 'assistant',
            'content' => $msg['message']
        ];
    }
    $messages[] = ['role' => 'user', 'content' => $message];

    $result = $claude->sendJson($systemPrompt, json_encode(['conversation' => $messages, 'player_says' => $message]));

    if (isset($result['error'])) {
        throw new Exception('AI error: ' . $result['error']);
    }

    $response = $result['data'];

    // Mark character as met
    $stmt = $pdo->prepare("UPDATE characters_game SET has_met = 1 WHERE id = ? AND game_id = ?");
    $stmt->execute([$characterId, $gameId]);

    // Update trust level
    $trustChange = (int)($response['trust_change'] ?? 0);
    $trustChange = max(-15, min(15, $trustChange)); // clamp
    $newTrust = max(0, min(100, $trustLevel + $trustChange));
    $stmt = $pdo->prepare("UPDATE characters_game SET trust_level = ? WHERE id = ? AND game_id = ?");
    $stmt->execute([$newTrust, $characterId, $gameId]);

    // Decrement probe count if this was a probe
    if ($isProbe) {
        $pdo->prepare("UPDATE player_state SET probes_remaining = GREATEST(probes_remaining - 1, 0) WHERE game_id = ?")
            ->execute([$gameId]);
    }

    // Save chat messages
    $stmt = $pdo->prepare("INSERT INTO chat_log (game_id, character_id, role, message) VALUES (?, ?, 'player', ?)");
    $stmt->execute([$gameId, $characterId, $message]);

    $stmt = $pdo->prepare("INSERT INTO chat_log (game_id, character_id, role, message) VALUES (?, ?, 'character', ?)");
    $stmt->execute([$gameId, $characterId, $response['dialogue']]);

    // Discover clues if revealed
    if (!empty($response['reveal_clue_ids'])) {
        foreach ($response['reveal_clue_ids'] as $clueId) {
            $stmt = $pdo->prepare("UPDATE clues SET discovered = 1 WHERE id = ? AND game_id = ?");
            $stmt->execute([$clueId, $gameId]);
        }
    }

    // Add notebook entries
    if (!empty($response['clue_notebook_entries'])) {
        $nbStmt = $pdo->prepare("INSERT INTO notebook_entries (game_id, entry_text, entry_type, source, clue_id) VALUES (?, ?, ?, ?, ?)");
        $clueCheck = $pdo->prepare("SELECT id FROM clues WHERE id = ? AND game_id = ?");
        foreach ($response['clue_notebook_entries'] as $entry) {
            if (empty($entry['entry_text'])) continue;
            $clueId = $entry['clue_id'] ?? null;
            if ($clueId) {
                $clueCheck->execute([$clueId, $gameId]);
                if (!$clueCheck->fetch()) $clueId = null;
            }
            $entryType = $isProbe ? 'probe' : ($entry['entry_type'] ?? 'testimony');
            $nbStmt->execute([
                $gameId,
                $entry['entry_text'],
                $entryType,
                $entry['source'] ?? $character['name'],
                $clueId
            ]);
        }
    }

    echo json_encode([
        'success' => true,
        'dialogue' => $response['dialogue'],
        'emotion' => $response['emotion'] ?? 'calm',
        'character_name' => $character['name'],
        'trust_level' => $newTrust,
        'trust_change' => $trustChange,
        'is_probe' => $isProbe
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
