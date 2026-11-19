<?php
// Choice rules are built from the room files in /rooms (room1.json ... room4.json),
// so the story and the scoring can never drift apart.
// Each rule: choiceID => [reason, weight, next, scene, room, room_done]

function loadChoiceRules(): array {
    static $rules = null;
    if ($rules !== null) {
        return $rules;
    }

    $rules = [];
    $files = glob(__DIR__ . '/../rooms/room*.json') ?: [];
    if (!$files) {
        throw new RuntimeException('No room files found in /rooms');
    }

    foreach ($files as $file) {
        $room = json_decode(file_get_contents($file), true);
        if (!is_array($room) || !isset($room['scenes'], $room['room'])) {
            throw new RuntimeException('Bad room file: ' . basename($file));
        }

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