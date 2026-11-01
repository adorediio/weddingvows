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

function createPlayer(string $username, string $email): string {
    $players = getDb()->players;
    if ($players->findOne(['username' => $username])) {
        throw new Exception('Username already exists');
    }
    $playerID = 'p' . uniqid();
    $players->insertOne([
        'playerID'   => $playerID,
        'username'   => $username,
        'email'      => $email,
        'created_at' => new UTCDateTime(),
    ]);
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