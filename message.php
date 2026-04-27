<?php
require_once 'config/db.php';
require_once 'includes/notification_functions.php';

if(!isset($_SESSION['user_id'])) {
    header("Location: auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Messaging functions (built-in)
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

function sendMessage($pdo, $sender_id, $receiver_id, $message) {
    $conv_id = getOrCreateConversation($pdo, $sender_id, $receiver_id);
    
    $stmt = $pdo->prepare("
        INSERT INTO messages (conversation_id, sender_id, receiver_id, message, created_at) 
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$conv_id, $sender_id, $receiver_id, $message]);
    
    $stmt = $pdo->prepare("
        UPDATE conversations SET last_message = ?, last_message_time = NOW(), updated_at = NOW() 
        WHERE id = ?
    ");
    $stmt->execute([$message, $conv_id]);
    
    return $pdo->lastInsertId();
}

function getUserConversations($pdo, $user_id) {
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
}

function getConversationMessages($pdo, $conversation_id, $user_id) {
    $stmt = $pdo->prepare("
        UPDATE messages SET is_read = 1, read_at = NOW() 
        WHERE conversation_id = ? AND receiver_id = ? AND is_read = 0
    ");
    $stmt->execute([$conversation_id, $user_id]);
    
    $stmt = $pdo->prepare("
        SELECT m.*, u.username as sender_name 
        FROM messages m
        JOIN users u ON m.sender_id = u.id
        WHERE m.conversation_id = ?
        ORDER BY m.created_at ASC
    ");
    $stmt->execute([$conversation_id]);
    return $stmt->fetchAll();
}

function getUnreadMessageCount($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
    $stmt->execute([$user_id]);
    return $stmt->fetchColumn();
}

function getAllUsersForChat($pdo, $current_user_id) {
    $stmt = $pdo->prepare("
        SELECT id, username, email, phone, role 
        FROM users 
        WHERE id != ?
        ORDER BY role, username ASC
    ");
    $stmt->execute([$current_user_id]);
    return $stmt->fetchAll();
}

function getUserById($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetch();
}

// Handle AJAX requests
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');
    
    if(isset($_POST['action'])) {
        if($_POST['action'] == 'send_message') {
            $receiver_id = $_POST['receiver_id'];
            $message = trim($_POST['message']);
            if(!empty($message)) {
                $message_id = sendMessage($pdo, $user_id, $receiver_id, $message);
                echo json_encode(['success' => true, 'message_id' => $message_id]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Message cannot be empty']);
            }
            exit();
        }
        
        if($_POST['action'] == 'get_messages') {
            $conversation_id = $_POST['conversation_id'];
            $last_id = isset($_POST['last_id']) ? (int)$_POST['last_id'] : 0;
            $messages = getConversationMessages($pdo, $conversation_id, $user_id);
            $new_messages = array_filter($messages, function($msg) use ($last_id) {
                return $msg['id'] > $last_id;
            });
            echo json_encode(['success' => true, 'messages' => array_values($new_messages)]);
            exit();
        }
        
        if($_POST['action'] == 'mark_read') {
            $conversation_id = $_POST['conversation_id'];
            $stmt = $pdo->prepare("
                UPDATE messages SET is_read = 1, read_at = NOW() 
                WHERE conversation_id = ? AND receiver_id = ? AND is_read = 0
            ");
            $stmt->execute([$conversation_id, $user_id]);
            echo json_encode(['success' => true]);
            exit();
        }
    }
}

$conversations = getUserConversations($pdo, $user_id);
$unread_count = getUnreadMessageCount($pdo, $user_id);
$selected_conversation = isset($_GET['conversation']) ? (int)$_GET['conversation'] : 0;
$new_user = isset($_GET['new']) ? (int)$_GET['new'] : 0;
$messages = [];

if($selected_conversation) {
    $messages = getConversationMessages($pdo, $selected_conversation, $user_id);
    foreach($conversations as $conv) {
        if($conv['id'] == $selected_conversation) {
            $other_user = getUserById($pdo, $conv['other_user_id']);
            break;
        }
    }
}

if($new_user && $new_user != $user_id) {
    $conv_id = getOrCreateConversation($pdo, $user_id, $new_user);
    header("Location: messages.php?conversation=" . $conv_id);
    exit();
}

$all_users = getAllUsersForChat($pdo, $user_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages - Agriculture Marketplace</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
            height: 100vh;
            overflow: hidden;
        }

        /* Navigation */
        .navbar {
            background: white;
            padding: 15px 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .logo h2 { color: #2e7d32; }
        .nav-links a {
            color: #333;
            text-decoration: none;
            margin-left: 20px;
            padding: 8px 15px;
            border-radius: 5px;
        }
        .nav-links a:hover { background: #e8f5e9; color: #2e7d32; }

        /* Messaging Container */
        .messaging-container {
            display: flex;
            height: calc(100vh - 65px);
            max-width: 1400px;
            margin: 0 auto;
            background: white;
        }

        /* Sidebar */
        .conversations-sidebar {
            width: 350px;
            background: white;
            border-right: 1px solid #e0e0e0;
            display: flex;
            flex-direction: column;
        }

        .sidebar-header {
            padding: 20px;
            background: #2e7d32;
            color: white;
        }
        .sidebar-header h2 { font-size: 1.3rem; display: flex; align-items: center; gap: 10px; }
        .new-chat-btn {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            padding: 8px 15px;
            border-radius: 20px;
            cursor: pointer;
            margin-top: 15px;
            width: 100%;
        }

        .search-bar {
            padding: 15px;
            border-bottom: 1px solid #e0e0e0;
        }
        .search-bar input {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #e0e0e0;
            border-radius: 25px;
        }

        .conversations-list {
            flex: 1;
            overflow-y: auto;
        }
        .conversation-item {
            display: flex;
            align-items: center;
            padding: 15px;
            cursor: pointer;
            transition: background 0.3s;
            border-bottom: 1px solid #f0f0f0;
            position: relative;
        }
        .conversation-item:hover { background: #f5f5f5; }
        .conversation-item.active { background: #e8f5e9; }
        .conversation-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
        }
        .conversation-avatar i { font-size: 1.5rem; color: white; }
        .conversation-info { flex: 1; }
        .conversation-name { font-weight: 600; color: #333; margin-bottom: 5px; }
        .last-message { font-size: 0.8rem; color: #666; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 200px; }
        .message-time { font-size: 0.7rem; color: #999; }
        .unread-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            background: #f44336;
            color: white;
            border-radius: 50%;
            padding: 2px 6px;
            font-size: 0.7rem;
        }

        /* Chat Area */
        .chat-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: #f0f2f5;
        }
        .chat-header {
            padding: 15px 20px;
            background: white;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .chat-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .chat-avatar i { font-size: 1.3rem; color: white; }
        .chat-info h3 { font-size: 1.1rem; margin-bottom: 3px; }
        .chat-info p { font-size: 0.8rem; color: #666; }

        .messages-area {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            display: flex;
            flex-direction: column;
        }
        .message {
            display: flex;
            margin-bottom: 15px;
            animation: fadeIn 0.3s ease;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .message.sent { justify-content: flex-end; }
        .message.received { justify-content: flex-start; }
        .message-bubble {
            max-width: 60%;
            padding: 10px 15px;
            border-radius: 18px;
            word-wrap: break-word;
        }
        .message.sent .message-bubble {
            background: #2e7d32;
            color: white;
            border-bottom-right-radius: 5px;
        }
        .message.received .message-bubble {
            background: white;
            color: #333;
            border-bottom-left-radius: 5px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }
        .message-time {
            font-size: 0.65rem;
            margin-top: 5px;
            opacity: 0.7;
        }
        .message.sent .message-time { text-align: right; }

        .input-area {
            padding: 15px 20px;
            background: white;
            border-top: 1px solid #e0e0e0;
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .message-input {
            flex: 1;
            padding: 12px 15px;
            border: 1px solid #e0e0e0;
            border-radius: 25px;
            font-size: 0.95rem;
            resize: none;
            font-family: inherit;
        }
        .message-input:focus { outline: none; border-color: #2e7d32; }
        .send-btn {
            background: #2e7d32;
            color: white;
            border: none;
            border-radius: 50%;
            width: 45px;
            height: 45px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .send-btn:hover { background: #1b5e20; transform: scale(1.05); }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 2000;
            justify-content: center;
            align-items: center;
        }
        .modal-content {
            background: white;
            border-radius: 20px;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
        }
        .modal-header {
            padding: 20px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-body { padding: 20px; }
        .user-list { display: flex; flex-direction: column; gap: 10px; }
        .user-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 12px;
            border-radius: 10px;
            cursor: pointer;
            transition: background 0.3s;
            border: 1px solid #e0e0e0;
        }
        .user-item:hover { background: #e8f5e9; border-color: #2e7d32; }
        .close-modal {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
        }

        .empty-state {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 20px;
            text-align: center;
        }
        .empty-state i { font-size: 5rem; color: #ccc; }

        @media (max-width: 768px) {
            .conversations-sidebar { display: none; }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="logo"><h2><i class="fas fa-seedling"></i> AgriMarket</h2></div>
        <div class="nav-links">
            <a href="index.php">Home</a>
            <a href="dashboard/<?php echo $user_role; ?>.php">Dashboard</a>
            <a href="messages.php" style="background:#e8f5e9;color:#2e7d32;">Messages</a>
            <a href="auth/logout.php">Logout</a>
        </div>
    </nav>

    <div class="messaging-container">
        <!-- Conversations Sidebar -->
        <div class="conversations-sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-comments"></i> Chats</h2>
                <button class="new-chat-btn" onclick="openNewChatModal()">
                    <i class="fas fa-plus"></i> New Chat
                </button>
            </div>
            <div class="search-bar">
                <input type="text" id="searchChat" placeholder="Search conversations..." onkeyup="filterConversations()">
            </div>
            <div class="conversations-list" id="conversationsList">
                <?php if(count($conversations) > 0): ?>
                    <?php foreach($conversations as $conv): ?>
                        <div class="conversation-item <?php echo $selected_conversation == $conv['id'] ? 'active' : ''; ?>" 
                             onclick="selectConversation(<?php echo $conv['id']; ?>)">
                            <div class="conversation-avatar"><i class="fas fa-user"></i></div>
                            <div class="conversation-info">
                                <div class="conversation-name"><?php echo htmlspecialchars($conv['other_username']); ?></div>
                                <div class="last-message"><?php echo htmlspecialchars(substr($conv['last_message'] ?? 'No messages yet', 0, 30)); ?></div>
                            </div>
                            <div class="message-time"><?php echo date('M d', strtotime($conv['updated_at'])); ?></div>
                            <?php if($conv['unread_count'] > 0): ?>
                                <span class="unread-badge"><?php echo $conv['unread_count']; ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px; color: #999;">
                        <i class="fas fa-inbox" style="font-size: 3rem;"></i>
                        <p style="margin-top: 10px;">No conversations yet</p>
                        <button class="new-chat-btn" onclick="openNewChatModal()" style="background: #2e7d32; margin-top: 20px; width: auto; padding: 10px 20px;">
                            Start a chat
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Chat Area -->
        <?php if($selected_conversation && isset($other_user)): ?>
        <div class="chat-area">
            <div class="chat-header">
                <div class="chat-avatar"><i class="fas fa-user"></i></div>
                <div class="chat-info">
                    <h3><?php echo htmlspecialchars($other_user['username']); ?></h3>
                    <p><i class="fas fa-tag"></i> <?php echo ucfirst($other_user['role']); ?></p>
                </div>
            </div>
            <div class="messages-area" id="messagesArea">
                <?php foreach($messages as $msg): ?>
                <div class="message <?php echo $msg['sender_id'] == $user_id ? 'sent' : 'received'; ?>">
                    <div class="message-bubble">
                        <?php echo nl2br(htmlspecialchars($msg['message'])); ?>
                        <div class="message-time">
                            <?php echo date('h:i A', strtotime($msg['created_at'])); ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="input-area">
                <textarea class="message-input" id="messageInput" placeholder="Type a message..." rows="1"></textarea>
                <button class="send-btn" onclick="sendMessage()"><i class="fas fa-paper-plane"></i></button>
            </div>
        </div>
        <?php else: ?>
        <div class="chat-area">
            <div class="empty-state">
                <i class="fas fa-comments"></i>
                <h3>No conversation selected</h3>
                <p>Select a chat from the sidebar or start a new conversation</p>
                <button class="new-chat-btn" onclick="openNewChatModal()" style="background: #2e7d32; width: auto; padding: 10px 20px;">
                    <i class="fas fa-plus"></i> New Chat
                </button>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Modal -->
    <div id="newChatModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user-plus"></i> New Chat</h3>
                <button class="close-modal" onclick="closeNewChatModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="user-list">
                    <?php foreach($all_users as $u): ?>
                        <div class="user-item" onclick="startChat(<?php echo $u['id']; ?>)">
                            <div><strong><?php echo htmlspecialchars($u['username']); ?></strong><br>
                            <small><?php echo ucfirst($u['role']); ?> | <?php echo htmlspecialchars($u['email']); ?></small></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        let lastMessageId = <?php echo !empty($messages) ? end($messages)['id'] : 0; ?>;
        let conversationId = <?php echo $selected_conversation ?: 0; ?>;
        
        const messagesArea = document.getElementById('messagesArea');
        if(messagesArea) messagesArea.scrollTop = messagesArea.scrollHeight;
        
        const messageInput = document.getElementById('messageInput');
        if(messageInput) {
            messageInput.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = Math.min(this.scrollHeight, 100) + 'px';
            });
            messageInput.addEventListener('keypress', function(e) {
                if(e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendMessage();
                }
            });
        }
        
        function sendMessage() {
            const message = messageInput.value.trim();
            if(!message || !conversationId) return;
            
            fetch('messages.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: 'action=send_message&receiver_id=<?php echo $other_user['id'] ?? 0; ?>&message=' + encodeURIComponent(message)
            })
            .then(response => response.json())
            .then(data => {
                if(data.success) {
                    const messageDiv = document.createElement('div');
                    messageDiv.className = 'message sent';
                    messageDiv.innerHTML = `<div class="message-bubble">${escapeHtml(message)}<div class="message-time">Just now</div></div>`;
                    messagesArea.appendChild(messageDiv);
                    messagesArea.scrollTop = messagesArea.scrollHeight;
                    messageInput.value = '';
                    messageInput.style.height = 'auto';
                }
            });
        }
        
        if(conversationId) {
            setInterval(function() {
                fetch('messages.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: 'action=get_messages&conversation_id=' + conversationId + '&last_id=' + lastMessageId
                })
                .then(response => response.json())
                .then(data => {
                    if(data.success && data.messages.length > 0) {
                        data.messages.forEach(msg => {
                            const messageDiv = document.createElement('div');
                            messageDiv.className = 'message ' + (msg.sender_id == <?php echo $user_id; ?> ? 'sent' : 'received');
                            messageDiv.innerHTML = `<div class="message-bubble">${escapeHtml(msg.message)}<div class="message-time">${new Date(msg.created_at).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'})}</div></div>`;
                            messagesArea.appendChild(messageDiv);
                            lastMessageId = msg.id;
                        });
                        messagesArea.scrollTop = messagesArea.scrollHeight;
                        
                        fetch('messages.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: 'action=mark_read&conversation_id=' + conversationId
                        });
                    }
                });
            }, 3000);
        }
        
        function selectConversation(convId) { window.location.href = 'messages.php?conversation=' + convId; }
        function openNewChatModal() { document.getElementById('newChatModal').style.display = 'flex'; }
        function closeNewChatModal() { document.getElementById('newChatModal').style.display = 'none'; }
        function startChat(userId) { window.location.href = 'messages.php?new=' + userId; }
        function filterConversations() {
            const search = document.getElementById('searchChat').value.toLowerCase();
            document.querySelectorAll('.conversation-item').forEach(conv => {
                const name = conv.querySelector('.conversation-name').textContent.toLowerCase();
                conv.style.display = name.includes(search) ? 'flex' : 'none';
            });
        }
        function escapeHtml(text) { const div = document.createElement('div'); div.textContent = text; return div.innerHTML; }
        window.onclick = function(event) { const modal = document.getElementById('newChatModal'); if (event.target == modal) modal.style.display = 'none'; }
    </script>
</body>
</html>