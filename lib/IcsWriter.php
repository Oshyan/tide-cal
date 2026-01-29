<?php

class IcsWriter {
    private $config;
    private $events = [];

    public function __construct($config) {
        $this->config = $config;
    }

    /**
     * Add a tide event to the calendar
     * 
     * @param array $tide_data Tide information
     * @param array $sun_data Sunrise/sunset information
     */
    public function addTideEvent($tide_data, $sun_data = []) {
        $this->events[] = [
            'kind' => 'tide',
            'tide' => $tide_data,
            'sun' => $sun_data
        ];
    }

    /**
     * Add a sunrise/sunset event to the calendar
     * 
     * @param string $date Date in format YYYY-MM-DD
     * @param string $time Time in format HH:MM
     * @param string $type sunrise|sunset
     */
    public function addSunEvent($date, $time, $type) {
        $this->events[] = [
            'kind' => 'sun',
            'date' => $date,
            'time' => $time,
            'sun_type' => $type
        ];
    }

    /**
     * Generate ICS calendar content
     * 
     * @return string Complete ICS calendar content
     */
    public function generateIcs() {
        $ics_lines = [];
        
        // Calendar headers
        $ics_lines[] = 'BEGIN:VCALENDAR';
        $ics_lines[] = 'PRODID:-//TideCal//SingleStation//EN';
        $ics_lines[] = 'VERSION:2.0';
        $ics_lines[] = 'CALSCALE:GREGORIAN';
        $ics_lines[] = 'METHOD:PUBLISH';
        $ics_lines[] = 'X-WR-CALNAME:' . $this->getCalendarName();
        $ics_lines[] = 'X-WR-TIMEZONE:' . $this->config['timezone'];
        
        // Add events
        foreach ($this->events as $event_data) {
            if (($event_data['kind'] ?? 'tide') === 'sun') {
                $this->addSunEventToIcs($ics_lines, $event_data);
            } else {
                $this->addTideEventToIcs($ics_lines, $event_data);
            }
        }
        
        $ics_lines[] = 'END:VCALENDAR';
        
        // Join with CRLF for RFC 5545 compliance
        $ics_content = implode("\r\n", $ics_lines) . "\r\n";
        
        // Fold long lines (optional but recommended)
        return $this->foldLines($ics_content);
    }

    /**
     * Add a single event to the ICS lines array
     * 
     * @param array &$ics_lines Array of ICS lines
     * @param array $event_data Event data with tide and sun information
     */
    private function addTideEventToIcs(&$ics_lines, $event_data) {
        $tide = $event_data['tide'];
        $sun = $event_data['sun'] ?? [];
        
        try {
            $tz = new DateTimeZone($this->config['timezone']);
            $tide_dt = new DateTime($tide['ts_local'], $tz);
            
            // Create UTC clone for UID generation
            $utc_dt = clone $tide_dt;
            $utc_dt->setTimezone(new DateTimeZone('UTC'));
            
            // Event duration: 30 minutes
            $end_dt = clone $tide_dt;
            $end_dt->add(new DateInterval('PT30M'));
            
            $ics_lines[] = 'BEGIN:VEVENT';
            
            // UID: deterministic and stable
            $uid = 'tide-' . $this->config['station_id'] . '-' . $utc_dt->format('Ymd\THis\Z');
            $ics_lines[] = 'UID:' . $uid;
            
            // Timestamp
            $now_utc = new DateTime('now', new DateTimeZone('UTC'));
            $ics_lines[] = 'DTSTAMP:' . $now_utc->format('Ymd\THis\Z');
            
            // Event times
            $ics_lines[] = 'DTSTART;TZID=' . $this->config['timezone'] . ':' . $tide_dt->format('Ymd\THis');
            $ics_lines[] = 'DTEND;TZID=' . $this->config['timezone'] . ':' . $end_dt->format('Ymd\THis');
            
            // Summary (title)
            $height_display = $this->formatTideHeight($tide['value_m']);
            $tide_label = ($tide['type'] === 'H') ? 'High Tide' : 'Low Tide';
            $ics_lines[] = 'SUMMARY:' . $tide_label . ' ' . $height_display;
            
            // Location
            $ics_lines[] = 'LOCATION:' . $this->escapeText($this->config['station_name']);
            
            // Description
            $description = $this->buildDescription($tide, $sun);
            $ics_lines[] = 'DESCRIPTION:' . $this->escapeText($description);
            
            $ics_lines[] = 'END:VEVENT';
            
        } catch (Exception $e) {
            error_log("IcsWriter: Failed to add event for tide at {$tide['ts_local']}: " . $e->getMessage());
        }
    }

