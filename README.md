# TideCal

Generate and publish ICS calendars of tide events (low/high) with flexible filters and optional sunrise/sunset events. Subscribe to your custom tide calendar from Google Calendar, Apple Calendar, or Outlook.

## Features

### Tide Filtering
- **Low tides**: Set maximum height threshold (e.g., only tides ≤ -0.5 ft)
- **High tides**: Set minimum height threshold (e.g., only tides ≥ 5.0 ft)
- Independent enable/disable for each tide type

### Time Window Filters
- **Daylight filters**: After sunrise, before sunset, or between both
- **Minutes offset**: Fine-tune with "X minutes after sunrise" or "X minutes before sunset"
- **Clock-time windows**: Set earliest/latest times (e.g., only between 10:00 AM and 6:00 PM)

### Sun Events
- Optional sunrise and/or sunset calendar events
- Choose all days or only days with qualifying tides

### Calendar Management
- **Unique calendar IDs** based on filter settings
- **Edit existing calendars** while preserving subscription URLs
- **Per-calendar logging** with View Log button
- **Event breakdown** showing tide count vs sun event count
- **Local timezone display** for created/updated timestamps

### Station Search
- Search NOAA tide stations by name or location
- Auto-populate coordinates, timezone, and station details

### Subscription
- ICS feed endpoint compatible with Google Calendar, Apple Calendar, Outlook
- Built-in instructions for adding calendars to each platform
- Copy URL or open directly in Apple Calendar

## Local Setup

This is a plain PHP app; no framework or build step required.

1. Copy `config.php.example` to `config.php` and configure defaults
2. Serve the folder with PHP:

```bash
php -S localhost:8000 -t /path/to/TideCal
```

3. Open http://localhost:8000/index.php

## Endpoints

| Endpoint | Description |
|----------|-------------|
| `index.php` | Main UI for generating calendars |
| `calendars.php` | List, edit, and manage generated calendars |
| `calendar.ics.php?id=...` | ICS feed for a specific calendar |
| `station_search.php` | AJAX station lookup |
| `get_log.php?id=...` | Fetch per-calendar generation logs |

## Directory Structure

```
TideCal/
├── index.php           # Main calendar generator UI
├── calendars.php       # Calendar management page
├── calendar.ics.php    # ICS feed endpoint
├── station_search.php  # Station search API
├── get_log.php         # Log retrieval API
├── config.php          # Configuration (not in repo)
├── deploy.sh           # Deployment script
├── lib/
│   ├── CalendarManager.php  # Calendar CRUD operations
│   ├── IcsWriter.php        # ICS file generation
│   ├── RateLimit.php        # Rate limiting
│   ├── StationLookup.php    # NOAA station lookup
│   ├── SunCalc.php          # Sunrise/sunset calculations
│   ├── TideProvider.php     # NOAA tide data fetching
│   └── Util.php             # Utility functions
├── data/               # Generated calendars + logs (gitignored)
├── cache/              # NOAA API cache (gitignored)
└── logs/               # Application logs (gitignored)
```

## Deployment

Deploy using the included script:

```bash
./deploy.sh          # Deploy to production
./deploy.sh --dry-run  # Preview changes without deploying
```

The script syncs code via rsync, excluding data/cache/logs directories.

## Data Sources

- **Tide predictions**: [NOAA CO-OPS Tides & Currents API](https://tidesandcurrents.noaa.gov/api/)
- **Sunrise/sunset**: Calculated using standard astronomical algorithms

## Repository

GitHub: https://github.com/Oshyan/tide-cal
