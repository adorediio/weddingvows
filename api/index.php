<?php
require_once __DIR__ . '/../mysql/queries.php';

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
    // No route match
    send(404, ['error'=>'Route not found']);

} catch (exception $e){ //Server error
    send(500, ['error' => $e->getMessage()]);
}

