<?php
// Get unread notification count
function getUnreadCount($pdo, $user_id) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$user_id]);
        return $stmt->fetchColumn();
    } catch(PDOException $e) {
        return 0;
    }
}

// Get recent notifications
function getRecentNotifications($pdo, $user_id, $limit = 10) {
    try {
        $limit = (int)$limit;
        $stmt = $pdo->prepare("
            SELECT * FROM notifications 
            WHERE user_id = ? 
            ORDER BY created_at DESC 
            LIMIT $limit
        ");
        $stmt->execute([$user_id]);
        return $stmt->fetchAll();
    } catch(PDOException $e) {
        return [];
    }
}

// Send notification
function sendNotification($pdo, $user_id, $title, $message, $type, $link) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, title, message, type, link) 
            VALUES (?, ?, ?, ?, ?)
        ");
        return $stmt->execute([$user_id, $title, $message, $type, $link]);
    } catch(PDOException $e) {
        return false;
    }
}

// Mark notification as read
function markNotificationRead($pdo, $notification_id, $user_id) {
    try {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        return $stmt->execute([$notification_id, $user_id]);
    } catch(PDOException $e) {
        return false;
    }
}

// Mark all notifications as read
function markAllNotificationsRead($pdo, $user_id) {
    try {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        return $stmt->execute([$user_id]);
    } catch(PDOException $e) {
        return false;
    }
}

// Get unread message count (with error handling)
function getUnreadMessageCount($pdo, $user_id) {
    try {
        // Check if messages table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'messages'");
        if($stmt->rowCount() == 0) {
            return 0;
        }
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
        $stmt->execute([$user_id]);
        return $stmt->fetchColumn();
    } catch(PDOException $e) {
        return 0;
    }
}

// Get or create conversation
function getOrCreateConversation($pdo, $user1_id, $user2_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT id FROM conversations 
            WHERE (participant1_id = ? AND participant2_id = ?) 
            OR (participant1_id = ? AND participant2_id = ?)
        ");
        $stmt->execute([$user1_id, $user2_id, $user2_id, $user1_id]);
        $conv = $stmt->fetch();
        
        if($conv) {
            return $conv['id'];
        } else {
            $stmt = $pdo->prepare("INSERT INTO conversations (participant1_id, participant2_id) VALUES (?, ?)");
            $stmt->execute([$user1_id, $user2_id]);
            return $pdo->lastInsertId();
        }
    } catch(PDOException $e) {
        return 0;
    }
}

// Send message
function sendMessage($pdo, $sender_id, $receiver_id, $message) {
    try {
        $conv_id = getOrCreateConversation($pdo, $sender_id, $receiver_id);
        
        $stmt = $pdo->prepare("
            INSERT INTO messages (conversation_id, sender_id, receiver_id, message) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$conv_id, $sender_id, $receiver_id, $message]);
        
        // Update conversation last message
        $stmt = $pdo->prepare("
            UPDATE conversations SET last_message = ?, last_message_time = NOW() WHERE id = ?
        ");
        $stmt->execute([$message, $conv_id]);
        
        // Send notification to receiver
        $sender_name = getUserName($pdo, $sender_id);
        sendNotification($pdo, $receiver_id, "New Message from $sender_name", $message, "message", "messages.php");
        
        return true;
    } catch(PDOException $e) {
        return false;
    }
}

// Get user conversations
function getUserConversations($pdo, $user_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT c.*, 
                   CASE WHEN c.participant1_id = ? THEN c.participant2_id ELSE c.participant1_id END as other_user_id,
                   u.username as other_username,
                   u.role as other_role,
                   (SELECT COUNT(*) FROM messages WHERE conversation_id = c.id AND receiver_id = ? AND is_read = 0) as unread_count
            FROM conversations c
            JOIN users u ON u.id = (CASE WHEN c.participant1_id = ? THEN c.participant2_id ELSE c.participant1_id END)
            WHERE c.participant1_id = ? OR c.participant2_id = ?
            ORDER BY c.updated_at DESC
        ");
        $stmt->execute([$user_id, $user_id, $user_id, $user_id, $user_id]);
        return $stmt->fetchAll();
    } catch(PDOException $e) {
        return [];
    }
}

// Get conversation messages
function getConversationMessages($pdo, $conversation_id, $user_id) {
    try {
        // Mark messages as read
        $stmt = $pdo->prepare("
            UPDATE messages SET is_read = 1, read_at = NOW() 
            WHERE conversation_id = ? AND receiver_id = ? AND is_read = 0
        ");
        $stmt->execute([$conversation_id, $user_id]);
        
        // Get messages
        $stmt = $pdo->prepare("
            SELECT m.*, u.username as sender_name 
            FROM messages m
            JOIN users u ON m.sender_id = u.id
            WHERE m.conversation_id = ?
            ORDER BY m.created_at ASC
        ");
        $stmt->execute([$conversation_id]);
        return $stmt->fetchAll();
    } catch(PDOException $e) {
        return [];
    }
}

// Get user name by ID
function getUserName($pdo, $user_id) {
    try {
        $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        return $user ? $user['username'] : 'Unknown';
    } catch(PDOException $e) {
        return 'Unknown';
    }
}

// Get all users except current
function getAllUsers($pdo, $current_user_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT id, username, email, phone, role FROM users WHERE id != ? ORDER BY role, username
        ");
        $stmt->execute([$current_user_id]);
        return $stmt->fetchAll();
    } catch(PDOException $e) {
        return [];
    }
}
?>