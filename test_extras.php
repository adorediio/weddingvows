<?php
// Run from the project root: php test_extras.php
// Uses the seed data: player "alex" (p001) with session s001,
// and s002 (a completed session).
require __DIR__ . '/mongodb/mongo.php';

function show(string $title, $data): void {
    echo "\n=== $title ===\n";
    echo json_encode(formatDates($data), JSON_PRETTY_PRINT) . "\n";
}

// 1. Normal use
show('getPlayerByUsername("alex")', getPlayerByUsername('alex'));
show('getPlayer("p001")', getPlayer('p001'));
show('getSessionsByPlayer("p001")', getSessionsByPlayer('p001'));
show('getLatestSession("p001")', getLatestSession('p001'));
show('getLatestSession("p001", unfinished only)', getLatestSession('p001', true));

// 2. Not found should return null / empty, not crash
show('getPlayerByUsername("nobody")', getPlayerByUsername('nobody'));
show('getSessionsByPlayer("p999")', getSessionsByPlayer('p999'));

// 3. Edge cases that should give a clear error
echo "\n=== Edge cases ===\n";

try {
    createPlayer('alex', 'dup@example.com');
    echo "FAIL: duplicate username was accepted\n";
} catch (Exception $e) {
    echo "OK duplicate username: " . $e->getMessage() . "\n";
}

try {
    startSession('p-does-not-exist');
    echo "FAIL: unknown player was accepted\n";
} catch (Exception $e) {
    echo "OK unknown player: " . $e->getMessage() . "\n";
}

try {
    saveChoice('s-does-not-exist', 'c01', 'scene_01', 'scene_02');
    echo "FAIL: unknown session was accepted\n";
} catch (Exception $e) {
    echo "OK unknown session: " . $e->getMessage() . "\n";
}

try {
    saveChoice('s002', 'c01', 'scene_01', 'scene_02'); // s002 is completed
    echo "FAIL: completed session accepted a choice\n";
} catch (Exception $e) {
    echo "OK completed session: " . $e->getMessage() . "\n";
}

echo "\nDone\n";