<?php

class Util {
    
    /**
     * Log a message to the monthly log file
     * 
     * @param string $message Log message
     * @param string $level Log level (INFO, WARNING, ERROR)
     * @param string $logs_dir Directory for log files
     */
    public static function log($message, $level = 'INFO', $logs_dir = null) {
        if (!$logs_dir) {
            $logs_dir = __DIR__ . '/../logs';
        }
        
        // Ensure logs directory exists
        if (!is_dir($logs_dir)) {
            mkdir($logs_dir, 0755, true);
        }
        
        // Create monthly log file name
        $log_file = $logs_dir . '/run-' . date('Ym') . '.log';
        
        // Format log entry
        $timestamp = date('Y-m-d H:i:s');
        $log_entry = "[{$timestamp}] {$level}: {$message}" . PHP_EOL;
        
        // Append to log file
        file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Log generation run statistics
     * 
     * @param array $stats Statistics array with keys: year, fetched_count, kept_count, duration, warnings, errors
     * @param string $logs_dir Directory for log files
     */
    public static function logGenerationRun($stats, $logs_dir = null) {
        $year = $stats['year'] ?? 'unknown';
        $fetched = $stats['fetched_count'] ?? 0;
        $kept = $stats['kept_count'] ?? 0;
        $duration = $stats['duration'] ?? 0;
        $warnings = $stats['warnings'] ?? 0;
        $errors = $stats['errors'] ?? 0;
        
        $message = sprintf(
            "Generation completed - Year: %s, Fetched: %d, Kept: %d, Duration: %.2fs, Warnings: %d, Errors: %d",
            $year, $fetched, $kept, $duration, $warnings, $errors
        );
        
        self::log($message, 'INFO', $logs_dir);
    }

    /**
     * Read JSON cache from disk if present and not expired
     * 
     * @param string $path
     * @param int $ttl_seconds
     * @return array|null
     */
    public static function readJsonCache($path, $ttl_seconds) {
        if ($ttl_seconds <= 0 || !file_exists($path)) {
            return null;
        }

        if ((time() - filemtime($path)) > $ttl_seconds) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Write JSON cache to disk (atomic best-effort)
     * 
     * @param string $path
     * @param mixed $data
     * @return void
     */
    public static function writeJsonCache($path, $data) {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $json = json_encode($data, JSON_PRETTY_PRINT);
        if ($json === false) {
            return;
        }

        $temp_path = $dir . '/.tmp-' . uniqid() . '.json';
        if (file_put_contents($temp_path, $json, LOCK_EX) === false) {
            return;
        }

        @rename($temp_path, $path);
    }
    
    /**
     * Get timezone offset in hours for a given timezone and date
     * 
     * @param string $timezone Timezone identifier
     * @param string $date Date string (optional, defaults to now)
     * @return float Offset in hours
     */
    public static function getTimezoneOffset($timezone, $date = null) {
        try {
            $tz = new DateTimeZone($timezone);
            $datetime = $date ? new DateTime($date, $tz) : new DateTime('now', $tz);
            return $datetime->getOffset() / 3600;
        } catch (Exception $e) {
            return 0; // Default to UTC
        }
    }
    
    /**
     * Validate timezone identifier
     * 
     * @param string $timezone
     * @return bool
     */
    public static function isValidTimezone($timezone) {
        try {
            new DateTimeZone($timezone);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Convert timezone-aware datetime string to UTC timestamp
     * 
     * @param string $datetime_str Datetime string in format 'YYYY-MM-DDTHH:MM:SS'
     * @param string $timezone Source timezone
     * @return int|false UTC timestamp or false on failure
     */
    public static function localToUtcTimestamp($datetime_str, $timezone) {
        try {
            $tz = new DateTimeZone($timezone);
            $dt = new DateTime($datetime_str, $tz);
            return $dt->getTimestamp();
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Format duration in seconds to human readable string
     * 
     * @param float $seconds
     * @return string
     */
    public static function formatDuration($seconds) {
        if ($seconds < 1) {
            return sprintf('%.0fms', $seconds * 1000);
        } elseif ($seconds < 60) {
            return sprintf('%.1fs', $seconds);
        } else {
            $minutes = intval($seconds / 60);
            $remaining_seconds = $seconds % 60;
            return sprintf('%dm %.1fs', $minutes, $remaining_seconds);
        }
    }
    
    /**
     * Safely get array value with default
     * 
     * @param array $array
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function get($array, $key, $default = null) {
        return isset($array[$key]) ? $array[$key] : $default;
    }
    
    /**
     * Sanitize filename for safe file operations
     * 
     * @param string $filename
     * @return string
     */
    public static function sanitizeFilename($filename) {
        // Remove or replace unsafe characters
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
        
        // Remove multiple consecutive underscores
        $filename = preg_replace('/_+/', '_', $filename);
        
        // Trim underscores from start and end
        return trim($filename, '_');
    }
    
    /**
     * Get file modification time in local timezone
     * 
     * @param string $file_path
     * @param string $timezone
     * @param string $format
     * @return string|null
     */
    public static function getFileModTime($file_path, $timezone = 'UTC', $format = 'Y-m-d H:i:s') {
        if (!file_exists($file_path)) {
            return null;
        }
        
        try {
            $mtime = filemtime($file_path);
            $dt = new DateTime('@' . $mtime);
            
            if ($timezone !== 'UTC') {
                $dt->setTimezone(new DateTimeZone($timezone));
            }
            
            return $dt->format($format);
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * Format file size in human readable format
     * 
     * @param int $size Size in bytes
     * @return string
     */
    public static function formatFileSize($size) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = 1024;
        
        for ($i = 0; $i < count($units) && $size >= $factor; $i++) {
            $size /= $factor;
        }
        
        return round($size, 1) . ' ' . $units[$i];
    }
    
    /**
     * Check if string looks like a valid NOAA station ID
     * 
     * @param string $station_id
     * @return bool
     */
    public static function isValidStationId($station_id) {
        // NOAA station IDs are typically 7-8 digit numbers
        return preg_match('/^\d{7,8}$/', $station_id);
    }
    
    /**
     * Parse year from config, defaulting to current year
     * 
     * @param mixed $year_config
     * @return int
     */
    public static function parseYear($year_config) {
        if (is_numeric($year_config) && $year_config > 1900 && $year_config < 2100) {
            return (int) $year_config;
        }
        return (int) date('Y');
    }
    
    /**
     * Validate latitude/longitude coordinates
     * 
     * @param float $lat
     * @param float $lon
     * @return bool
     */
    public static function isValidCoordinates($lat, $lon) {
        return is_numeric($lat) && is_numeric($lon) && 
               $lat >= -90 && $lat <= 90 && 
               $lon >= -180 && $lon <= 180;
    }
    
    /**
     * Create date range strings for API calls
     * 
     * @param int $year
     * @return array ['start' => 'YYYY-MM-DD', 'end' => 'YYYY-MM-DD']
     */
    public static function getYearDateRange($year) {
        return [
            'start' => sprintf('%04d-01-01', $year),
            'end' => sprintf('%04d-12-31', $year)
        ];
    }

    /**
     * Get list of all dates in a year
     * 
     * @param int $year
     * @return array
     */
    public static function getDatesInYear($year) {
        $dates = [];
        $start = new DateTime(sprintf('%04d-01-01', $year));
        $end = new DateTime(sprintf('%04d-12-31', $year));
        $end->setTime(0, 0, 0);
        
        $current = clone $start;
        while ($current <= $end) {
            $dates[] = $current->format('Y-m-d');
            $current->modify('+1 day');
        }
        
        return $dates;
    }
    
    /**
     * Count events in an ICS file (rough estimate)
     * 
     * @param string $ics_content
     * @return int
     */
    public static function countIcsEvents($ics_content) {
        return substr_count($ics_content, 'BEGIN:VEVENT');
    }
    
    /**
     * Measure execution time of a callable
     * 
     * @param callable $callback
     * @return array ['result' => mixed, 'duration' => float]
     */
    public static function timeExecution($callback) {
        $start = microtime(true);
        $result = call_user_func($callback);
        $duration = microtime(true) - $start;
        
        return [
            'result' => $result,
            'duration' => $duration
        ];
    }
}
