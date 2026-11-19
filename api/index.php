<?php
require_once __DIR__ . '/../mysql/queries.php';
require_once __DIR__ . '/choices.php';
require_once __DIR__ . '/outcome.php';
require_once __DIR__ . '/profile.php';

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

// Browsers send an options request first
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'OPTIONS'){
    send(204, null); //No content, request works
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

    // POST /choices   body: {"sessionID": "...", "choiceID": "r2_02", "sceneID": "r2_s1"}
    // Saves the choice and returns the next scene. room_done is true when the next
    // scene is an ending. The session stays open until /sessions/{id}/complete.
    if ($method === 'POST' && $parts === ['choices']) {
        mongo();
        $data = body();
        need($data, ['sessionID', 'choiceID', 'sceneID']);

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

    // POST /sessions/{sessionID}/complete
    // Called once, when every room has been finished (showEnding). Closes the
    // session and returns the outcome that links the choices to the SQL statistics.
    if ($method === 'POST' && count($parts) === 3 && $parts[0] === 'sessions' && $parts[2] === 'complete') {
        mongo();
        $session = getSession($parts[1]);
        if (!$session) {
            send(404, ['error' => 'Session not found']);
        }
        completeSession($parts[1]);   // does nothing if it was already completed
        send(200, ['finished' => true, 'outcome' => buildOutcome($session['choices'])]);
    }

    // POST /sessions   body: {"playerID": "..."}
    if ($method === 'POST' && $parts === ['sessions']) {
        mongo();
        $data = body();
        need($data, ['playerID']);
        send(201, ['sessionID' => startSession($data['playerID'])]);
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
        mongo();
        $p = getPlayerByUsername($parts[1]);
        if ($p) {
            send(200, formatDates($p));
        }
        send(404, ['error' => 'Player not found']);
    }

    // No route match
    send(404, ['error' => 'Route not found']);

} catch (Throwable $e) { // Server error
    send(500, ['error' => $e->getMessage()]);
}