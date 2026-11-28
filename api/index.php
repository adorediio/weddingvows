<?php

require_once __DIR__ . '/../mysql/queries.php';
require_once __DIR__ . '/../mysql/auth.php';
require_once __DIR__ . '/choices.php';
require_once __DIR__ . '/outcome.php';
require_once __DIR__ . '/profile.php';

session_set_cookie_params(['path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Prints JSON with status code
function send(int $status, $data){
    http_response_code($status);
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

// Loads Hafi's MongoDB code when a route needs it.
function mongo(): void {
    require_once __DIR__ . '/../mongodb/mongo.php';
}

// Reads the JSON the frontend sends in a POST request.
function body(): array {
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

// Stops with a 400 error if a required field is missing.
function need(array $data, array $fields): void {
    foreach ($fields as $f) {
        if (!isset($data[$f]) || $data[$f] === '') {
            send(400, ['error' => "Missing field: $f"]);
        }
    }
}

function currentPlayerID(): ?string {
    return $_SESSION['playerID'] ?? null;
}

function requireLogin(): string {
    $id = currentPlayerID();
    if ($id === null) {
        send(401, ['error' => 'Not logged in']);
    }
    return $id;
}

function ownSessionOrFail(string $sessionID): array {
    $playerID = requireLogin();
    $session = getSession($sessionID);
    if (!$session) {
        send(404, ['error' => 'Session not found']);
    }
    if (($session['playerID'] ?? null) !== $playerID) {
        send(403, ['error' => 'This game belongs to another player']);
    }
    return $session;
}

function fullOutcome(array $session): array {
    $outcome = buildOutcome($session['choices'] ?? []);
    $player = getPlayer($session['playerID']);
    $outcome['age_comparison'] = buildAgeComparison($player['age_group'] ?? null);
    return $outcome;
}

function accountPayload(array $player): array {
    mongo();
    $playerID = $player['playerID'];
    $saves = listPlayerSaves($playerID);
    return [
        'username'   => $player['username'],
        'age_group'  => $player['age_group'] ?? null,
        'new_player' => count($saves) === 0,
        'resume'     => getResumeInfo($playerID),
        'finished'   => getFinishedInfo($playerID),
        'saves'      => $saves,
    ];
}

// Browsers send an options request first
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'OPTIONS'){
    send(204, null); // No content, request works
}

// "/stats/reasons?year=2025" becomes ['stats', 'reasons']
$path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

// Also works when the API sits in a subfolder, e.g. XAMPP:
// /weddingvows/api/index.php/health  ->  'health'
$base = trim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
if ($base !== '' && strpos($path, $base) === 0) {
    $path = trim(substr($path, strlen($base)), '/');
}
if (strpos($path, 'index.php') === 0) {
    $path = trim(substr($path, strlen('index.php')), '/');
}
$parts = $path === '' ? [] : explode('/', $path);

try {
    // To get the health of the website
    if ($method === 'GET' && $parts === ['health']) {
        send(200, ['status' => 'ok']);
    }

    // To get /stats/reasons?year=2025
    if ($method === 'GET' && $parts === ['stats', 'reasons']) {
        $year = isset($_GET['year']) ? (int) $_GET['year'] : latestYear();
        send(200, ['year' => $year, 'reasons' => reasonStats($year)]);
    }

    // To get /stats/age?year=2025
    if ($method === 'GET' && $parts === ['stats', 'age']) {
        $year = isset($_GET['year']) ? (int) $_GET['year'] : latestYear();
        send(200, ['year' => $year, 'ages' => ageStats($year)]);
    }

    // GET /stats/age-groups  -> the age groups the player can pick, youngest first
    if ($method === 'GET' && $parts === ['stats', 'age-groups']) {
        send(200, ['age_groups' => knownAgeGroups()]);
    }

    // GET /stats/profile?age_group=30-34&year=2025
    // How that age group compares with all divorces (SQL only, nothing is saved)
    if ($method === 'GET' && $parts === ['stats', 'profile']) {
        $group = $_GET['age_group'] ?? '';
        if (!in_array($group, knownAgeGroups(), true)) {
            send(400, ['error' => 'Unknown age_group']);
        }
        $year = isset($_GET['year']) ? (int) $_GET['year'] : null;
        send(200, buildProfileComparison($group, $year));
    }

    // GET /stats/choices?sceneID=r4_s1
    // How all players answered one scene: percent per choiceID (from MongoDB).
    if ($method === 'GET' && $parts === ['stats', 'choices']) {
        $sceneID = $_GET['sceneID'] ?? '';
        if ($sceneID === '' || !choiceIDsForScene($sceneID)) {
            send(400, ['error' => 'Unknown sceneID']);
        }
        mongo();
        send(200, buildChoiceStats($sceneID, countChoicesForScene($sceneID)));
    }

    // GET /stats/midgame?reason=Financial+Problems&sessionID=s123
    // Powers the top-of-screen live SQL HUD AND the Halfway Checkpoint after Room 2
    if ($method === 'GET' && $parts === ['stats', 'midgame']) {
        $reason = $_GET['reason'] ?? 'Unreasonable Behaviour';
        $sessionID = $_GET['sessionID'] ?? null;

        $payload = [
            'spotlight' => midgameReasonSpotlight($reason),
            'sql_facts' => hubSqlFacts(),
        ];

        mongo();
        $payload['community'] = getCommunityPulse();

        if ($sessionID) {
            $session = getSession($sessionID);
            if ($session && ($session['playerID'] ?? null) === currentPlayerID()) {
                $scores = tallyChoices($session['choices'] ?? []);
                $payload['session_so_far'] = [
                    'choices_made' => count($session['choices'] ?? []),
                    'scores'       => $scores,
                    'top_reason'   => array_key_first($scores),
                    'flags'        => $session['flags'] ?? [],
                ];
            }
        }
        send(200, $payload);
    }

    // GET /stats/hub
    // Powers the live SQL + MongoDB pulse bar at the bottom of the Room Selection screen
    if ($method === 'GET' && $parts === ['stats', 'hub']) {
        mongo();
        send(200, [
            'sql'       => hubSqlFacts(),
            'community' => getCommunityPulse(),
        ]);
    }

    // POST /auth/signup
    if ($method === 'POST' && $parts === ['auth', 'signup']) {
        
        $d = body();
        need($d, ['username', 'password']);
        $username = strtolower(trim((string) $d['username']));
        $password = (string) $d['password'];
        if (!preg_match('/^[a-z0-9_]{3,20}$/', $username)) {
            send(400, ['error' => 'Username must be 3 to 20 letters, numbers or underscores']);
        }
        if (strlen($password) < 6 || strlen($password) > 72) {
            send(400, ['error' => 'Password must be 6 to 72 characters']);
        }
        try {
            $playerID = createAccount($username, password_hash($password, PASSWORD_DEFAULT));
        } catch (Exception $e) {
            send(409, ['error' => $e->getMessage()]);
        }
        session_regenerate_id(true);
        $_SESSION['playerID'] = $playerID;
        send(201, accountPayload(getPlayer($playerID)));
    }

    // POST /auth/login
    if ($method === 'POST' && $parts === ['auth', 'login']) {
        //mongo();
        $d = body();
        need($d, ['username', 'password']);
        $player = getPlayerByUsername(strtolower(trim((string) $d['username'])));
        if (!$player || !isset($player['password_hash']) ||
            !password_verify((string) $d['password'], $player['password_hash'])) {
            usleep(400000);
            send(401, ['error' => 'Wrong username or password']);
        }
        session_regenerate_id(true);
        $_SESSION['playerID'] = $player['playerID'];
        send(200, accountPayload($player));
    }

    // GET /auth/me
    if ($method === 'GET' && $parts === ['auth', 'me']) {
        $playerID = requireLogin();
        //mongo();
        $player = getPlayer($playerID);
        if (!$player) {
            session_destroy();
            send(401, ['error' => 'Not logged in']);
        }
        send(200, accountPayload($player));
    }

    // POST /auth/logout
    if ($method === 'POST' && $parts === ['auth', 'logout']) {
        $_SESSION = [];
        session_destroy();
        send(200, ['logged_out' => true]);
    }

        // POST /auth/delete-account   (deletes the logged-in player)
    if ($method === 'POST' && $parts === ['auth', 'delete-account']) {
        $playerID = requireLogin();
        mongo();
        foreach (getSessionsByPlayer($playerID) as $s) {
            deleteSession($s['sessionID']);
        }
        deletePlayer($playerID);
        $_SESSION = [];
        session_destroy();
        send(200, ['deleted' => true]);
    }

    // POST /auth/profile   body: {"age_group": "30-34"}
    if ($method === 'POST' && $parts === ['auth', 'profile']) {
        $playerID = requireLogin();
        mongo();
        $d = body();
        need($d, ['age_group']);
        if (!in_array($d['age_group'], knownAgeGroups(), true)) {
            send(400, ['error' => 'Unknown age_group']);
        }
        setPlayerAgeGroup($playerID, $d['age_group']);
        send(200, ['saved' => true]);
    }

        // POST /auth/update-profile
    // body: {"username": "...", "age_group": "30-34", "current_password": "...", "new_password": "..."}
    if ($method === 'POST' && $parts === ['auth', 'update-profile']) {
        $playerID = requireLogin();
        $d = body();
        need($d, ['username']);

        $username = strtolower(trim((string) $d['username']));
        if (!preg_match('/^[a-z0-9_]{3,20}$/', $username)) {
            send(400, ['error' => 'Username must be 3 to 20 letters, numbers or underscores']);
        }

        $ageGroup = $d['age_group'] ?? null;
        if ($ageGroup === '') {
            $ageGroup = null;
        }
        if ($ageGroup !== null && !in_array($ageGroup, knownAgeGroups(), true)) {
            send(400, ['error' => 'Unknown age_group']);
        }

        // Password only changes when a new one is typed, and the current one must match.
        $newHash = null;
        $newPassword = (string) ($d['new_password'] ?? '');
        if ($newPassword !== '') {
            if (strlen($newPassword) < 6 || strlen($newPassword) > 72) {
                send(400, ['error' => 'New password must be 6 to 72 characters']);
            }
            $player = getPlayer($playerID);
            if (!$player || !password_verify((string) ($d['current_password'] ?? ''), $player['password_hash'])) {
                usleep(400000);
                send(403, ['error' => 'Current password is wrong']);
            }
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        }

        try {
            updatePlayerProfile($playerID, $username, $ageGroup, $newHash);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                send(409, ['error' => 'That username is already taken']);
            }
            throw $e;
        }
        send(200, accountPayload(getPlayer($playerID)));
    }

    // POST /game/new
    if ($method === 'POST' && $parts === ['game', 'new']) {
        $playerID = requireLogin();
        mongo();
        $d = body();
        $slot = isset($d['slot']) ? (int) $d['slot'] : null;
        send(201, ['sessionID' => startSession($playerID, 'scene_01', $slot)]);
    }

    // POST /sessions/{sessionID}/room-done   body: {"room": 2}
    if ($method === 'POST' && count($parts) === 3 && $parts[0] === 'sessions' && $parts[2] === 'room-done') {
        mongo();
        ownSessionOrFail($parts[1]);
        $d = body();
        need($d, ['room']);
        $room = (int) $d['room'];
        if ($room < 1 || $room > 4) {
            send(400, ['error' => 'Unknown room']);
        }
        markRoomDone($parts[1], $room);
        send(200, ['saved' => true]);
    }

    // POST /choices   body: {"sessionID": "...", "choiceID": "r2_02", "sceneID": "r2_s1"}
    if ($method === 'POST' && $parts === ['choices']) {
        mongo();
        $data = body();
        need($data, ['sessionID', 'choiceID', 'sceneID']);
        ownSessionOrFail($data['sessionID']);

        $rule = getChoiceRule($data['choiceID']);
        if ($rule === null) {
            send(400, ['error' => 'Unknown choiceID']);
        }
        if ($rule['scene'] !== $data['sceneID']) {
            send(400, ['error' => 'This choice does not belong to that scene']);
        }

        saveChoice($data['sessionID'], $data['choiceID'], $data['sceneID'], $rule['next']);
        send(200, [
            'saved'      => true,
            'next_scene' => $rule['next'],
            'room_done'  => $rule['room_done']
        ]);
    }

    // GET /sessions/{sessionID}/outcome
    if ($method === 'GET' && count($parts) === 3 && $parts[0] === 'sessions' && $parts[2] === 'outcome') {
        mongo();
        $session = ownSessionOrFail($parts[1]);
        if (($session['completed_at'] ?? null) === null) {
            send(409, ['error' => 'This game is not finished yet']);
        }
        $outcome = $session['outcome'] ?? null;
        if ($outcome === null) {   // games finished before snapshots existed
            $outcome = fullOutcome($session);
            saveOutcomeSnapshot($parts[1], $outcome);
        }
        send(200, ['outcome' => $outcome]);
    }

    // POST /sessions/{sessionID}/complete
    if ($method === 'POST' && count($parts) === 3 && $parts[0] === 'sessions' && $parts[2] === 'complete') {
        mongo();
        $session = ownSessionOrFail($parts[1]);
        completeSession($parts[1]);   // does nothing if it was already completed
        $outcome = $session['outcome'] ?? null;
        if ($outcome === null) {
            $outcome = fullOutcome($session);
            saveOutcomeSnapshot($parts[1], $outcome);
        }
        send(200, ['finished' => true, 'outcome' => $outcome]);
    }

    // GET /sessions  -> list all save files for the logged-in player
    if ($method === 'GET' && $parts === ['sessions']) {
        $playerID = requireLogin();
        mongo();
        send(200, ['saves' => listPlayerSaves($playerID)]);
    }

    // GET /sessions/{sessionID}  -> load one specific save file
    if ($method === 'GET' && count($parts) === 2 && $parts[0] === 'sessions') {
        mongo();
        $session = ownSessionOrFail($parts[1]);
        send(200, ['save' => buildResumePayload($session)]);
    }

    // POST /sessions/{sessionID}/save  -> save into an empty slot or overwrite a chosen slot (max 5)
    if ($method === 'POST' && count($parts) === 3 && $parts[0] === 'sessions' && $parts[2] === 'save') {
        mongo();
        $session = ownSessionOrFail($parts[1]);
        $d = body();
        $currentRoom = isset($d['current_room']) && $d['current_room'] !== null ? (int) $d['current_room'] : null;
        $currentScene = isset($d['current_scene']) && $d['current_scene'] !== '' ? (string) $d['current_scene'] : null;
        $tensionPoints = isset($d['tension_points']) ? (int) $d['tension_points'] : 0;
        $slot = isset($d['slot']) ? (int) $d['slot'] : null;
        $targetID = isset($d['target_session_id']) && $d['target_session_id'] !== ''
            ? (string) $d['target_session_id']
            : null;

        try {
            if ($targetID !== null && $targetID !== $parts[1]) {
                $saved = overwriteSessionFromCurrent(
                    $parts[1],
                    $targetID,
                    $session['playerID'],
                    $currentRoom,
                    $currentScene,
                    $tensionPoints,
                    $slot
                );
            } elseif ($targetID === $parts[1] || empty($d['as_new'])) {
                $saved = saveSessionState($parts[1], $currentRoom, $currentScene, $tensionPoints, $slot);
            } else {
                $saved = duplicateSessionAsSave(
                    $parts[1],
                    $session['playerID'],
                    $currentRoom,
                    $currentScene,
                    $tensionPoints,
                    $slot
                );
            }
        } catch (Exception $e) {
            send(400, ['error' => $e->getMessage()]);
        }

        send(200, ['saved' => true, 'save' => buildResumePayload($saved, $slot)]);
    }

    // POST /sessions/{sessionID}/delete  -> delete a save file
    if ($method === 'POST' && count($parts) === 3 && $parts[0] === 'sessions' && $parts[2] === 'delete') {
        mongo();
        ownSessionOrFail($parts[1]);
        deleteSession($parts[1]);
        send(200, ['deleted' => true]);
    }

    // POST /sessions   body: {"playerID": "..."}
    if ($method === 'POST' && $parts === ['sessions']) {
        $playerID = requireLogin();
        mongo();
        send(201, ['sessionID' => startSession($playerID)]);
    }

    // POST /players   body: {"username": "...", "email": "..."}
    if ($method === 'POST' && $parts === ['players']) {
        mongo();
        $d = body();
        need($d, ['username', 'email']);
        $ageGroup = $d['age_group'] ?? null;
        if ($ageGroup !== null && !in_array($ageGroup, knownAgeGroups(), true)) {
            send(400, ['error' => 'Unknown age_group']);
        }
        send(201, ['playerID' => createPlayer($d['username'], $d['email'])]);
    }

    // GET /players/{username}
    if ($method === 'GET' && count($parts) === 2 && $parts[0] === 'players') {
        $playerID = requireLogin();
        mongo();
        $p = getPlayerByUsername($parts[1]);
        if ($p && $p['playerID'] !== $playerID) {
            send(403, ['error' => 'Not your account']);
        }
        if ($p) {
            unset($p['password_hash']);
            send(200, formatDates($p));
        }
    }

    // No route match
    send(404, ['error' => 'Route not found']);

} catch (Throwable $e) { // Server error
    send(500, ['error' => $e->getMessage()]);
}