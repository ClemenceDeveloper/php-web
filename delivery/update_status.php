<?php
require_once __DIR__ . '/../config/db.php';

if(!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Only admin and transport can update delivery status
$user_role = $_SESSION['role'];
if($user_role != 'admin' && $user_role != 'transport') {
    die("You don't have permission to update delivery status!");
}

$delivery_id = $_GET['id'] ?? 0;
$new_status = $_GET['status'] ?? '';
$location = $_GET['location'] ?? '';
$notes = $_GET['notes'] ?? '';

// Define valid status transitions
$valid_statuses = ['confirmed', 'preparing', 'picked_up', 'in_transit', 'out_for_delivery', 'delivered', 'cancelled'];

if(!in_array($new_status, $valid_statuses)) {
    die("Invalid status!");
}

try {
    // Get delivery details
    $stmt = $pdo->prepare("SELECT * FROM delivery_tracking WHERE id = ?");
    $stmt->execute([$delivery_id]);
    $delivery = $stmt->fetch();
    
    if(!$delivery) {
        die("Delivery not found!");
    }
    
    // For transport users, verify they are assigned to this delivery
    if($user_role == 'transport') {
        $stmt = $pdo->prepare("
            SELECT * FROM transport_requests 
            WHERE order_id = ? AND transport_id = ? AND status = 'accepted'
        ");
        $stmt->execute([$delivery['order_id'], $_SESSION['user_id']]);
        if(!$stmt->fetch()) {
            die("You are not authorized to update this delivery!");
        }
    }
    
    $pdo->beginTransaction();
    
    // Update delivery tracking
    $stmt = $pdo->prepare("
        UPDATE delivery_tracking 
        SET status = ?, current_location = ?, notes = ?, updated_at = NOW() 
        WHERE id = ?
    ");
    $stmt->execute([$new_status, $location, $notes, $delivery_id]);
    
    // Add to tracking history
    $stmt = $pdo->prepare("
        INSERT INTO delivery_tracking_history (delivery_id, status, location, notes) 
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$delivery_id, $new_status, $location, $notes]);
    
    // Update order status based on delivery status
    $order_status_map = [
        'confirmed' => 'confirmed',
        'preparing' => 'processing',
        'picked_up' => 'shipped',
        'in_transit' => 'shipped',
        'out_for_delivery' => 'shipped',
        'delivered' => 'delivered',
        'cancelled' => 'cancelled'
    ];
    
    if(isset($order_status_map[$new_status])) {
        $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
        $stmt->execute([$order_status_map[$new_status], $delivery['order_id']]);
    }
    
    // If delivered, update actual delivery time
    if($new_status == 'delivered') {
        $stmt = $pdo->prepare("UPDATE delivery_tracking SET actual_delivery = NOW() WHERE id = ?");
        $stmt->execute([$delivery_id]);
        
        // Update product status to sold
        $stmt = $pdo->prepare("
            UPDATE products SET status = 'sold' 
            WHERE id = (SELECT product_id FROM orders WHERE id = ?)
        ");
        $stmt->execute([$delivery['order_id']]);
    }
    
    $pdo->commit();
    
    // Redirect back with success message
    header("Location: track.php?order_id=" . $delivery['order_id'] . "&success=1");
    exit();
    
} catch(PDOException $e) {
    $pdo->rollBack();
    die("Error updating status: " . $e->getMessage());
}
?>