<?php
require_once __DIR__ . '/lib/CalendarManager.php';
require_once __DIR__ . '/lib/Util.php';

// Load configuration
$config = require __DIR__ . '/config.php';

// Initialize calendar manager
$calendar_manager = new CalendarManager(__DIR__ . '/data');

// Handle delete action
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'delete' && isset($_POST['calendar_id'])) {
        $calendar_id = $_POST['calendar_id'];
        if ($calendar_manager->deleteCalendar($calendar_id)) {
            $message = "Calendar {$calendar_id} deleted successfully.";
            $message_type = 'success';
        } else {
            $message = "Failed to delete calendar {$calendar_id}.";
            $message_type = 'error';
        }
    } elseif ($_POST['action'] === 'cleanup') {
        $days = (int) ($_POST['cleanup_days'] ?? 365);
        $deleted_count = $calendar_manager->cleanupOldCalendars($days);
        $message = "Cleaned up {$deleted_count} old calendars.";
        $message_type = 'success';
    }
}

$all_calendars = $calendar_manager->getAllCalendars();

// Sort by updated_at descending (most recent first)
uasort($all_calendars, function($a, $b) {
    return strcmp($b['updated_at'], $a['updated_at']);
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TideCal - All Calendars</title>
    <style>
        :root {
            --primary: #007cba;
            --primary-dark: #005a87;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --success: #059669;
            --success-bg: #d1fae5;
            --error: #dc2626;
            --error-bg: #fee2e2;
            --warning: #d97706;
            --warning-bg: #fef3c7;
            --radius-sm: 4px;
            --radius-md: 6px;
        }

        * { box-sizing: border-box; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 960px;
            margin: 1rem auto;
            padding: 0 1rem;
            line-height: 1.4;
            color: var(--gray-800);
            background: var(--gray-50);
        }

        .header {
            text-align: center;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--gray-200);
        }
        .header h1 { margin: 0; font-size: 1.5rem; }
        .header p { margin: 0.25rem 0 0; font-size: 0.9rem; color: var(--gray-500); }

        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
        }

        .btn {
            background: var(--primary);
            color: white;
            border: none;
            padding: 6px 12px;
            font-size: 12px;
            font-weight: 500;
            border-radius: var(--radius-md);
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .btn:hover { background: var(--primary-dark); }
        .btn.secondary { background: var(--gray-500); }
        .btn.secondary:hover { background: var(--gray-600); }
        .btn.danger { background: var(--error); }
        .btn.danger:hover { background: #b91c1c; }

        .message {
            margin-bottom: 0.75rem;
            padding: 0.5rem 0.75rem;
            border-radius: var(--radius-md);
            font-size: 0.85rem;
        }
        .message.success { background: var(--success-bg); color: #065f46; }
        .message.error { background: var(--error-bg); color: #991b1b; }

        .stats { font-size: 0.85rem; color: var(--gray-600); }
        .stats strong { color: var(--primary); font-size: 1.1rem; }

        .maintenance {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.8rem;
            color: var(--warning);
        }
        .maintenance input {
            width: 50px;
            padding: 3px 6px;
            border: 1px solid var(--gray-300);
            border-radius: var(--radius-sm);
            font-size: 12px;
        }

        .calendar-list { display: flex; flex-direction: column; gap: 0.5rem; }

        .calendar-row {
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-md);
            padding: 0.5rem 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.8rem;
        }
        .calendar-row:hover { border-color: var(--gray-300); }

        .cal-station {
            min-width: 120px;
            max-width: 140px;
        }
        .cal-station .name {
            font-weight: 600;
            font-size: 0.85rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .cal-station .ids {
            font-size: 0.7rem;
            color: var(--gray-400);
            font-family: monospace;
        }

        .cal-params {
            flex: 1;
            display: flex;
            flex-wrap: wrap;
            gap: 0.25rem 0.5rem;
            color: var(--gray-600);
            font-size: 0.75rem;
        }
        .cal-params span { white-space: nowrap; }
        .cal-params .label { color: var(--gray-400); }
        .cal-params .sep { color: var(--gray-300); }

        .cal-dates {
            font-size: 0.7rem;
            color: var(--gray-400);
            text-align: right;
            min-width: 100px;
        }

        .cal-actions {
            display: flex;
            gap: 3px;
        }
        .btn-sm {
            background: white;
            color: var(--gray-500);
            border: 1px solid var(--gray-200);
            padding: 4px 6px;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-size: 11px;
            display: flex;
            align-items: center;
            gap: 2px;
        }
        .btn-sm:hover {
            border-color: var(--primary);
            color: var(--primary);
        }
        .btn-sm.danger:hover {
            border-color: var(--error);
            color: var(--error);
        }
        .btn-sm.clicked {
            background: var(--success-bg);
            border-color: var(--success);
            color: var(--success);
        }

        .empty-state {
            text-align: center;
            padding: 2rem;
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-md);
        }
        .empty-state p { color: var(--gray-500); margin: 0.5rem 0 1rem; }

        .footer {
            margin-top: 1.5rem;
            padding-top: 0.75rem;
            border-top: 1px solid var(--gray-200);
            text-align: center;
            font-size: 0.75rem;
            color: var(--gray-400);
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal.active { display: flex; }
        .modal-box {
            background: white;
            border-radius: var(--radius-md);
            width: 90%;
            max-width: 600px;
            max-height: 70vh;
            display: flex;
            flex-direction: column;
        }
        .modal-header {
            padding: 0.625rem 0.875rem;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-header h3 { margin: 0; font-size: 0.95rem; }
        .modal-close {
            background: none;
            border: none;
            font-size: 1.25rem;
            cursor: pointer;
            color: var(--gray-400);
            line-height: 1;
        }
        .modal-close:hover { color: var(--gray-600); }
        .modal-body {
            padding: 0.75rem;
            overflow-y: auto;
        }
        .log-content {
            font-family: 'SF Mono', Monaco, monospace;
            font-size: 0.7rem;
            line-height: 1.5;
            background: var(--gray-50);
            padding: 0.5rem;
            border-radius: var(--radius-sm);
            white-space: pre-wrap;
            word-break: break-all;
        }

        .toast {
            position: fixed;
            top: 16px;
            right: 16px;
            background: var(--success);
            color: white;
            padding: 8px 14px;
            border-radius: var(--radius-md);
            font-size: 0.8rem;
            z-index: 1001;
            animation: slideIn 0.2s ease-out;
        }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } }
        @keyframes slideOut { to { transform: translateX(100%); opacity: 0; } }

        @media (max-width: 700px) {
            .calendar-row { flex-wrap: wrap; }
            .cal-station { min-width: 100%; }
            .cal-dates { min-width: auto; text-align: left; }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>TideCal US</h1>
        <p>All Generated Calendars</p>
    </div>

    <?php if ($message): ?>
    <div class="message <?php echo htmlspecialchars($message_type); ?>">
        <?php echo htmlspecialchars($message); ?>
    </div>
    <?php endif; ?>

    <div class="top-bar">
        <div style="display: flex; align-items: center; gap: 1rem;">
            <a href="index.php" class="btn secondary">&larr; Back</a>
            <span class="stats"><strong><?php echo count($all_calendars); ?></strong> calendars</span>
        </div>
        <?php if (!empty($all_calendars)): ?>
        <form method="POST" class="maintenance">
            <input type="hidden" name="action" value="cleanup">
            <span>Delete &gt;</span>
            <input type="number" name="cleanup_days" value="365" min="1">
            <span>days old</span>
            <button type="submit" class="btn danger" onclick="return confirm('Delete old calendars?')">Clean</button>
        </form>
        <?php endif; ?>
    </div>

    <?php if (!empty($all_calendars)): ?>
    <div class="calendar-list">
        <?php foreach ($all_calendars as $calendar): ?>
        <?php
        $params = $calendar['params'];
        $calendar_url = $calendar_manager->getCalendarUrl($calendar['id'], $config['base_url']);
        $ics_file = $calendar_manager->getCalendarFilePath($calendar['id']);
        $file_exists = file_exists($ics_file);
        $ics_content = $file_exists ? file_get_contents($ics_file) : '';
        $event_counts = $file_exists ? Util::countIcsEventsByType($ics_content) : ['low' => 0, 'high' => 0, 'sunrise' => 0, 'sunset' => 0, 'total' => 0];

        // Build edit URL with ALL params
        $edit_params = [
            'edit' => $calendar['id'],
            // Station info
            'station_id' => $params['station_id'] ?? '',
            'station_name' => $params['station_name'] ?? '',
            'lat' => $params['lat'] ?? '',
            'lon' => $params['lon'] ?? '',
            'timezone' => $params['timezone'] ?? '',
            // Basic settings
            'year' => $params['year'] ?? date('Y'),
            'unit' => $params['unit'] ?? 'ft',
        ];

        // Low tide params
        if (!empty($params['include_low_tides'])) {
            $edit_params['include_low_tides'] = '1';
            $edit_params['min_low_tide_value'] = $params['min_low_tide_value'] ?? 0;
            $edit_params['low_time_filter'] = $params['low_time_filter'] ?? 'none';
            $edit_params['low_minutes_after_sunrise'] = $params['low_minutes_after_sunrise'] ?? 0;
            $edit_params['low_minutes_before_sunset'] = $params['low_minutes_before_sunset'] ?? 0;
            if (!empty($params['low_earliest_time_enabled'])) {
                $edit_params['low_earliest_time_enabled'] = '1';
                $edit_params['low_earliest_time'] = $params['low_earliest_time'] ?? '00:00';
            }
            if (!empty($params['low_latest_time_enabled'])) {
                $edit_params['low_latest_time_enabled'] = '1';
                $edit_params['low_latest_time'] = $params['low_latest_time'] ?? '23:59';
            }
        }

        // High tide params
        if (!empty($params['include_high_tides'])) {
            $edit_params['include_high_tides'] = '1';
            $edit_params['high_tide_min_value'] = $params['high_tide_min_value'] ?? 0;
            $edit_params['high_time_filter'] = $params['high_time_filter'] ?? 'none';
            $edit_params['high_minutes_after_sunrise'] = $params['high_minutes_after_sunrise'] ?? 0;
            $edit_params['high_minutes_before_sunset'] = $params['high_minutes_before_sunset'] ?? 0;
            if (!empty($params['high_earliest_time_enabled'])) {
                $edit_params['high_earliest_time_enabled'] = '1';
                $edit_params['high_earliest_time'] = $params['high_earliest_time'] ?? '00:00';
            }
            if (!empty($params['high_latest_time_enabled'])) {
                $edit_params['high_latest_time_enabled'] = '1';
                $edit_params['high_latest_time'] = $params['high_latest_time'] ?? '23:59';
            }
        }

        // Sun event params
        if (!empty($params['include_sunrise_events'])) $edit_params['include_sunrise_events'] = '1';
        if (!empty($params['include_sunset_events'])) $edit_params['include_sunset_events'] = '1';
        if (!empty($params['sun_events_match_tide_days'])) $edit_params['sun_events_match_tide_days'] = '1';

        $edit_url = 'index.php?' . http_build_query($edit_params);
        ?>
        <div class="calendar-row">
            <div class="cal-station">
                <div class="name" title="<?php echo htmlspecialchars($params['station_name'] ?? 'Unknown'); ?>">
                    <?php echo htmlspecialchars($params['station_name'] ?? 'Unknown'); ?>
                </div>
                <div class="ids"><?php echo htmlspecialchars($params['station_id'] ?? ''); ?> / <?php echo htmlspecialchars($calendar['id']); ?></div>
            </div>
            <div class="cal-params">
                <span><span class="label">Year:</span> <?php echo htmlspecialchars($params['year'] ?? date('Y')); ?></span>
                <?php if (!empty($params['include_low_tides'])): ?>
                <span class="sep">|</span>
                <span><span class="label">Low:</span> &le;<?php echo htmlspecialchars($params['min_low_tide_value'] ?? '0'); ?><?php echo htmlspecialchars($params['unit'] ?? 'ft'); ?></span>
                <?php endif; ?>
                <?php if (!empty($params['include_high_tides'])): ?>
                <span class="sep">|</span>
                <span><span class="label">High:</span> &ge;<?php echo htmlspecialchars($params['high_tide_min_value'] ?? '0'); ?><?php echo htmlspecialchars($params['unit'] ?? 'ft'); ?></span>
                <?php endif; ?>
                <?php if ($event_counts['total'] > 0): ?>
                <span class="sep">|</span>
                <?php
                    $tide_count = $event_counts['low'] + $event_counts['high'];
                    $sun_count = $event_counts['sunrise'] + $event_counts['sunset'];
                ?>
                <?php if ($tide_count > 0): ?>
                <span title="<?php echo $event_counts['low']; ?> low, <?php echo $event_counts['high']; ?> high"><?php echo $tide_count; ?> tides</span>
                <?php endif; ?>
                <?php if ($tide_count > 0 && $sun_count > 0): ?><span class="sep">+</span><?php endif; ?>
                <?php if ($sun_count > 0): ?>
                <span title="<?php echo $event_counts['sunrise']; ?> sunrise, <?php echo $event_counts['sunset']; ?> sunset"><?php echo $sun_count; ?> sun</span>
                <?php endif; ?>
                <?php endif; ?>
                <?php if (!$file_exists): ?>
                <span style="color: var(--error);">missing</span>
                <?php endif; ?>
            </div>
            <div class="cal-dates">
                <div class="local-time" data-utc="<?php echo htmlspecialchars($calendar['created_at']); ?>"></div>
                <div class="local-time" data-utc="<?php echo htmlspecialchars($calendar['updated_at']); ?>" data-prefix="Upd: "></div>
            </div>
            <div class="cal-actions">
                <button class="btn-sm" onclick="copyUrl('<?php echo htmlspecialchars($calendar_url); ?>', this)" title="Copy URL">📋</button>
                <button class="btn-sm" onclick="appleCal('<?php echo htmlspecialchars($calendar_url); ?>')" title="Apple Calendar">🍎</button>
                <a href="<?php echo htmlspecialchars($edit_url); ?>" class="btn-sm" title="Edit">✏️</a>
                <button class="btn-sm" onclick="viewLog('<?php echo htmlspecialchars($calendar['id']); ?>')" title="View Log">📄</button>
                <form method="POST" style="display:inline;margin:0;">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="calendar_id" value="<?php echo htmlspecialchars($calendar['id']); ?>">
                    <button type="submit" class="btn-sm danger" onclick="return confirm('Delete?')" title="Delete">🗑️</button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="empty-state">
        <h2>No Calendars Yet</h2>
        <p>Create your first calendar to get started.</p>
        <a href="index.php" class="btn">Create Calendar</a>
    </div>
    <?php endif; ?>

    <div class="footer">TideCal &middot; NOAA Tides &amp; Currents</div>

    <div class="modal" id="log-modal">
        <div class="modal-box">
            <div class="modal-header">
                <h3>Generation Log</h3>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="log-content" id="log-content">Loading...</div>
            </div>
        </div>
    </div>

    <script>
    // Convert UTC times to local
    document.querySelectorAll('.local-time').forEach(el => {
        const utc = el.dataset.utc;
        const prefix = el.dataset.prefix || '';
        if (utc) {
            const d = new Date(utc + 'Z'); // Append Z to treat as UTC
            const opts = { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' };
            el.textContent = prefix + d.toLocaleDateString('en-US', opts);
        }
    });

    function copyUrl(url, btn) {
        navigator.clipboard.writeText(url).then(() => {
            btn.classList.add('clicked');
            btn.textContent = '✓';
            setTimeout(() => { btn.textContent = '📋'; btn.classList.remove('clicked'); }, 1500);
            toast('URL copied');
        }).catch(() => {
            // Fallback
            const ta = document.createElement('textarea');
            ta.value = url;
            ta.style.cssText = 'position:fixed;opacity:0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            btn.classList.add('clicked');
            btn.textContent = '✓';
            setTimeout(() => { btn.textContent = '📋'; btn.classList.remove('clicked'); }, 1500);
            toast('URL copied');
        });
    }

    function appleCal(url) {
        const webcal = url.replace('https://', 'webcal://');
        const isApple = /iPhone|iPad|iPod|Macintosh|MacIntel/.test(navigator.userAgent);
        if (!isApple) {
            toast('Apple Calendar works on Apple devices', 3000);
            return;
        }
        window.location.href = webcal;
    }

    function viewLog(id) {
        document.getElementById('log-modal').classList.add('active');
        document.getElementById('log-content').textContent = 'Loading...';
        fetch('get_log.php?id=' + encodeURIComponent(id) + '&lines=50')
            .then(r => r.json())
            .then(d => {
                document.getElementById('log-content').textContent = d.error ? 'Error: ' + d.error : (d.log || d.message || 'No logs yet');
            })
            .catch(e => {
                document.getElementById('log-content').textContent = 'Failed: ' + e.message;
            });
    }

    function closeModal() {
        document.getElementById('log-modal').classList.remove('active');
    }

    document.getElementById('log-modal').addEventListener('click', e => {
        if (e.target.id === 'log-modal') closeModal();
    });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

    function toast(msg, dur = 2500) {
        const t = document.createElement('div');
        t.className = 'toast';
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(() => {
            t.style.animation = 'slideOut 0.2s ease-in forwards';
            setTimeout(() => t.remove(), 200);
        }, dur);
    }
    </script>
</body>
</html>
