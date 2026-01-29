<?php
require_once __DIR__ . '/lib/StationLookup.php';
require_once __DIR__ . '/lib/RateLimit.php';

header('Content-Type: application/json');

// Rate limiting for search requests
$rate_limiter = new RateLimit(__DIR__ . '/data/rate_limits');
$client_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

if (!$rate_limiter->isAllowed($client_ip, 'station_search', 30, 60)) {
    http_response_code(429);
    echo json_encode([
        'error' => 'Too many search requests. Please wait ' . $rate_limiter->getResetTime($client_ip, 'station_search', 60) . ' seconds.'
    ]);
    exit;
}

$query = $_GET['q'] ?? '';

if (empty($query) || strlen($query) < 2) {
    echo json_encode([]);
    exit;
}

// Get stations that match the search query
$stations = StationLookup::searchStations($query);

echo json_encode($stations);
?>