<?php
// Load configuration first (always safe)
$default_config = require __DIR__ . '/config.php';
require_once __DIR__ . '/lib/StationLookup.php';
require_once __DIR__ . '/lib/RateLimit.php';

function getCheckboxValue($key, $default) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        return isset($_POST[$key]);
    }
    return $default;
}

// Handle station lookup requests
if (isset($_POST['lookup_station'])) {
    $lookup_station_id = $_POST['lookup_station'];
    $lookup_data = StationLookup::getStationData($lookup_station_id);
    if ($lookup_data) {
        // Redirect back with the station data
        $query_params = http_build_query([
            'station_id' => $lookup_data['station_id'],
            'station_name' => $lookup_data['name'],
            'lat' => $lookup_data['lat'],
            'lon' => $lookup_data['lon'],
            'timezone' => $lookup_data['timezone']
        ]);
        header("Location: " . $_SERVER['PHP_SELF'] . "?" . $query_params);
        exit;
    }
}

// Handle form input and auto-populate station data
$station_id = $_GET['station_id'] ?? $_POST['station_id'] ?? $default_config['station_id'];
$station_data = null;

// Auto-populate station data if we have a station ID
if (!empty($station_id)) {
    $station_data = StationLookup::getStationData($station_id);
}

// Use station data if available, otherwise fall back to config/defaults
$form_data = [
    'station_id' => $station_id,
    'station_name' => $_GET['station_name'] ?? $station_data['name'] ?? $_POST['station_name'] ?? $default_config['station_name'],
    'lat' => $_GET['lat'] ?? $station_data['lat'] ?? $_POST['lat'] ?? $default_config['lat'],
    'lon' => $_GET['lon'] ?? $station_data['lon'] ?? $_POST['lon'] ?? $default_config['lon'],
    'timezone' => $_POST['timezone'] ?? $_GET['timezone'] ?? ($station_data['timezone'] ?? $default_config['timezone']),
    'year' => $_POST['year'] ?? $default_config['year'],
    'unit' => $_POST['unit'] ?? $default_config['unit'],
    'include_low_tides' => getCheckboxValue('include_low_tides', $default_config['include_low_tides']),
    'min_low_tide_value' => (float) ($_POST['min_low_tide_value'] ?? $default_config['min_low_tide_value']),
    'low_time_filter' => $_POST['low_time_filter'] ?? $default_config['low_time_filter'],
    'low_minutes_after_sunrise' => (int) ($_POST['low_minutes_after_sunrise'] ?? $default_config['low_minutes_after_sunrise']),
    'low_minutes_before_sunset' => (int) ($_POST['low_minutes_before_sunset'] ?? $default_config['low_minutes_before_sunset']),
    'low_earliest_time_enabled' => getCheckboxValue('low_earliest_time_enabled', $default_config['low_earliest_time_enabled']),
    'low_earliest_time' => $_POST['low_earliest_time'] ?? $default_config['low_earliest_time'],
    'low_latest_time_enabled' => getCheckboxValue('low_latest_time_enabled', $default_config['low_latest_time_enabled']),
    'low_latest_time' => $_POST['low_latest_time'] ?? $default_config['low_latest_time'],
    'include_high_tides' => getCheckboxValue('include_high_tides', $default_config['include_high_tides']),
    'high_tide_min_value' => (float) ($_POST['high_tide_min_value'] ?? $default_config['high_tide_min_value']),
    'high_time_filter' => $_POST['high_time_filter'] ?? $default_config['high_time_filter'],
    'high_minutes_after_sunrise' => (int) ($_POST['high_minutes_after_sunrise'] ?? $default_config['high_minutes_after_sunrise']),
    'high_minutes_before_sunset' => (int) ($_POST['high_minutes_before_sunset'] ?? $default_config['high_minutes_before_sunset']),
    'high_earliest_time_enabled' => getCheckboxValue('high_earliest_time_enabled', $default_config['high_earliest_time_enabled']),
    'high_earliest_time' => $_POST['high_earliest_time'] ?? $default_config['high_earliest_time'],
    'high_latest_time_enabled' => getCheckboxValue('high_latest_time_enabled', $default_config['high_latest_time_enabled']),
    'high_latest_time' => $_POST['high_latest_time'] ?? $default_config['high_latest_time'],
    'include_sunrise_events' => getCheckboxValue('include_sunrise_events', $default_config['include_sunrise_events']),
    'include_sunset_events' => getCheckboxValue('include_sunset_events', $default_config['include_sunset_events']),
    'sun_events_match_tide_days' => isset($_POST['sun_events_match_tide_days']) ? (bool)$_POST['sun_events_match_tide_days'] : $default_config['sun_events_match_tide_days']
];

// Initialize variables
$result_message = '';
$result_type = '';
$calendar_url = '';
$calendar_id_for_log = '';

// Handle form submission (load complex logic only when needed)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate') {
    // Rate limiting for calendar generation
    $rate_limiter = new RateLimit(__DIR__ . '/data/rate_limits');
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    if (!$rate_limiter->isAllowed($client_ip, 'calendar_generation', 5, 300)) {
        $result_type = 'error';
        $result_message = 'Too many calendar generation requests. Please wait ' . $rate_limiter->getResetTime($client_ip, 'calendar_generation', 300) . ' seconds before generating another calendar.';
        $calendar_url = '';
    } else {
        try {
            // Load required libraries only on form submission
        require_once __DIR__ . '/lib/TideProvider.php';
        require_once __DIR__ . '/lib/SunCalc.php';
        require_once __DIR__ . '/lib/IcsWriter.php';
        require_once __DIR__ . '/lib/Util.php';
        require_once __DIR__ . '/lib/CalendarManager.php';
        
        // Initialize calendar manager
        $calendar_manager = new CalendarManager(__DIR__ . '/data');
        
        // Validate inputs
        $validation_error = validateFormData($form_data);
        if ($validation_error) {
            throw new Exception($validation_error);
        }
        
        // Resolve year for processing
        $resolved_year = Util::parseYear($form_data['year']);
        
        // Create working config
        $working_config = array_merge($default_config, $form_data);
        $working_config['year'] = $resolved_year;
        
        // Get or create calendar entry
        $calendar_entry = $calendar_manager->getOrCreateCalendar($working_config);
        $calendar_id = $calendar_entry['id'];
        $calendar_id_for_log = $calendar_id;

        // Generate calendar URL
        $calendar_url = $calendar_manager->getCalendarUrl($calendar_id, $default_config['base_url']);
        
        $generation_result = Util::timeExecution(function() use ($working_config, $calendar_manager, $calendar_id) {
            return generateTideCalendar($working_config, $calendar_manager, $calendar_id);
        });
        
        $stats = $generation_result['result'];
        $stats['duration'] = $generation_result['duration'];
        $stats['calendar_id'] = $calendar_id;
        $stats['calendar_url'] = $calendar_url;
        
        // Log the generation run (both global and per-calendar)
        Util::logGenerationRun($stats, __DIR__ . '/logs', __DIR__ . '/data');
        
        $result_type = 'success';
        $result_message = buildSuccessMessage($stats, $working_config);
        
    } catch (Exception $e) {
        $error_msg = $e->getMessage();
        error_log("Generation failed: {$error_msg}");
        
        $result_type = 'error';
        $result_message = "Generation failed: {$error_msg}";
        $calendar_url = ''; // Ensure no URL is shown on error
        }
    }
}

