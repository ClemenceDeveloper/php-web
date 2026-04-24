<?php
// Notification Functions for all dashboards

function sendNotification($pdo, $user_id, $title, $message, $type, $link) {
    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, title, message, type, link, created_at) 
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    return $stmt->execute([$user_id, $title, $message, $type, $link]);
}

// Send notification to all buyers
function notifyAllBuyers($pdo, $title, $message, $type, $link) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'user'");
    $stmt->execute();
    $buyers = $stmt->fetchAll();
    
    foreach($buyers as $buyer) {
        sendNotification($pdo, $buyer['id'], $title, $message, $type, $link);
    }
    return count($buyers);
}

// Send notification to all farmers
function notifyAllFarmers($pdo, $title, $message, $type, $link) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'farmer'");
    $stmt->execute();
    $farmers = $stmt->fetchAll();
    
    foreach($farmers as $farmer) {
        sendNotification($pdo, $farmer['id'], $title, $message, $type, $link);
    }
    return count($farmers);
}

// Send notification to all transporters
function notifyAllTransporters($pdo, $title, $message, $type, $link) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'transport'");
    $stmt->execute();
    $transporters = $stmt->fetchAll();
    
    foreach($transporters as $transporter) {
        sendNotification($pdo, $transporter['id'], $title, $message, $type, $link);
    }
    return count($transporters);
}

// Send notification to specific user
function notifyUser($pdo, $user_id, $title, $message, $type, $link) {
    return sendNotification($pdo, $user_id, $title, $message, $type, $link);
}

// Get unread notification count
function getUnreadCount($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$user_id]);
    return $stmt->fetchColumn();
}

// Get recent notifications - FIXED VERSION
function getRecentNotifications($pdo, $user_id, $limit = 10) {
    // Convert limit to integer to prevent SQL injection
    $limit = (int)$limit;
    
    // Use direct integer in query instead of placeholder for LIMIT
    $stmt = $pdo->prepare("
        SELECT * FROM notifications 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        LIMIT $limit
    ");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

// Mark notification as read
function markAsRead($pdo, $notification_id, $user_id) {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    return $stmt->execute([$notification_id, $user_id]);
}

// Mark all notifications as read
function markAllAsRead($pdo, $user_id) {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    return $stmt->execute([$user_id]);
}

// Delete old notifications (older than 30 days)
function deleteOldNotifications($pdo) {
    $stmt = $pdo->prepare("DELETE FROM notifications WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    return $stmt->execute();
}

// Get notification by ID
function getNotificationById($pdo, $notification_id, $user_id) {
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE id = ? AND user_id = ?");
    $stmt->execute([$notification_id, $user_id]);
    return $stmt->fetch();
}

// Get all notifications with pagination
function getAllNotifications($pdo, $user_id, $offset = 0, $limit = 20) {
    $limit = (int)$limit;
    $offset = (int)$offset;
    
    $stmt = $pdo->prepare("
        SELECT * FROM notifications 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

// Get total notifications count
function getTotalNotificationsCount($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetchColumn();
}
?>