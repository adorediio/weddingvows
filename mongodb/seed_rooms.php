<?php
// ONE-TIME script: loads rooms/room1.json ... room4.json into the MongoDB `rooms` collection.
//
//   php mongodb/seed_rooms.php
//
// Safe to run again: each room is replaced in place (upsert on "room"), never duplicated.
// After loading, it reads every room BACK from MongoDB and compares it with the JSON file.
// It exits with an error if even one value differs, so you know the DB copy is identical.

require __DIR__ . '/mongo.php';

$dir   = __DIR__ . '/../rooms';
$files = glob($dir . '/room*.json') ?: [];
if (!$files) {
    fwrite(STDERR, "No room files found in $dir\n");
    exit(1);
}
natsort($files);

$rooms = getDb()->rooms;
$rooms->createIndex(['room' => 1], ['unique' => true]);

$sourceDocs = [];
foreach ($files as $file) {
    $doc = json_decode(file_get_contents($file), true);
    if (!is_array($doc) || !isset($doc['room'], $doc['scenes']) || !is_array($doc['scenes'])) {
        fwrite(STDERR, 'Bad room file: ' . basename($file) . "\n");
        exit(1);
    }
    $doc['room'] = (int) $doc['room'];
    $sourceDocs[$doc['room']] = $doc;

    $rooms->replaceOne(['room' => $doc['room']], $doc, ['upsert' => true]);
    printf("Loaded room %d (%s): %d scenes\n", $doc['room'], $doc['title'] ?? '?', count($doc['scenes']));
}

// ---- Parity check: Mongo copy must equal the JSON file, value for value ----
function canonical($v) {
    // Compare through JSON so key order and int/float quirks are judged the way the browser sees them.
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
}

$bad = 0;
foreach ($sourceDocs as $n => $src) {
    $back = $rooms->findOne(['room' => $n], TYPE_MAP);
    unset($back['_id']);
    if (canonical($back) !== canonical($src)) {
        $bad++;
        fwrite(STDERR, "MISMATCH in room $n\n");
    }
}

$choices = 0;
foreach ($sourceDocs as $src) {
    foreach ($src['scenes'] as $scene) {
        $choices += count($scene['choices'] ?? []);
    }
}

if ($bad) {
    fwrite(STDERR, "$bad room(s) differ from the JSON files. Do not switch over yet.\n");
    exit(1);
}
printf("OK: %d rooms, %d choices; MongoDB matches the JSON files exactly.\n", count($sourceDocs), $choices);
