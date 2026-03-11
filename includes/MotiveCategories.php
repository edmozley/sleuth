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
 * Generate motive options using the 3-step approach:
 * 1) Use the true motive text + category from the plot
 * 2) PHP picks 4 random unique decoy categories
 * 3) AI generates plausible decoy texts for those categories
 *
 * Returns array of 5 motive option objects, or throws on failure.
 */
function generateMotiveOptions(Claude $claude, array $plot): array
{
    $allCategories = implode(', ', MOTIVE_CATEGORIES);

    // Step 1: AI picks the best category for the true motive and writes a detailed version
    $step1Prompt = <<<PROMPT
You are a murder mystery game designer. Given the plot details below, do two things:
1. Choose the single best-fitting category for the killer's true motive
2. Write a motive description in EXACTLY 40-50 words (specific to this story, 2-3 sentences)

PLOT CONTEXT:
- Setting: {$plot['setting_description']}
- Time Period: {$plot['time_period']}
- Victim: {$plot['victim_name']}
- Killer: {$plot['killer_name']}
- Weapon: {$plot['weapon']}
- Motive: {$plot['motive']}
- Backstory: {$plot['backstory']}

Valid categories: {$allCategories}

Return JSON:
{
    "category": "the single best category key",
    "text": "motive description in 40-50 words, specific to this story"
}
PROMPT;

    $step1 = $claude->sendJson($step1Prompt, "Classify motive. Seed: " . bin2hex(random_bytes(4)), 0.5, 512);
    if (isset($step1['error'])) {
        throw new Exception('Motive classification failed: ' . $step1['error']);
    }

    $trueCategory = $step1['data']['category'] ?? null;
    $trueText = $step1['data']['text'] ?? null;

    if (!$trueCategory || !$trueText) {
        throw new Exception('AI did not return motive category/text');
    }

    // Validate category is in our list
    if (!in_array($trueCategory, MOTIVE_CATEGORIES)) {
        $trueCategory = 'secret'; // safe fallback
    }

    // Step 2: PHP picks 4 unique decoy categories (guaranteed different from true + each other)
    $decoyCategories = pickRandomCategories(4, [$trueCategory]);

    // Step 3: AI writes plausible decoy motive texts for each assigned category
    // We tell the AI the true category (but NOT the text) so it can avoid overlapping themes
    $decoyList = implode(', ', $decoyCategories);
    $step3Prompt = <<<PROMPT
You are a murder mystery game designer. Write 4 plausible but FALSE motive descriptions for a murder mystery.

PLOT CONTEXT (use this for plausibility only):
- Setting: {$plot['setting_description']}
- Time Period: {$plot['time_period']}
- Victim: {$plot['victim_name']}
- Killer: {$plot['killer_name']}

The REAL motive category is "{$trueCategory}". Your decoys must NOT overlap with "{$trueCategory}" themes in any way — no financial angles if the real motive is money, no romantic angles if the real motive is love, etc.

For each category below, write a motive in EXACTLY 40-50 words (2-3 sentences) that COULD plausibly explain why someone in this setting might commit murder. Each must explore a COMPLETELY DIFFERENT angle from the real motive and from each other. ALL motives must be similar in length and detail level.

Categories to write for: {$decoyList}

Return JSON:
{
    "decoys": [
        {"category": "{$decoyCategories[0]}", "text": "plausible motive for this category"},
        {"category": "{$decoyCategories[1]}", "text": "plausible motive for this category"},
        {"category": "{$decoyCategories[2]}", "text": "plausible motive for this category"},
        {"category": "{$decoyCategories[3]}", "text": "plausible motive for this category"}
    ]
}

RULES:
- Each motive must be clearly distinct from the others AND from the real "{$trueCategory}" motive
- Make them believable for this specific setting and characters
- EXACTLY 40-50 words each, 2-3 sentences, specific and detailed
- Do NOT mention inheritance, money, wealth, or financial themes if the real motive is about those
PROMPT;

    $step3 = $claude->sendJson($step3Prompt, "Generate decoy motives. Seed: " . bin2hex(random_bytes(4)), 0.8, 1024);
    if (isset($step3['error'])) {
        throw new Exception('Decoy generation failed: ' . $step3['error']);
    }

    $decoys = $step3['data']['decoys'] ?? null;
    if (!$decoys || !is_array($decoys) || count($decoys) < 4) {
        throw new Exception('AI did not return 4 decoy motives');
    }

    // Assemble final array: true motive first (index 0), then 4 decoys
    $motives = [];
    $motives[] = ['text' => $trueText, 'category' => $trueCategory, 'is_correct' => true];
    foreach (array_slice($decoys, 0, 4) as $d) {
        $motives[] = [
            'text' => $d['text'],
            'category' => $d['category'],
            'is_correct' => false
        ];
    }

    return $motives;
}