/**
 * Validate form data
 */
function validateFormData($data) {
    if (empty($data['station_id']) || !preg_match('/^\d{7,8}$/', $data['station_id'])) {
        return "Invalid station ID. Must be 7-8 digits.";
    }
    
    if (empty($data['station_name'])) {
        return "Station name is required.";
    }
    
    if (!is_numeric($data['lat']) || $data['lat'] < -90 || $data['lat'] > 90) {
        return "Invalid latitude. Must be -90 to 90.";
    }
    
    if (!is_numeric($data['lon']) || $data['lon'] < -180 || $data['lon'] > 180) {
        return "Invalid longitude. Must be -180 to 180.";
    }
    
    try {
        new DateTimeZone($data['timezone']);
    } catch (Exception $e) {
        return "Invalid timezone identifier.";
    }
    
    $year = (int)$data['year'];
    if ($year < 1900 || $year > 2100) {
        return "Year must be between 1900 and 2100.";
    }
    
    if (!in_array($data['unit'], ['ft', 'm'], true)) {
        return "Unit must be 'ft' or 'm'.";
    }
    
    if (!is_numeric($data['min_low_tide_value'])) {
        return "Min tide value must be a number.";
    }
    
    if (!is_numeric($data['high_tide_min_value'])) {
        return "High tide minimum value must be a number.";
    }
    
    $allowed_time_filters = ['none', 'after_sunrise', 'before_sunset', 'between'];
    if (!in_array($data['low_time_filter'], $allowed_time_filters, true)) {
        return "Low tide time filter must be one of: none, after_sunrise, before_sunset, between.";
    }
    if (!in_array($data['high_time_filter'], $allowed_time_filters, true)) {
        return "High tide time filter must be one of: none, after_sunrise, before_sunset, between.";
    }
    
    if ($data['low_minutes_after_sunrise'] < 0 || $data['low_minutes_after_sunrise'] > 1440) {
        return "Low tide sunrise margin must be between 0 and 1440 minutes.";
    }
    if ($data['low_minutes_before_sunset'] < 0 || $data['low_minutes_before_sunset'] > 1440) {
        return "Low tide sunset margin must be between 0 and 1440 minutes.";
    }
    if ($data['high_minutes_after_sunrise'] < 0 || $data['high_minutes_after_sunrise'] > 1440) {
        return "High tide sunrise margin must be between 0 and 1440 minutes.";
    }
    if ($data['high_minutes_before_sunset'] < 0 || $data['high_minutes_before_sunset'] > 1440) {
        return "High tide sunset margin must be between 0 and 1440 minutes.";
    }

    $time_pattern = '/^([01]\\d|2[0-3]):[0-5]\\d$/';
    if (!empty($data['low_earliest_time_enabled']) && !preg_match($time_pattern, (string) $data['low_earliest_time'])) {
        return "Low tide earliest time must be in HH:MM (24h) format.";
    }
    if (!empty($data['low_latest_time_enabled']) && !preg_match($time_pattern, (string) $data['low_latest_time'])) {
        return "Low tide latest time must be in HH:MM (24h) format.";
    }
    if (!empty($data['high_earliest_time_enabled']) && !preg_match($time_pattern, (string) $data['high_earliest_time'])) {
        return "High tide earliest time must be in HH:MM (24h) format.";
    }
    if (!empty($data['high_latest_time_enabled']) && !preg_match($time_pattern, (string) $data['high_latest_time'])) {
        return "High tide latest time must be in HH:MM (24h) format.";
    }
    
    return null; // No validation errors
}

/**
 * Main generation pipeline
 */
