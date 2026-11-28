<?php
// Room stories live in the MongoDB `rooms` collection: one document per room,
// in exactly the same shape as the old rooms/room{n}.json files
// ({room, title, maxTension, start, scenes:{...}}), plus Mongo's own _id.
//
// Rollback switch: put ROOMS_SOURCE=json in .env to read the JSON files again.
// (The default is mongo.)

const ROOM_COUNT = 4;

// Decides where the rooms come from: "mongo" (default) or "json".
function roomsSource(): string {
    // .env is normally loaded by mongo.php, but in json mode that file is never loaded,
    // so read .env here too (once) or the switch would be ignored.     <-- NEW
    static $envLoaded = false;                                          // remembers we already tried
    if (!$envLoaded) {
        $envLoaded = true;
        // only read .env if the setting isn't already available
        if (!isset($_ENV['ROOMS_SOURCE']) && getenv('ROOMS_SOURCE') === false) {
            require_once __DIR__ . '/../vendor/autoload.php';           // loads the Dotenv library
            Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad(); // reads .env (no error if it's missing)
        }
    }
    // use the setting if present, otherwise default to "mongo"
    return strtolower($_ENV['ROOMS_SOURCE'] ?? (getenv('ROOMS_SOURCE') ?: 'mongo'));
}

/** One room as the plain array the game reads, or null when it does not exist. */
function getRoom(int $n): ?array {
    if (roomsSource() === 'json') {                      // rollback mode: read the old file
        $file = __DIR__ . '/../rooms/room' . $n . '.json';
        if (!is_file($file)) {
            return null;
        }
        $room = json_decode(file_get_contents($file), true);
        return is_array($room) ? $room : null;
    }

    require_once __DIR__ . '/mongo.php';                 // your existing Mongo connection
    $room = getDb()->rooms->findOne(['room' => $n], TYPE_MAP);   // find room number n
    if ($room === null) {
        return null;
    }
    unset($room['_id']);   // Mongo's internal id is not part of the story
    return $room;
}

/** Every room, as [roomNumber => room], in room order. */
function getAllRooms(): array {
    if (roomsSource() === 'json') {                      // rollback mode: read all the files
        $rooms = [];
        foreach (glob(__DIR__ . '/../rooms/room*.json') ?: [] as $file) {
            $room = json_decode(file_get_contents($file), true);
            if (!is_array($room) || !isset($room['scenes'], $room['room'])) {
                throw new RuntimeException('Bad room file: ' . basename($file));
            }
            $rooms[(int) $room['room']] = $room;
        }
        ksort($rooms);
        return $rooms;
    }

    require_once __DIR__ . '/mongo.php';
    $rooms = [];
    $cursor = getDb()->rooms->find([], array_merge(TYPE_MAP, ['sort' => ['room' => 1]]));  // all rooms, 1 to 4
    foreach ($cursor as $room) {
        unset($room['_id']);
        if (!isset($room['scenes'], $room['room'])) {
            throw new RuntimeException('Bad room document in MongoDB');
        }
        $rooms[(int) $room['room']] = $room;
    }
    return $rooms;
}