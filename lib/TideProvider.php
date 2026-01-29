<?php

require_once __DIR__ . '/Util.php';

class TideProvider {
    private $config;

    public function __construct($config) {
        $this->config = $config;
    }

    /**
     * Fetch tide predictions for a date range from NOAA CO-OPS API
     * 
     * @param string $station_id NOAA station ID
     * @param string $start_date Format: YYYY-MM-DD
     * @param string $end_date Format: YYYY-MM-DD
     * @param string $timezone Local timezone for output
     * @return array Array of tide predictions with format:
     *               [['ts_local' => 'YYYY-MM-DDTHH:MM:SS', 'type' => 'L'|'H', 'value_m' => float], ...]
     */
    public function fetchTidePredictions($station_id, $start_date, $end_date, $timezone) {
        $provider_config = $this->config['provider'];

        $cache_config = $this->config['cache'] ?? [];
        $cache_dir = $cache_config['dir'] ?? null;
        $cache_ttl = (int) ($cache_config['noaa_ttl'] ?? 0);
        
        // NOAA API parameters
        $params = [
            'product' => 'predictions',
            'application' => 'NOS.COOPS.TAC.WL',
            'begin_date' => str_replace('-', '', $start_date), // YYYYMMDD format
            'end_date' => str_replace('-', '', $end_date),
            'datum' => 'MLLW', // Mean Lower Low Water
            'station' => $station_id,
            'time_zone' => 'lst_ldt', // Local Standard/Daylight Time
            'units' => 'metric', // Always fetch in meters, convert for display
            'interval' => 'hilo', // High/Low only
            'format' => 'json'
        ];

        $url = $provider_config['base_url'] . '?' . http_build_query($params);

        $cache_path = null;
        if ($cache_dir && $cache_ttl > 0) {
            $cache_key_data = [
                'station_id' => $station_id,
                'start_date' => $start_date,
                'end_date' => $end_date,
                'timezone' => $timezone,
                'params' => $params
            ];
            $cache_path = rtrim($cache_dir, '/') . '/noaa-' . md5(json_encode($cache_key_data)) . '.json';
            $cached_predictions = Util::readJsonCache($cache_path, $cache_ttl);
            if (is_array($cached_predictions)) {
                return $this->processTideData($cached_predictions, $timezone);
            }
        }
        
        $attempts = 0;
        $max_attempts = 1 + $provider_config['retry_attempts'];
        
        while ($attempts < $max_attempts) {
            $attempts++;
            
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => $provider_config['timeout'],
                    'user_agent' => $provider_config['user_agent']
                ]
            ]);

            $response = @file_get_contents($url, false, $context);
            
            if ($response === false) {
                $error = error_get_last();
                if ($attempts >= $max_attempts) {
                    throw new Exception("NOAA API is currently unavailable. This may be temporary - please try again in a few minutes. Technical details: Failed to fetch tide data after {$max_attempts} attempts. Last error: " . ($error['message'] ?? 'Unknown network error'));
                }
                sleep(2); // Brief delay before retry
                continue;
            }

            $data = json_decode($response, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                if ($attempts >= $max_attempts) {
                    throw new Exception("NOAA API returned invalid data format. This may indicate a temporary service issue. Please try again later. Technical details: " . json_last_error_msg());
                }
                sleep(2); // Brief delay before retry
                continue;
            }

            if (isset($data['error'])) {
                $errorMsg = $data['error']['message'] ?? 'Unknown API error';
                if (strpos(strtolower($errorMsg), 'internal server error') !== false || 
                    strpos(strtolower($errorMsg), '500') !== false) {
                    throw new Exception("NOAA's servers are experiencing technical difficulties. This is usually temporary - please try again in a few minutes. (API Error: {$errorMsg})");
                } else {
                    throw new Exception("NOAA API error: {$errorMsg}");
                }
            }

            if (!isset($data['predictions']) || !is_array($data['predictions'])) {
                if ($attempts >= $max_attempts) {
                    $responseSnippet = substr($response, 0, 200);
                    if (strpos($responseSnippet, 'Internal server error') !== false) {
                        throw new Exception("NOAA's tide prediction service is temporarily unavailable. This often resolves within a few minutes. Please try again later.");
                    } else {
                        throw new Exception("No tide predictions found for this station. The station may not provide tide prediction data, or the service may be temporarily unavailable.");
                    }
                }
                sleep(2); // Brief delay before retry
                continue;
            }

            // Success - process the data
            if ($cache_path && $cache_ttl > 0) {
                Util::writeJsonCache($cache_path, $data['predictions']);
            }
            return $this->processTideData($data['predictions'], $timezone);
        }
        
        throw new Exception("Unexpected error in tide data fetching");
    }

    /**
     * Process raw NOAA tide data into normalized format
     * 
     * @param array $predictions Raw predictions from NOAA API
     * @param string $timezone Target timezone
     * @return array Normalized tide data
     */
    private function processTideData($predictions, $timezone) {
        $processed = [];
        $tz = new DateTimeZone($timezone);

        foreach ($predictions as $prediction) {
            if (!isset($prediction['t'], $prediction['v'], $prediction['type'])) {
                continue; // Skip malformed entries
            }

            // Parse the time (NOAA returns local time in format: YYYY-MM-DD HH:MM)
            $datetime = DateTime::createFromFormat('Y-m-d H:i', $prediction['t'], $tz);
            if (!$datetime) {
                continue; // Skip unparseable times
            }

            $processed[] = [
                'ts_local' => $datetime->format('Y-m-d\TH:i:s'),
                'type' => strtoupper($prediction['type']), // 'H' or 'L'
                'value_m' => (float) $prediction['v'] // Already in meters from API
            ];
        }

        // Sort by timestamp to ensure chronological order
        usort($processed, function($a, $b) {
            return strcmp($a['ts_local'], $b['ts_local']);
        });

        return $processed;
    }

    /**
     * Convert meters to feet
     * 
     * @param float $meters
     * @return float
     */
    public static function metersToFeet($meters) {
        return $meters * 3.28084;
    }

    /**
     * Convert feet to meters
     * 
     * @param float $feet
     * @return float
     */
    public static function feetToMeters($feet) {
        return $feet / 3.28084;
    }
}
