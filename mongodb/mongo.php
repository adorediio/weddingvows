<?php
require __DIR__ . '/../vendor/autoload.php';

use MongoDB\Client;
use MongoDB\BSON\UTCDateTime;

Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->load();

const TYPE_MAP = ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']];

function getDb() {
    static $db = null;
    if ($db === null) {
        $client = new Client($_ENV['MONGO_URI']);
        $db = $client->selectDatabase('wedding_vows');
    }
    return $db;
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

function startSession(string $playerID, string $firstScene = 'scene_01'): string {
    if (!getDb()->players->findOne(['playerID' => $playerID])) {
        throw new Exception('Player not found');
    }
    $sessionID = 's' . uniqid();
    getDb()->game_sessions->insertOne([
        'sessionID'     => $sessionID,
        'playerID'      => $playerID,
        'started_at'    => new UTCDateTime(),
        'completed_at'  => null,
        'current_scene' => $firstScene,
        'choices'       => [],
    ]);
    return $sessionID;
}

function saveChoice(string $sessionID, string $choiceID, string $sceneID, string $nextScene): bool {
    $result = getDb()->game_sessions->updateOne(
        ['sessionID' => $sessionID, 'completed_at' => null],
        [
            '$push' => ['choices' => [
                'choiceID'  => $choiceID,
                'sceneID'   => $sceneID,
                'chosen_at' => new UTCDateTime(),
            ]],
            '$set' => ['current_scene' => $nextScene],
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

function completeSession(string $sessionID): bool {
    $result = getDb()->game_sessions->updateOne(
        ['sessionID' => $sessionID, 'completed_at' => null],
        ['$set' => ['completed_at' => new UTCDateTime()]]
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

/** All games a player has played, newest first. Returns an empty list if none. */
function getSessionsByPlayer(string $playerID): array {
    $cursor = getDb()->game_sessions->find(
        ['playerID' => $playerID],
        array_merge(TYPE_MAP, ['sort' => ['started_at' => -1]])
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
        array_merge(TYPE_MAP, ['sort' => ['started_at' => -1]])
    );
}


/**
 * How every player answered one scene, across all sessions.
 * Returns [choiceID => count] for the choices that were picked at least once,
 * e.g. ['r4_01' => 12, 'r4_03' => 5].
 *
 * $unwind turns each session's choices array into one row per choice,
 * $match keeps only this scene, $group counts the rows per choiceID.
 */
function countChoicesForScene(string $sceneID): array {
    $cursor = getDb()->game_sessions->aggregate([
        ['$match'  => ['choices.sceneID' => $sceneID]],   // lets an index skip unrelated sessions
        ['$unwind' => '$choices'],
        ['$match'  => ['choices.sceneID' => $sceneID]],   // drop the other choices of those sessions
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