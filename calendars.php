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
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
            max-width: 1200px; 
            margin: 2rem auto; 
            padding: 0 1rem; 
            line-height: 1.6; 
            color: #333;
        }
        .header { 
            text-align: center; 
            margin-bottom: 2rem; 
            padding-bottom: 1rem; 
            border-bottom: 2px solid #e0e0e0;
        }
        .nav { 
            margin-bottom: 2rem; 
            text-align: center;
        }
        .btn { 
            background: #007cba; 
            color: white; 
            border: none; 
            padding: 10px 20px; 
            font-size: 14px; 
            border-radius: 6px; 
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            margin: 0 5px;
            transition: background-color 0.2s;
        }
        .btn:hover { 
            background: #005a87;
        }
        .btn.secondary { 
            background: #6c757d;
        }
        .btn.secondary:hover { 
            background: #545b62;
        }
        .btn-clicked {
            background: #27ae60 !important;
            transform: scale(0.95);
        }
        .btn.danger { 
            background: #dc3545;
        }
        .btn.danger:hover { 
            background: #c82333;
        }
        .btn.small {
            padding: 5px 10px;
            font-size: 12px;
        }
        .message { 
            margin: 1rem 0; 
            padding: 1rem; 
            border-radius: 8px;
        }
        .message.success { 
            background: #d4edda; 
            border: 1px solid #c3e6cb; 
            color: #155724;
        }
        .message.error { 
            background: #f8d7da; 
            border: 1px solid #f5c6cb; 
            color: #721c24;
        }
        .stats { 
            background: #f8f9fa; 
            padding: 1rem; 
            border-radius: 8px; 
            margin-bottom: 2rem;
            text-align: center;
        }
        .calendar-table { 
            width: 100%; 
            border-collapse: collapse; 
            margin-top: 1rem;
        }
        .calendar-table th, 
        .calendar-table td { 
            border: 1px solid #ddd; 
            padding: 12px; 
            text-align: left;
        }
        .calendar-table th { 
            background: #f8f9fa; 
            font-weight: bold;
        }
        .calendar-table tr:nth-child(even) { 
            background: #f8f9fa;
        }
        .calendar-table tr:hover { 
            background: #e9ecef;
        }
        .url-cell { 
            font-family: monospace; 
            font-size: 12px; 
            max-width: 200px; 
            word-break: break-all;
        }
        .params-cell { 
            font-size: 12px; 
            max-width: 250px;
        }
        .param-row { 
            margin: 2px 0;
        }
        .cleanup-section { 
            background: #fff3cd; 
            border: 1px solid #ffeaa7; 
            padding: 1rem; 
            border-radius: 8px; 
            margin-bottom: 2rem;
        }
        .cleanup-section h3 { 
            margin-top: 0; 
            color: #856404;
        }
        .form-inline { 
            display: inline-flex; 
            align-items: center; 
            gap: 10px;
        }
        .form-inline input { 
            padding: 5px; 
            border: 1px solid #ddd; 
            border-radius: 4px; 
            width: 80px;
        }
        .empty-state { 
            text-align: center; 
            padding: 3rem; 
            color: #666;
        }
        .copy-btn {
            margin-left: 5px;
            padding: 2px 6px;
            font-size: 10px;
        }
        .btn-outline {
            background: white;
            color: #333;
            border: 1px solid #ddd;
            padding: 6px 4px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 9px;
            text-align: center;
            transition: all 0.2s;
            min-height: 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }
        .btn-outline:hover {
            border-color: #007cba;
            background: #f8f9fa;
            transform: translateY(-1px);
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
        }
        .subscribe-cell {
            min-width: 180px;
        }
        @media (max-width: 768px) {
            .calendar-table { 
                font-size: 12px;
            }
            .calendar-table th, 
            .calendar-table td { 
                padding: 6px;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🌊 TideCal - All Calendars</h1>
    </div>

    <div class="nav">
        <a href="index.php" class="btn">← Back to Main</a>
    </div>

    <?php if ($message): ?>
    <div class="message <?php echo htmlspecialchars($message_type); ?>">
        <?php echo htmlspecialchars($message); ?>
    </div>
    <?php endif; ?>

    <div class="stats">
        <h3>📊 Calendar Statistics</h3>
        <p><strong><?php echo count($all_calendars); ?></strong> calendars created</p>
    </div>

    <?php if (!empty($all_calendars)): ?>
    <div class="cleanup-section">
        <h3>🧹 Maintenance</h3>
        <form method="POST" style="display: inline;">
            <div class="form-inline">
                <input type="hidden" name="action" value="cleanup">
                <label>Delete calendars older than:</label>
                <input type="number" name="cleanup_days" value="365" min="1" max="9999">
                <span>days</span>
                <button type="submit" class="btn small danger" onclick="return confirm('Are you sure you want to delete old calendars?')">
                    Clean Up
                </button>
            </div>
        </form>
    </div>

    <h2>📅 All Calendars</h2>
    
    <table class="calendar-table">
        <thead>
            <tr>
                <th>Calendar ID</th>
                <th>Station</th>
                <th>Parameters</th>
                <th>Created</th>
                <th>Updated</th>
                <th>Subscribe</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($all_calendars as $calendar): ?>
            <?php 
            $params = $calendar['params'];
            $calendar_url = $calendar_manager->getCalendarUrl($calendar['id'], $config['base_url']);
            $ics_file = $calendar_manager->getCalendarFilePath($calendar['id']);
            $file_exists = file_exists($ics_file);
            $event_count = $file_exists ? Util::countIcsEvents(file_get_contents($ics_file)) : 0;
            ?>
            <tr>
                <td>
                    <code><?php echo htmlspecialchars($calendar['id']); ?></code>
                    <?php if (!$file_exists): ?>
                    <br><small style="color: #dc3545;">⚠️ File missing</small>
                    <?php endif; ?>
                </td>
                <td>
                    <?php echo htmlspecialchars($params['station_name'] ?? 'Unknown'); ?><br>
                    <small><?php echo htmlspecialchars($params['station_id'] ?? ''); ?></small>
                </td>
                <td class="params-cell">
                    <div class="param-row"><strong>Year:</strong> <?php echo htmlspecialchars($params['year'] ?? 'current'); ?></div>
                    <div class="param-row"><strong>Low tides:</strong> <?php echo !empty($params['include_low_tides']) ? 'On' : 'Off'; ?></div>
                    <?php if (!empty($params['include_low_tides'])): ?>
                    <div class="param-row"><strong>Low max:</strong> ≤ <?php echo htmlspecialchars($params['min_low_tide_value'] ?? ''); ?> <?php echo htmlspecialchars($params['unit'] ?? 'ft'); ?></div>
                    <div class="param-row"><strong>Low time:</strong> <?php echo htmlspecialchars($params['low_time_filter'] ?? 'none'); ?></div>
                    <?php if (!empty($params['low_earliest_time_enabled'])): ?>
                    <div class="param-row"><strong>Low earliest:</strong> <?php echo htmlspecialchars($params['low_earliest_time'] ?? ''); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($params['low_latest_time_enabled'])): ?>
                    <div class="param-row"><strong>Low latest:</strong> <?php echo htmlspecialchars($params['low_latest_time'] ?? ''); ?></div>
                    <?php endif; ?>
                    <?php endif; ?>
                    <div class="param-row"><strong>High tides:</strong> <?php echo !empty($params['include_high_tides']) ? 'On' : 'Off'; ?></div>
                    <?php if (!empty($params['include_high_tides'])): ?>
                    <div class="param-row"><strong>High min:</strong> ≥ <?php echo htmlspecialchars($params['high_tide_min_value'] ?? ''); ?> <?php echo htmlspecialchars($params['unit'] ?? 'ft'); ?></div>
                    <div class="param-row"><strong>High time:</strong> <?php echo htmlspecialchars($params['high_time_filter'] ?? 'none'); ?></div>
                    <?php if (!empty($params['high_earliest_time_enabled'])): ?>
                    <div class="param-row"><strong>High earliest:</strong> <?php echo htmlspecialchars($params['high_earliest_time'] ?? ''); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($params['high_latest_time_enabled'])): ?>
                    <div class="param-row"><strong>High latest:</strong> <?php echo htmlspecialchars($params['high_latest_time'] ?? ''); ?></div>
                    <?php endif; ?>
                    <?php endif; ?>
                    <div class="param-row"><strong>Sun events:</strong>
                        <?php
                        $sun_parts = [];
                        if (!empty($params['include_sunrise_events'])) $sun_parts[] = 'sunrise';
                        if (!empty($params['include_sunset_events'])) $sun_parts[] = 'sunset';
                        echo !empty($sun_parts) ? htmlspecialchars(implode(', ', $sun_parts)) : 'off';
                        ?>
                    </div>
                    <?php if (!empty($params['include_sunrise_events']) || !empty($params['include_sunset_events'])): ?>
                    <div class="param-row"><strong>Sun scope:</strong> <?php echo !empty($params['sun_events_match_tide_days']) ? 'tide days' : 'all days'; ?></div>
                    <?php endif; ?>
                    <?php if ($event_count > 0): ?>
                    <div class="param-row"><strong>Events:</strong> <?php echo $event_count; ?> total</div>
                    <?php endif; ?>
                </td>
                <td>
                    <?php echo htmlspecialchars(date('M j, Y', strtotime($calendar['created_at']))); ?><br>
                    <small><?php echo htmlspecialchars(date('g:i A', strtotime($calendar['created_at']))); ?></small>
                </td>
                <td>
                    <?php echo htmlspecialchars(date('M j, Y', strtotime($calendar['updated_at']))); ?><br>
                    <small><?php echo htmlspecialchars(date('g:i A', strtotime($calendar['updated_at']))); ?></small>
                </td>
                <td class="subscribe-cell">
                    <div class="mini-calendar-buttons" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 4px;">
                        <button class="btn-outline" onclick="copyToClipboard('<?php echo htmlspecialchars($calendar_url); ?>', this)" title="Copy URL">
                            📋<br><small>Copy</small>
                        </button>
                        <button class="btn-outline" onclick="addToAppleCalendar('<?php echo htmlspecialchars($calendar_url); ?>')" title="Add to Apple/iOS Calendar">
                            🍎<br><small>Apple</small>
                        </button>
                        <button class="btn-outline" onclick="downloadCalendar('<?php echo htmlspecialchars($calendar_url); ?>')" title="Download ICS">
                            💾<br><small>Save</small>
                        </button>
                    </div>
                </td>
                <td>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="calendar_id" value="<?php echo htmlspecialchars($calendar['id']); ?>">
                        <button type="submit" class="btn small danger" 
                                onclick="return confirm('Delete calendar <?php echo htmlspecialchars($calendar['id']); ?>?')">
                            🗑️ Delete
                        </button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php else: ?>
    <div class="empty-state">
        <h2>📭 No Calendars Yet</h2>
        <p>No calendars have been created yet.</p>
        <a href="index.php" class="btn">Create Your First Calendar</a>
    </div>
    <?php endif; ?>

    <div style="margin-top: 3rem; padding-top: 2rem; border-top: 1px solid #e0e0e0; text-align: center; font-size: 0.9em; color: #666;">
        <p>TideCal - Calendar Management</p>
        <p>Data provided by NOAA Tides & Currents</p>
    </div>

    <script>
    function copyToClipboard(text, button) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function() {
                // Show temporary feedback
                if (button) {
                    button.classList.add('btn-clicked');
                    button.innerHTML = '✅<br><small>Copied!</small>';
                    setTimeout(() => {
                        button.innerHTML = '📋<br><small>Copy</small>';
                        button.classList.remove('btn-clicked');
                    }, 2000);
                }
                showToast('Calendar URL copied! For Google Calendar: go to Settings > Add calendar > From URL, then paste. For other apps: look for "Add calendar" or "Subscribe" options.', 15000);
            }).catch(function(err) {
                console.error('Failed to copy: ', err);
                fallbackCopyTextToClipboard(text, button);
            });
        } else {
            fallbackCopyTextToClipboard(text, button);
        }
    }

    function fallbackCopyTextToClipboard(text, button) {
        var textArea = document.createElement("textarea");
        textArea.value = text;
        textArea.style.top = "0";
        textArea.style.left = "0";
        textArea.style.position = "fixed";
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        
        try {
            var successful = document.execCommand('copy');
            if (successful) {
                if (button) {
                    button.classList.add('btn-clicked');
                    button.innerHTML = '✅<br><small>Copied!</small>';
                    setTimeout(() => {
                        button.innerHTML = '📋<br><small>Copy</small>';
                        button.classList.remove('btn-clicked');
                    }, 2000);
                }
                showToast('Calendar URL copied! For Google Calendar: go to Settings > Add calendar > From URL, then paste. For other apps: look for "Add calendar" or "Subscribe" options.', 15000);
            }
        } catch (err) {
            console.error('Fallback: Failed to copy', err);
        }
        
        document.body.removeChild(textArea);
    }
    
    function showToast(message, duration = 3000) {
        const toast = document.createElement('div');
        toast.textContent = message;
        toast.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: #27ae60;
            color: white;
            padding: 12px 20px;
            border-radius: 6px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            z-index: 1000;
            font-weight: 500;
            animation: slideIn 0.3s ease-out;
            max-width: 350px;
            line-height: 1.4;
        `;
        
        if (!document.querySelector('#toast-styles')) {
            const style = document.createElement('style');
            style.id = 'toast-styles';
            style.textContent = `
                @keyframes slideIn {
                    from { transform: translateX(100%); opacity: 0; }
                    to { transform: translateX(0); opacity: 1; }
                }
                @keyframes slideOut {
                    from { transform: translateX(0); opacity: 1; }
                    to { transform: translateX(100%); opacity: 0; }
                }
            `;
            document.head.appendChild(style);
        }
        
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.style.animation = 'slideOut 0.3s ease-in';
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 300);
        }, duration);  // Now actually using the duration parameter!
    }
    
    // Download calendar file with proper filename
    function downloadCalendar(url) {
        showToast('Downloading calendar file...');
        
        fetch(url)
            .then(response => response.blob())
            .then(blob => {
                const downloadUrl = window.URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = downloadUrl;
                link.download = 'tide-calendar.ics'; // Force proper filename
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                window.URL.revokeObjectURL(downloadUrl);
            })
            .catch(error => {
                console.error('Download failed:', error);
                // Fallback to direct link
                window.open(url, '_blank');
            });
    }
    
    // Apple Calendar integration (iOS/macOS native webcal support)
    function addToAppleCalendar(url) {
        console.log('Adding to Apple Calendar:', url);
        
        const webcalUrl = url.replace('https://', 'webcal://');
        console.log('Using webcal URL:', webcalUrl);
        
        const isIOS = /iPhone|iPad|iPod/.test(navigator.userAgent);
        const isMac = /Macintosh|MacIntel|MacPPC|Mac68K/.test(navigator.userAgent);
        const isAndroid = /Android/.test(navigator.userAgent);
        
        if (isAndroid) {
            // Android doesn't support Apple Calendar - show helpful message
            showToast('Apple Calendar is not available on Android. Use Copy button to manually add the calendar to your preferred calendar app.', 10000);
            return;
        }
        
        if (!isIOS && !isMac) {
            // Non-Apple devices - show helpful message
            showToast('Apple Calendar works on iPhone, iPad, and Mac. Use Copy button to manually add the calendar to your calendar app.', 10000);
            return;
        }
        
        showToast('Opening Apple Calendar. If it doesn\'t work, use the Copy button to manually add the calendar.', 15000);
        
        if (isIOS) {
            // On iOS, use window.location.href - this is the recommended approach per Apple docs
            window.location.href = webcalUrl;
        } else {
            // On macOS, try new window first to avoid navigation, fallback to same window
            const newWindow = window.open(webcalUrl, '_blank');
            if (!newWindow || newWindow.closed || typeof newWindow.closed === 'undefined') {
                window.location.href = webcalUrl;
            }
        }
    }
    
    
    </script>
</body>
</html>
