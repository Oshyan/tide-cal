# Tide Calendar (Single Station) — **MVP Spec (LAMP, ICS only)**

> **Use:** hand this to a CLI coding agent as the project brief.
> **Scope:** minimal viable product, **no Google Calendar API**, **no CLI tools**.
> **Hosting:** existing **LAMP** stack only.
> **Output:** public **ICS feed URL** you can add to Google Calendar.
> **Interaction:** simple web page (`tidecal.php`) with a **Generate/Update** button.

---

## 1) Goal

Publish a calendar of **low tides** for **one Station** for a chosen **year**, filtered by:

* **Minimum tide height** (e.g., include only **negative** lows)
* **Daylight window**: include only low tides occurring **≥ N minutes before local sunset**

The calendar is exposed as **`/tides/tides.ics.php`** (HTTP) for subscription in Google Calendar.

---

## 2) Deliverables (files & structure)

```
/var/www/html/tides/
  config.php                 # All settings (single station, filters, paths)
  tidecal.php                # Web UI: button to Generate/Update ICS now
  tides.ics.php              # Streams the generated ICS file
  lib/
    TideProvider.php         # Fetch tide predictions for a date range
    SunCalc.php              # Compute sunrise/sunset for lat/lon/date
    IcsWriter.php            # Build VCALENDAR/VEVENT text
    Util.php                 # Timezone helpers, unit conversion, logging
  data/
    tides-YYYY.ics           # Generated ICS for configured year
  logs/
    run-YYYYMM.log           # Append-only log of generation runs
```

No database is required.

---

## 3) Configuration (`config.php`)

```php
<?php
return [
  // Station (single)
  'station_id' => '9414290',          // NOAA CO-OPS station id
  'station_name' => 'San Francisco',  // Display name
  'lat' => 37.806, 'lon' => -122.465, // For sunrise/sunset
  'timezone' => 'America/Los_Angeles',// PHP TZ for ICS DTSTART/DTEND

  // Year scope (default: current year if null)
  'year' => null,

  // Filters
  'unit' => 'ft',                      // 'ft' or 'm' (display only)
  'min_low_tide_value' => 0.0, // include all lows ≤ this value (e.g. 0.0 = all negative lows; -0.5 = only ≤ -0.5)
  'minutes_before_sunset_required' => 60, // e.g., must be ≥ 60 min before sunset

  // Output
  'ics_path' => __DIR__ . '/data/tides-{{YEAR}}.ics',

  // Web
  'base_url' => 'https://example.com/tides/', // used in DESCRIPTION if desired

  // Provider
  'provider' => [
    // Implement TideProvider using publicly-available high/low predictions.
    // Inputs: station_id, start_date, end_date, time_zone=local (LST/LDT).
    // Output: array of [timestamp_local (ISO8601), type ('H'|'L'), value (meters)]
  ],
];
```

> **Note:** If the provider returns meters and you want feet in titles, convert via `ft = m * 3.28084` (round to 1 decimal for display).

---

## 4) Web UI (`tidecal.php`)

* Minimal HTML page with one **Generate/Update** button.
* On click (POST), it:

  1. Loads `config.php` and resolves `year` (current if null).
  2. Calls the generation pipeline (below).
  3. Writes `tides-YYYY.ics` atomically (temp file → rename).
  4. Shows a short result summary: number fetched, number after filters, path to ICS, last modified time.
* Security is not strict (per user instruction), but:

  * Use **POST** for action.
  * CSRF token not required for MVP; keep code simple.

---

## 5) Public ICS endpoint (`tides.ics.php`)

* Reads the configured ICS file for the current year.
* Sends headers:

  * `Content-Type: text/calendar; charset=utf-8`
  * `Cache-Control: public, max-age=3600`
  * `Last-Modified` based on file mtime
  * Optional `ETag`
* If file missing, return an **empty VCALENDAR** with a comment line (so subscriptions don’t error).

---

## 6) Generation pipeline (called from `tidecal.php`)

**Inputs:** `station_id`, `lat/lon`, `timezone`, `year`, `min_low_tide_value`, `minutes_before_sunset_required`.

**Steps:**

1. **Fetch predictions (year range)**

   * Pull **high/low** tide predictions for `Jan 1` → `Dec 31` of `year`.
   * Request **local station time** (LST/LDT) so DST is handled by provider.
   * Normalize to:

     ```php
     [
       [
         'ts_local' => 'YYYY-MM-DDTHH:MM:SS', // local time string
         'type'     => 'L'|'H',
         'value_m'  => float,                  // meters
       ],
       ...
     ]
     ```

2. **Filter to lows below threshold**

   * Keep entries where `type == 'L'` and `value_m < threshold_m`.
   * `threshold_m` = (`min_low_tide_value` in chosen unit) converted to meters for comparison.