    /**
     * Format tide height for display
     * 
     * @param float $value_m Tide height in meters
     * @return string Formatted height with unit
     */
    private function formatTideHeight($value_m) {
        if ($this->config['unit'] === 'ft') {
            $value_ft = TideProvider::metersToFeet($value_m);
            return sprintf('%+.1f ft', $value_ft);
        } else {
            return sprintf('%+.1f m', $value_m);
        }
    }

    /**
     * Build event description
     * 
     * @param array $tide Tide data
     * @param array $sun Sun data
     * @return string Description text
     */
    private function buildDescription($tide, $sun) {
        $lines = [];
        
        // Station info
        $lines[] = 'Station: ' . $this->config['station_name'] . ' (' . $this->config['station_id'] . ')';
        $lines[] = 'Type: ' . (($tide['type'] === 'H') ? 'High Tide' : 'Low Tide');
        
        // Local time
        $lines[] = 'Local time: ' . str_replace('T', ' ', $tide['ts_local']);
        
        // Height in both units
        $value_m = $tide['value_m'];
        $value_ft = TideProvider::metersToFeet($value_m);
        
        if ($this->config['unit'] === 'ft') {
            $lines[] = sprintf('Height: %.1f ft (%.2f m)', $value_ft, $value_m);
        } else {
            $lines[] = sprintf('Height: %.1f m (%.1f ft)', $value_m, $value_ft);
        }
        
        // Sun times
        if (!empty($sun['sunrise_time']) && !empty($sun['sunset_time'])) {
            $lines[] = 'Sunrise: ' . $sun['sunrise_time'] . ' · Sunset: ' . $sun['sunset_time'];
            
            if (isset($sun['margin_minutes'])) {
                $hours = intval($sun['margin_minutes'] / 60);
                $minutes = $sun['margin_minutes'] % 60;
                if ($hours > 0) {
                    $lines[] = sprintf('Margin to sunset: %dh %dm', $hours, $minutes);
                } else {
                    $lines[] = sprintf('Margin to sunset: %dm', $minutes);
                }
            }
        }
        
        // Generation timestamp
        $now = new DateTime('now', new DateTimeZone($this->config['timezone']));
        $lines[] = 'Generated: ' . $now->format('Y-m-d H:i');
        
        // Optional source URL
        if (!empty($this->config['base_url'])) {
            $lines[] = 'Source: ' . rtrim($this->config['base_url'], '/');
        }
        
        return implode("\n", $lines);
    }

    /**
     * Add a sunrise/sunset event to the ICS lines
     * 
     * @param array &$ics_lines
     * @param array $event_data
     */
    private function addSunEventToIcs(&$ics_lines, $event_data) {
        try {
            $tz = new DateTimeZone($this->config['timezone']);
            $event_dt = new DateTime($event_data['date'] . ' ' . $event_data['time'] . ':00', $tz);
            
            $utc_dt = clone $event_dt;
            $utc_dt->setTimezone(new DateTimeZone('UTC'));
            
            $end_dt = clone $event_dt;
            $end_dt->add(new DateInterval('PT10M'));
            
            $type_label = ($event_data['sun_type'] === 'sunset') ? 'Sunset' : 'Sunrise';
            
            $ics_lines[] = 'BEGIN:VEVENT';
            $uid = strtolower($type_label) . '-' . $this->config['station_id'] . '-' . $utc_dt->format('Ymd\THis\Z');
            $ics_lines[] = 'UID:' . $uid;
            
            $now_utc = new DateTime('now', new DateTimeZone('UTC'));
            $ics_lines[] = 'DTSTAMP:' . $now_utc->format('Ymd\THis\Z');
            
            $ics_lines[] = 'DTSTART;TZID=' . $this->config['timezone'] . ':' . $event_dt->format('Ymd\THis');
            $ics_lines[] = 'DTEND;TZID=' . $this->config['timezone'] . ':' . $end_dt->format('Ymd\THis');
            
            $ics_lines[] = 'SUMMARY:' . $type_label;
            $ics_lines[] = 'LOCATION:' . $this->escapeText($this->config['station_name']);
            
            $desc_lines = [];
            $desc_lines[] = 'Station: ' . $this->config['station_name'] . ' (' . $this->config['station_id'] . ')';
            $desc_lines[] = 'Event: ' . $type_label;
            $desc_lines[] = 'Local time: ' . $event_dt->format('Y-m-d H:i');
            if (!empty($this->config['base_url'])) {
                $desc_lines[] = 'Source: ' . rtrim($this->config['base_url'], '/');
            }
            $ics_lines[] = 'DESCRIPTION:' . $this->escapeText(implode("\n", $desc_lines));
            
            $ics_lines[] = 'END:VEVENT';
            
        } catch (Exception $e) {
            error_log("IcsWriter: Failed to add sun event for {$event_data['date']} {$event_data['time']}: " . $e->getMessage());
        }
    }

