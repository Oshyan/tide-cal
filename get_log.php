<?php
/**
 * Get log for a specific calendar
 * Usage: get_log.php?id=<calendar_id>&lines=<num_lines>
 */

require_once __DIR__ . '/lib/Util.php';

header('Content-Type: application/json');

$calendar_id = $_GET['id'] ?? '';
$lines = (int)($_GET['lines'] ?? 50);

// Validate calendar ID format (alphanumeric, 12 chars)
if (!preg_match('/^[a-f0-9]{12}$/', $calendar_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid calendar ID format']);
    exit;
}

// Check if calendar exists
$calendar_file = __DIR__ . '/data/calendar-' . $calendar_id . '.ics';
if (!file_exists($calendar_file)) {
    http_response_code(404);
    echo json_encode(['error' => 'Calendar not found']);
    exit;
}

// Get log content
$log_content = Util::getCalendarLog($calendar_id, __DIR__ . '/data', $lines);

if ($log_content === null) {
    echo json_encode([
        'calendar_id' => $calendar_id,
        'log' => '',
        'message' => 'No log entries yet'
    ]);
} else {
    echo json_encode([
        'calendar_id' => $calendar_id,
        'log' => $log_content
    ]);
}
