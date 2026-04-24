<?php
session_start();
require_once '../config/db.php';

if(!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false]);
    exit();
}

$notification_id = $_POST['notification_id'] ?? 0;
$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
$success = $stmt->execute([$notification_id, $user_id]);

echo json_encode(['success' => $success]);
?>