    /**
     * Build calendar name based on selected event types
     * 
     * @return string
     */
    private function getCalendarName() {
        $parts = [];
        if (!empty($this->config['include_low_tides'])) {
            $parts[] = 'Low tides';
        }
        if (!empty($this->config['include_high_tides'])) {
            $parts[] = 'High tides';
        }
        if (!empty($this->config['include_sunrise_events'])) {
            $parts[] = 'Sunrise';
        }
        if (!empty($this->config['include_sunset_events'])) {
            $parts[] = 'Sunset';
        }
        
        $suffix = '';
        if (!empty($parts)) {
            $suffix = ' (' . implode(', ', $parts) . ')';
        }
        
        return 'Tides - ' . $this->config['station_name'] . $suffix;
    }

    /**
     * Escape text for ICS format
     * 
     * @param string $text
     * @return string
     */
    private function escapeText($text) {
        // Escape special characters according to RFC 5545
        $text = str_replace(['\\', ';', ',', "\n", "\r"], ['\\\\', '\\;', '\\,', '\\n', '\\n'], $text);
        return $text;
    }

    /**
     * Fold long lines according to RFC 5545
     * 
     * @param string $content
     * @return string
     */
    private function foldLines($content) {
        $lines = explode("\r\n", $content);
        $folded_lines = [];
        
        foreach ($lines as $line) {
            if (strlen($line) <= 75) {
                $folded_lines[] = $line;
            } else {
                // Fold at 75 octets, continue with space
                $folded_lines[] = substr($line, 0, 75);
                $remaining = substr($line, 75);
                
                while (strlen($remaining) > 74) { // 74 because of leading space
                    $folded_lines[] = ' ' . substr($remaining, 0, 74);
                    $remaining = substr($remaining, 74);
                }
                
                if (!empty($remaining)) {
                    $folded_lines[] = ' ' . $remaining;
                }
            }
        }
        
        return implode("\r\n", $folded_lines) . "\r\n";
    }

    /**
     * Write ICS content to file atomically
     * 
     * @param string $ics_content
     * @param string $output_path
     * @throws Exception
     */
    public function writeToFile($ics_content, $output_path) {
        $dir = dirname($output_path);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                throw new Exception("Failed to create directory: {$dir}");
            }
        }

        // Atomic write using temporary file
        $temp_path = $dir . '/.tmp-' . uniqid() . '.ics';
        
        if (file_put_contents($temp_path, $ics_content, LOCK_EX) === false) {
            throw new Exception("Failed to write temporary ICS file: {$temp_path}");
        }
        
        if (!rename($temp_path, $output_path)) {
            @unlink($temp_path); // Cleanup on failure
            throw new Exception("Failed to rename temporary file to: {$output_path}");
        }
    }

    /**
     * Generate empty ICS calendar for error cases
     * 
     * @param string $reason Optional reason for empty calendar
     * @return string Empty ICS content
     */
    public function generateEmptyIcs($reason = 'No events available') {
        $ics_lines = [];
        
        $ics_lines[] = 'BEGIN:VCALENDAR';
        $ics_lines[] = 'PRODID:-//TideCal//SingleStation//EN';
        $ics_lines[] = 'VERSION:2.0';
        $ics_lines[] = 'CALSCALE:GREGORIAN';
        $ics_lines[] = 'METHOD:PUBLISH';
        $ics_lines[] = 'X-WR-CALNAME:' . $this->getCalendarName();
        $ics_lines[] = 'X-WR-TIMEZONE:' . $this->config['timezone'];
        $ics_lines[] = 'X-WR-CALDESC:' . $this->escapeText($reason);
        $ics_lines[] = 'END:VCALENDAR';
        
        return implode("\r\n", $ics_lines) . "\r\n";
    }
}