3. **Compute sunset and apply daylight filter**

   * For each remaining tide’s **local date**, compute **sunset** at (`lat`, `lon`) with `timezone`.
   * Keep only events where `(sunset_time - tide_time) >= minutes_before_sunset_required * 60s`.
   * If sunset fails for a date, **include** the event and mark a flag for “sunset unavailable” in DESCRIPTION (also log a warning).

4. **Build ICS**

   * Calendar-level headers (see **ICS format** below).
   * For each kept tide:

     * `SUMMARY`: `Low Tide −X.Y ft` (or `−X.Y m`) — one decimal.
     * `DTSTART;TZID=...:` local datetime (no Z).
     * `DTEND` = `DTSTART + 30 minutes` (fixed duration).
     * `UID`: deterministic stable key, e.g. `tide-<station_id>-<UTC_ts>` (UTC timestamp of the instant).
     * `DESCRIPTION`: include station name/id, local time, height in both units, sunrise/sunset times, generation timestamp, and optional source URL.
     * `LOCATION`: `<station_name>`
   * Write to temp, then `rename()` to `tides-YYYY.ics`.

5. **Logging**

   * Append to `logs/run-YYYYMM.log`: timestamp, year, fetched count, kept count, duration, warnings/errors.

---

## 7) ICS format (example skeleton)

```
BEGIN:VCALENDAR
PRODID:-//TideCal//SingleStation//EN
VERSION:2.0
CALSCALE:GREGORIAN
METHOD:PUBLISH
X-WR-CALNAME:Tides - San Francisco (Low tides)
X-WR-TIMEZONE:America/Los_Angeles

BEGIN:VEVENT
UID:tide-9414290-20260115T093000Z
DTSTAMP:20250102T010203Z
DTSTART;TZID=America/Los_Angeles:20260115T013000
DTEND;TZID=America/Los_Angeles:20260115T020000
SUMMARY:Low Tide −1.2 ft
LOCATION:San Francisco
DESCRIPTION:Station: San Francisco (9414290)\nLocal time: 2026-01-15 01:30\nHeight: -1.2 ft (-0.37 m)\nSunrise: 07:24 · Sunset: 17:12\nGenerated: 2025-08-31 12:00
END:VEVENT

END:VCALENDAR
```

* Use `\n` for newlines inside DESCRIPTION.
* Ensure **CRLF** (`\r\n`) line endings for maximum compatibility.
* Fold long lines per RFC 5545 (75 octets) if convenient; not strictly required for most clients but recommended.

---

## 8) Implementation notes

* **Timezone**: create all PHP `DateTime` with `new DateTimeZone($config['timezone'])`. Store UTC clones when building deterministic `UID`s.
* **Sun calculations**: use a standard algorithm (e.g., NOAA/SPA equivalent) in `SunCalc.php` that returns sunrise/sunset local times for a given date & lat/lon.
* **Atomic write**: write ICS to `.../data/.tmp-<rand>.ics` then `rename()` to target path.
* **HTTP timeouts**: set provider fetch timeout (e.g., 15s) and retry once.
* **Units**: compare in meters; **display** in configured unit and optionally both in DESCRIPTION.
* **Resilience**: if provider is down, keep existing ICS; show error message on `tidecal.php`.

---

## 9) Minimal UI behavior (`tidecal.php`)

* Shows current config (station, year, filters).
* Button: **Generate/Update** → POST → runs pipeline.
* Result panel:

  * “Generated `data/tides-YYYY.ics`”
  * Counts: fetched vs. kept
  * Last modified time
  * **Subscription URL:** full link to `tides.ics.php`
* If failure: show concise error and advise retry.

---

## 10) Test checklist

* ✅ Generate for current year; confirm file exists and is non-empty.
* ✅ Import `tides.ics.php` into Google Calendar; verify times (incl. DST transitions).
* ✅ Change `min_low_tide_value` and regenerate; confirm count drops/changes.
* ✅ Change `minutes_before_sunset_required`; confirm filtering behaves.
* ✅ Manually inspect an event title/description for correct units and times.
* ✅ Remove `data/tides-YYYY.ics` and hit `tides.ics.php` → returns empty VCALENDAR (no server error).
* ✅ Log file contains a new entry with counts and duration.

---

## 11) Acceptance criteria

* A user can visit `tidecal.php`, click **Generate/Update**, and then add the ICS feed at `tides.ics.php` to Google Calendar.
* Events only include **low tides** that are **below the configured threshold** and **≥ N minutes before sunset**.
* Times reflect the configured **local timezone** (with DST).
* Regeneration is **idempotent** and fast (<5s typical after caching).
* No DB required; config-only deployment.

---

**End of spec.**
