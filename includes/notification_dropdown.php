<?php
// Get notification counts
$unread_count = getUnreadCount($pdo, $_SESSION['user_id']);
$notifications = getRecentNotifications($pdo, $_SESSION['user_id'], 10);
$total_notifications = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ?");
$total_notifications->execute([$_SESSION['user_id']]);
$total_count = $total_notifications->fetchColumn();
?>
<style>
.notification-icon {
    position: relative;
    cursor: pointer;
    transition: transform 0.3s;
}
.notification-icon:hover {
    transform: scale(1.1);
}
.notification-badge {
    position: absolute;
    top: -8px;
    right: -8px;
    background: #f44336;
    color: white;
    border-radius: 50%;
    padding: 2px 6px;
    font-size: 10px;
    min-width: 18px;
    text-align: center;
    animation: pulse 1.5s infinite;
    font-weight: bold;
}
@keyframes pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.2); }
}
.notification-dropdown {
    position: absolute;
    top: 55px;
    right: 20px;
    width: 380px;
    background: white;
    border-radius: 12px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
    z-index: 1000;
    display: none;
    max-height: 500px;
    overflow-y: auto;
    border: 1px solid #e0e0e0;
}
.notification-dropdown.show {
    display: block;
    animation: slideDown 0.3s ease;
}
@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}
.notification-header {
    padding: 15px 20px;
    border-bottom: 1px solid #e0e0e0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: linear-gradient(135deg, <?php echo $_SESSION['role'] == 'admin' ? '#1a1a2e' : ($_SESSION['role'] == 'farmer' ? '#2e7d32' : ($_SESSION['role'] == 'user' ? '#1976d2' : '#ef6c00')); ?>, <?php echo $_SESSION['role'] == 'admin' ? '#16213e' : ($_SESSION['role'] == 'farmer' ? '#1b5e20' : ($_SESSION['role'] == 'user' ? '#1565c0' : '#e65100')); ?>);
    border-radius: 12px 12px 0 0;
    color: white;
    position: sticky;
    top: 0;
    z-index: 10;
}
.notification-header strong {
    font-size: 1rem;
}
.mark-all-btn {
    font-size: 0.75rem;
    color: #ffd700;
    text-decoration: none;
    transition: opacity 0.3s;
}
.mark-all-btn:hover {
    opacity: 0.8;
}
.notification-list {
    max-height: 420px;
    overflow-y: auto;
}
.notification-item {
    padding: 15px 20px;
    border-bottom: 1px solid #f0f0f0;
    transition: background 0.3s;
    text-decoration: none;
    display: block;
    cursor: pointer;
}
.notification-item:hover {
    background: #f9f9f9;
}
.notification-item.unread {
    background: #e3f2fd;
    border-left: 3px solid #2196f3;
}
.notification-title {
    font-weight: bold;
    margin-bottom: 5px;
    color: #333;
    font-size: 0.95rem;
}
.notification-message {
    font-size: 0.85rem;
    color: #666;
    margin-bottom: 5px;
    line-height: 1.4;
}
.notification-time {
    font-size: 0.7rem;
    color: #999;
    display: flex;
    align-items: center;
    gap: 5px;
}
.mark-read {
    float: right;
    font-size: 0.7rem;
    color: #2196f3;
    text-decoration: none;
    padding: 2px 8px;
    border-radius: 10px;
    background: #e3f2fd;
}
.mark-read:hover {
    background: #bbdef5;
}
.empty-notifications {
    text-align: center;
    padding: 50px 20px;
    color: #999;
}
.empty-notifications i {
    font-size: 3rem;
    margin-bottom: 15px;
    color: #ccc;
}
.notification-footer {
    padding: 10px 20px;
    border-top: 1px solid #e0e0e0;
    text-align: center;
    background: #f8f9fa;
    border-radius: 0 0 12px 12px;
}
.view-all-link {
    text-decoration: none;
    color: #2e7d32;
    font-size: 0.85rem;
    font-weight: 600;
}
.notification-type-product { border-left-color: #4caf50 !important; }
.notification-type-order { border-left-color: #ff9800 !important; }
.notification-type-payment { border-left-color: #2196f3 !important; }
.notification-type-delivery { border-left-color: #9c27b0 !important; }
.notification-type-wallet { border-left-color: #ff9800 !important; }
.notification-type-withdrawal { border-left-color: #f44336 !important; }
</style>

<div class="notification-icon" onclick="toggleNotifications()">
    <i class="fas fa-bell"></i>
    <?php if($unread_count > 0): ?>
        <span class="notification-badge"><?php echo $unread_count; ?></span>
    <?php endif; ?>
</div>

<div class="notification-dropdown" id="notificationDropdown">
    <div class="notification-header">
        <strong><i class="fas fa-bell"></i> Notifications</strong>
        <?php if($unread_count > 0): ?>
            <a href="#" class="mark-all-btn" onclick="markAllNotificationsRead(); return false;">
                Mark all as read
            </a>
        <?php endif; ?>
    </div>
    
    <div class="notification-list">
        <?php if(count($notifications) > 0): ?>
            <?php foreach($notifications as $notif): ?>
                <a href="<?php echo $notif['link']; ?>" class="notification-item <?php echo $notif['is_read'] ? '' : 'unread'; ?> notification-type-<?php echo $notif['type']; ?>" data-id="<?php echo $notif['id']; ?>">
                    <div class="notification-title">
                        <?php echo $notif['title']; ?>
                        <?php if(!$notif['is_read']): ?>
                            <span class="mark-read" onclick="event.stopPropagation(); markAsRead(<?php echo $notif['id']; ?>); return false;">Mark read</span>
                        <?php endif; ?>
                    </div>
                    <div class="notification-message"><?php echo $notif['message']; ?></div>
                    <div class="notification-time">
                        <i class="fas fa-clock"></i>
                        <?php 
                        $time = strtotime($notif['created_at']);
                        $now = time();
                        $diff = $now - $time;
                        if($diff < 60) {
                            echo "Just now";
                        } elseif($diff < 3600) {
                            echo floor($diff/60) . " minutes ago";
                        } elseif($diff < 86400) {
                            echo floor($diff/3600) . " hours ago";
                        } else {
                            echo date('M d, H:i', $time);
                        }
                        ?>
                    </div>
                </a>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-notifications">
                <i class="fas fa-bell-slash"></i>
                <p>No notifications yet</p>
                <small>When you receive notifications, they'll appear here</small>
            </div>
        <?php endif; ?>
    </div>
    
    <?php if($total_count > 10): ?>
    <div class="notification-footer">
        <a href="#" class="view-all-link" onclick="viewAllNotifications(); return false;">
            View all notifications <i class="fas fa-arrow-right"></i>
        </a>
    </div>
    <?php endif; ?>
</div>

<script>
function toggleNotifications() {
    const dropdown = document.getElementById('notificationDropdown');
    dropdown.classList.toggle('show');
}

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    const dropdown = document.getElementById('notificationDropdown');
    const icon = document.querySelector('.notification-icon');
    if (icon && dropdown && !icon.contains(event.target) && !dropdown.contains(event.target)) {
        dropdown.classList.remove('show');
    }
});

function markAsRead(notifId) {
    fetch('ajax/mark_notification_read.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'notification_id=' + notifId
    })
    .then(response => response.json())
    .then(data => {
        if(data.success) {
            location.reload();
        }
    });
}

function markAllNotificationsRead() {
    if(confirm('Mark all notifications as read?')) {
        fetch('ajax/mark_all_read.php', {
            method: 'POST'
        })
        .then(response => response.json())
        .then(data => {
            if(data.success) {
                location.reload();
            }
        });
    }
}

function viewAllNotifications() {
    window.location.href = 'notifications.php';
}

// Auto-refresh notifications every 30 seconds
setInterval(function() {
    fetch('ajax/get_notification_count.php')
        .then(response => response.json())
        .then(data => {
            const badge = document.querySelector('.notification-badge');
            if(data.count > 0) {
                if(badge) {
                    badge.textContent = data.count;
                } else {
                    const icon = document.querySelector('.notification-icon');
                    const newBadge = document.createElement('span');
                    newBadge.className = 'notification-badge';
                    newBadge.textContent = data.count;
                    icon.appendChild(newBadge);
                }
            } else if(badge) {
                badge.remove();
            }
        })
        .catch(error => console.log('Error:', error));
}, 30000);
</script>