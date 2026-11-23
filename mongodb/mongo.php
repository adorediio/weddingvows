<?php
require __DIR__ . '/../vendor/autoload.php';

use MongoDB\Client;
use MongoDB\BSON\UTCDateTime;

Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->load();

const TYPE_MAP = ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']];
const MAX_SAVE_SLOTS = 5;

function getDb() {
    static $db = null;
    if ($db === null) {
        $client = new Client($_ENV['MONGO_URI']);
        $db = $client->selectDatabase('wedding_vows');
    }
    return $db;
}

/**
 * Generates an automatic save-file name from the player's current checkpoint
 * and Singapore local time, e.g. "Room 1 · 1:18 PM" or "Room Hub · 1:18 PM".
 */
function generateCheckpointSaveName(?int $currentRoom, ?string $currentScene = null, bool $completed = false): string {
    $tz = new DateTimeZone('Asia/Singapore');
    $timeStr = (new DateTime('now', $tz))->format('g:i A');

    if ($completed) {
        return 'Completed · ' . $timeStr;
    }
    if ($currentRoom === null && $currentScene !== null && preg_match('/^r(\d+)_/', $currentScene, $m)) {
        $currentRoom = (int) $m[1];
    }
    if ($currentRoom !== null && $currentRoom > 0) {
        return 'Room ' . $currentRoom . ' · ' . $timeStr;
    }
    return 'Room Hub · ' . $timeStr;
}

function createPlayer(string $username, string $email, ?string $ageGroup = null): string {
    $players = getDb()->players;
    if ($players->findOne(['username' => $username])) {
        throw new Exception('Username already exists');
    }
    $playerID = 'p' . uniqid();
    $player = [
        'playerID'   => $playerID,
        'username'   => $username,
        'email'      => $email,
        'created_at' => new UTCDateTime(),
    ];
    // The "personal start" profile. Only an age group is kept, never an exact age.
    if ($ageGroup !== null) {
        $player['profile'] = [
            'age_group' => $ageGroup,
            'set_at'    => new UTCDateTime(),
        ];
    }
    $players->insertOne($player);
    return $playerID;
}

function nextAvailableSlot(string $playerID): int {
    $sessions = getSessionsByPlayer($playerID);
    $used = [];
    foreach ($sessions as $idx => $s) {
        $used[] = isset($s['slot']) ? (int) $s['slot'] : ($idx + 1);
    }
    for ($i = 1; $i <= MAX_SAVE_SLOTS; $i++) {
        if (!in_array($i, $used, true)) {
            return $i;
        }
    }
    return 1;
}

function startSession(string $playerID, string $firstScene = 'scene_01', ?int $slot = null): string {
    if (!getDb()->players->findOne(['playerID' => $playerID])) {
        throw new Exception('Player not found');
    }

    $existing = getSessionsByPlayer($playerID);
    $chosenSlot = ($slot !== null && $slot >= 1 && $slot <= MAX_SAVE_SLOTS)
        ? $slot
        : nextAvailableSlot($playerID);
    $autoName = generateCheckpointSaveName(null, $firstScene);
    $now = new UTCDateTime();

    // If all 5 save slots already exist, reuse the oldest one so total saves never exceed 5
    if (count($existing) >= MAX_SAVE_SLOTS) {
        $oldest = end($existing);
        $sessionID = $oldest['sessionID'];
        getDb()->game_sessions->updateOne(
            ['sessionID' => $sessionID],
            [
                '$set' => [
                    'slot'           => $chosenSlot,
                    'save_name'      => $autoName,
                    'started_at'     => $now,
                    'updated_at'     => $now,
                    'completed_at'   => null,
                    'current_room'   => null,
                    'current_scene'  => $firstScene,
                    'tension_points' => 0,
                    'rooms_done'     => [],
                    'choices'        => [],
                ],
                '$unset' => ['outcome' => ''],
            ]
        );
        return $sessionID;
    }

    $sessionID = 's' . uniqid();
    getDb()->game_sessions->insertOne([
        'sessionID'      => $sessionID,
        'playerID'       => $playerID,
        'slot'           => $chosenSlot,
        'save_name'      => $autoName,
        'started_at'     => $now,
        'updated_at'     => $now,
        'completed_at'   => null,
        'current_room'   => null,
        'current_scene'  => $firstScene,
        'tension_points' => 0,
        'choices'        => [],
    ]);
    return $sessionID;
}

