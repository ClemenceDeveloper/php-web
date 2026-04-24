<?php
session_start();
require_once '../config/db.php';

if(!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false]);
    exit();
}

$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
$success = $stmt->execute([$user_id]);

echo json_encode(['success' => $success]);
?>