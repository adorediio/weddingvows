<?php

require_once __DIR__ . '/database.php'; //

function createAccount(string $username, string $passwordHash): string
{
    global $pdo;

    $playerID = uniqid('p');

    $stmt = $pdo->prepare("
        INSERT INTO players (
            playerID,
            username,
            password_hash
        )
        VALUES (
            :playerID,
            :username,
            :password_hash
        )
    ");

    $stmt->execute([
        ':playerID' => $playerID,
        ':username' => $username,
        ':password_hash' => $passwordHash
    ]);

    return $playerID;
}

function getPlayerByUsername(string $username): ?array
{
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT *
        FROM players
        WHERE username = :username
        LIMIT 1
    ");

    $stmt->execute([
        ':username' => $username
    ]);

    $player = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$player) {
        return null;
    }

    $player['profile'] = [
        'age_group' => $player['age_group'] ?? null
    ];

    return $player;

    //return $player ?: null;
}

function getPlayer(string $playerID): ?array
{
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT *
        FROM players
        WHERE playerID = :playerID
        LIMIT 1
    ");

    $stmt->execute([
        ':playerID' => $playerID
    ]);

    $player = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$player) {
        return null;
    }

    $player['profile'] = [
        'age_group' => $player['age_group'] ?? null
    ];

    return $player;

    //return $player ?: null;
}

function setPlayerAgeGroup(string $playerID, string $ageGroup): bool
{
    global $pdo;

    $stmt = $pdo->prepare("
        UPDATE players
        SET age_group = :age_group
        WHERE playerID = :playerID
    ");

    return $stmt->execute([
        ':age_group' => $ageGroup,
        ':playerID' => $playerID
    ]);
}