function saveChoice(string $sessionID, string $choiceID, string $sceneID, string $nextScene): bool {
    $room = null;
    if (preg_match('/^r(\d+)_/', $nextScene, $m)) {
        $room = (int) $m[1];
    }

    $set = [
        'current_scene' => $nextScene,
        'save_name'     => generateCheckpointSaveName($room, $nextScene),
        'updated_at'    => new UTCDateTime(),
    ];
    if ($room !== null) {
        $set['current_room'] = $room;
    }

    $result = getDb()->game_sessions->updateOne(
        ['sessionID' => $sessionID, 'completed_at' => null],
        [
            '$push' => ['choices' => [
                'choiceID'  => $choiceID,
                'sceneID'   => $sceneID,
                'chosen_at' => new UTCDateTime(),
            ]],
            '$set' => $set,
        ]
    );
    if ($result->getMatchedCount() === 0) {
        throw new Exception('Session not found or already completed');
    }
    return true;
}

function getSession(string $sessionID): ?array {
    return getDb()->game_sessions->findOne(['sessionID' => $sessionID], TYPE_MAP);
}

function saveOutcomeSnapshot(string $sessionID, array $outcome): void {
    getDb()->game_sessions->updateOne(
        ['sessionID' => $sessionID, 'outcome' => ['$exists' => false]],
        ['$set' => ['outcome' => $outcome]]
    );
}

function completeSession(string $sessionID): bool {
    $now = new UTCDateTime();
    $result = getDb()->game_sessions->updateOne(
        ['sessionID' => $sessionID, 'completed_at' => null],
        ['$set' => [
            'completed_at' => $now,
            'updated_at'   => $now,
            'current_room' => null,
            'save_name'    => generateCheckpointSaveName(null, null, true),
        ]]
    );
    return $result->getModifiedCount() === 1;
}


// ------------------------------------------------------------------
// Fully qualified class names are used, so no extra "use" lines needed.
// ------------------------------------------------------------------

/**
 * Makes a MongoDB document safe to send to the frontend as JSON.
 * Turns dates into text like "2026-10-27T10:05:00+00:00"
 * and the internal _id into a plain string.
 * Works on one document or a list of documents.
 */
function formatDates($data) {
    if ($data instanceof MongoDB\BSON\UTCDateTime) {
        return $data->toDateTime()->format('c');
    }
    if ($data instanceof MongoDB\BSON\ObjectId) {
        return (string) $data;
    }
    if (is_array($data)) {
        return array_map('formatDates', $data);
    }
    return $data;
}

/** Find one player by playerID. Returns null if not found. */
function getPlayer(string $playerID): ?array {
    return getDb()->players->findOne(['playerID' => $playerID], TYPE_MAP);
}

/** Find one player by username (for login / resume). Returns null if not found. */
function getPlayerByUsername(string $username): ?array {
    return getDb()->players->findOne(['username' => $username], TYPE_MAP);
}

/** All games / save files a player has played, most recently updated first. */
function getSessionsByPlayer(string $playerID): array {
    $cursor = getDb()->game_sessions->find(
        ['playerID' => $playerID],
        array_merge(TYPE_MAP, ['sort' => ['updated_at' => -1, 'started_at' => -1]])
    );
    return $cursor->toArray();
}

/**
 * The player's most recent game, or null if they have none.
 * Pass true to get only the latest UNFINISHED game (for "Continue").
 */
function getLatestSession(string $playerID, bool $unfinishedOnly = false): ?array {
    $filter = ['playerID' => $playerID];
    if ($unfinishedOnly) {
        $filter['completed_at'] = null;
    }
    return getDb()->game_sessions->findOne(
        $filter,
        array_merge(TYPE_MAP, ['sort' => ['updated_at' => -1, 'started_at' => -1]])
    );
}


