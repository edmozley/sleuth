<?php

// All valid motive categories — must match the keys in motive-icons.js
const MOTIVE_CATEGORIES = [
    'money', 'jealousy', 'revenge', 'power', 'secret', 'betrayal',
    'love', 'fear', 'honor', 'family', 'ideology', 'madness',
    'freedom', 'desperation', 'rivalry', 'justice', 'accident',
    'loyalty', 'manipulation', 'curiosity', 'thrill', 'mercy',
    'political', 'tradition'
];

/**
 * Pick N unique random categories, excluding any in $exclude.
 */
function pickRandomCategories(int $count, array $exclude = []): array
{
    $available = array_values(array_diff(MOTIVE_CATEGORIES, $exclude));
    shuffle($available);
    return array_slice($available, 0, $count);
}

/**
 * Generate 5 motives per character (excluding the victim).
 * For the killer: 1 correct motive + 4 decoys.
 * For non-killers: 5 plausible but false motives (all decoys).
 *
 * Returns array keyed by character_id, each containing 5 motive objects.
 */
function generateCharacterMotives(Claude $claude, array $plot, array $characters): array
{
    $allCategories = implode(', ', MOTIVE_CATEGORIES);
    $seed = bin2hex(random_bytes(4));

    // Build character list for context (exclude victim)
    $charDescriptions = [];
    $killerCharId = null;
    foreach ($characters as $char) {
        if ($char['role'] === 'victim') continue;
        $charDescriptions[] = "- {$char['name']} (ID: {$char['id']}, role: {$char['role']}): {$char['description']}. {$char['backstory']}";
        if ($char['role'] === 'killer') $killerCharId = $char['id'];
    }
    $charListStr = implode("\n", $charDescriptions);

    $prompt = <<<PROMPT
You are a murder mystery game designer. Generate 5 plausible motives for EACH suspect character below. The player must figure out who the real killer is and what their true motive was.

PLOT CONTEXT:
- Setting: {$plot['setting_description']}
- Time Period: {$plot['time_period']}
- Victim: {$plot['victim_name']}
- Killer: {$plot['killer_name']}
- Weapon: {$plot['weapon']}
- True Motive: {$plot['motive']}
- Backstory: {$plot['backstory']}

CHARACTERS (excluding victim):
{$charListStr}

Valid motive categories: {$allCategories}

For EACH character, create exactly 5 motives:
- Each motive must be specific to THAT character's background, role, and relationship to the victim
- Each motive must use a DIFFERENT category from the valid list (no repeats within the same character)
- For the KILLER ({$plot['killer_name']}): exactly ONE motive must be the TRUE motive (marked is_correct: true), and it must align with the plot's actual motive. The other 4 must be plausible but false.
- For all OTHER characters: all 5 motives must be plausible but false (is_correct: false). Make them convincing — the player should genuinely consider each character as a possible killer.
- Each motive text should be 40-50 words (2-3 sentences), specific to that character

Return JSON:
{
    "character_motives": [
        {
            "character_id": 123,
            "motives": [
                {"text": "motive description", "category": "category_key", "is_correct": false},
                {"text": "motive description", "category": "category_key", "is_correct": true},
                ...
            ]
        }
    ]
}

RULES:
- Every character (except victim) MUST have exactly 5 motives
- Each motive within a character must use a different category
- Only the killer's set should contain exactly 1 correct motive
- Motives must reference the specific character by name and be tailored to their story
- Do NOT use generic motives — each must feel personal and specific to that character
PROMPT;

    $result = $claude->sendJson($prompt, "Generate per-character motives. Seed: {$seed}", 0.8, 8192);
    if (isset($result['error'])) {
        throw new Exception('Character motive generation failed: ' . $result['error']);
    }

    $data = $result['data']['character_motives'] ?? null;
    if (!$data || !is_array($data)) {
        throw new Exception('AI did not return character_motives array');
    }

    // Validate and organize by character_id
    $validCharIds = array_column($characters, 'id');
    $motivesByChar = [];
    foreach ($data as $charMotives) {
        $charId = (int)($charMotives['character_id'] ?? 0);
        if (!in_array($charId, $validCharIds)) continue;

        $motives = $charMotives['motives'] ?? [];
        $validated = [];
        foreach (array_slice($motives, 0, 5) as $m) {
            $category = $m['category'] ?? 'secret';
            if (!in_array($category, MOTIVE_CATEGORIES)) $category = 'secret';
            $validated[] = [
                'text' => $m['text'] ?? '',
                'category' => $category,
                'is_correct' => ($charId === $killerCharId) ? !empty($m['is_correct']) : false
            ];
        }
        $motivesByChar[$charId] = $validated;
    }

    // Ensure killer has exactly 1 correct motive
    if ($killerCharId && isset($motivesByChar[$killerCharId])) {
        $correctCount = 0;
        foreach ($motivesByChar[$killerCharId] as $m) {
            if ($m['is_correct']) $correctCount++;
        }
        if ($correctCount === 0 && count($motivesByChar[$killerCharId]) > 0) {
            $motivesByChar[$killerCharId][0]['is_correct'] = true;
        } elseif ($correctCount > 1) {
            $foundFirst = false;
            foreach ($motivesByChar[$killerCharId] as &$m) {
                if ($m['is_correct']) {
                    if ($foundFirst) $m['is_correct'] = false;
                    $foundFirst = true;
                }
            }
            unset($m);
        }
    }

    return $motivesByChar;
}
