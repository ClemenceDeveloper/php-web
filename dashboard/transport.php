<?php
// Include database connection
$pdo = require_once __DIR__ . '/../config/db.php';

// Check if user is logged in and is a transport provider
if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'transport') {
    header("Location: ../auth/login.php");
    exit();
}

$transport_id = $_SESSION['user_id'];
$success = '';
$error = '';

// Include notification functions
require_once __DIR__ . '/../includes/notification_functions.php';

// Get notification data
$unread_count = getUnreadCount($pdo, $transport_id);
$notifications = getRecentNotifications($pdo, $transport_id, 10);

// Messaging count
$unread_msg_count = 0;

// Check if profile_image column exists
$stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'profile_image'");
$has_profile_image = $stmt->rowCount() > 0;

// Handle Accept Delivery Request
if(isset($_GET['accept']) && isset($_GET['request_id'])) {
    $request_id = $_GET['request_id'];
    
    try {
        $pdo->beginTransaction();
        
        // Remove accepted_at column - just update status
        $stmt = $pdo->prepare("UPDATE transport_requests SET transport_id = ?, status = 'accepted' WHERE id = ? AND status = 'pending'");
        $stmt->execute([$transport_id, $request_id]);
        
        if($stmt->rowCount() > 0) {
            $stmt = $pdo->prepare("SELECT order_id FROM transport_requests WHERE id = ?");
            $stmt->execute([$request_id]);
            $request = $stmt->fetch();
            
            $stmt = $pdo->prepare("UPDATE orders SET status = 'shipped' WHERE id = ?");
            $stmt->execute([$request['order_id']]);
            
            // Get order details for notifications
            $stmt = $pdo->prepare("
                SELECT o.*, p.name as product_name, p.farmer_id, 
                       buyer.id as buyer_id, buyer.username as buyer_name,
                       farmer.id as farmer_id, farmer.username as farmer_name
                FROM orders o
                JOIN products p ON o.product_id = p.id
                JOIN users buyer ON o.buyer_id = buyer.id
                JOIN users farmer ON p.farmer_id = farmer.id
                WHERE o.id = ?
            ");
            $stmt->execute([$request['order_id']]);
            $order = $stmt->fetch();
            
            // Send notification to BUYER
            sendNotification($pdo, $order['buyer_id'],
                "🚚 Delivery Accepted!",
                "Your order #{$request['order_id']} for {$order['product_name']} has been accepted by a transporter and is on its way!",
                "delivery",
                "../delivery/track.php?order_id={$request['order_id']}"
            );
            
            // Send notification to FARMER
            sendNotification($pdo, $order['farmer_id'],
                "🚚 Delivery Picked Up!",
                "Your product '{$order['product_name']}' has been picked up by a transporter and is being delivered to the customer.",
                "delivery",
                "../dashboard/farmer.php?section=orders"
            );
            
            // Send notification to TRANSPORT (self)
            sendNotification($pdo, $transport_id,
                "✅ Delivery Accepted!",
                "You have accepted delivery for order #{$request['order_id']}. Please pick up the product from the farmer.",
                "delivery",
                "transport.php?section=active"
            );
            
            $pdo->commit();
            $success = "Delivery request accepted successfully! Notifications sent to farmer and buyer.";
        } else {
            $error = "Request already taken or invalid!";
        }
    } catch(PDOException $e) {
        $pdo->rollBack();
        $error = "Failed to accept request: " . $e->getMessage();
    }
}

// Handle Complete Delivery
if(isset($_GET['complete']) && isset($_GET['request_id'])) {
    $request_id = $_GET['request_id'];
    
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("UPDATE transport_requests SET status = 'completed' WHERE id = ? AND transport_id = ?");
        $stmt->execute([$request_id, $transport_id]);
        
        if($stmt->rowCount() > 0) {
            $stmt = $pdo->prepare("SELECT order_id FROM transport_requests WHERE id = ?");
            $stmt->execute([$request_id]);
            $request = $stmt->fetch();
            
            $stmt = $pdo->prepare("UPDATE orders SET status = 'delivered' WHERE id = ?");
            $stmt->execute([$request['order_id']]);
            
            // Update product status to sold
            $stmt = $pdo->prepare("
                UPDATE products SET status = 'sold' 
                WHERE id = (SELECT product_id FROM orders WHERE id = ?)
            ");
            $stmt->execute([$request['order_id']]);
            
            // Get order details for notifications
            $stmt = $pdo->prepare("
                SELECT o.*, p.name as product_name, p.farmer_id, 
                       buyer.id as buyer_id, buyer.username as buyer_name,
                       farmer.id as farmer_id, farmer.username as farmer_name
                FROM orders o
                JOIN products p ON o.product_id = p.id
                JOIN users buyer ON o.buyer_id = buyer.id
                JOIN users farmer ON p.farmer_id = farmer.id
                WHERE o.id = ?
            ");
            $stmt->execute([$request['order_id']]);
            $order = $stmt->fetch();
            
            // Send notification to BUYER
            sendNotification($pdo, $order['buyer_id'],
                "✅ Order Delivered!",
                "Great news! Your order #{$request['order_id']} for {$order['product_name']} has been delivered successfully. Thank you for shopping with us!",
                "delivery",
                "../dashboard/user.php?section=orders"
            );
            
            // Send notification to FARMER
            sendNotification($pdo, $order['farmer_id'],
                "💰 Order Completed!",
                "Your product '{$order['product_name']}' has been delivered to the customer. Payment will be processed to your account.",
                "payment",
                "../dashboard/farmer.php?section=earnings"
            );
            
            // Send notification to TRANSPORT (self)
            sendNotification($pdo, $transport_id,
                "🎉 Delivery Completed!",
                "You have successfully completed delivery for order #{$request['order_id']}. ₱50 has been added to your earnings.",
                "earnings",
                "transport.php?section=earnings"
            );
            
            $pdo->commit();
            $success = "Delivery completed successfully! Notifications sent to farmer and buyer.";
        } else {
            $error = "Failed to complete delivery!";
        }
    } catch(PDOException $e) {
        $pdo->rollBack();
        $error = "Failed to complete delivery: " . $e->getMessage();
    }
}

// Handle Delivery Update (In Transit, Out for Delivery, etc.)
if(isset($_GET['update_status']) && isset($_GET['request_id']) && isset($_GET['status'])) {
    $request_id = $_GET['request_id'];
    $new_status = $_GET['status'];
    
    $valid_statuses = ['picked_up', 'in_transit', 'out_for_delivery'];
    
    if(in_array($new_status, $valid_statuses)) {
        try {
            $stmt = $pdo->prepare("UPDATE transport_requests SET status = ? WHERE id = ? AND transport_id = ?");
            $stmt->execute([$new_status, $request_id, $transport_id]);
            
            if($stmt->rowCount() > 0) {
                // Get order details
                $stmt = $pdo->prepare("
                    SELECT o.id as order_id, o.buyer_id, p.name as product_name,
                           buyer.username as buyer_name
                    FROM transport_requests tr
                    JOIN orders o ON tr.order_id = o.id
                    JOIN products p ON o.product_id = p.id
                    JOIN users buyer ON o.buyer_id = buyer.id
                    WHERE tr.id = ?
                ");
                $stmt->execute([$request_id]);
                $order = $stmt->fetch();
                
                $status_messages = [
                    'picked_up' => '📦 Your order has been picked up from the farmer!',
                    'in_transit' => '🚚 Your order is now in transit!',
                    'out_for_delivery' => '🚛 Your order is out for delivery! It will arrive soon.'
                ];
                
                // Send notification to buyer
                sendNotification($pdo, $order['buyer_id'],
                    "📍 Delivery Update",
                    $status_messages[$new_status] . " Order #{$order['order_id']} for {$order['product_name']}.",
                    "delivery",
                    "../delivery/track.php?order_id={$order['order_id']}"
                );
                
                $success = "Delivery status updated to " . ucfirst(str_replace('_', ' ', $new_status));
            }
        } catch(PDOException $e) {
            $error = "Failed to update status: " . $e->getMessage();
        }
    }
}

// Mark notification as read
if(isset($_GET['mark_read']) && isset($_GET['notif_id'])) {
    $notif_id = $_GET['notif_id'];
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->execute([$notif_id, $transport_id]);
    header("Location: transport.php");
    exit();
}

// Mark all as read
if(isset($_GET['mark_all_read'])) {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->execute([$transport_id]);
    header("Location: transport.php");
    exit();
}

// Get statistics
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM transport_requests WHERE transport_id = ? AND status = 'completed'");
$stmt->execute([$transport_id]);
$completed_deliveries = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM transport_requests WHERE transport_id = ? AND status = 'accepted'");
$stmt->execute([$transport_id]);
$pending_deliveries = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM transport_requests WHERE status = 'pending'");
$stmt->execute([]);
$available_requests = $stmt->fetchColumn();

$total_earnings = $completed_deliveries * 50;

// Get available delivery requests
$available_requests_list = $pdo->prepare("
    SELECT tr.*, o.id as order_id, o.order_date, o.total_price,
           p.name as product_name, p.quantity, p.unit,
           buyer.username as buyer_name, buyer.address as delivery_address, buyer.phone as buyer_phone,
           farmer.username as farmer_name, farmer.address as pickup_address, farmer.phone as farmer_phone
    FROM transport_requests tr
    JOIN orders o ON tr.order_id = o.id
    JOIN products p ON o.product_id = p.id
    JOIN users buyer ON o.buyer_id = buyer.id
    JOIN users farmer ON p.farmer_id = farmer.id
    WHERE tr.status = 'pending'
    ORDER BY tr.created_at DESC
");
$available_requests_list->execute([]);

// Get my active deliveries
$active_deliveries = $pdo->prepare("
    SELECT tr.*, o.id as order_id, o.order_date, o.total_price,
           p.name as product_name, p.quantity, p.unit,
           buyer.username as buyer_name, buyer.address as delivery_address, buyer.phone as buyer_phone,
           farmer.username as farmer_name, farmer.address as pickup_address, farmer.phone as farmer_phone
    FROM transport_requests tr
    JOIN orders o ON tr.order_id = o.id
    JOIN products p ON o.product_id = p.id
    JOIN users buyer ON o.buyer_id = buyer.id
    JOIN users farmer ON p.farmer_id = farmer.id
    WHERE tr.transport_id = ? AND tr.status = 'accepted'
    ORDER BY tr.created_at DESC
");
$active_deliveries->execute([$transport_id]);

// Get delivery history
$delivery_history = $pdo->prepare("
    SELECT tr.*, o.id as order_id, o.order_date, o.total_price,
           p.name as product_name, p.quantity, p.unit,
           buyer.username as buyer_name, buyer.address as delivery_address,
           farmer.username as farmer_name, farmer.address as pickup_address
    FROM transport_requests tr
    JOIN orders o ON tr.order_id = o.id
    JOIN products p ON o.product_id = p.id
    JOIN users buyer ON o.buyer_id = buyer.id
    JOIN users farmer ON p.farmer_id = farmer.id
    WHERE tr.transport_id = ? AND tr.status = 'completed'
    ORDER BY tr.created_at DESC
    LIMIT 20
");
$delivery_history->execute([$transport_id]);

// Get user info for profile
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$transport_id]);
$user = $stmt->fetch();

// Get total notifications count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ?");
$stmt->execute([$transport_id]);
$total_notifications = $stmt->fetchColumn();

// Set profile image
$profile_image = 'https://ui-avatars.com/api/?background=0284c7&color=fff&name=' . urlencode($user['username']);
if($has_profile_image && !empty($user['profile_image']) && file_exists(__DIR__ . '/../' . $user['profile_image'])) {
    $profile_image = '../' . $user['profile_image'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transport Dashboard - Agriculture Marketplace</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f0f4f8; overflow-x: hidden; }

        /* Icon and Badge Styles */
        .icon-link { position: relative; text-decoration: none; color: #475569; margin-right: 15px; }
        .icon-link i { font-size: 1.3rem; }
        .icon-link:hover { color: #0284c7; }
        .badge { position: absolute; top: -8px; right: -8px; background: #ef4444; color: white; border-radius: 50%; padding: 2px 6px; font-size: 10px; min-width: 18px; text-align: center; animation: pulse 1.5s infinite; }
        @keyframes pulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.2); } }

        /* Notification Dropdown */
        .notification-dropdown { position: absolute; top: 55px; right: 20px; width: 380px; background: white; border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); z-index: 1000; display: none; max-height: 500px; overflow-y: auto; border: 1px solid #e0e0e0; }
        .notification-dropdown.show { display: block; animation: slideDown 0.3s ease; }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        .notification-header { padding: 15px 20px; border-bottom: 1px solid #e0e0e0; display: flex; justify-content: space-between; align-items: center; background: linear-gradient(135deg, #0284c7, #0369a1); border-radius: 12px 12px 0 0; color: white; position: sticky; top: 0; z-index: 10; }
        .mark-all-btn { font-size: 0.75rem; color: #fbbf24; text-decoration: none; }
        .notification-list { max-height: 420px; overflow-y: auto; }
        .notification-item { padding: 15px 20px; border-bottom: 1px solid #f0f0f0; transition: background 0.3s; text-decoration: none; display: block; cursor: pointer; }
        .notification-item:hover { background: #f8fafc; }
        .notification-item.unread { background: #e0f2fe; border-left: 3px solid #0284c7; }
        .notification-title { font-weight: bold; margin-bottom: 5px; color: #333; font-size: 0.95rem; }
        .notification-message { font-size: 0.85rem; color: #666; margin-bottom: 5px; line-height: 1.4; }
        .notification-time { font-size: 0.7rem; color: #999; display: flex; align-items: center; gap: 5px; }
        .mark-read { float: right; font-size: 0.7rem; color: #0284c7; text-decoration: none; padding: 2px 8px; border-radius: 10px; background: #e0f2fe; }
        .empty-notifications { text-align: center; padding: 50px 20px; color: #999; }
        .empty-notifications i { font-size: 3rem; margin-bottom: 15px; color: #ccc; }

        /* Sidebar Styles */
        .sidebar { position: fixed; left: 0; top: 0; width: 280px; height: 100%; background: linear-gradient(135deg, #1e293b, #0f172a); color: white; box-shadow: 2px 0 10px rgba(0,0,0,0.1); transition: all 0.3s; z-index: 1000; }
        .sidebar-header { padding: 30px 20px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-header i { font-size: 3rem; margin-bottom: 10px; color: #38bdf8; }
        .sidebar-header h3 { font-size: 1.3rem; margin-bottom: 5px; }
        .sidebar-header p { font-size: 0.85rem; opacity: 0.8; }
        .sidebar-menu { padding: 20px 0; }
        .menu-item { padding: 12px 25px; cursor: pointer; transition: all 0.3s; display: flex; align-items: center; gap: 12px; }
        .menu-item:hover { background: rgba(255,255,255,0.1); padding-left: 30px; }
        .menu-item.active { background: rgba(56, 189, 248, 0.2); border-left: 4px solid #38bdf8; }
        .menu-item i { width: 25px; font-size: 1.2rem; }

        /* Main Content */
        .main-content { margin-left: 280px; padding: 20px; }

        /* Top Navbar */
        .top-navbar { background: white; padding: 15px 30px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; position: sticky; top: 0; z-index: 999; }
        .page-title h2 { color: #1e293b; font-size: 1.5rem; }
        .user-info { display: flex; align-items: center; gap: 20px; position: relative; }
        .user-info img { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; }
        .logout-btn { background: #ef4444; color: white; padding: 8px 20px; border-radius: 8px; text-decoration: none; transition: all 0.3s; }
        .logout-btn:hover { background: #dc2626; }

        /* Stats Cards */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); display: flex; align-items: center; justify-content: space-between; transition: all 0.3s; }
        .stat-card:hover { transform: translateY(-5px); box-shadow: 0 5px 20px rgba(0,0,0,0.1); }
        .stat-info h3 { font-size: 0.85rem; color: #64748b; margin-bottom: 5px; }
        .stat-number { font-size: 1.8rem; font-weight: bold; color: #0284c7; }
        .stat-icon i { font-size: 2.5rem; color: #38bdf8; opacity: 0.7; }

        /* Sections */
        .section { background: white; border-radius: 12px; padding: 25px; margin-bottom: 30px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); display: none; }
        .section.active { display: block; animation: fadeIn 0.5s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .section-title { font-size: 1.3rem; color: #1e293b; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 2px solid #e2e8f0; }

        /* Delivery Cards Grid */
        .delivery-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(420px, 1fr)); gap: 20px; margin-top: 20px; }
        .delivery-card { background: white; border-radius: 12px; overflow: hidden; transition: all 0.3s; box-shadow: 0 2px 10px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; }
        .delivery-card:hover { transform: translateY(-5px); box-shadow: 0 5px 20px rgba(0,0,0,0.1); }
        .delivery-header { background: linear-gradient(135deg, #0284c7, #0369a1); color: white; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; }
        .delivery-badge { background: rgba(255,255,255,0.2); padding: 4px 12px; border-radius: 20px; font-size: 0.7rem; }
        .delivery-body { padding: 15px; }
        .info-row { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; padding: 8px; background: #f8fafc; border-radius: 8px; }
        .info-row i { width: 25px; color: #0284c7; }
        .info-row strong { color: #334155; min-width: 100px; }
        .delivery-actions { padding: 15px; border-top: 1px solid #e2e8f0; display: flex; gap: 10px; flex-wrap: wrap; }
        .btn-accept, .btn-complete, .btn-update { padding: 8px 15px; border: none; border-radius: 8px; cursor: pointer; text-decoration: none; display: inline-block; transition: all 0.3s; text-align: center; }
        .btn-accept { background: #10b981; color: white; flex: 1; }
        .btn-accept:hover { background: #059669; transform: translateY(-2px); }
        .btn-complete { background: #3b82f6; color: white; flex: 1; }
        .btn-complete:hover { background: #2563eb; transform: translateY(-2px); }
        .btn-update { background: #f59e0b; color: white; font-size: 0.8rem; }
        .btn-update:hover { background: #d97706; }

        /* Tables */
        .data-table { width: 100%; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        th { background: #f8fafc; color: #1e293b; font-weight: 600; }
        tr:hover { background: #f8fafc; }
        .status-badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 0.75rem; }
        .status-pending { background: #f59e0b; color: white; }
        .status-accepted { background: #3b82f6; color: white; }
        .status-completed { background: #10b981; color: white; }

        /* Alert Messages */
        .alert { padding: 12px 15px; border-radius: 10px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .alert-success { background: #d1fae5; color: #065f46; border-left: 4px solid #10b981; }
        .alert-error { background: #fee2e2; color: #991b1b; border-left: 4px solid #ef4444; }

        /* Empty States */
        .empty-state { text-align: center; padding: 40px; }
        .empty-state i { font-size: 3rem; color: #cbd5e1; margin-bottom: 15px; }
        .empty-state p { color: #64748b; }

        /* Profile Section */
        .profile-container { background: white; border-radius: 16px; padding: 25px; margin-bottom: 25px; }
        .profile-header { display: flex; align-items: center; gap: 25px; margin-bottom: 30px; flex-wrap: wrap; }
        .profile-avatar { position: relative; display: inline-block; }
        .profile-avatar img { width: 100px; height: 100px; border-radius: 50%; object-fit: cover; border: 3px solid #0284c7; }
        .profile-avatar .edit-photo { position: absolute; bottom: 5px; right: 5px; background: #0284c7; color: white; border-radius: 50%; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.3s; }
        .profile-info h2 { color: #1e293b; margin-bottom: 5px; }
        .profile-stats { display: flex; gap: 20px; margin-top: 10px; }
        .profile-stats .stat { text-align: center; }
        .profile-stats .stat .number { font-size: 1.3rem; font-weight: bold; color: #0284c7; }
        .profile-stats .stat .label { font-size: 0.75rem; color: #64748b; }
        .profile-form { margin-top: 20px; padding-top: 20px; border-top: 1px solid #e2e8f0; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 600; color: #334155; }
        .form-group input { width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 8px; transition: all 0.3s; }
        .form-group input:focus { outline: none; border-color: #0284c7; box-shadow: 0 0 0 3px rgba(2,132,199,0.1); }
        .btn-save { background: linear-gradient(135deg, #0284c7, #0369a1); color: white; padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; transition: all 0.3s; }
        .btn-save:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(2,132,199,0.3); }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar { left: -280px; }
            .main-content { margin-left: 0; }
            .delivery-grid { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: 1fr; }
            .form-row { grid-template-columns: 1fr; }
            .notification-dropdown { width: 320px; right: 10px; }
            .profile-header { flex-direction: column; text-align: center; }
            .profile-stats { justify-content: center; }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <i class="fas fa-truck-fast"></i>
            <h3>Transport Hub</h3>
            <p><?php echo htmlspecialchars($_SESSION['username']); ?></p>
        </div>
        <div class="sidebar-menu">
            <div class="menu-item active" data-section="dashboard"><i class="fas fa-chart-line"></i><span>Dashboard</span></div>
            <div class="menu-item" data-section="available"><i class="fas fa-bell"></i><span>Available Requests</span><?php if($available_requests > 0): ?><span style="background: #ef4444; padding: 2px 8px; border-radius: 20px; font-size: 0.7rem; margin-left: auto;"><?php echo $available_requests; ?></span><?php endif; ?></div>
            <div class="menu-item" data-section="active"><i class="fas fa-truck-moving"></i><span>Active Deliveries</span><?php if($pending_deliveries > 0): ?><span style="background: #10b981; padding: 2px 8px; border-radius: 20px; font-size: 0.7rem; margin-left: auto;"><?php echo $pending_deliveries; ?></span><?php endif; ?></div>
            <div class="menu-item" data-section="history"><i class="fas fa-history"></i><span>Delivery History</span></div>
            <div class="menu-item" data-section="earnings"><i class="fas fa-chart-simple"></i><span>Earnings</span></div>
            <div class="menu-item" data-section="profile"><i class="fas fa-user-circle"></i><span>My Profile</span></div>
        </div>
    </div>

    <div class="main-content">
        <div class="top-navbar">
            <div class="page-title"><h2><i class="fas fa-truck"></i> Transport Dashboard</h2></div>
            <div class="user-info">
                <a href="../messages.php" class="icon-link"><i class="fas fa-envelope"></i><?php if($unread_msg_count > 0): ?><span class="badge"><?php echo $unread_msg_count; ?></span><?php endif; ?></a>
                <div class="icon-link" onclick="toggleNotifications()"><i class="fas fa-bell"></i><?php if($unread_count > 0): ?><span class="badge"><?php echo $unread_count; ?></span><?php endif; ?></div>
                <div class="notification-dropdown" id="notificationDropdown">
                    <div class="notification-header"><strong><i class="fas fa-bell"></i> Notifications</strong><?php if($unread_count > 0): ?><a href="?mark_all_read=1" class="mark-all-btn">Mark all as read</a><?php endif; ?></div>
                    <div class="notification-list"><?php if(count($notifications) > 0): ?><?php foreach($notifications as $notif): ?><a href="<?php echo $notif['link']; ?>" class="notification-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>"><div class="notification-title"><?php echo $notif['title']; ?><?php if(!$notif['is_read']): ?><a href="?mark_read=1&notif_id=<?php echo $notif['id']; ?>" class="mark-read">Mark read</a><?php endif; ?></div><div class="notification-message"><?php echo $notif['message']; ?></div><div class="notification-time"><?php echo date('M d, H:i', strtotime($notif['created_at'])); ?></div></a><?php endforeach; ?><?php else: ?><div class="empty-notifications"><i class="fas fa-bell-slash"></i><p>No notifications yet</p></div><?php endif; ?></div>
                </div>
                <img src="<?php echo $profile_image; ?>" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover;">
                <span><?php echo htmlspecialchars($_SESSION['username']); ?></span>
                <a href="../auth/logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>

        <?php if($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div><?php endif; ?>
        <?php if($error): ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div><?php endif; ?>

        <!-- Dashboard Section -->
        <div id="dashboard" class="section active">
            <h2 class="section-title">Dashboard Overview</h2>
            <div class="stats-grid">
                <div class="stat-card"><div class="stat-info"><h3>Available Requests</h3><div class="stat-number"><?php echo $available_requests; ?></div></div><div class="stat-icon"><i class="fas fa-bell"></i></div></div>
                <div class="stat-card"><div class="stat-info"><h3>Active Deliveries</h3><div class="stat-number"><?php echo $pending_deliveries; ?></div></div><div class="stat-icon"><i class="fas fa-truck-moving"></i></div></div>
                <div class="stat-card"><div class="stat-info"><h3>Completed Deliveries</h3><div class="stat-number"><?php echo $completed_deliveries; ?></div></div><div class="stat-icon"><i class="fas fa-check-circle"></i></div></div>
                <div class="stat-card"><div class="stat-info"><h3>Total Earnings</h3><div class="stat-number">₱<?php echo number_format($total_earnings, 2); ?></div></div><div class="stat-icon"><i class="fas fa-money-bill-wave"></i></div></div>
            </div>
            <div style="background: linear-gradient(135deg, #0284c7, #0369a1); color: white; padding: 30px; border-radius: 12px; text-align: center;"><i class="fas fa-truck" style="font-size: 3rem; margin-bottom: 15px;"></i><h3>Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?>!</h3><p>You have completed <?php echo $completed_deliveries; ?> deliveries successfully</p><a href="#" onclick="showSection('available')" style="display: inline-block; margin-top: 15px; background: white; color: #0284c7; padding: 10px 20px; border-radius: 8px; text-decoration: none; font-weight: bold;">View Available Requests</a></div>
        </div>

        <!-- Available Requests Section -->
        <div id="available" class="section">
            <h2 class="section-title"><i class="fas fa-bell"></i> Available Delivery Requests</h2>
            <?php if($available_requests_list->rowCount() > 0): ?>
                <div class="delivery-grid">
                    <?php while($request = $available_requests_list->fetch(PDO::FETCH_ASSOC)): ?>
                        <div class="delivery-card">
                            <div class="delivery-header"><h4><i class="fas fa-shopping-cart"></i> Order #<?php echo $request['order_id']; ?></h4><span class="delivery-badge">New Request</span></div>
                            <div class="delivery-body">
                                <div class="info-row"><i class="fas fa-box"></i><strong>Product:</strong> <span><?php echo htmlspecialchars($request['product_name']); ?> (<?php echo $request['quantity']; ?> <?php echo $request['unit']; ?>)</span></div>
                                <div class="info-row"><i class="fas fa-user-tie"></i><strong>Farmer:</strong> <span><?php echo htmlspecialchars($request['farmer_name']); ?> | 📞 <?php echo htmlspecialchars($request['farmer_phone']); ?></span></div>
                                <div class="info-row"><i class="fas fa-map-marker-alt"></i><strong>Pickup:</strong> <span><?php echo htmlspecialchars($request['pickup_address']); ?></span></div>
                                <div class="info-row"><i class="fas fa-user"></i><strong>Buyer:</strong> <span><?php echo htmlspecialchars($request['buyer_name']); ?> | 📞 <?php echo htmlspecialchars($request['buyer_phone']); ?></span></div>
                                <div class="info-row"><i class="fas fa-home"></i><strong>Delivery:</strong> <span><?php echo htmlspecialchars($request['delivery_address']); ?></span></div>
                                <div class="info-row"><i class="fas fa-tag"></i><strong>Order Value:</strong> <span>₱<?php echo number_format($request['total_price'], 2); ?></span></div>
                            </div>
                            <div class="delivery-actions"><a href="?accept=1&request_id=<?php echo $request['id']; ?>" class="btn-accept" onclick="return confirm('Accept this delivery request?')"><i class="fas fa-check"></i> Accept Delivery</a></div>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div class="empty-state"><i class="fas fa-inbox"></i><p>No available delivery requests at the moment.</p><p style="font-size: 0.85rem; margin-top: 10px;">Check back later for new requests!</p></div>
            <?php endif; ?>
        </div>

        <!-- Active Deliveries Section -->
        <div id="active" class="section">
            <h2 class="section-title"><i class="fas fa-truck-moving"></i> My Active Deliveries</h2>
            <?php if($active_deliveries->rowCount() > 0): ?>
                <div class="delivery-grid">
                    <?php while($delivery = $active_deliveries->fetch(PDO::FETCH_ASSOC)): ?>
                        <div class="delivery-card">
                            <div class="delivery-header"><h4><i class="fas fa-truck"></i> Delivery #<?php echo $delivery['id']; ?></h4><span class="delivery-badge">In Progress</span></div>
                            <div class="delivery-body">
                                <div class="info-row"><i class="fas fa-box"></i><strong>Product:</strong> <span><?php echo htmlspecialchars($delivery['product_name']); ?> (<?php echo $delivery['quantity']; ?> <?php echo $delivery['unit']; ?>)</span></div>
                                <div class="info-row"><i class="fas fa-user-tie"></i><strong>Farmer:</strong> <span><?php echo htmlspecialchars($delivery['farmer_name']); ?> | 📞 <?php echo htmlspecialchars($delivery['farmer_phone']); ?></span></div>
                                <div class="info-row"><i class="fas fa-map-marker-alt"></i><strong>Pickup:</strong> <span><?php echo htmlspecialchars($delivery['pickup_address']); ?></span></div>
                                <div class="info-row"><i class="fas fa-user"></i><strong>Buyer:</strong> <span><?php echo htmlspecialchars($delivery['buyer_name']); ?> | 📞 <?php echo htmlspecialchars($delivery['buyer_phone']); ?></span></div>
                                <div class="info-row"><i class="fas fa-home"></i><strong>Delivery:</strong> <span><?php echo htmlspecialchars($delivery['delivery_address']); ?></span></div>
                            </div>
                            <div class="delivery-actions"><a href="?complete=1&request_id=<?php echo $delivery['id']; ?>" class="btn-complete" onclick="return confirm('Complete this delivery?')"><i class="fas fa-check-double"></i> Complete Delivery</a></div>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div class="empty-state"><i class="fas fa-truck"></i><p>No active deliveries.</p><a href="#" onclick="showSection('available')" class="btn-accept" style="display: inline-block; width: auto; margin-top: 15px;"><i class="fas fa-bell"></i> Accept New Requests</a></div>
            <?php endif; ?>
        </div>

        <!-- Delivery History Section -->
        <div id="history" class="section">
            <h2 class="section-title"><i class="fas fa-history"></i> Delivery History</h2>
            <?php if($delivery_history->rowCount() > 0): ?>
                <div class="data-table">
                    <table><thead><tr><th>Delivery ID</th><th>Order #</th><th>Product</th><th>From (Farmer)</th><th>To (Buyer)</th><th>Completed Date</th><th>Status</th></tr></thead>
                    <tbody><?php while($history = $delivery_history->fetch(PDO::FETCH_ASSOC)): ?><tr><td>#<?php echo $history['id']; ?></td><td>#<?php echo $history['order_id']; ?></td><td><?php echo htmlspecialchars($history['product_name']); ?></td><td><?php echo htmlspecialchars($history['farmer_name']); ?></td><td><?php echo htmlspecialchars($history['buyer_name']); ?></td><td><?php echo date('M d, Y', strtotime($history['created_at'])); ?></td><td><span class="status-badge status-completed"><i class="fas fa-check-circle"></i> Completed</span></td></tr><?php endwhile; ?></tbody></table>
                </div>
            <?php else: ?>
                <div class="empty-state"><i class="fas fa-history"></i><p>No delivery history yet.</p><p>Start accepting deliveries to build your history!</p></div>
            <?php endif; ?>
        </div>

        <!-- Earnings Section -->
        <div id="earnings" class="section">
            <h2 class="section-title"><i class="fas fa-chart-line"></i> Earnings Overview</h2>
            <div class="stats-grid">
                <div class="stat-card"><div class="stat-info"><h3>Total Earnings</h3><div class="stat-number">₱<?php echo number_format($total_earnings, 2); ?></div></div><div class="stat-icon"><i class="fas fa-money-bill-wave"></i></div></div>
                <div class="stat-card"><div class="stat-info"><h3>Completed Deliveries</h3><div class="stat-number"><?php echo $completed_deliveries; ?></div></div><div class="stat-icon"><i class="fas fa-check-circle"></i></div></div>
                <div class="stat-card"><div class="stat-info"><h3>Average per Delivery</h3><div class="stat-number">₱50.00</div></div><div class="stat-icon"><i class="fas fa-chart-line"></i></div></div>
            </div>
            <div style="background: linear-gradient(135deg, #0284c7, #0369a1); color: white; padding: 30px; border-radius: 12px; text-align: center; margin-top: 20px;"><i class="fas fa-trophy" style="font-size: 3rem; margin-bottom: 10px;"></i><h3>Great Job!</h3><p>You've earned ₱<?php echo number_format($total_earnings, 2); ?> from <?php echo $completed_deliveries; ?> deliveries</p><div style="margin-top: 15px;"><div style="background: rgba(255,255,255,0.2); border-radius: 10px; padding: 10px;"><small>Next milestone: <?php echo 10 - ($completed_deliveries % 10); ?> more deliveries to earn a bonus!</small></div></div></div>
        </div>

        <!-- Profile Section -->
        <div id="profile" class="section">
            <h2 class="section-title"><i class="fas fa-user-circle"></i> My Profile</h2>
            <div class="profile-container">
                <div class="profile-header">
                    <div class="profile-avatar"><img src="<?php echo $profile_image; ?>" alt="Profile"><div class="edit-photo" onclick="alert('Profile picture upload coming soon')"><i class="fas fa-camera"></i></div></div>
                    <div class="profile-info"><h2><?php echo htmlspecialchars($user['username']); ?></h2><p><i class="fas fa-envelope"></i> <?php echo $user['email']; ?></p><p><i class="fas fa-phone"></i> <?php echo $user['phone'] ?: 'Not provided'; ?></p><div class="profile-stats"><div class="stat"><div class="number"><?php echo $completed_deliveries; ?></div><div class="label">Deliveries</div></div><div class="stat"><div class="number"><?php echo $pending_deliveries; ?></div><div class="label">Active</div></div><div class="stat"><div class="number">₱<?php echo number_format($total_earnings, 2); ?></div><div class="label">Earned</div></div></div></div>
                </div>
                <div class="profile-form"><h3>Edit Profile Information</h3><form method="POST" action=""><div class="form-row"><div class="form-group"><label>Full Name</label><input type="text" name="fullname" value="<?php echo htmlspecialchars($user['username']); ?>" required></div><div class="form-group"><label>Email</label><input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required></div></div><div class="form-row"><div class="form-group"><label>Phone</label><input type="tel" name="phone" value="<?php echo htmlspecialchars($user['phone']); ?>"></div><div class="form-group"><label>Address</label><input type="text" name="address" value="<?php echo htmlspecialchars($user['address']); ?>"></div></div><button type="submit" name="update_profile" class="btn-save">Save Changes</button></form></div>
                <div class="profile-form"><h3>Change Password</h3><form method="POST" action=""><div class="form-group"><label>Current Password</label><input type="password" name="current_password" required></div><div class="form-row"><div class="form-group"><label>New Password</label><input type="password" name="new_password" required></div><div class="form-group"><label>Confirm Password</label><input type="password" name="confirm_password" required></div></div><button type="submit" name="change_password" class="btn-save">Change Password</button></form></div>
            </div>
        </div>
    </div>

    <script>
        function showSection(sectionId) {
            document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
            document.getElementById(sectionId).classList.add('active');
            document.querySelectorAll('.menu-item').forEach(item => {
                item.classList.remove('active');
                if(item.getAttribute('data-section') === sectionId) item.classList.add('active');
            });
        }
        document.querySelectorAll('.menu-item').forEach(item => {
            item.addEventListener('click', function() { showSection(this.getAttribute('data-section')); });
        });
        function toggleNotifications() { document.getElementById('notificationDropdown').classList.toggle('show'); }
        document.addEventListener('click', function(event) {
            const dropdown = document.getElementById('notificationDropdown');
            const icon = document.querySelector('.icon-link');
            if (icon && dropdown && !icon.contains(event.target) && !dropdown.contains(event.target)) dropdown.classList.remove('show');
        });
        setTimeout(() => { document.querySelectorAll('.alert').forEach(alert => alert.style.display = 'none'); }, 5000);
    </script>
</body>
</html>