<?php

class RateLimit {
    private $data_dir;
    
    public function __construct($data_dir) {
        $this->data_dir = $data_dir;
        if (!is_dir($data_dir)) {
            mkdir($data_dir, 0755, true);
        }
    }
    
    /**
     * Check if request is within rate limits
     * 
     * @param string $identifier Client identifier (IP, session, etc.)
     * @param string $action Action being rate limited
     * @param int $max_requests Maximum requests allowed
     * @param int $window_seconds Time window in seconds
     * @return bool True if within limits, false if exceeded
     */
    public function isAllowed($identifier, $action, $max_requests = 10, $window_seconds = 60) {
        $key = $this->getKey($identifier, $action);
        $file = $this->data_dir . '/rate_' . $key . '.json';
        
        $now = time();
        $requests = [];
        
        // Load existing requests
        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true);
            if ($data && isset($data['requests'])) {
                $requests = $data['requests'];
            }
        }
        
        // Remove expired requests
        $requests = array_filter($requests, function($timestamp) use ($now, $window_seconds) {
            return ($now - $timestamp) < $window_seconds;
        });
        
        // Check if limit exceeded
        if (count($requests) >= $max_requests) {
            return false;
        }
        
        // Add current request
        $requests[] = $now;
        
        // Save updated requests
        $data = ['requests' => array_values($requests)];
        file_put_contents($file, json_encode($data));
        
        return true;
    }
    
    /**
     * Get time until rate limit resets
     * 
     * @param string $identifier Client identifier
     * @param string $action Action being rate limited  
     * @param int $window_seconds Time window in seconds
     * @return int Seconds until oldest request expires
     */
    public function getResetTime($identifier, $action, $window_seconds = 60) {
        $key = $this->getKey($identifier, $action);
        $file = $this->data_dir . '/rate_' . $key . '.json';
        
        if (!file_exists($file)) {
            return 0;
        }
        
        $data = json_decode(file_get_contents($file), true);
        if (!$data || !isset($data['requests']) || empty($data['requests'])) {
            return 0;
        }
        
        $oldest_request = min($data['requests']);
        $reset_time = $oldest_request + $window_seconds - time();
        
        return max(0, $reset_time);
    }
    
    /**
     * Clean up old rate limit files
     * 
     * @param int $older_than_seconds Delete files older than this many seconds
     */
    public function cleanup($older_than_seconds = 3600) {
        $files = glob($this->data_dir . '/rate_*.json');
        $cutoff = time() - $older_than_seconds;
        
        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
            }
        }
    }
    
    /**
     * Generate safe key for rate limiting
     */
    private function getKey($identifier, $action) {
        return substr(md5($identifier . '_' . $action), 0, 16);
    }
}