function generateTideCalendar($config, $calendar_manager, $calendar_id) {
    $stats = [
        'year' => $config['year'],
        'fetched_count' => 0,
        'kept_count' => 0,
        'kept_low_count' => 0,
        'kept_high_count' => 0,
        'sun_events_count' => 0,
        'warnings' => 0,
        'errors' => 0
    ];
    
    // Step 1: Fetch tide predictions
    $provider = new TideProvider($config);
    $date_range = Util::getYearDateRange($config['year']);
    
    $tide_predictions = $provider->fetchTidePredictions(
        $config['station_id'],
        $date_range['start'],
        $date_range['end'],
        $config['timezone']
    );
    
    $stats['fetched_count'] = count($tide_predictions);
    
    // Step 2: Prepare thresholds
    $low_threshold_m = null;
    if (!empty($config['include_low_tides'])) {
        $low_threshold_m = ($config['unit'] === 'ft') 
            ? TideProvider::feetToMeters($config['min_low_tide_value'])
            : $config['min_low_tide_value'];
    }
    $high_threshold_m = null;
    if (!empty($config['include_high_tides'])) {
        $high_threshold_m = ($config['unit'] === 'ft') 
            ? TideProvider::feetToMeters($config['high_tide_min_value'])
            : $config['high_tide_min_value'];
    }
    
    // Step 3: Apply filters and build events
    $ics_writer = new IcsWriter($config);
    $kept_events = [];
    $qualifying_dates = [];
    
    foreach ($tide_predictions as $tide) {
        $type = $tide['type'] ?? '';
        $include = false;
        $time_check = [
            'passes' => true,
            'sunset_time' => null,
            'sunrise_time' => null,
            'margin_minutes' => null
        ];
        
        if ($type === 'L' && !empty($config['include_low_tides']) && $low_threshold_m !== null) {
            if ($tide['value_m'] <= $low_threshold_m) {
                $time_check = SunCalc::checkTimeWindow(
                    $tide['ts_local'],
                    $config['lat'],
                    $config['lon'],
                    $config['timezone'],
                    $config['low_time_filter'],
                    $config['low_minutes_after_sunrise'],
                    $config['low_minutes_before_sunset']
                );
                $clock_check = SunCalc::checkClockWindow(
                    $tide['ts_local'],
                    $config['timezone'],
                    $config['low_earliest_time_enabled'],
                    $config['low_earliest_time'],
                    $config['low_latest_time_enabled'],
                    $config['low_latest_time']
                );
                $include = $time_check['passes'] && $clock_check['passes'];
                if ($include) {
                    $stats['kept_low_count']++;
                }
            }
        } elseif ($type === 'H' && !empty($config['include_high_tides']) && $high_threshold_m !== null) {
            if ($tide['value_m'] >= $high_threshold_m) {
                $time_check = SunCalc::checkTimeWindow(
                    $tide['ts_local'],
                    $config['lat'],
                    $config['lon'],
                    $config['timezone'],
                    $config['high_time_filter'],
                    $config['high_minutes_after_sunrise'],
                    $config['high_minutes_before_sunset']
                );
                $clock_check = SunCalc::checkClockWindow(
                    $tide['ts_local'],
                    $config['timezone'],
                    $config['high_earliest_time_enabled'],
                    $config['high_earliest_time'],
                    $config['high_latest_time_enabled'],
                    $config['high_latest_time']
                );
                $include = $time_check['passes'] && $clock_check['passes'];
                if ($include) {
                    $stats['kept_high_count']++;
                }
            }
        }
        
        if ($include) {
            // Only warn about missing sunrise/sunset for tides we're actually including
            if ($time_check['sunset_time'] === null && $time_check['sunrise_time'] === null) {
                $stats['warnings']++;
                error_log("No sunrise/sunset data for {$tide['ts_local']}, including event");
            }
            $ics_writer->addTideEvent($tide, $time_check);
            $kept_events[] = $tide;
            $qualifying_dates[substr($tide['ts_local'], 0, 10)] = true;
        }
    }
    
    $stats['kept_count'] = count($kept_events);
    
    // Step 4: Add sunrise/sunset events (optional)
    if (!empty($config['include_sunrise_events']) || !empty($config['include_sunset_events'])) {
        $dates = [];
        if (!empty($config['sun_events_match_tide_days'])) {
            $dates = array_keys($qualifying_dates);
        } else {
            $dates = Util::getDatesInYear($config['year']);
        }
        
        foreach ($dates as $date) {
            $sun_times = SunCalc::getSunriseSunset($config['lat'], $config['lon'], $date, $config['timezone']);
            if (!$sun_times) {
                continue;
            }
            
            if (!empty($config['include_sunrise_events']) && !empty($sun_times['sunrise'])) {
                $ics_writer->addSunEvent($date, $sun_times['sunrise'], 'sunrise');
                $stats['sun_events_count']++;
            }
            if (!empty($config['include_sunset_events']) && !empty($sun_times['sunset'])) {
                $ics_writer->addSunEvent($date, $sun_times['sunset'], 'sunset');
                $stats['sun_events_count']++;
            }
        }
    }
    
    // Step 5: Generate and write ICS file to unique calendar file
    $ics_content = $ics_writer->generateIcs();
    $ics_path = $calendar_manager->getCalendarFilePath($calendar_id);
    
    $ics_writer->writeToFile($ics_content, $ics_path);
    
    return $stats;
}

/**
 * Build success message with statistics
 */
