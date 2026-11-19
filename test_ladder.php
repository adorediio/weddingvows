<?php
// Run from the project root: php test_ladder.php
// Needs MySQL running with divorce_db imported (no MongoDB needed).
require __DIR__ . '/api/ladder.php';

$year = latestYear();
$national = reasonStats($year);

// A path with a shouted argument, an insult and a slammed door, but nothing thrown
$choices = [
    ['choiceID' => 'r4_01'],   // Match his volume          -> r4_s2a (shouting)
    ['choiceID' => 'r4_04'],   // Throw an insult back      -> r4_s3a (door slam)
    ['choiceID' => 'r4_14'],   // Pick up your keys and go  -> ending
];

foreach (buildLadder($choices, $year, $national) as $r) {
    printf("%d %-34s %-5s %s\n", $r['level'], $r['title'], $r['lit'] ? 'LIT' : '-', $r['reason']);
}
// Expected: rungs 1, 2 and 3 LIT, rung 4 not lit
echo "\n" . json_encode(buildLadder($choices, $year, $national)[3], JSON_PRETTY_PRINT) . "\n";