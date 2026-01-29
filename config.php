<?php
return [
    // Station (single)
    'station_id' => '9414290',          // NOAA CO-OPS station id
    'station_name' => 'San Francisco',  // Display name
    'lat' => 37.806, 'lon' => -122.465, // For sunrise/sunset
    'timezone' => 'America/Los_Angeles',// PHP TZ for ICS DTSTART/DTEND

    // Year scope (default: current year if null)
    'year' => date('Y'),

    // Filters
    'unit' => 'ft',                      // 'ft' or 'm' (display only)
    'include_low_tides' => true,
    'min_low_tide_value' => -0.5, // include lows ≤ this value (e.g. -0.5 = only ≤ -0.5)
    'low_time_filter' => 'after_sunrise', // none | after_sunrise | before_sunset | between
    'low_minutes_after_sunrise' => 0,
    'low_minutes_before_sunset' => 0,
    'low_earliest_time_enabled' => false,
    'low_earliest_time' => '00:00',
    'low_latest_time_enabled' => false,
    'low_latest_time' => '23:59',

    'include_high_tides' => false,
    'high_tide_min_value' => 4.0, // include highs ≥ this value
    'high_time_filter' => 'none', // none | after_sunrise | before_sunset | between
    'high_minutes_after_sunrise' => 0,
    'high_minutes_before_sunset' => 0,
    'high_earliest_time_enabled' => false,
    'high_earliest_time' => '00:00',
    'high_latest_time_enabled' => false,
    'high_latest_time' => '23:59',

    'include_sunrise_events' => false,
    'include_sunset_events' => false,
    'sun_events_match_tide_days' => true, // only include sun events on days with qualifying tides

    // Output
    'ics_path' => __DIR__ . '/data/tides-{{YEAR}}.ics',

    // Cache
    'cache' => [
        'dir' => __DIR__ . '/cache',
        'noaa_ttl' => 86400, // seconds
    ],

    // Web
    'base_url' => 'https://oshyan.com/tides/', // used in DESCRIPTION if desired

    // Provider (NOAA CO-OPS API configuration)
    'provider' => [
        'base_url' => 'https://api.tidesandcurrents.noaa.gov/api/prod/datagetter',
        'timeout' => 15, // seconds
        'retry_attempts' => 1,
        'user_agent' => 'TideCal/1.0 (Tide Calendar Generator)',
    ],
];
