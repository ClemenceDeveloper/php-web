<?php
session_start();
require_once '../config/db.php';
require_once '../includes/messaging_functions.php';

if(!isset($_SESSION['user_id'])) {
    echo json_encode(['messages' => []]);
    exit();
}

$user_id = $_SESSION['user_id'];
$conversation_id = $_GET['conversation_id'] ?? 0;
$last_count = $_GET['last_count'] ?? 0;

$messages = getConversationMessages($pdo, $conversation_id, $user_id);

$new_messages = array_slice($messages, $last_count);
$formatted_messages = [];

foreach($new_messages as $msg) {
    $formatted_messages[] = [
        'id' => $msg['id'],
        'message' => nl2br(htmlspecialchars($msg['message'])),
        'sender_id' => $msg['sender_id'],
        'time' => date('h:i A', strtotime($msg['created_at'])),
        'is_read' => $msg['is_read']
    ];
}

echo json_encode([
    'messages' => $formatted_messages,
    'total' => count($messages)
]);
?>