# TideCal

Generate and publish an ICS calendar of tide events (low/high) with flexible filters and optional sunrise/sunset events.

## Features
- Low and high tide toggles with independent thresholds
- Sunlight window filters (after sunrise / before sunset / between)
- Clock-time window filters (earliest/latest)
- Optional sunrise/sunset events (all days or only on qualifying tide days)
- Unique calendar IDs based on filter settings
- ICS subscription endpoint for Google/Apple Calendar

## Local Setup
This is a plain PHP app; no framework or build step required.

1. Configure defaults in `config.php`
2. Serve the folder with PHP (example):

```bash
php -S localhost:8000 -t /path/to/TideCal
```

Then open:
```
http://localhost:8000/index.php
```

## Key Endpoints
- `index.php` — UI to generate calendars
- `calendar.ics.php?id=...` — ICS feed for a generated calendar
- `calendars.php` — list and manage generated calendars
- `station_search.php` — station lookup

## Data + Caching
- Generated calendars live in `data/`
- Logs in `logs/`
- NOAA API cache in `cache/`

These directories are intentionally ignored by git.

## Deploy (oshyan.com)
Code is deployed to:
```
/home/customer/www/oshyan.com/public_html/tides/
```

Typical deploy flow:
1. Sync code (rsync or similar)
2. Regenerate calendars from the UI

## Repository
- GitHub: https://github.com/Oshyan/tide-cal

