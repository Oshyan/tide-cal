<?php

class StationLookup {
    
    /**
     * Get station metadata from NOAA CO-OPS API
     * 
     * @param string $station_id NOAA station ID
     * @return array|null Station data with name, lat, lon, timezone or null if not found
     */
    public static function getStationData($station_id) {
        if (!preg_match('/^\d{7,8}$/', $station_id)) {
            return null; // Invalid station ID format
        }
        
        // NOAA Stations API endpoint
        $url = "https://tidesandcurrents.noaa.gov/api/datagetter?product=predictions&application=NOS.COOPS.TAC.WL&begin_date=" . date('Ymd') . "&end_date=" . date('Ymd') . "&datum=MLLW&station={$station_id}&time_zone=lst_ldt&units=metric&interval=hilo&format=json";
        
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'user_agent' => 'TideCal/1.0 (Station Lookup)'
            ]
        ]);

        $response = @file_get_contents($url, false, $context);
        
        if ($response === false) {
            // Try alternate metadata endpoint
            return self::getStationDataFromMetadata($station_id);
        }

        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE || !isset($data['metadata'])) {
            return self::getStationDataFromMetadata($station_id);
        }

        $metadata = $data['metadata'];
        
        return [
            'station_id' => $station_id,
            'name' => $metadata['name'] ?? 'Station ' . $station_id,
            'lat' => (float) ($metadata['lat'] ?? 0),
            'lon' => (float) ($metadata['lon'] ?? 0),
            'timezone' => self::guessTimezoneFromLocation((float) ($metadata['lat'] ?? 0), (float) ($metadata['lon'] ?? 0))
        ];
    }
    
    /**
     * Try alternate metadata endpoint
     */
    private static function getStationDataFromMetadata($station_id) {
        // Use a known station database or return common stations
        $known_stations = [
            '9414290' => ['name' => 'San Francisco', 'lat' => 37.806, 'lon' => -122.465, 'timezone' => 'America/Los_Angeles'],
            '8518750' => ['name' => 'The Battery, NY', 'lat' => 40.7, 'lon' => -74.015, 'timezone' => 'America/New_York'],
            '8571421' => ['name' => 'Lewes, DE', 'lat' => 38.782, 'lon' => -75.119, 'timezone' => 'America/New_York'],
            '8638610' => ['name' => 'Sewells Point, VA', 'lat' => 36.947, 'lon' => -76.33, 'timezone' => 'America/New_York'],
            '8665530' => ['name' => 'Charleston, SC', 'lat' => 32.781, 'lon' => -79.925, 'timezone' => 'America/New_York'],
            '8729840' => ['name' => 'Mayport, FL', 'lat' => 30.398, 'lon' => -81.428, 'timezone' => 'America/New_York'],
            '8761724' => ['name' => 'Galveston Bay, TX', 'lat' => 29.313, 'lon' => -94.793, 'timezone' => 'America/Chicago'],
            '9410170' => ['name' => 'San Diego, CA', 'lat' => 32.714, 'lon' => -117.173, 'timezone' => 'America/Los_Angeles'],
            '9447130' => ['name' => 'Seattle, WA', 'lat' => 47.602, 'lon' => -122.339, 'timezone' => 'America/Los_Angeles'],
            '1611400' => ['name' => 'Nawiliwili, HI', 'lat' => 21.955, 'lon' => -159.356, 'timezone' => 'Pacific/Honolulu'],
            '9461380' => ['name' => 'Anchorage, AK', 'lat' => 61.238, 'lon' => -149.89, 'timezone' => 'America/Anchorage']
        ];
        
        if (isset($known_stations[$station_id])) {
            $station = $known_stations[$station_id];
            return [
                'station_id' => $station_id,
                'name' => $station['name'],
                'lat' => $station['lat'],
                'lon' => $station['lon'],
                'timezone' => $station['timezone']
            ];
        }
        
        return null;
    }
    
    /**
     * Guess timezone from latitude/longitude
     * 
     * @param float $lat
     * @param float $lon
     * @return string
     */
    private static function guessTimezoneFromLocation($lat, $lon) {
        // Very basic timezone guessing for US coastal areas
        if ($lat >= 24 && $lat <= 49 && $lon >= -125 && $lon <= -66) {
            // Continental US
            if ($lon >= -125 && $lon <= -104) {
                return 'America/Los_Angeles'; // Pacific
            } elseif ($lon >= -104 && $lon <= -87) {
                return 'America/Denver'; // Mountain
            } elseif ($lon >= -87 && $lon <= -84) {
                return 'America/Chicago'; // Central
            } else {
                return 'America/New_York'; // Eastern
            }
        } elseif ($lat >= 18 && $lat <= 28 && $lon >= -161 && $lon <= -154) {
            return 'Pacific/Honolulu'; // Hawaii
        } elseif ($lat >= 54 && $lat <= 72 && $lon >= -180 && $lon <= -129) {
            return 'America/Anchorage'; // Alaska
        }
        
        return 'America/New_York'; // Default fallback
    }
    
    /**
     * Search stations by name or location
     * 
     * @param string $query Search term
     * @return array Array of matching stations
     */
    public static function searchStations($query) {
        $stations = self::getAllStations();
        $query = strtolower(trim($query));
        
        if (empty($query)) {
            return array_slice($stations, 0, 10); // Return first 10 if no query
        }
        
        $matches = [];
        
        foreach ($stations as $station_id => $station_data) {
            $name = strtolower($station_data['name']);
            $state = strtolower($station_data['state'] ?? '');
            
            // Check if query matches station name, state, or station ID
            if (strpos($name, $query) !== false || 
                strpos($state, $query) !== false || 
                strpos($station_id, $query) === 0) {
                
                $matches[] = [
                    'id' => $station_id,
                    'name' => $station_data['name'],
                    'state' => $station_data['state'] ?? '',
                    'label' => $station_data['name'] . ($station_data['state'] ? ', ' . $station_data['state'] : ''),
                    'lat' => $station_data['lat'],
                    'lon' => $station_data['lon'],
                    'timezone' => $station_data['timezone']
                ];
            }
        }
        
        // Sort by relevance (exact matches first, then alphabetical)
        usort($matches, function($a, $b) use ($query) {
            $a_starts = stripos($a['name'], $query) === 0;
            $b_starts = stripos($b['name'], $query) === 0;
            
            if ($a_starts && !$b_starts) return -1;
            if (!$a_starts && $b_starts) return 1;
            
            return strcmp($a['name'], $b['name']);
        });
        
        return array_slice($matches, 0, 15); // Return top 15 matches
    }
    
    /**
     * Get comprehensive list of NOAA tide prediction stations
     * Fetches from NOAA CO-OPS Metadata API with local caching
     * 
     * @return array
     */
    public static function getAllStations() {
        $cache_file = __DIR__ . '/../cache/noaa_stations.json';
        $cache_dir = dirname($cache_file);
        
        // Ensure cache directory exists
        if (!is_dir($cache_dir)) {
            mkdir($cache_dir, 0755, true);
        }
        
        // Check if cache exists and is fresh (24 hours)
        if (file_exists($cache_file) && (time() - filemtime($cache_file)) < 86400) {
            $cached_data = json_decode(file_get_contents($cache_file), true);
            if ($cached_data && is_array($cached_data)) {
                return $cached_data;
            }
        }
        
        // Fetch from NOAA API
        $stations_data = self::fetchStationsFromNOAA();
        
        if (!empty($stations_data)) {
            // Cache the results
            file_put_contents($cache_file, json_encode($stations_data, JSON_PRETTY_PRINT));
            return $stations_data;
        }
        
        // Fallback to cached data if API fails
        if (file_exists($cache_file)) {
            $cached_data = json_decode(file_get_contents($cache_file), true);
            if ($cached_data && is_array($cached_data)) {
                return $cached_data;
            }
        }
        
        // Last resort: return essential stations
        return self::getFallbackStations();
    }
    
    /**
     * Fetch stations from NOAA CO-OPS Metadata API
     * 
     * @return array
     */
    private static function fetchStationsFromNOAA() {
        $url = 'https://api.tidesandcurrents.noaa.gov/mdapi/prod/webapi/stations.json?type=tidepredictions';
        
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 30,
                'user_agent' => 'TideCal/1.0 (Station Lookup)'
            ]
        ]);
        
        $response = @file_get_contents($url, false, $context);
        
        if ($response === false) {
            return [];
        }
        
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE || !isset($data['stations'])) {
            return [];
        }
        
        $stations = [];
        
        foreach ($data['stations'] as $station) {
            if (!isset($station['id']) || !isset($station['name'])) {
                continue;
            }
            
            $stations[$station['id']] = [
                'name' => $station['name'],
                'state' => $station['state'] ?? '',
                'lat' => (float) ($station['lat'] ?? 0),
                'lon' => (float) ($station['lng'] ?? 0),
                'timezone' => self::guessTimezoneFromLocation(
                    (float) ($station['lat'] ?? 0),
                    (float) ($station['lng'] ?? 0)
                ),
                'type' => $station['type'] ?? 'S'
            ];
        }
        
        return $stations;
    }
    
    /**
     * Fallback stations for when API is unavailable
     * 
     * @return array
     */
    private static function getFallbackStations() {
        return [
            '9414290' => ['name' => 'San Francisco', 'state' => 'CA', 'lat' => 37.806, 'lon' => -122.465, 'timezone' => 'America/Los_Angeles'],
            '9414523' => ['name' => 'Pillar Point Harbor, Half Moon Bay', 'state' => 'CA', 'lat' => 37.5, 'lon' => -122.48, 'timezone' => 'America/Los_Angeles'],
            '8518750' => ['name' => 'The Battery', 'state' => 'NY', 'lat' => 40.7, 'lon' => -74.015, 'timezone' => 'America/New_York'],
            '8516945' => ['name' => 'Kings Point', 'state' => 'NY', 'lat' => 40.811, 'lon' => -73.765, 'timezone' => 'America/New_York'],
            '9410170' => ['name' => 'San Diego', 'state' => 'CA', 'lat' => 32.714, 'lon' => -117.173, 'timezone' => 'America/Los_Angeles'],
            '9447130' => ['name' => 'Seattle', 'state' => 'WA', 'lat' => 47.602, 'lon' => -122.339, 'timezone' => 'America/Los_Angeles'],
            '8665530' => ['name' => 'Charleston', 'state' => 'SC', 'lat' => 32.781, 'lon' => -79.925, 'timezone' => 'America/New_York'],
            '8761724' => ['name' => 'Galveston Bay', 'state' => 'TX', 'lat' => 29.313, 'lon' => -94.793, 'timezone' => 'America/Chicago'],
            '1611400' => ['name' => 'Nawiliwili', 'state' => 'HI', 'lat' => 21.955, 'lon' => -159.356, 'timezone' => 'Pacific/Honolulu'],
            '9461380' => ['name' => 'Anchorage', 'state' => 'AK', 'lat' => 61.238, 'lon' => -149.89, 'timezone' => 'America/Anchorage']
        ];
    }
    
    /**
     * Get list of popular stations for dropdown/autocomplete
     * 
     * @return array
     */
    public static function getPopularStations() {
        return [
            '9414290' => 'San Francisco, CA',
            '8518750' => 'The Battery, New York, NY', 
            '9410170' => 'San Diego, CA',
            '9447130' => 'Seattle, WA',
            '8665530' => 'Charleston, SC',
            '8761724' => 'Galveston Bay, TX',
            '8729840' => 'Mayport, FL',
            '8571421' => 'Lewes, DE',
            '8638610' => 'Sewells Point, VA',
            '1611400' => 'Nawiliwili, HI',
            '9461380' => 'Anchorage, AK'
        ];
    }
}