<?php
// Choice rules are built from the room documents in the MongoDB `rooms` collection
// (see mongodb/rooms.php), so the story and the scoring can never drift apart.
// Each rule: choiceID => [reason, weight, next, scene, room, room_done]

require_once __DIR__ . '/../mongodb/rooms.php';

function loadChoiceRules(): array {
    static $rules = null;
    if ($rules !== null) {
        return $rules;
    }

    $rules = [];
    $allRooms = getAllRooms();
    if (!$allRooms) {
        throw new RuntimeException('No rooms found. Run: php mongodb/seed_rooms.php');
    }

    foreach ($allRooms as $room) {
        foreach ($room['scenes'] as $sceneID => $scene) {
            foreach ($scene['choices'] as $choice) {
                $id = $choice['id'];
                if (isset($rules[$id])) {
                    throw new RuntimeException("Duplicate choiceID: $id");
                }
                $next = $choice['next'];
                $rules[$id] = [
                    'reason'    => $choice['reason'] ?? null,
                    'weight'    => (int) ($choice['weight'] ?? 0),
                    'next'      => $next,
                    'scene'     => $sceneID,
                    'room'      => (int) $room['room'],
                    // true when the next scene is an ending, so the room is finished
                    'room_done' => isset($room['scenes'][$next]['ending']),
                ];
            }
        }
    }
    return $rules;
}

// Returns the rule for one choice, or null when the choiceID does not exist.
function getChoiceRule(string $choiceID): ?array {
    return loadChoiceRules()[$choiceID] ?? null;
}

// Adds the points per divorce reason
function tallyChoices(array $choices): array {
    $scores = [];

    foreach ($choices as $choice) {
        $rule = getChoiceRule($choice['choiceID']);
        if ($rule === null) {
            continue; // skip a choiceID that is not in the table
        }
        if ($rule['reason'] === null || $rule['weight'] <= 0) {
            continue; // neutral choice, no divorce reason attached
        }
        $reason = $rule['reason'];
        $scores[$reason] = ($scores[$reason] ?? 0) + $rule['weight'];
    }
    arsort($scores); // highest points first
    return $scores;
}

// All choiceIDs that belong to one scene (empty list when the scene does not exist).
function choiceIDsForScene(string $sceneID): array {
    $ids = [];
    foreach (loadChoiceRules() as $id => $rule) {
        if ($rule['scene'] === $sceneID) {
            $ids[] = $id;
        }
    }
    return $ids;
}
 
// Turns raw counts into percentages. Every choice of the scene is listed, even with 0 picks.
// "enough" is false while too few people have played for a percentage to mean anything.
const MIN_STAT_SAMPLE = 5;
 
function buildChoiceStats(string $sceneID, array $counts): array {
    $ids   = choiceIDsForScene($sceneID);
    $total = 0;
    foreach ($ids as $id) {
        $total += $counts[$id] ?? 0;
    }
 
    $choices = [];
    foreach ($ids as $id) {
        $n = $counts[$id] ?? 0;
        $choices[$id] = [
            'count'   => $n,
            'percent' => $total > 0 ? (int) round($n / $total * 100) : 0,
        ];
    }
    return [
        'sceneID' => $sceneID,
        'total'   => $total,
        'enough'  => $total >= MIN_STAT_SAMPLE,
        'choices' => $choices,
    ];
}