<?php
require_once __DIR__ . '/../mysql/queries.php';
require_once __DIR__ . '/choices.php';
require_once __DIR__ . '/outcome.php';

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
$parts = $path == '' ? [] : explode('/', $path);

try {
    // To get the health of the website
    if($method === 'GET' && $parts === ['health']){
        send(200, ['status' => 'ok']);
    }
    // To get /stats/reasons?year=2025
        if($method === 'GET' && $parts === ['stats', 'reasons']){
            $year = isset ($_GET['year']) ? (int) $_GET['year'] : latestYear();
            send(200, ['year' => $year, 'reasons' => reasonStats($year)]);
    }
    // To get /stats/age?year=2025
    if ($method === 'GET' && $parts === ['stats', 'age']) {
        $year = isset($_GET['year']) ? (int) $_GET['year'] : latestYear();
        send(200, ['year' => $year, 'ages' => ageStats($year)]);
    }

    // Saves the choices, decides the next scene and returns the outcome when story ends
    if ($method === 'POST' && $parts === ['choices']){
        mongo();
        $data = body();
        need($data, ['sessionID', 'choiceID', 'sceneID']);
        $rule = getChoiceRule($data['choiceID']);
        // if rule is empty
        if ($rule === NULL){
            send(400, ['error' => 'Unknown choiceID']);
        }
        $next = $rule['next'];
        saveChoice($data['sessionID'], $data['choiceID'], $data['sceneID'], $next ?? $data['sceneID']);
        $result = ['saved' => true, 'next_scene' => $next, 'finished' => $next === null];
        // if next choice is empty
        if ($next === null) {
            completeSession($data['sessionID']);
            $session = getSession($data['sessionID']);
            $result['outcome'] = buildOutcome($session['choices']);
        }
        send(200, $result);
    }

    if ($method === 'POST' && $parts === ['sessions']){
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
    send(404, ['error'=>'Route not found']);

} catch (exception $e){ //Server error
    send(500, ['error' => $e->getMessage()]);
}