/**
 * How every player answered one scene, across all sessions.
 * Returns [choiceID => count] for the choices that were picked at least once,
 * e.g. ['r4_01' => 12, 'r4_03' => 5].
 */
function countChoicesForScene(string $sceneID): array {
    $cursor = getDb()->game_sessions->aggregate([
        ['$match'  => ['choices.sceneID' => $sceneID]],
        ['$unwind' => '$choices'],
        ['$match'  => ['choices.sceneID' => $sceneID]],
        ['$group'  => ['_id' => '$choices.choiceID', 'count' => ['$sum' => 1]]],
    ], TYPE_MAP);

    $counts = [];
    foreach ($cursor as $row) {
        $counts[$row['_id']] = (int) $row['count'];
    }
    return $counts;
}

function getCommunityPulse(): array {
    $pipeline = [
        ['$facet' => [
            'totals' => [
                ['$group' => [
                    '_id'           => null,
                    'sessions'      => ['$sum' => 1],
                    'completed'     => ['$sum' => ['$cond' => [['$ne' => ['$completed_at', null]], 1, 0]]],
                    'total_choices' => ['$sum' => ['$size' => ['$ifNull' => ['$choices', []]]]],
                ]],
            ],
            'secret_sessions' => [
                ['$match' => ['choices.choiceID' => ['$in' => ['r2_06', 'r2_07', 'r2_11', 'r2_17', 'r2_25']]]],
                ['$count' => 'n'],
            ],
            'bills_sessions' => [
                ['$match' => ['choices.choiceID' => ['$in' => ['r1_04', 'r1_06', 'r1_15']]]],
                ['$count' => 'n'],
            ],
        ]],
    ];

    $res = getDb()->game_sessions->aggregate($pipeline, TYPE_MAP)->toArray();
    $facet = $res[0] ?? [];
    $totals = $facet['totals'][0] ?? ['sessions' => 0, 'completed' => 0, 'total_choices' => 0];
    $sessions = max(1, (int) $totals['sessions']);
    $secretCount = (int) ($facet['secret_sessions'][0]['n'] ?? 0);
    $billsCount  = (int) ($facet['bills_sessions'][0]['n'] ?? 0);

    return [
        'total_sessions'     => (int) $totals['sessions'],
        'completed_sessions' => (int) $totals['completed'],
        'avg_choices'        => $totals['sessions'] > 0 ? round($totals['total_choices'] / $totals['sessions'], 1) : 0,
        'secret_percent'     => round($secretCount * 100 / $sessions),
        'bills_percent'      => round($billsCount * 100 / $sessions),
    ];
}

// ------------------------------------------------------------------
// Accounts, login, 5-slot checkpoint saves, and "Continue"
// ------------------------------------------------------------------
function createAccount(string $username, string $passwordHash, ?string $ageGroup = null): string {
    $players = getDb()->players;
    $players->createIndex(['username' => 1], ['unique' => true]);

    $playerID = 'p' . uniqid();
    $player = [
        'playerID'      => $playerID,
        'username'      => $username,
        'password_hash' => $passwordHash,
        'created_at'    => new UTCDateTime(),
    ];
    if ($ageGroup !== null) {
        $player['profile'] = ['age_group' => $ageGroup, 'set_at' => new UTCDateTime()];
    }
    try {
        $players->insertOne($player);
    } catch (MongoDB\Driver\Exception\BulkWriteException $e) {
        throw new Exception('Username already taken');
    }
    return $playerID;
}

function setPlayerAgeGroup(string $playerID, string $ageGroup): void {
    getDb()->players->updateOne(
        ['playerID' => $playerID],
        ['$set' => ['profile' => ['age_group' => $ageGroup, 'set_at' => new UTCDateTime()]]]
    );
}

