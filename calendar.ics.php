<?php
require_once __DIR__ . '/lib/CalendarManager.php';
require_once __DIR__ . '/lib/IcsWriter.php';

// Load configuration
$config = require __DIR__ . '/config.php';

// Initialize calendar manager
$calendar_manager = new CalendarManager(__DIR__ . '/data');

// Get calendar ID from query parameter
$calendar_id = $_GET['id'] ?? '';

if (empty($calendar_id)) {
    // No ID provided - return error
    http_response_code(400);
    header('Content-Type: text/plain');
    echo "Error: Calendar ID required. Use ?id=CALENDAR_ID";
    exit;
}

// Validate calendar ID format (basic security check)
if (!preg_match('/^[a-f0-9]{12}$/', $calendar_id)) {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo "Error: Invalid calendar ID format";
    exit;
}

// Get calendar info
$calendar_info = $calendar_manager->getCalendar($calendar_id);

if (!$calendar_info) {
    // Calendar not found - return 404 with empty calendar
    http_response_code(404);
    header('Content-Type: text/calendar; charset=utf-8');
    header('Cache-Control: public, max-age=300'); // 5 minutes for not found
    
    // Return minimal empty calendar
    echo "BEGIN:VCALENDAR\r\n";
    echo "PRODID:-//TideCal//SingleStation//EN\r\n";
    echo "VERSION:2.0\r\n";
    echo "CALSCALE:GREGORIAN\r\n";
    echo "METHOD:PUBLISH\r\n";
    echo "X-WR-CALNAME:Tides - Calendar Not Found\r\n";
    echo "END:VCALENDAR\r\n";
    exit;
}

// Get calendar file path
$ics_file_path = $calendar_manager->getCalendarFilePath($calendar_id);

// Set appropriate headers for calendar content
header('Content-Type: text/calendar; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('Access-Control-Allow-Origin: *'); // Allow cross-origin requests for calendar subscriptions
header('Access-Control-Allow-Headers: Content-Type');

try {
    if (file_exists($ics_file_path)) {
        // Get file modification time for Last-Modified header
        $file_mtime = filemtime($ics_file_path);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $file_mtime) . ' GMT');
        
        // Optional ETag based on file modification time and size
        $etag = md5($file_mtime . filesize($ics_file_path) . $calendar_id);
        header('ETag: "' . $etag . '"');
        
        // Check if client has cached version
        $if_none_match = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        $if_modified_since = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
        
        if (($if_none_match && trim($if_none_match, '"') === $etag) || 
            ($if_modified_since && strtotime($if_modified_since) >= $file_mtime)) {
            header('HTTP/1.1 304 Not Modified');
            exit;
        }
        
        // Serve the ICS file
        $ics_content = file_get_contents($ics_file_path);
        
        if ($ics_content === false) {
            throw new Exception("Failed to read ICS file");
        }
        
        echo $ics_content;
        
    } else {
        // File doesn't exist - return empty calendar with calendar info
        $params = $calendar_info['params'];
        
        // Create temporary config for IcsWriter
        $temp_config = array_merge($config, $params);
        
        $ics_writer = new IcsWriter($temp_config);
        $empty_ics = $ics_writer->generateEmptyIcs(
            "Calendar not yet generated for {$params['station_name']} ({$params['year']})"
        );
        
        // Set appropriate cache headers for empty calendar
        header('Cache-Control: public, max-age=300'); // 5 minutes for empty calendar
        
        echo $empty_ics;
    }
    
} catch (Exception $e) {
    // Log error but don't expose details to client
    error_log("calendar.ics.php error for ID {$calendar_id}: " . $e->getMessage());
    
    // Return empty calendar on error to avoid breaking calendar subscriptions
    try {
        $params = $calendar_info['params'] ?? [];
        $station_name = $params['station_name'] ?? 'Unknown Station';
        
        $temp_config = array_merge($config, $params);
        $ics_writer = new IcsWriter($temp_config);
        $empty_ics = $ics_writer->generateEmptyIcs("Calendar temporarily unavailable - {$station_name}");
        
        header('Cache-Control: public, max-age=60'); // 1 minute retry for errors
        echo $empty_ics;
        
    } catch (Exception $fallback_error) {
        // Ultimate fallback - minimal valid ICS
        header('Cache-Control: public, max-age=60');
        echo "BEGIN:VCALENDAR\r\n";
        echo "PRODID:-//TideCal//SingleStation//EN\r\n";
        echo "VERSION:2.0\r\n";
        echo "CALSCALE:GREGORIAN\r\n";
        echo "METHOD:PUBLISH\r\n";
        echo "X-WR-CALNAME:Tides - Error\r\n";
        echo "END:VCALENDAR\r\n";
    }
}