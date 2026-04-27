<?php
session_start();
require_once '../config/db.php';
require_once '../includes/messaging_functions.php';

if(!isset($_SESSION['user_id'])) {
    echo json_encode(['count' => 0]);
    exit();
}

$user_id = $_SESSION['user_id'];
$count = getUnreadMessageCount($pdo, $user_id);

echo json_encode(['count' => $count]);
?>