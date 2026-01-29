<?php

class CalendarManager {
    private $calendars_file;
    private $data_dir;
    
    public function __construct($data_dir) {
        $this->data_dir = $data_dir;
        $this->calendars_file = $data_dir . '/calendars.json';
        
        // Ensure data directory exists
        if (!is_dir($data_dir)) {
            mkdir($data_dir, 0755, true);
        }
        
        // Initialize calendars file if it doesn't exist
        if (!file_exists($this->calendars_file)) {
            $this->saveCalendars([]);
        }
    }
    
    /**
     * Generate unique calendar ID based on parameters
     * 
     * @param array $params Calendar parameters
     * @return string Unique calendar ID
     */
    public function generateCalendarId($params) {
        // Create hash from key parameters that affect the calendar content
        $key_params = [
            'station_id' => $params['station_id'],
            'year' => $params['year'],
            'unit' => $params['unit'],
            'include_low_tides' => $params['include_low_tides'],
            'min_low_tide_value' => $params['min_low_tide_value'],
            'low_time_filter' => $params['low_time_filter'],
            'low_minutes_after_sunrise' => $params['low_minutes_after_sunrise'],
            'low_minutes_before_sunset' => $params['low_minutes_before_sunset'],
            'low_earliest_time_enabled' => $params['low_earliest_time_enabled'],
            'low_earliest_time' => $params['low_earliest_time'],
            'low_latest_time_enabled' => $params['low_latest_time_enabled'],
            'low_latest_time' => $params['low_latest_time'],
            'include_high_tides' => $params['include_high_tides'],
            'high_tide_min_value' => $params['high_tide_min_value'],
            'high_time_filter' => $params['high_time_filter'],
            'high_minutes_after_sunrise' => $params['high_minutes_after_sunrise'],
            'high_minutes_before_sunset' => $params['high_minutes_before_sunset'],
            'high_earliest_time_enabled' => $params['high_earliest_time_enabled'],
            'high_earliest_time' => $params['high_earliest_time'],
            'high_latest_time_enabled' => $params['high_latest_time_enabled'],
            'high_latest_time' => $params['high_latest_time'],
            'include_sunrise_events' => $params['include_sunrise_events'],
            'include_sunset_events' => $params['include_sunset_events'],
            'sun_events_match_tide_days' => $params['sun_events_match_tide_days']
        ];
        
        // Sort to ensure consistent hash regardless of array order
        ksort($key_params);
        
        // Create short, URL-safe hash
        $hash = substr(md5(json_encode($key_params)), 0, 12);
        
        return $hash;
    }
    
    /**
     * Get or create calendar entry
     * 
     * @param array $params Calendar parameters
     * @return array Calendar entry with id, params, created_at, updated_at
     */
    public function getOrCreateCalendar($params, $force_id = null) {
        $calendars = $this->loadCalendars();
        $now = date('Y-m-d H:i:s');

        // If editing an existing calendar, use its ID
        if ($force_id && isset($calendars[$force_id])) {
            $calendar_id = $force_id;
            $calendars[$calendar_id]['updated_at'] = $now;
            $calendars[$calendar_id]['params'] = $params;
        } else {
            // Generate ID from params for new calendars
            $calendar_id = $this->generateCalendarId($params);

            if (isset($calendars[$calendar_id])) {
                // Update existing calendar with same params
                $calendars[$calendar_id]['updated_at'] = $now;
                $calendars[$calendar_id]['params'] = $params;
            } else {
                // Create new calendar entry
                $calendars[$calendar_id] = [
                    'id' => $calendar_id,
                    'params' => $params,
                    'created_at' => $now,
                    'updated_at' => $now
                ];
            }
        }

        $this->saveCalendars($calendars);

        return $calendars[$calendar_id];
    }
    
    /**
     * Get all calendars
     * 
     * @return array All calendar entries
     */
    public function getAllCalendars() {
        return $this->loadCalendars();
    }
    
    /**
     * Get calendar by ID
     * 
     * @param string $calendar_id
     * @return array|null Calendar entry or null if not found
     */
    public function getCalendar($calendar_id) {
        $calendars = $this->loadCalendars();
        return $calendars[$calendar_id] ?? null;
    }
    
    /**
     * Delete calendar
     * 
     * @param string $calendar_id
     * @return bool True if deleted, false if not found
     */
    public function deleteCalendar($calendar_id) {
        $calendars = $this->loadCalendars();
        
        if (isset($calendars[$calendar_id])) {
            // Remove ICS file if it exists
            $ics_file = $this->getCalendarFilePath($calendar_id);
            if (file_exists($ics_file)) {
                unlink($ics_file);
            }
            
            unset($calendars[$calendar_id]);
            $this->saveCalendars($calendars);
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Get file path for calendar ICS file
     * 
     * @param string $calendar_id
     * @return string File path
     */
    public function getCalendarFilePath($calendar_id) {
        return $this->data_dir . '/calendar-' . $calendar_id . '.ics';
    }
    
    /**
     * Get calendar URL
     * 
     * @param string $calendar_id
     * @param string $base_url
     * @return string Calendar subscription URL
     */
    public function getCalendarUrl($calendar_id, $base_url) {
        $base_url = rtrim($base_url, '/');
        return $base_url . '/calendar.ics.php?id=' . $calendar_id;
    }
    
    /**
     * Clean up old calendars (optional maintenance function)
     * 
     * @param int $days_old Delete calendars older than this many days
     * @return int Number of calendars deleted
     */
    public function cleanupOldCalendars($days_old = 365) {
        $calendars = $this->loadCalendars();
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days_old} days"));
        $deleted_count = 0;
        
        foreach ($calendars as $calendar_id => $calendar) {
            if ($calendar['updated_at'] < $cutoff_date) {
                if ($this->deleteCalendar($calendar_id)) {
                    $deleted_count++;
                }
            }
        }
        
        return $deleted_count;
    }
    
    /**
     * Load calendars from JSON file
     * 
     * @return array
     */
    private function loadCalendars() {
        if (!file_exists($this->calendars_file)) {
            return [];
        }
        
        $content = file_get_contents($this->calendars_file);
        if ($content === false) {
            return [];
        }
        
        $calendars = json_decode($content, true);
        return is_array($calendars) ? $calendars : [];
    }
    
    /**
     * Save calendars to JSON file
     * 
     * @param array $calendars
     */
    private function saveCalendars($calendars) {
        $content = json_encode($calendars, JSON_PRETTY_PRINT);
        file_put_contents($this->calendars_file, $content, LOCK_EX);
    }
}
