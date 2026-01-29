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
            --radius-md: 8px;
            --radius-lg: 12px;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 900px;
            margin: 1.5rem auto;
            padding: 0 1rem;
            line-height: 1.5;
            color: var(--gray-800);
            background: var(--gray-50);
        }

        .header {
            text-align: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--gray-200);
        }
        .header h1 {
            margin: 0 0 0.25rem;
            font-size: 1.75rem;
        }
        .header p {
            margin: 0;
            font-size: 1rem;
            color: var(--gray-500);
        }

        .nav {
            margin-bottom: 1.25rem;
            text-align: center;
        }

        .btn {
            background: var(--primary);
            color: white;
            border: none;
            padding: 8px 16px;
            font-size: 13px;
            font-weight: 500;
            border-radius: var(--radius-md);
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: background-color 0.15s, transform 0.1s;
        }
        .btn:hover { background: var(--primary-dark); }
        .btn:active { transform: scale(0.98); }
        .btn.secondary { background: var(--gray-500); }
        .btn.secondary:hover { background: var(--gray-600); }
        .btn.danger { background: var(--error); }
        .btn.danger:hover { background: #b91c1c; }
        .btn.small { padding: 5px 10px; font-size: 11px; }
        .btn-clicked { background: var(--success) !important; }

        .message {
            margin: 0 0 1rem;
            padding: 0.75rem 1rem;
            border-radius: var(--radius-md);
            font-size: 0.9rem;
        }
        .message.success {
            background: var(--success-bg);
            border: 1px solid #a7f3d0;
            color: #065f46;
        }
        .message.error {
            background: var(--error-bg);
            border: 1px solid #fecaca;
            color: #991b1b;
        }

        .stats-bar {
            background: white;
            padding: 0.75rem 1rem;
            border-radius: var(--radius-lg);
            margin-bottom: 1rem;
            border: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.75rem;
        }
        .stats-bar .stat {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
        }
        .stats-bar .stat strong {
            font-size: 1.25rem;
            color: var(--primary);
        }

        .maintenance-bar {
            background: var(--warning-bg);
            padding: 0.625rem 1rem;
            border-radius: var(--radius-md);
            margin-bottom: 1rem;
            border: 1px solid #fcd34d;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .maintenance-bar .label {
            font-size: 0.85rem;
            color: #92400e;
            font-weight: 500;
        }
        .maintenance-bar .form-inline {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .maintenance-bar input[type="number"] {
            width: 60px;
            padding: 4px 8px;
            border: 1px solid var(--gray-300);
            border-radius: var(--radius-sm);
            font-size: 13px;
        }
        .maintenance-bar span {
            font-size: 0.8rem;
            color: #92400e;
        }

        .calendar-grid {
            display: grid;
            gap: 0.75rem;
        }

        .calendar-card {
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-lg);
            padding: 0.875rem 1rem;
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 0.75rem;
        }
        .calendar-card:hover {
            border-color: var(--gray-300);
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }

        .card-main {
            display: grid;
            grid-template-columns: 140px 1fr;
            gap: 0.75rem 1rem;
            align-items: start;
        }

        .card-station {
            border-right: 1px solid var(--gray-200);
            padding-right: 0.75rem;
        }
        .card-station .name {
            font-weight: 600;
            font-size: 0.95rem;
            color: var(--gray-800);
            margin-bottom: 0.15rem;
        }
        .card-station .id {
            font-family: monospace;
            font-size: 0.75rem;
            color: var(--gray-500);
        }
        .card-station .calendar-id {
            margin-top: 0.5rem;
            padding-top: 0.5rem;
            border-top: 1px solid var(--gray-100);
        }
        .card-station .calendar-id code {
            font-size: 0.7rem;
            color: var(--gray-400);
            background: var(--gray-50);
            padding: 2px 4px;
            border-radius: 3px;
        }
        .card-station .file-warning {
            color: var(--error);
            font-size: 0.7rem;
            margin-top: 0.25rem;
        }

        .card-params {
            display: flex;
            flex-wrap: wrap;
            gap: 0.375rem 0.75rem;
            font-size: 0.8rem;
            color: var(--gray-600);
        }
        .card-params .param {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        .card-params .param-label {
            color: var(--gray-400);
        }
        .card-params .param-value {
            font-weight: 500;
        }
        .card-params .divider {
            color: var(--gray-300);
        }

        .card-dates {
            display: flex;
            gap: 1rem;
            font-size: 0.75rem;
            color: var(--gray-500);
            margin-top: 0.5rem;
            padding-top: 0.5rem;
            border-top: 1px solid var(--gray-100);
        }

        .card-actions {
            display: flex;
            flex-direction: column;
            gap: 0.375rem;
            align-items: flex-end;
        }

        .subscribe-buttons {
            display: flex;
            gap: 4px;
        }
        .btn-icon {
            background: white;
            color: var(--gray-600);
            border: 1px solid var(--gray-200);
            padding: 6px 8px;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-size: 12px;
            transition: all 0.15s;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .btn-icon:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: var(--gray-50);
        }
        .btn-icon.clicked {
            background: var(--success-bg);
            border-color: var(--success);
            color: var(--success);
        }

        .action-buttons {
            display: flex;
            gap: 4px;
        }

        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-lg);
        }
        .empty-state h2 {
            margin: 0 0 0.5rem;
            color: var(--gray-600);
        }
        .empty-state p {
            color: var(--gray-500);
            margin: 0 0 1rem;
        }

        .footer {
            margin-top: 2rem;
            padding-top: 1rem;
            border-top: 1px solid var(--gray-200);
            text-align: center;
            font-size: 0.8rem;
            color: var(--gray-400);
        }

        /* Log modal */
        .log-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .log-modal.active {
            display: flex;
        }
        .log-modal-content {
            background: white;
            border-radius: var(--radius-lg);
            width: 90%;
            max-width: 700px;
            max-height: 80vh;
            display: flex;
            flex-direction: column;
        }
        .log-modal-header {
            padding: 0.875rem 1rem;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .log-modal-header h3 {
            margin: 0;
            font-size: 1rem;
        }
        .log-modal-header .close-btn {
            background: none;
            border: none;
            font-size: 1.25rem;
            cursor: pointer;
            color: var(--gray-400);
            padding: 0;
            line-height: 1;
        }
        .log-modal-header .close-btn:hover {
            color: var(--gray-600);
        }
        .log-modal-body {
            padding: 1rem;
            overflow-y: auto;
            flex: 1;
        }
        .log-content {
            font-family: 'SF Mono', Monaco, 'Courier New', monospace;
            font-size: 0.75rem;
            line-height: 1.5;
            background: var(--gray-50);
            padding: 0.75rem;
            border-radius: var(--radius-sm);
            white-space: pre-wrap;
            word-break: break-all;
            color: var(--gray-700);
            min-height: 100px;
        }

        /* Toast */
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            background: var(--success);
            color: white;
            padding: 10px 16px;
            border-radius: var(--radius-md);
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
            z-index: 1001;
            font-size: 0.85rem;
            max-width: 320px;
            animation: slideIn 0.3s ease-out;
        }
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes slideOut {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }

        @media (max-width: 640px) {
            .calendar-card {
                grid-template-columns: 1fr;
            }
            .card-main {
                grid-template-columns: 1fr;
            }
            .card-station {
                border-right: none;
                border-bottom: 1px solid var(--gray-200);
                padding-right: 0;
                padding-bottom: 0.75rem;
            }
            .card-actions {
                flex-direction: row;
                justify-content: flex-end;
            }
            .stats-bar {
                flex-direction: column;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>TideCal US</h1>
        <p>All Generated Calendars</p>
    </div>

    <div class="nav">
        <a href="index.php" class="btn secondary">&larr; Back to Generator</a>
    </div>

    <?php if ($message): ?>
    <div class="message <?php echo htmlspecialchars($message_type); ?>">
        <?php echo htmlspecialchars($message); ?>
    </div>
    <?php endif; ?>

    <div class="stats-bar">
        <div class="stat">
            <strong><?php echo count($all_calendars); ?></strong>
            <span>calendars created</span>
        </div>
    </div>

    <?php if (!empty($all_calendars)): ?>
    <div class="maintenance-bar">
        <span class="label">Maintenance</span>
        <form method="POST" class="form-inline">
            <input type="hidden" name="action" value="cleanup">
            <span>Delete older than</span>
            <input type="number" name="cleanup_days" value="365" min="1" max="9999">
            <span>days</span>
            <button type="submit" class="btn small danger" onclick="return confirm('Delete calendars older than this?')">
                Clean Up
            </button>
        </form>
    </div>

    <div class="calendar-grid">
        <?php foreach ($all_calendars as $calendar): ?>
        <?php
        $params = $calendar['params'];
        $calendar_url = $calendar_manager->getCalendarUrl($calendar['id'], $config['base_url']);
        $ics_file = $calendar_manager->getCalendarFilePath($calendar['id']);
        $file_exists = file_exists($ics_file);
        $event_count = $file_exists ? Util::countIcsEvents(file_get_contents($ics_file)) : 0;
        ?>
        <div class="calendar-card">
            <div class="card-main">
                <div class="card-station">
                    <div class="name"><?php echo htmlspecialchars($params['station_name'] ?? 'Unknown'); ?></div>
                    <div class="id"><?php echo htmlspecialchars($params['station_id'] ?? ''); ?></div>
                    <div class="calendar-id">
                        <code><?php echo htmlspecialchars($calendar['id']); ?></code>
                    </div>
                    <?php if (!$file_exists): ?>
                    <div class="file-warning">File missing</div>
                    <?php endif; ?>
                </div>
                <div class="card-details">
                    <div class="card-params">
                        <span class="param">
                            <span class="param-label">Year:</span>
                            <span class="param-value"><?php echo htmlspecialchars($params['year'] ?? date('Y')); ?></span>
                        </span>
                        <span class="divider">|</span>
                        <?php if (!empty($params['include_low_tides'])): ?>
                        <span class="param">
                            <span class="param-label">Low:</span>
                            <span class="param-value">&le;<?php echo htmlspecialchars($params['min_low_tide_value'] ?? '0'); ?><?php echo htmlspecialchars($params['unit'] ?? 'ft'); ?></span>
                        </span>
                        <?php endif; ?>
                        <?php if (!empty($params['include_high_tides'])): ?>
                        <span class="param">
                            <span class="param-label">High:</span>
                            <span class="param-value">&ge;<?php echo htmlspecialchars($params['high_tide_min_value'] ?? '0'); ?><?php echo htmlspecialchars($params['unit'] ?? 'ft'); ?></span>
                        </span>
                        <?php endif; ?>
                        <?php if ($event_count > 0): ?>
                        <span class="divider">|</span>
                        <span class="param">
                            <span class="param-value"><?php echo $event_count; ?> events</span>
                        </span>
                        <?php endif; ?>
                        <?php
                        $sun_parts = [];
                        if (!empty($params['include_sunrise_events'])) $sun_parts[] = 'sunrise';
                        if (!empty($params['include_sunset_events'])) $sun_parts[] = 'sunset';
                        if (!empty($sun_parts)): ?>
                        <span class="divider">|</span>
                        <span class="param">
                            <span class="param-label">Sun:</span>
                            <span class="param-value"><?php echo implode('+', $sun_parts); ?></span>
                        </span>
                        <?php endif; ?>
                    </div>
                    <div class="card-dates">
                        <span>Created: <?php echo htmlspecialchars(date('M j, Y g:ia', strtotime($calendar['created_at']))); ?></span>
                        <span>Updated: <?php echo htmlspecialchars(date('M j, Y g:ia', strtotime($calendar['updated_at']))); ?></span>
                    </div>
                </div>
            </div>
            <div class="card-actions">
                <div class="subscribe-buttons">
                    <button class="btn-icon" onclick="copyToClipboard('<?php echo htmlspecialchars($calendar_url); ?>', this)" title="Copy URL">
                        <span class="icon">📋</span> Copy
                    </button>
                    <button class="btn-icon" onclick="addToAppleCalendar('<?php echo htmlspecialchars($calendar_url); ?>')" title="Apple Calendar">
                        <span class="icon">🍎</span>
                    </button>
                    <button class="btn-icon" onclick="downloadCalendar('<?php echo htmlspecialchars($calendar_url); ?>')" title="Download">
                        <span class="icon">💾</span>
                    </button>
                </div>
                <div class="action-buttons">
                    <button class="btn-icon" onclick="viewLog('<?php echo htmlspecialchars($calendar['id']); ?>')" title="View Log">
                        <span class="icon">📋</span> Log
                    </button>
                    <form method="POST" style="display: inline; margin: 0;">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="calendar_id" value="<?php echo htmlspecialchars($calendar['id']); ?>">
                        <button type="submit" class="btn-icon" style="color: var(--error);"
                                onclick="return confirm('Delete this calendar?')" title="Delete">
                            <span class="icon">🗑️</span>
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php else: ?>
    <div class="empty-state">
        <h2>No Calendars Yet</h2>
        <p>No calendars have been created yet.</p>
        <a href="index.php" class="btn">Create Your First Calendar</a>
    </div>
    <?php endif; ?>

    <div class="footer">
        <p>TideCal &middot; Data provided by NOAA Tides &amp; Currents</p>
    </div>

    <!-- Log Modal -->
    <div class="log-modal" id="log-modal">
        <div class="log-modal-content">
            <div class="log-modal-header">
                <h3>Generation Log</h3>
                <button class="close-btn" onclick="closeLogModal()">&times;</button>
            </div>
            <div class="log-modal-body">
                <div class="log-content" id="log-content">Loading...</div>
            </div>
        </div>
    </div>

    <script>
    function copyToClipboard(text, button) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function() {
                if (button) {
                    button.classList.add('clicked');
                    const originalHTML = button.innerHTML;
                    button.innerHTML = '<span class="icon">✅</span> Copied';
                    setTimeout(() => {
                        button.innerHTML = originalHTML;
                        button.classList.remove('clicked');
                    }, 2000);
                }
                showToast('URL copied! In Google Calendar: Settings > Add calendar > From URL');
            }).catch(function(err) {
                fallbackCopyTextToClipboard(text, button);
            });
        } else {
            fallbackCopyTextToClipboard(text, button);
        }
    }

    function fallbackCopyTextToClipboard(text, button) {
        var textArea = document.createElement("textarea");
        textArea.value = text;
        textArea.style.cssText = "position:fixed;top:0;left:0;opacity:0";
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();

        try {
            if (document.execCommand('copy')) {
                if (button) {
                    button.classList.add('clicked');
                    const originalHTML = button.innerHTML;
                    button.innerHTML = '<span class="icon">✅</span> Copied';
                    setTimeout(() => {
                        button.innerHTML = originalHTML;
                        button.classList.remove('clicked');
                    }, 2000);
                }
                showToast('URL copied! In Google Calendar: Settings > Add calendar > From URL');
            }
        } catch (err) {
            console.error('Copy failed:', err);
        }

        document.body.removeChild(textArea);
    }

    function showToast(message, duration = 4000) {
        const toast = document.createElement('div');
        toast.className = 'toast';
        toast.textContent = message;
        document.body.appendChild(toast);

        setTimeout(() => {
            toast.style.animation = 'slideOut 0.3s ease-in';
            setTimeout(() => toast.remove(), 300);
        }, duration);
    }

    function downloadCalendar(url) {
        showToast('Downloading...');
        fetch(url)
            .then(response => response.blob())
            .then(blob => {
                const downloadUrl = window.URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = downloadUrl;
                link.download = 'tide-calendar.ics';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                window.URL.revokeObjectURL(downloadUrl);
            })
            .catch(error => {
                console.error('Download failed:', error);
                window.open(url, '_blank');
            });
    }

    function addToAppleCalendar(url) {
        const webcalUrl = url.replace('https://', 'webcal://');
        const isIOS = /iPhone|iPad|iPod/.test(navigator.userAgent);
        const isMac = /Macintosh|MacIntel/.test(navigator.userAgent);
        const isAndroid = /Android/.test(navigator.userAgent);

        if (isAndroid) {
            showToast('Apple Calendar not available on Android. Use Copy instead.', 5000);
            return;
        }

        if (!isIOS && !isMac) {
            showToast('Apple Calendar works on iPhone, iPad, and Mac.', 5000);
            return;
        }

        showToast('Opening Apple Calendar...');

        if (isIOS) {
            window.location.href = webcalUrl;
        } else {
            const newWindow = window.open(webcalUrl, '_blank');
            if (!newWindow || newWindow.closed) {
                window.location.href = webcalUrl;
            }
        }
    }

    function viewLog(calendarId) {
        const modal = document.getElementById('log-modal');
        const logContent = document.getElementById('log-content');

        modal.classList.add('active');
        logContent.textContent = 'Loading...';

        fetch('get_log.php?id=' + encodeURIComponent(calendarId) + '&lines=50')
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    logContent.textContent = 'Error: ' + data.error;
                } else if (data.log) {
                    logContent.textContent = data.log;
                } else {
                    logContent.textContent = data.message || 'No log entries yet';
                }
            })
            .catch(error => {
                logContent.textContent = 'Failed to load log: ' + error.message;
            });
    }

    function closeLogModal() {
        document.getElementById('log-modal').classList.remove('active');
    }

    // Close modal on backdrop click
    document.getElementById('log-modal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeLogModal();
        }
    });

    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeLogModal();
        }
    });
    </script>
</body>
</html>