function markRoomDone(string $sessionID, int $room): void {
    getDb()->game_sessions->updateOne(
        ['sessionID' => $sessionID],
        [
            '$addToSet' => ['rooms_done' => $room],
            '$set'      => [
                'current_room' => null,
                'save_name'    => generateCheckpointSaveName(null, 'hub'),
                'updated_at'   => new UTCDateTime(),
            ],
        ]
    );
}

function playerHasSessions(string $playerID): bool {
    return getDb()->game_sessions->findOne(['playerID' => $playerID]) !== null;
}

/** Normalizes a game_sessions document into the save-state payload expected by the frontend. */
function buildResumePayload(array $s, ?int $fallbackSlot = null): array {
    $choices = $s['choices'] ?? [];
    $slot = isset($s['slot']) ? (int) $s['slot'] : $fallbackSlot;
    $currentRoom = isset($s['current_room']) && $s['current_room'] !== null ? (int) $s['current_room'] : null;
    $completed = ($s['completed_at'] ?? null) !== null;

    return [
        'sessionID'      => $s['sessionID'],
        'slot'           => $slot,
        'save_name'      => $s['save_name'] ?? generateCheckpointSaveName($currentRoom, $s['current_scene'] ?? null, $completed),
        'current_room'   => $currentRoom,
        'current_scene'  => $s['current_scene'] ?? null,
        'rooms_done'     => array_map('intval', $s['rooms_done'] ?? []),
        'choice_ids'     => array_column($choices, 'choiceID'),
        'choices_made'   => count($choices),
        'tension_points' => (int) ($s['tension_points'] ?? 0),
        'completed'      => $completed,
        'updated_at'     => formatDates($s['updated_at'] ?? $s['started_at'] ?? null),
    ];
}

function getResumeInfo(string $playerID): ?array {
    $s = getLatestSession($playerID);
    if (!$s || ($s['completed_at'] ?? null) !== null) {
        return null;
    }
    return buildResumePayload($s);
}

/** Overwrites the active save file in place with an auto-generated checkpoint name. */
function saveSessionState(
    string $sessionID,
    ?int $currentRoom,
    ?string $currentScene,
    int $tensionPoints,
    ?int $slot = null
): array {
    $set = [
        'save_name'      => generateCheckpointSaveName($currentRoom, $currentScene),
        'current_room'   => $currentRoom,
        'tension_points' => $tensionPoints,
        'updated_at'     => new UTCDateTime(),
    ];
    if ($currentScene !== null && $currentScene !== '') {
        $set['current_scene'] = $currentScene;
    }
    if ($slot !== null && $slot >= 1 && $slot <= MAX_SAVE_SLOTS) {
        $set['slot'] = $slot;
    }
    getDb()->game_sessions->updateOne(
        ['sessionID' => $sessionID],
        ['$set' => $set]
    );
    return getSession($sessionID);
}

/** Overwrites another existing save slot with the player's current progress and auto-generated checkpoint name. */
function overwriteSessionFromCurrent(
    string $sourceSessionID,
    string $targetSessionID,
    string $playerID,
    ?int $currentRoom,
    ?string $currentScene,
    int $tensionPoints,
    ?int $slot = null
): array {
    $source = getSession($sourceSessionID);
    $target = getSession($targetSessionID);
    if (!$target || ($target['playerID'] ?? null) !== $playerID) {
        throw new Exception('Target save slot not found');
    }

    $now = new UTCDateTime();
    $resolvedScene = ($currentScene !== null && $currentScene !== '') ? $currentScene : ($source['current_scene'] ?? 'scene_01');
    $set = [
        'save_name'      => generateCheckpointSaveName($currentRoom, $resolvedScene),
        'updated_at'     => $now,
        'completed_at'   => null,
        'current_room'   => $currentRoom,
        'current_scene'  => $resolvedScene,
        'tension_points' => $tensionPoints,
        'rooms_done'     => $source['rooms_done'] ?? [],
        'choices'        => $source['choices'] ?? [],
    ];
    if ($slot !== null && $slot >= 1 && $slot <= MAX_SAVE_SLOTS) {
        $set['slot'] = $slot;
    }

    getDb()->game_sessions->updateOne(
        ['sessionID' => $targetSessionID, 'playerID' => $playerID],
        ['$set' => $set, '$unset' => ['outcome' => '']]
    );
    return getSession($targetSessionID);
}

