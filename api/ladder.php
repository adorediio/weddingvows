<?php
// The escalation ladder shown at the end of the game.
// A rung is "lit" when the player's path went through one of its scenes, or the player
// picked one of its choices. Each rung is tied to the divorce reason (from the SQL data)
// that comes closest to it. Change the rungs, triggers or reasons here, nowhere else.
require_once __DIR__ . '/choices.php';
require_once __DIR__ . '/../mysql/queries.php';

// Lowest rung first. "reason" must match Dim_Reason.reason_name exactly.
function ladderRungs(): array {
    return [
        [
            'key'     => 'voices',
            'title'   => 'Raised voices',
            'blurb'   => 'The volume climbs. People stop listening and start competing.',
            'reason'  => 'Personality Differences',
            'scenes'  => ['r3_s2b', 'r3_s3c', 'r4_s2a'],
            'choices' => [],
        ],
        [
            'key'     => 'insults',
            'title'   => 'Insults and contempt',
            'blurb'   => 'Words chosen to hurt: name-calling, mockery, saying the one thing that lands.',
            'reason'  => 'Unreasonable Behaviour',
            'scenes'  => ['r3_s3c', 'r4_s2a'],
            'choices' => ['r4_04', 'r4_08'],
        ],
        [
            'key'     => 'slam',
            'title'   => 'Slammed doors and intimidation',
            'blurb'   => 'A door slammed, a palm on the table, a mug put down hard. Nobody is touched, but everybody feels it.',
            'reason'  => 'Unreasonable Behaviour',
            //'scenes'  => ['r3_s4c', 'r4_s3a', 'r4_s3b'],
            'scenes'  => ['r3_s4c', 'r4_s3a', 'r4_s3b', 'r3_end_line', 'r1_c4c', 'r1_c5b'],
            'choices' => [],
        ],
        [
            'key'     => 'thrash',
            'title'   => 'Thrashing and breaking things',
            'blurb'   => 'Objects are thrown and smashed. This is no longer an argument.',
            'reason'  => 'Domestic Violence & Abuses',
            'scenes'  => ['r4_s4a', 'r4_s4b', 'r4_s5a', 'r4_s5b'],
            'choices' => [],
        ],
    ];
}

// Every scene the player was in: the scene of each choice, plus the scene it led to.
function visitedScenes(array $choices): array {
    $seen = [];
    foreach ($choices as $choice) {
        $rule = getChoiceRule($choice['choiceID'] ?? '');
        if ($rule === null) {
            continue;
        }
        $seen[$rule['scene']] = true;
        $seen[$rule['next']]  = true;
    }
    return $seen;
}

// $national is the reasonStats() rows for the year, passed in so the SQL runs only once.
function buildLadder(array $choices, int $year, array $national): array {
    $visited = visitedScenes($choices);
    $picked  = array_flip(array_column($choices, 'choiceID'));

    $byReason = [];
    foreach ($national as $row) {
        $byReason[$row['reason']] = $row;
    }

    $cache = [];   // two rungs can share a reason, so look each one up once
    $rungs = [];
    foreach (ladderRungs() as $i => $rung) {
        $lit = false;
        foreach ($rung['scenes'] as $sceneID) {
            if (isset($visited[$sceneID])) { $lit = true; }
        }
        foreach ($rung['choices'] as $choiceID) {
            if (isset($picked[$choiceID])) { $lit = true; }
        }

        $reason = $rung['reason'];
        if (!isset($cache[$reason])) {
            $trend = reasonTrend($reason);
            $dur   = reasonByDuration($reason, $year);
            $cache[$reason] = [
                'trend' => count($trend) >= 2 ? [
                    'from_year'  => $trend[0]['year'],
                    'from_count' => $trend[0]['count'],
                    'to_year'    => end($trend)['year'],
                    'to_count'   => end($trend)['count'],
                ] : null,
                'peak_duration' => $dur ? [
                    'label' => $dur[0]['duration'],
                    'count' => $dur[0]['count'],
                    'share' => $dur[0]['share'],
                ] : null,
            ];
        }

        $rungs[] = [
            'level'         => $i + 1,
            'key'           => $rung['key'],
            'title'         => $rung['title'],
            'blurb'         => $rung['blurb'],
            'lit'           => $lit,
            'reason'        => $reason,
            'national'      => $byReason[$reason] ?? null,   // count, rank, percent for the year
            'trend'         => $cache[$reason]['trend'],
            'peak_duration' => $cache[$reason]['peak_duration'],
        ];
    }
    return $rungs;
}