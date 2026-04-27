<?php
// Get or create conversation between two users
function getOrCreateConversation($pdo, $user1_id, $user2_id) {
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
}

// Send a message
function sendMessage($pdo, $sender_id, $receiver_id, $message) {
    $conversation_id = getOrCreateConversation($pdo, $sender_id, $receiver_id);
    
    $stmt = $pdo->prepare("
        INSERT INTO messages (conversation_id, sender_id, receiver_id, message, created_at) 
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$conversation_id, $sender_id, $receiver_id, $message]);
    
    // Update conversation last message
    $stmt = $pdo->prepare("
        UPDATE conversations SET last_message = ?, last_message_time = NOW(), updated_at = NOW() 
        WHERE id = ?
    ");
    $stmt->execute([$message, $conversation_id]);
    
    return $pdo->lastInsertId();
}

// Get all conversations for a user
function getUserConversations($pdo, $user_id) {
    $stmt = $pdo->prepare("
        SELECT c.*, 
               CASE WHEN c.participant1_id = ? THEN c.participant2_id ELSE c.participant1_id END as other_user_id,
               u.username as other_username,
               u.role as other_role,
               u.profile_image as other_profile_image,
               (SELECT COUNT(*) FROM messages WHERE conversation_id = c.id AND receiver_id = ? AND is_read = 0) as unread_count
        FROM conversations c
        JOIN users u ON u.id = (CASE WHEN c.participant1_id = ? THEN c.participant2_id ELSE c.participant1_id END)
        WHERE c.participant1_id = ? OR c.participant2_id = ?
        ORDER BY c.updated_at DESC
    ");
    $stmt->execute([$user_id, $user_id, $user_id, $user_id, $user_id]);
    return $stmt->fetchAll();
}

// Get messages for a conversation
function getConversationMessages($pdo, $conversation_id, $user_id) {
    // Mark messages as read
    $stmt = $pdo->prepare("
        UPDATE messages SET is_read = 1, read_at = NOW() 
        WHERE conversation_id = ? AND receiver_id = ? AND is_read = 0
    ");
    $stmt->execute([$conversation_id, $user_id]);
    
    // Get messages
    $stmt = $pdo->prepare("
        SELECT m.*, u.username as sender_name, u.profile_image as sender_image
        FROM messages m
        JOIN users u ON m.sender_id = u.id
        WHERE m.conversation_id = ?
        ORDER BY m.created_at ASC
    ");
    $stmt->execute([$conversation_id]);
    return $stmt->fetchAll();
}

// Get unread message count
function getUnreadMessageCount($pdo, $user_id) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM messages 
        WHERE receiver_id = ? AND is_read = 0
    ");
    $stmt->execute([$user_id]);
    return $stmt->fetchColumn();
}

// Get user by ID
function getUserById($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetch();
}

// Get all users except current
function getAllUsersForChat($pdo, $current_user_id) {
    $stmt = $pdo->prepare("
        SELECT id, username, email, phone, role, profile_image, 
               (SELECT COUNT(*) FROM messages WHERE sender_id = u.id AND receiver_id = ? AND is_read = 0) as unread_count
        FROM users u
        WHERE id != ?
        ORDER BY 
            CASE role 
                WHEN 'farmer' THEN 1 
                WHEN 'user' THEN 2 
                WHEN 'transport' THEN 3 
                ELSE 4 
            END,
            username ASC
    ");
    $stmt->execute([$current_user_id, $current_user_id]);
    return $stmt->fetchAll();
}
?>