/** Saves into an empty slot (up to MAX_SAVE_SLOTS = 5) with an auto-generated checkpoint name. */
function duplicateSessionAsSave(
    string $sourceSessionID,
    string $playerID,
    ?int $currentRoom,
    ?string $currentScene,
    int $tensionPoints,
    ?int $slot = null
): array {
    if (count(getSessionsByPlayer($playerID)) >= MAX_SAVE_SLOTS) {
        throw new Exception('All 5 save slots are full. Select an existing slot to overwrite.');
    }

    $source = getSession($sourceSessionID);
    $chosenSlot = ($slot !== null && $slot >= 1 && $slot <= MAX_SAVE_SLOTS)
        ? $slot
        : nextAvailableSlot($playerID);
    $resolvedScene = ($currentScene !== null && $currentScene !== '') ? $currentScene : ($source['current_scene'] ?? 'scene_01');
    $newID = 's' . uniqid();
    $now = new UTCDateTime();

    getDb()->game_sessions->insertOne([
        'sessionID'      => $newID,
        'playerID'       => $playerID,
        'slot'           => $chosenSlot,
        'save_name'      => generateCheckpointSaveName($currentRoom, $resolvedScene),
        'started_at'     => $source['started_at'] ?? $now,
        'updated_at'     => $now,
        'completed_at'   => null,
        'current_room'   => $currentRoom,
        'current_scene'  => $resolvedScene,
        'tension_points' => $tensionPoints,
        'rooms_done'     => $source['rooms_done'] ?? [],
        'choices'        => $source['choices'] ?? [],
    ]);
    return getSession($newID);
}

/** Returns up to 5 save files for a player ordered by slot 1..5. */
function listPlayerSaves(string $playerID): array {
    $sessions = array_slice(getSessionsByPlayer($playerID), 0, MAX_SAVE_SLOTS);
    $usedSlots = [];
    foreach ($sessions as $s) {
        if (isset($s['slot']) && $s['slot'] >= 1 && $s['slot'] <= MAX_SAVE_SLOTS) {
            $usedSlots[] = (int) $s['slot'];
        }
    }

    $result = [];
    foreach ($sessions as $s) {
        $slot = isset($s['slot']) && $s['slot'] >= 1 && $s['slot'] <= MAX_SAVE_SLOTS
            ? (int) $s['slot']
            : null;
        if ($slot === null) {
            for ($i = 1; $i <= MAX_SAVE_SLOTS; $i++) {
                if (!in_array($i, $usedSlots, true)) {
                    $slot = $i;
                    $usedSlots[] = $i;
                    break;
                }
            }
        }
        $result[] = buildResumePayload($s, $slot);
    }

    usort($result, function ($a, $b) {
        return ($a['slot'] ?? 99) <=> ($b['slot'] ?? 99);
    });
    return $result;
}

/** Every finished game with the age group of its player (null when the player skipped it). */
function getCompletedSessionsWithAge(): array {
    $cursor = getDb()->game_sessions->aggregate([
        ['$match'   => ['completed_at' => ['$ne' => null]]],
        ['$lookup'  => ['from' => 'players', 'localField' => 'playerID', 'foreignField' => 'playerID', 'as' => 'p']],
        ['$project' => ['choices' => 1, 'age_group' => ['$arrayElemAt' => ['$p.profile.age_group', 0]]]],
    ], TYPE_MAP);
    return $cursor->toArray();
}

/** The player's newest game when it is finished (for "View my last report"), or null. */
function getFinishedInfo(string $playerID): ?array {
    $s = getLatestSession($playerID);
    if (!$s || ($s['completed_at'] ?? null) === null) {
        return null;
    }
    return ['sessionID' => $s['sessionID'], 'choices_made' => count($s['choices'] ?? [])];
}