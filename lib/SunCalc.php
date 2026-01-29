<?php

class SunCalc {
    private static $sun_cache = [];
    
    /**
     * Calculate sunrise and sunset times for a given date and location
     * Uses accurate solar position algorithm (replacement for deprecated PHP functions)
     * 
     * @param float $lat Latitude in degrees
     * @param float $lon Longitude in degrees
     * @param string $date Date in format YYYY-MM-DD
     * @param string $timezone Timezone identifier (e.g., 'America/Los_Angeles')
     * @return array ['sunrise' => 'HH:MM', 'sunset' => 'HH:MM'] or null on failure
     */
    public static function getSunriseSunset($lat, $lon, $date, $timezone) {
        try {
            $cache_key = $date . '|' . $lat . '|' . $lon . '|' . $timezone;
            if (array_key_exists($cache_key, self::$sun_cache)) {
                return self::$sun_cache[$cache_key];
            }

            $tz = new DateTimeZone($timezone);
            $datetime = new DateTime($date . ' 12:00:00', $tz);
            
            // Get Julian day number
            $julian_day = self::getJulianDayNumber($datetime);
            
            // Calculate sunrise and sunset
            $sun_times = self::calculateSunTimes($lat, $lon, $julian_day, $timezone, $date);
            
            if ($sun_times === null) {
                return null; // Polar day/night
            }
            
            self::$sun_cache[$cache_key] = $sun_times;
            return $sun_times;
            
        } catch (Exception $e) {
            error_log("SunCalc error for {$date} at {$lat},{$lon}: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Calculate Julian day number for a given DateTime
     * 
     * @param DateTime $datetime
     * @return float
     */
    private static function getJulianDayNumber($datetime) {
        $year = (int) $datetime->format('Y');
        $month = (int) $datetime->format('n');
        $day = (int) $datetime->format('j');
        
        if ($month <= 2) {
            $year -= 1;
            $month += 12;
        }
        
        $a = floor($year / 100);
        $b = 2 - $a + floor($a / 4);
        
        $julian_day = floor(365.25 * ($year + 4716)) + floor(30.6001 * ($month + 1)) + $day + $b - 1524.5;
        
        return $julian_day;
    }
    
    /**
     * Calculate sunrise and sunset times using accurate solar position algorithm
     * 
     * @param float $lat Latitude in degrees
     * @param float $lon Longitude in degrees  
     * @param float $julian_day Julian day number
     * @param string $timezone Timezone identifier
     * @return array|null Sun times or null for polar conditions
     */
    private static function calculateSunTimes($lat, $lon, $julian_day, $timezone, $date) {
        // Convert latitude to radians
        $lat_rad = deg2rad($lat);
        
        // Calculate the equation of time and solar declination
        $n = $julian_day - 2451545.0;
        $L = fmod(280.460 + 0.9856474 * $n, 360.0);
        $g = deg2rad(fmod(357.528 + 0.9856003 * $n, 360.0));
        $lambda = deg2rad($L + 1.915 * sin($g) + 0.020 * sin(2 * $g));
        
        // Solar declination
        $sin_delta = sin(deg2rad(23.439)) * sin($lambda);
        $cos_delta = sqrt(1 - $sin_delta * $sin_delta);
        $delta = asin($sin_delta);
        
        // Hour angle for civil twilight (sun 6 degrees below horizon)
        $zenith = deg2rad(90.833); // Civil twilight
        $cos_h = (cos($zenith) - sin($lat_rad) * $sin_delta) / (cos($lat_rad) * $cos_delta);
        
        // Check for polar day/night
        if ($cos_h > 1) {
            return null; // Polar night - sun never rises
        }
        if ($cos_h < -1) {
            return null; // Polar day - sun never sets
        }
        
        $h = acos($cos_h);
        $h_hours = rad2deg($h) / 15.0;
        
        // Calculate equation of time
        $y = tan(deg2rad(23.439) / 2);
        $y = $y * $y;
        
        $sin_2L = sin(2 * $lambda);
        $cos_2L = cos(2 * $lambda);
        $sin_4L = sin(4 * $lambda);
        $sin_g = sin($g);
        $sin_2g = sin(2 * $g);
        
        $E = 4 * rad2deg($y * $sin_2L - 2 * 0.0167 * $sin_g + 4 * 0.0167 * $y * $sin_g * $cos_2L - 
                         0.5 * $y * $y * $sin_4L - 1.25 * 0.0167 * 0.0167 * $sin_2g);
        
        // Solar noon
        $solar_noon = 12.0 - ($lon / 15.0) - ($E / 60.0);
        
        // Sunrise and sunset times
        $sunrise_decimal = $solar_noon - $h_hours;
        $sunset_decimal = $solar_noon + $h_hours;
        
        // Convert to local timezone
        try {
            $tz = new DateTimeZone($timezone);
            
            // Apply timezone offset for the requested date (handles DST correctly)
            $temp_dt = new DateTime($date . ' 12:00:00', $tz);
            $utc_offset_hours = $temp_dt->getOffset() / 3600;
            
            $sunrise_local = $sunrise_decimal + $utc_offset_hours;
            $sunset_local = $sunset_decimal + $utc_offset_hours;
            
            // Handle day boundary crossings
            if ($sunrise_local < 0) $sunrise_local += 24;
            if ($sunrise_local >= 24) $sunrise_local -= 24;
            if ($sunset_local < 0) $sunset_local += 24;
            if ($sunset_local >= 24) $sunset_local -= 24;
            
            return [
                'sunrise' => self::decimalHoursToTime($sunrise_local),
                'sunset' => self::decimalHoursToTime($sunset_local)
            ];
            
        } catch (Exception $e) {
            error_log("SunCalc timezone conversion error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Convert decimal hours to HH:MM format
     * 
     * @param float $decimal_hours
     * @return string
     */
    private static function decimalHoursToTime($decimal_hours) {
        $hours = floor($decimal_hours);
        $minutes = round(($decimal_hours - $hours) * 60);
        
        // Handle minute overflow (e.g., 59.7 minutes rounds to 60)
        if ($minutes >= 60) {
            $hours += 1;
            $minutes = 0;
        }
        
        // Handle hour overflow 
        if ($hours >= 24) {
            $hours = $hours % 24;
        }
        
        return sprintf('%02d:%02d', (int)$hours, (int)$minutes);
    }
    
    /**
     * Check if a tide time passes a time window relative to sunrise/sunset
     * 
     * @param string $tide_time Local time in format 'YYYY-MM-DDTHH:MM:SS'
     * @param float $lat
     * @param float $lon
     * @param string $timezone
     * @param string $mode none | after_sunrise | before_sunset | between
     * @param int $minutes_after_sunrise Required minutes after sunrise
     * @param int $minutes_before_sunset Required minutes before sunset
     * @return array ['passes' => bool, 'sunset_time' => 'HH:MM'|null, 'sunrise_time' => 'HH:MM'|null, 'margin_minutes' => int|null]
     */
    public static function checkTimeWindow($tide_time, $lat, $lon, $timezone, $mode, $minutes_after_sunrise, $minutes_before_sunset) {
        try {
            $tz = new DateTimeZone($timezone);
            $tide_dt = new DateTime($tide_time, $tz);
            $date = $tide_dt->format('Y-m-d');
            
            $sun_times = self::getSunriseSunset($lat, $lon, $date, $timezone);
            
            if (!$sun_times || !isset($sun_times['sunset']) || !isset($sun_times['sunrise'])) {
                // No sunrise/sunset data available - include the tide but mark it
                return [
                    'passes' => true,
                    'sunset_time' => null,
                    'sunrise_time' => null,
                    'margin_minutes' => null
                ];
            }
            
            $sunrise_dt = new DateTime($date . ' ' . $sun_times['sunrise'] . ':00', $tz);
            $sunset_dt = new DateTime($date . ' ' . $sun_times['sunset'] . ':00', $tz);
            
            $required_start_dt = clone $sunrise_dt;
            if ($minutes_after_sunrise > 0) {
                $required_start_dt->add(new DateInterval('PT' . $minutes_after_sunrise . 'M'));
            }
            
            $required_end_dt = clone $sunset_dt;
            if ($minutes_before_sunset > 0) {
                $required_end_dt->sub(new DateInterval('PT' . $minutes_before_sunset . 'M'));
            }
            
            $passes = true;
            if ($mode === 'after_sunrise') {
                $passes = $tide_dt >= $required_start_dt;
            } elseif ($mode === 'before_sunset') {
                $passes = $tide_dt <= $required_end_dt;
            } elseif ($mode === 'between') {
                $passes = ($tide_dt >= $required_start_dt) && ($tide_dt <= $required_end_dt);
            }
            
            $margin_seconds = $sunset_dt->getTimestamp() - $tide_dt->getTimestamp();
            $margin_minutes = (int) round($margin_seconds / 60);
            
            return [
                'passes' => $passes,
                'sunset_time' => $sun_times['sunset'],
                'sunrise_time' => $sun_times['sunrise'],
                'margin_minutes' => $margin_minutes
            ];
            
        } catch (Exception $e) {
            error_log("SunCalc time window check failed for {$tide_time}: " . $e->getMessage());
            return [
                'passes' => true,
                'sunset_time' => null,
                'sunrise_time' => null,
                'margin_minutes' => null
            ];
        }
    }

    /**
     * Check if a tide time falls within an optional clock-time window
     * 
     * @param string $tide_time Local time in format 'YYYY-MM-DDTHH:MM:SS'
     * @param string $timezone
     * @param bool $earliest_enabled
     * @param string $earliest_time HH:MM
     * @param bool $latest_enabled
     * @param string $latest_time HH:MM
     * @return array ['passes' => bool]
     */
    public static function checkClockWindow($tide_time, $timezone, $earliest_enabled, $earliest_time, $latest_enabled, $latest_time) {
        if (empty($earliest_enabled) && empty($latest_enabled)) {
            return ['passes' => true];
        }
        
        try {
            $tz = new DateTimeZone($timezone);
            $tide_dt = new DateTime($tide_time, $tz);
            $tide_minutes = ((int) $tide_dt->format('H')) * 60 + (int) $tide_dt->format('i');
            
            $earliest_minutes = 0;
            $latest_minutes = 23 * 60 + 59;
            
            if (!empty($earliest_enabled)) {
                [$eh, $em] = array_map('intval', explode(':', $earliest_time));
                $earliest_minutes = $eh * 60 + $em;
            }
            if (!empty($latest_enabled)) {
                [$lh, $lm] = array_map('intval', explode(':', $latest_time));
                $latest_minutes = $lh * 60 + $lm;
            }
            
            if (!empty($earliest_enabled) && !empty($latest_enabled) && $earliest_minutes > $latest_minutes) {
                $passes = ($tide_minutes >= $earliest_minutes) || ($tide_minutes <= $latest_minutes);
            } else {
                $passes = true;
                if (!empty($earliest_enabled)) {
                    $passes = $passes && ($tide_minutes >= $earliest_minutes);
                }
                if (!empty($latest_enabled)) {
                    $passes = $passes && ($tide_minutes <= $latest_minutes);
                }
            }
            
            return ['passes' => $passes];
            
        } catch (Exception $e) {
            error_log("SunCalc clock window check failed for {$tide_time}: " . $e->getMessage());
            return ['passes' => true];
        }
    }

    /**
     * Backwards-compatible wrapper for the old daylight check
     */
    public static function checkDaylightWindow($tide_time, $lat, $lon, $timezone, $minutes_before_sunset) {
        return self::checkTimeWindow($tide_time, $lat, $lon, $timezone, 'before_sunset', 0, $minutes_before_sunset);
    }
}
