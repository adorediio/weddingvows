<?php
//  To update with real choice ID later (waiting for afi's part)
const CHOICES = [
    'c01' => ['reason' => 'Financial Problems',    'weight' => 2, 'next' => 'scene_02'],
    'c05' => ['reason' => 'Adultery & Infidelity', 'weight' => 3, 'next' => 'scene_03'],
    'c06' => ['reason' => 'Desertion', 'weight' => 2, 'next' => null]
];
 
// Returns the rule for one choice, or null when the choiceID does not exist.
function getChoiceRule(string $choiceID): ?array {
    return CHOICES[$choiceID] ?? null;
}

// Adds the points per divorce reason
function tallyChoices(array $choices): array {
    $scores = [];

    foreach ($choices as $choice){
        $rule = getChoiceRule($choice['choiceID']);
        if ($rule === null){
            continue; // skip a choiceID that is not in the table
        }
        $reason = $rule['reason'];
        $scores[$reason] = ($scores[$reason] ?? 0) + $rule['weight'];
    }
    arsort($scores); //highest points first
    return $scores;
}