function buildSuccessMessage($stats, $config) {
    $lines = [];
    $lines[] = "✅ Generated calendar ID: {$stats['calendar_id']}";
    $lines[] = "📊 Fetched {$stats['fetched_count']} tide predictions, kept {$stats['kept_count']} tide events";
    
    $detail_parts = [];
    if (!empty($config['include_low_tides'])) {
        $detail_parts[] = "{$stats['kept_low_count']} low";
    }
    if (!empty($config['include_high_tides'])) {
        $detail_parts[] = "{$stats['kept_high_count']} high";
    }
    if (!empty($detail_parts)) {
        $lines[] = "• Breakdown: " . implode(', ', $detail_parts);
    }
    if (!empty($stats['sun_events_count'])) {
        $lines[] = "☀️ Added {$stats['sun_events_count']} sunrise/sunset events";
    }
    $lines[] = "⏱️ Completed in " . number_format($stats['duration'], 2) . "s";
    
    if ($stats['warnings'] > 0) {
        $lines[] = "⚠️ {$stats['warnings']} warnings (check logs for details)";
    }
    
    return implode('\n', $lines);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TideCal - Low Tide Calendar Generator</title>
    <!-- Cache buster: v6.0 - Design overhaul -->
    <style>
        :root {
            --primary: #007cba;
            --primary-dark: #005a87;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --success: #059669;
            --success-bg: #d1fae5;
            --error: #dc2626;
            --error-bg: #fee2e2;
            --radius-sm: 4px;
            --radius-md: 8px;
            --radius-lg: 12px;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 820px;
            margin: 1.5rem auto;
            padding: 0 1rem;
            line-height: 1.5;
            color: var(--gray-800);
            background: var(--gray-50);
        }

        .header {
            text-align: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--gray-200);
        }
        .header h1 {
            margin: 0 0 0.25rem;
            font-size: 1.75rem;
        }
        .header h2 {
            margin: 0;
            font-size: 1rem;
            font-weight: 400;
            color: var(--gray-500);
        }

        .btn {
            background: var(--primary);
            color: white;
            border: none;
            padding: 10px 20px;
            font-size: 14px;
            font-weight: 500;
            border-radius: var(--radius-md);
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: background-color 0.15s, transform 0.1s;
        }
        .btn:hover { background: var(--primary-dark); }
        .btn:active { transform: scale(0.98); }
        .btn.secondary { background: var(--gray-500); }
        .btn.secondary:hover { background: var(--gray-600); }

        /* Compact form sections */
        .form-section {
            background: white;
            padding: 0.875rem 1rem;
            border-radius: var(--radius-lg);
            margin-bottom: 0.75rem;
            border: 1px solid var(--gray-200);
        }
        .form-section h3 {
            margin: 0 0 0.75rem;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Top section - station left, settings right */
        .setup-grid {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 1.5rem;
            align-items: start;
        }
        .setup-grid .station-col {
            display: grid;
            grid-template-columns: 85px 160px 110px;
            gap: 0.5rem 0.75rem;
        }
        .setup-grid .station-col .full-row {
            grid-column: 1 / -1;
        }
        .setup-grid .settings-col {
            padding-left: 1.5rem;
            border-left: 1px solid var(--gray-200);
        }
        .settings-col h4 {
            margin: 0 0 0.5rem;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--gray-600);
        }
        .settings-row {
            display: flex;
            gap: 1rem;
            align-items: center;
            margin-bottom: 0.75rem;
        }
        .settings-row .inline-field {
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        .settings-row .inline-field label {
            font-size: 0.8rem;
            font-weight: 500;
            color: var(--gray-600);
            white-space: nowrap;
        }
        .settings-row .inline-field input,
        .settings-row .inline-field select {
            padding: 5px 8px;
            font-size: 13px;
            border: 1px solid var(--gray-300);
            border-radius: var(--radius-sm);
        }
        .settings-row .inline-field input[type="number"] {
            width: 65px;
        }
        .settings-row .inline-field select {
            width: auto;
        }
        .settings-col .sun-section {
            margin-top: 0.75rem;
            padding-top: 0.75rem;
            border-top: 1px solid var(--gray-200);
        }
        .settings-col .sun-title {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--gray-600);
            margin: 0 0 0.4rem;
        }
        .settings-col .sun-row {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 0.5rem;
        }
        .settings-col .sun-check {
            display: flex;
            align-items: center;
            gap: 0.3rem;
            font-size: 0.8rem;
        }
        .settings-col .sun-check input {
            margin: 0;
        }
        .settings-col .sun-select-row {
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        .settings-col .sun-select-row label {
            font-size: 0.75rem;
            color: var(--gray-500);
        }
        .settings-col .sun-select-row select {
            font-size: 0.8rem;
            padding: 3px 6px;
            border: 1px solid var(--gray-300);
            border-radius: var(--radius-sm);
        }

        .form-group {
            margin-bottom: 0;
        }
        .form-group.full-width {
            grid-column: 1 / -1;
        }
        .form-group.span-2 {
            grid-column: span 2;
        }
        .form-group label {
            display: block;
            font-weight: 600;
            font-size: 0.8rem;
            color: var(--gray-600);
            margin-bottom: 0.2rem;
        }
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 7px 10px;
            border: 1px solid var(--gray-300);
            border-radius: var(--radius-sm);
            font-size: 14px;
            box-sizing: border-box;
            background: white;
        }
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(0, 124, 186, 0.15);
        }
        .form-group input.grayed-out,
        .form-group input[readonly] {
            background-color: var(--gray-100);
            color: var(--gray-500);
            border-color: var(--gray-200);
        }
        .form-group small {
            display: block;
            color: var(--gray-400);
            margin-top: 0.15rem;
            font-size: 11px;
        }

        .search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid var(--gray-300);
            border-top: none;
            border-radius: 0 0 var(--radius-sm) var(--radius-sm);
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .search-result-item {
            padding: 8px 12px;
            cursor: pointer;
            border-bottom: 1px solid var(--gray-100);
            font-size: 13px;
        }
        .search-result-item:hover { background-color: var(--gray-50); }
        .search-result-item:last-child { border-bottom: none; }
        .form-group { position: relative; }

        /* Section headers - more compact */
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 0.75rem;
        }
        .section-header h3 { margin: 0; }
        .section-subtitle {
            margin: 0;
            color: var(--gray-400);
            font-size: 0.8rem;
            font-weight: 400;
        }

        /* Tide filter cards - more compact */
        .filters-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
        }
        .filter-card {
            background: var(--gray-50);
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-md);
            padding: 0.75rem;
        }
        .filter-card.is-disabled { opacity: 0.5; }

        .filter-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            margin-bottom: 0.75rem;
            padding-bottom: 0.625rem;
            border-bottom: 1px solid var(--gray-200);
        }
        .filter-card-title {
            margin: 0;
            font-size: 0.9rem;
            font-weight: 600;
        }
        .filter-card-sub {
            margin: 2px 0 0;
            font-size: 0.75rem;
            color: var(--gray-400);
        }

        /* Toggle switch - smaller */
        .toggle {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.75rem;
            color: var(--gray-500);
            white-space: nowrap;
        }
        .switch {
            position: relative;
            display: inline-block;
            width: 36px;
            height: 20px;
        }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider {
            position: absolute;
            cursor: pointer;
            inset: 0;
            background-color: var(--gray-300);
            transition: 0.15s;
            border-radius: 999px;
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 14px;
            width: 14px;
            left: 3px;
            top: 3px;
            background-color: #fff;
            transition: 0.15s;
            border-radius: 50%;
            box-shadow: 0 1px 2px rgba(0,0,0,0.15);
        }
        .switch input:checked + .slider { background-color: var(--primary); }
        .switch input:checked + .slider:before { transform: translateX(16px); }

        /* Filter content - natural sizing */
        .filter-content {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        .filter-row {
            display: flex;
            gap: 1rem;
            align-items: flex-start;
        }

        .field-group {
            display: flex;
            flex-direction: column;
        }
        .field-label {
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.03em;
            margin-bottom: 0.25rem;
        }
        .field-group input[type="number"] {
            width: 60px;
            padding: 5px 7px;
            font-size: 13px;
        }
        .field-group select {
            width: auto;
            min-width: 120px;
            padding: 5px 7px;
            font-size: 13px;
        }
        .field-group small {
            font-size: 10px;
            color: var(--gray-400);
            margin-top: 0.2rem;
        }

        /* Time window row */
        .time-row {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .time-window-row {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.4rem 0.5rem;
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-sm);
            flex-shrink: 0;
        }
        .time-window-row input[type="checkbox"] {
            width: 13px;
            height: 13px;
            margin: 0;
        }
        .time-window-row label {
            font-size: 0.75rem;
            font-weight: 500;
            color: var(--gray-600);
            white-space: nowrap;
        }
        .time-window-row input[type="time"] {
            padding: 3px 5px;
            font-size: 11px;
            width: 85px;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-sm);
        }

        /* Sun events - inline compact */
        .sun-events-row {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            flex-wrap: wrap;
        }
        .sun-check {
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        .sun-check input[type="checkbox"] {
            width: 15px;
            height: 15px;
            margin: 0;
        }
        .sun-check label {
            font-size: 0.85rem;
            font-weight: 500;
            color: var(--gray-700);
        }
        .sun-check small {
            font-size: 0.75rem;
            color: var(--gray-400);
            margin-left: 0.25rem;
        }

        .divider {
            width: 1px;
            height: 20px;
            background: var(--gray-300);
        }

        /* Buttons */
        .button-section {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.75rem;
            margin: 1.25rem 0 0.5rem;
        }
        .button-section + p {
            text-align: center;
            color: var(--gray-400);
            font-size: 0.8rem;
            margin: 0.5rem 0 0;
        }

        /* Results */
        .result {
            margin: 0 0 1rem;
            padding: 0.875rem;
            border-radius: var(--radius-md);
            font-size: 0.9rem;
        }
        .result.success {
            background: var(--success-bg);
            border: 1px solid #a7f3d0;
            color: #065f46;
        }
        .result.error {
            background: var(--error-bg);
            border: 1px solid #fecaca;
            color: #991b1b;
        }
        .result pre {
            margin: 0;
            font-size: 0.85rem;
            line-height: 1.5;
        }

        .subscription-url {
            background: #fef3c7;
            border: 1px solid #fcd34d;
            padding: 1rem;
            border-radius: var(--radius-md);
            margin-top: 1rem;
        }
        .subscription-url h3 {
            margin: 0 0 0.5rem;
            font-size: 1rem;
        }
        .subscription-url p {
            margin: 0 0 0.75rem;
            font-size: 0.85rem;
            color: var(--gray-600);
        }

        .url-box {
            background: white;
            border: 1px solid var(--gray-300);
            padding: 10px 70px 10px 10px;
            border-radius: var(--radius-sm);
            font-family: ui-monospace, monospace;
            font-size: 12px;
            word-break: break-all;
            position: relative;
        }
        .copy-btn {
            position: absolute;
            top: 6px;
            right: 6px;
            background: var(--primary);
            color: white;
            border: none;
            padding: 4px 10px;
            border-radius: var(--radius-sm);
            font-size: 11px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.15s;
        }
        .copy-btn:hover { background: var(--primary-dark); }

        .calendar-buttons {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            margin: 0.75rem 0;
        }
        .btn-calendar-outline {
            background: white;
            color: var(--gray-700);
            border: 1px solid var(--gray-300);
            padding: 10px 8px;
            border-radius: var(--radius-md);
            cursor: pointer;
            font-size: 12px;
            font-weight: 500;
            text-align: center;
            transition: all 0.15s;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            gap: 4px;
        }
        .btn-calendar-outline:hover {
            border-color: var(--primary);
            background: var(--gray-50);
        }
        .btn-clicked {
            background: var(--success) !important;
            color: white !important;
            border-color: var(--success) !important;
        }

        .footer {
            margin-top: 2rem;
            padding-top: 1rem;
            border-top: 1px solid var(--gray-200);
            text-align: center;
            font-size: 0.8rem;
            color: var(--gray-400);
        }
        .footer p { margin: 0.25rem 0; }

        pre { white-space: pre-wrap; word-wrap: break-word; }

        /* Modal */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            padding: 1rem;
        }
        .modal-overlay.active { display: flex; }
        .modal {
            background: #fff;
            border-radius: var(--radius-lg);
            width: 100%;
            max-width: 520px;
            max-height: 85vh;
            overflow: auto;
            padding: 1.25rem;
            box-shadow: 0 20px 60px rgba(0,0,0,0.25);
        }
        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.75rem;
        }
        .modal-header h3 {
            margin: 0;
            font-size: 1.1rem;
        }
        .modal-close {
            background: transparent;
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: var(--gray-400);
            padding: 0;
            line-height: 1;
        }
        .modal-close:hover { color: var(--gray-600); }

        /* Responsive */
        @media (max-width: 768px) {
            .setup-grid {
                grid-template-columns: 1fr;
            }
            .setup-grid .calendar-col {
                padding-left: 0;
                padding-top: 1rem;
                border-left: none;
                border-top: 1px solid var(--gray-200);
            }
            .filters-grid {
                grid-template-columns: 1fr;
            }
            .filter-row.triple {
                grid-template-columns: 1fr;
            }
            .section-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.25rem;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🌊 TideCal US</h1>
        <h2>Tide Calendar Generator for the United States</h2>
    </div>

    <?php if ($result_message): ?>
    <div id="result_modal" class="modal-overlay active" role="dialog" aria-modal="true">
        <div class="modal">
            <div class="modal-header">
                <h3><?php echo ($result_type === 'success') ? '✅ Calendar Generated' : '⚠️ Generation Failed'; ?></h3>
                <button type="button" class="modal-close" onclick="closeResultModal()" aria-label="Close">×</button>
            </div>
            <div class="result <?php echo $result_type; ?>" style="margin: 0 0 1rem;">
                <pre><?php echo htmlspecialchars(str_replace('\n', "\n", $result_message)); ?></pre>
            </div>
            
            <?php if ($calendar_url): ?>
            <div class="subscription-url">
                <h3>📅 Subscribe to Calendar</h3>
                <p>Choose how to add this calendar:</p>

                <div class="calendar-buttons">
                    <button class="btn-calendar-outline" onclick="copyToClipboard('<?php echo htmlspecialchars($calendar_url); ?>')">
                        📋<br><small>Copy URL</small>
                    </button>
                    <button class="btn-calendar-outline" onclick="addToAppleCalendar('<?php echo htmlspecialchars($calendar_url); ?>')">
                        🍎<br><small>Apple</small>
                    </button>
                    <button class="btn-calendar-outline" onclick="downloadCalendar('<?php echo htmlspecialchars($calendar_url); ?>')">
                        💾<br><small>Download</small>
                    </button>
                </div>

                <div class="url-box">
                    <?php echo htmlspecialchars($calendar_url); ?>
                    <button class="copy-btn" onclick="copyToClipboard('<?php echo htmlspecialchars($calendar_url); ?>')">Copy</button>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($calendar_id_for_log): ?>
            <div id="log-section" style="display: none; margin-top: 1rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                    <strong style="font-size: 0.85rem; color: #666;">📋 Generation Log</strong>
                    <button type="button" onclick="document.getElementById('log-section').style.display='none'" style="background: none; border: none; cursor: pointer; font-size: 1rem;">×</button>
                </div>
                <pre id="log-content" style="background: #f5f5f5; padding: 0.75rem; border-radius: 4px; font-size: 0.75rem; max-height: 150px; overflow-y: auto; margin: 0; white-space: pre-wrap; word-break: break-all;"></pre>
            </div>
            <?php endif; ?>

            <div class="button-section" style="margin-top: 1rem;">
                <button class="btn secondary" type="button" onclick="closeResultModal()">Close</button>
                <?php if ($calendar_id_for_log): ?>
                <button class="btn secondary" type="button" onclick="viewLog('<?php echo htmlspecialchars($calendar_id_for_log); ?>')">📋 View Log</button>
                <?php endif; ?>
                <a href="calendars.php" class="btn secondary">📅 All Calendars</a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="action" value="generate">

        <!-- Station & Settings -->
        <div class="form-section">
            <div class="setup-grid">
                <div class="station-col">
                    <div class="form-group full-row">
                        <label for="station_search">Search Stations</label>
                        <input type="text" id="station_search" placeholder="Type station name or location..."
                               autocomplete="off" onkeyup="searchStations(this.value)">
                        <div id="search_results" class="search-results"></div>
                    </div>

                    <div class="form-group">
                        <label for="station_id">Station ID</label>
                        <input type="text" id="station_id" name="station_id"
                               value="<?php echo htmlspecialchars($form_data['station_id']); ?>"
                               pattern="[0-9]{7,8}" required readonly>
                    </div>

                    <div class="form-group">
                        <label for="station_name">Name</label>
                        <input type="text" id="station_name" name="station_name"
                               value="<?php echo htmlspecialchars($form_data['station_name']); ?>" readonly class="grayed-out">
                    </div>

                    <div class="form-group">
                        <label for="timezone">Timezone</label>
                        <select id="timezone" name="timezone" required>
                            <?php
                            $common_timezones = [
                                'America/New_York' => 'Eastern',
                                'America/Chicago' => 'Central',
                                'America/Denver' => 'Mountain',
                                'America/Los_Angeles' => 'Pacific',
                                'America/Anchorage' => 'Alaska',
                                'Pacific/Honolulu' => 'Hawaii',
                                'UTC' => 'UTC'
                            ];

                            foreach ($common_timezones as $tz_id => $tz_name) {
                                $selected = ($tz_id === $form_data['timezone']) ? 'selected' : '';
                                echo "<option value=\"" . htmlspecialchars($tz_id) . "\" {$selected}>" . htmlspecialchars($tz_name) . "</option>";
                            }

                            if (!isset($common_timezones[$form_data['timezone']])) {
                                echo "<option value=\"" . htmlspecialchars($form_data['timezone']) . "\" selected>" . htmlspecialchars($form_data['timezone']) . "</option>";
                            }
                            ?>
                        </select>
                    </div>

                    <input type="hidden" id="lat" name="lat" value="<?php echo htmlspecialchars($form_data['lat']); ?>">
                    <input type="hidden" id="lon" name="lon" value="<?php echo htmlspecialchars($form_data['lon']); ?>">
                </div>

                <div class="settings-col">
                    <h4>Calendar Settings</h4>
                    <div class="settings-row">
                        <div class="inline-field">
                            <label for="year">Year</label>
                            <input type="number" id="year" name="year"
                                   value="<?php echo htmlspecialchars($form_data['year']); ?>"
                                   min="2000" max="2050" required>
                        </div>
                        <div class="inline-field">
                            <label for="unit">Unit</label>
                            <select id="unit" name="unit" required>
                                <option value="ft" <?php echo ($form_data['unit'] === 'ft') ? 'selected' : ''; ?>>Feet</option>
                                <option value="m" <?php echo ($form_data['unit'] === 'm') ? 'selected' : ''; ?>>Meters</option>
                            </select>
                        </div>
                    </div>

                    <div class="sun-section">
                        <p class="sun-title">☀️ Sun Events</p>
                        <div class="sun-row">
                            <label class="sun-check">
                                <input type="checkbox" id="include_sunrise_events" name="include_sunrise_events" <?php echo $form_data['include_sunrise_events'] ? 'checked' : ''; ?>>
                                Sunrise
                            </label>
                            <label class="sun-check">
                                <input type="checkbox" id="include_sunset_events" name="include_sunset_events" <?php echo $form_data['include_sunset_events'] ? 'checked' : ''; ?>>
                                Sunset
                            </label>
                        </div>
                        <div class="sun-select-row">
                            <label for="sun_events_match_tide_days">When:</label>
                            <select id="sun_events_match_tide_days" name="sun_events_match_tide_days">
                                <option value="1" <?php echo $form_data['sun_events_match_tide_days'] ? 'selected' : ''; ?>>Tide days only</option>
                                <option value="0" <?php echo !$form_data['sun_events_match_tide_days'] ? 'selected' : ''; ?>>Every day</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tide Filters -->
        <div class="form-section">
            <div class="section-header">
                <h3>🌊 Tide Filters</h3>
                <span class="section-subtitle">Choose tide types and filtering criteria</span>
            </div>
            <div class="filters-grid">
                <!-- Low Tides -->
                <div class="filter-card" data-toggle="include_low_tides">
                    <div class="filter-card-header">
                        <div>
                            <p class="filter-card-title">Low Tides</p>
                        </div>
                        <div class="toggle">
                            <label class="switch" for="include_low_tides">
                                <input type="checkbox" id="include_low_tides" name="include_low_tides" <?php echo $form_data['include_low_tides'] ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                    </div>

                    <div class="filter-content">
                        <div class="filter-row">
                            <div class="field-group">
                                <div class="field-label">Max Height</div>
                                <input type="number" id="min_low_tide_value" name="min_low_tide_value"
                                       step="0.1" value="<?php echo $form_data['min_low_tide_value']; ?>">
                                <small>Include lows ≤ this</small>
                            </div>
                            <div class="field-group">
                                <div class="field-label">Daylight Filter</div>
                                <select id="low_time_filter" name="low_time_filter">
                                    <option value="none" <?php echo ($form_data['low_time_filter'] === 'none') ? 'selected' : ''; ?>>None</option>
                                    <option value="after_sunrise" <?php echo ($form_data['low_time_filter'] === 'after_sunrise') ? 'selected' : ''; ?>>After sunrise</option>
                                    <option value="before_sunset" <?php echo ($form_data['low_time_filter'] === 'before_sunset') ? 'selected' : ''; ?>>Before sunset</option>
                                    <option value="between" <?php echo ($form_data['low_time_filter'] === 'between') ? 'selected' : ''; ?>>Daylight only</option>
                                </select>
                            </div>
                        </div>

                        <div class="filter-row">
                            <div class="field-group">
                                <div class="field-label">Min After Sunrise</div>
                                <input type="number" id="low_minutes_after_sunrise" name="low_minutes_after_sunrise"
                                       value="<?php echo $form_data['low_minutes_after_sunrise']; ?>" min="0" max="1440">
                            </div>
                            <div class="field-group">
                                <div class="field-label">Min Before Sunset</div>
                                <input type="number" id="low_minutes_before_sunset" name="low_minutes_before_sunset"
                                       value="<?php echo $form_data['low_minutes_before_sunset']; ?>" min="0" max="1440">
                            </div>
                        </div>

                        <div class="time-row">
                            <div class="time-window-row">
                                <input type="checkbox" id="low_earliest_time_enabled" name="low_earliest_time_enabled" <?php echo $form_data['low_earliest_time_enabled'] ? 'checked' : ''; ?>>
                                <label for="low_earliest_time_enabled">After</label>
                                <input type="time" id="low_earliest_time" name="low_earliest_time" value="<?php echo htmlspecialchars($form_data['low_earliest_time']); ?>">
                            </div>
                            <div class="time-window-row">
                                <input type="checkbox" id="low_latest_time_enabled" name="low_latest_time_enabled" <?php echo $form_data['low_latest_time_enabled'] ? 'checked' : ''; ?>>
                                <label for="low_latest_time_enabled">Before</label>
                                <input type="time" id="low_latest_time" name="low_latest_time" value="<?php echo htmlspecialchars($form_data['low_latest_time']); ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- High Tides -->
                <div class="filter-card" data-toggle="include_high_tides">
                    <div class="filter-card-header">
                        <div>
                            <p class="filter-card-title">High Tides</p>
                        </div>
                        <div class="toggle">
                            <label class="switch" for="include_high_tides">
                                <input type="checkbox" id="include_high_tides" name="include_high_tides" <?php echo $form_data['include_high_tides'] ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                    </div>

                    <div class="filter-content">
                        <div class="filter-row">
                            <div class="field-group">
                                <div class="field-label">Min Height</div>
                                <input type="number" id="high_tide_min_value" name="high_tide_min_value"
                                       step="0.1" value="<?php echo $form_data['high_tide_min_value']; ?>">
                                <small>Include highs ≥ this</small>
                            </div>
                            <div class="field-group">
                                <div class="field-label">Daylight Filter</div>
                                <select id="high_time_filter" name="high_time_filter">
                                    <option value="none" <?php echo ($form_data['high_time_filter'] === 'none') ? 'selected' : ''; ?>>None</option>
                                    <option value="after_sunrise" <?php echo ($form_data['high_time_filter'] === 'after_sunrise') ? 'selected' : ''; ?>>After sunrise</option>
                                    <option value="before_sunset" <?php echo ($form_data['high_time_filter'] === 'before_sunset') ? 'selected' : ''; ?>>Before sunset</option>
                                    <option value="between" <?php echo ($form_data['high_time_filter'] === 'between') ? 'selected' : ''; ?>>Daylight only</option>
                                </select>
                            </div>
                        </div>

                        <div class="filter-row">
                            <div class="field-group">
                                <div class="field-label">Min After Sunrise</div>
                                <input type="number" id="high_minutes_after_sunrise" name="high_minutes_after_sunrise"
                                       value="<?php echo $form_data['high_minutes_after_sunrise']; ?>" min="0" max="1440">
                            </div>
                            <div class="field-group">
                                <div class="field-label">Min Before Sunset</div>
                                <input type="number" id="high_minutes_before_sunset" name="high_minutes_before_sunset"
                                       value="<?php echo $form_data['high_minutes_before_sunset']; ?>" min="0" max="1440">
                            </div>
                        </div>

                        <div class="time-row">
                            <div class="time-window-row">
                                <input type="checkbox" id="high_earliest_time_enabled" name="high_earliest_time_enabled" <?php echo $form_data['high_earliest_time_enabled'] ? 'checked' : ''; ?>>
                                <label for="high_earliest_time_enabled">After</label>
                                <input type="time" id="high_earliest_time" name="high_earliest_time" value="<?php echo htmlspecialchars($form_data['high_earliest_time']); ?>">
                            </div>
                            <div class="time-window-row">
                                <input type="checkbox" id="high_latest_time_enabled" name="high_latest_time_enabled" <?php echo $form_data['high_latest_time_enabled'] ? 'checked' : ''; ?>>
                                <label for="high_latest_time_enabled">Before</label>
                                <input type="time" id="high_latest_time" name="high_latest_time" value="<?php echo htmlspecialchars($form_data['high_latest_time']); ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="button-section">
            <button type="submit" class="btn">✨ Generate Calendar</button>
            <a href="calendars.php" class="btn secondary">📅 View Calendars</a>
        </div>
        <p>Creates a unique calendar based on your filter settings</p>
    </form>

    <div class="footer">
        <p>TideCal - Low Tide Calendar Generator</p>
        <p>Data provided by NOAA Tides & Currents</p>
    </div>

    <script>
    let searchTimeout;
    function closeResultModal() {
        const modal = document.getElementById('result_modal');
        if (modal) {
            modal.classList.remove('active');
        }
    }
    document.addEventListener('click', function(event) {
        const modal = document.getElementById('result_modal');
        if (modal && event.target === modal) {
            closeResultModal();
        }
    });
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeResultModal();
        }
    });

    function viewLog(calendarId) {
        const logSection = document.getElementById('log-section');
        const logContent = document.getElementById('log-content');
        if (!logSection || !logContent) return;

        logContent.textContent = 'Loading...';
        logSection.style.display = 'block';

        fetch('get_log.php?id=' + encodeURIComponent(calendarId) + '&lines=30')
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    logContent.textContent = 'Error: ' + data.error;
                } else if (data.log) {
                    logContent.textContent = data.log;
                } else {
                    logContent.textContent = data.message || 'No log entries yet';
                }
            })
            .catch(error => {
                logContent.textContent = 'Failed to load log: ' + error.message;
            });
    }

    function syncFilterCard(toggleId) {
        const checkbox = document.getElementById(toggleId);
        const card = document.querySelector(`.filter-card[data-toggle="${toggleId}"]`);
        if (!checkbox || !card) return;
        if (checkbox.checked) {
            card.classList.remove('is-disabled');
        } else {
            card.classList.add('is-disabled');
        }
    }
    document.addEventListener('DOMContentLoaded', function() {
        ['include_low_tides', 'include_high_tides'].forEach(id => {
            syncFilterCard(id);
            const checkbox = document.getElementById(id);
            if (checkbox) {
                checkbox.addEventListener('change', () => syncFilterCard(id));
            }
        });
    });
    
    function searchStations(query) {
        clearTimeout(searchTimeout);
        
        const resultsDiv = document.getElementById('search_results');
        
        if (query.length < 2) {
            resultsDiv.style.display = 'none';
            return;
        }
        
        searchTimeout = setTimeout(() => {
            fetch(`station_search.php?q=${encodeURIComponent(query)}`)
                .then(response => response.json())
                .then(stations => {
                    if (stations.length > 0) {
                        let html = '';
                        stations.forEach(station => {
                            html += `<div class="search-result-item" onclick="selectStation('${station.id}', '${station.name.replace(/'/g, "\\'")}', ${station.lat}, ${station.lon}, '${station.timezone}')">
                                        <strong>${station.name}</strong>
                                        ${station.state ? ', ' + station.state : ''} (${station.id})
                                    </div>`;
                        });
                        resultsDiv.innerHTML = html;
                        resultsDiv.style.display = 'block';
                    } else {
                        resultsDiv.innerHTML = '<div class="search-result-item">No stations found</div>';
                        resultsDiv.style.display = 'block';
                    }
                })
                .catch(error => {
                    console.error('Search error:', error);
                    resultsDiv.style.display = 'none';
                });
        }, 300); // 300ms delay for debouncing
    }
    
    function selectStation(stationId, stationName, lat, lon, timezone) {
        document.getElementById('station_search').value = stationName;
        document.getElementById('station_id').value = stationId;
        document.getElementById('station_name').value = stationName;
        document.getElementById('lat').value = lat;
        document.getElementById('lon').value = lon;
        
        // Update timezone if it's a reasonable match
        const timezoneSelect = document.getElementById('timezone');
        for (let option of timezoneSelect.options) {
            if (option.value === timezone) {
                option.selected = true;
                break;
            }
        }
        
        // Hide search results
        document.getElementById('search_results').style.display = 'none';
    }
    
    // Hide search results when clicking outside
    document.addEventListener('click', function(event) {
        const searchDiv = document.getElementById('search_results');
        const searchInput = document.getElementById('station_search');
        
        if (!searchDiv.contains(event.target) && event.target !== searchInput) {
            searchDiv.style.display = 'none';
        }
    });
    
    function copyToClipboard(text) {
        const button = event.target;
        
        button.classList.add('btn-clicked');
        button.textContent = 'Copied!';
        
        showToast('Calendar URL copied! For Google Calendar: go to Settings > Add calendar > From URL, then paste. For other apps: look for "Add calendar" or "Subscribe" options.', 15000);
        
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function() {
                setTimeout(() => {
                    button.textContent = '📋 Copy';
                    button.classList.remove('btn-clicked');
                }, 2000);
            }).catch(function(err) {
                console.error('Failed to copy: ', err);
                fallbackCopyTextToClipboard(text);
            });
        } else {
            fallbackCopyTextToClipboard(text);
        }
    }

    function fallbackCopyTextToClipboard(text) {
        const button = event.target;
        var textArea = document.createElement("textarea");
        textArea.value = text;
        textArea.style.top = "0";
        textArea.style.left = "0";
        textArea.style.position = "fixed";
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        
        try {
            var successful = document.execCommand('copy');
            if (successful) {
                setTimeout(() => {
                    button.textContent = '📋 Copy';
                    button.classList.remove('btn-clicked');
                }, 2000);
            }
        } catch (err) {
            console.error('Fallback: Failed to copy', err);
            button.textContent = '📋 Copy';
            button.classList.remove('btn-clicked');
        }
        
        document.body.removeChild(textArea);
    }
    
    function showToast(message, duration = 3000) {
        const toast = document.createElement('div');
        toast.textContent = message;
        toast.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: #27ae60;
            color: white;
            padding: 12px 20px;
            border-radius: 6px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            z-index: 1000;
            font-weight: 500;
            animation: slideIn 0.3s ease-out;
            max-width: 350px;
            line-height: 1.4;
        `;
        
        if (!document.querySelector('#toast-styles')) {
            const style = document.createElement('style');
            style.id = 'toast-styles';
            style.textContent = `
                @keyframes slideIn {
                    from { transform: translateX(100%); opacity: 0; }
                    to { transform: translateX(0); opacity: 1; }
                }
                @keyframes slideOut {
                    from { transform: translateX(0); opacity: 1; }
                    to { transform: translateX(100%); opacity: 0; }
                }
            `;
            document.head.appendChild(style);
        }
        
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.style.animation = 'slideOut 0.3s ease-in';
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 300);
        }, duration);  // Now actually using the duration parameter!
    }
    
    // Download calendar file with proper filename
    function downloadCalendar(url) {
        showToast('Downloading calendar file...');
        
        fetch(url)
            .then(response => response.blob())
            .then(blob => {
                const downloadUrl = window.URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = downloadUrl;
                link.download = 'tide-calendar.ics'; // Force proper filename
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                window.URL.revokeObjectURL(downloadUrl);
            })
            .catch(error => {
                console.error('Download failed:', error);
                // Fallback to direct link
                window.open(url, '_blank');
            });
    }
    
    // Apple Calendar integration (iOS/macOS native webcal support)
    function addToAppleCalendar(url) {
        console.log('Adding to Apple Calendar:', url);
        
        const webcalUrl = url.replace('https://', 'webcal://');
        console.log('Using webcal URL:', webcalUrl);
        
        const isIOS = /iPhone|iPad|iPod/.test(navigator.userAgent);
        const isMac = /Macintosh|MacIntel|MacPPC|Mac68K/.test(navigator.userAgent);
        const isAndroid = /Android/.test(navigator.userAgent);
        
        if (isAndroid) {
            // Android doesn't support Apple Calendar - show helpful message
            showToast('Apple Calendar is not available on Android. Use Copy button to manually add the calendar to your preferred calendar app.', 10000);
            return;
        }
        
        if (!isIOS && !isMac) {
            // Non-Apple devices - show helpful message
            showToast('Apple Calendar works on iPhone, iPad, and Mac. Use Copy button to manually add the calendar to your calendar app.', 10000);
            return;
        }
        
        showToast('Opening Apple Calendar. If it doesn\'t work, use the Copy button to manually add the calendar.', 15000);
        
        if (isIOS) {
            // On iOS, use window.location.href - this is the recommended approach per Apple docs
            window.location.href = webcalUrl;
        } else {
            // On macOS, try new window first to avoid navigation, fallback to same window
            const newWindow = window.open(webcalUrl, '_blank');
            if (!newWindow || newWindow.closed || typeof newWindow.closed === 'undefined') {
                window.location.href = webcalUrl;
            }
        }
    }
    
    </script>
</body>
</html>
