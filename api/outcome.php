<?php
require_once __DIR__ . '/choices.php';
require_once __DIR__ . '/../mysql/queries.php';

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
        ];
}