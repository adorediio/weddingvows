<?php
require_once __DIR__ . '/choices.php';
require_once __DIR__ . '/../mysql/queries.php';
require_once __DIR__ . '/ladder.php';

// Links a player's choices with the national divorce statistics
function buildOutcome(array $choices, ?int $year = null): array{
    $year = $year ?? latestYear();
    $scores = tallyChoices($choices);
    $mainReason = array_key_first($scores); //reason with the most points
    $national = reasonStats($year); // all reasons for that year, with rank and percent
    // find the national row for the player's main reason
    $match = null;
    foreach ($national as $row){
        if ($row['reason'] === $mainReason){
            $match = $row;
        }
    }
        return [
            'year' => $year,
            'scores' => $scores,
            'main_reason' => $mainReason,
        'national_match' => $match,  // count, rank, percent for the main reason
        'national_top3'  => array_slice($national, 0, 3), // the three most common reasons in Singapore
        'ladder'         => buildLadder($choices, $year, $national), // warning-signs rungs, lit or not
        ];
}

const MIN_AGE_SAMPLE = 3;

function buildAgeComparison(?string $ageGroup): ?array {
    if ($ageGroup === null) {
        return null;
    }
    $all = [];
    $mine = [];
    $allN = 0;
    $mineN = 0;
    foreach (getCompletedSessionsWithAge() as $s) {
        $main = array_key_first(tallyChoices($s['choices'] ?? []));
        if ($main === null) {
            continue;
        }
        $allN++;
        $all[$main] = ($all[$main] ?? 0) + 1;
        if (($s['age_group'] ?? null) === $ageGroup) {
            $mineN++;
            $mine[$main] = ($mine[$main] ?? 0) + 1;
        }
    }

    $reasons = [];
    foreach (loadChoiceRules() as $rule) {
        if ($rule['reason'] !== null && $rule['weight'] > 0) {
            $reasons[$rule['reason']] = true;
        }
    }
    $order = array_keys($reasons);
    usort($order, fn($a, $b) => ($all[$b] ?? 0) <=> ($all[$a] ?? 0));

    $themes = [];
    foreach ($order as $reason) {
        $themes[] = [
            'reason'        => $reason,
            'group_percent' => $mineN ? round(($mine[$reason] ?? 0) * 100 / $mineN, 1) : 0,
            'all_percent'   => $allN ? round(($all[$reason] ?? 0) * 100 / $allN, 1) : 0,
        ];
    }
    return [
        'age_group'   => $ageGroup,
        'group_games' => $mineN,
        'all_games'   => $allN,
        'min_sample'  => MIN_AGE_SAMPLE,
        'enough'      => $mineN >= MIN_AGE_SAMPLE,
        'themes'      => $themes,
    ];
}