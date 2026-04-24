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

// Get notification data - FIXED: Use $transport_id instead of $user_id
$unread_count = getUnreadCount($pdo, $transport_id);
$notifications = getRecentNotifications($pdo, $transport_id, 10);

// Check if profile_image column exists
$stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'profile_image'");
$has_profile_image = $stmt->rowCount() > 0;

// Handle Accept Delivery Request
if(isset($_GET['accept']) && isset($_GET['request_id'])) {
    $request_id = $_GET['request_id'];
    
    try {
        $stmt = $pdo->prepare("UPDATE transport_requests SET transport_id = ?, status = 'accepted' WHERE id = ? AND status = 'pending'");
        $stmt->execute([$transport_id, $request_id]);
        
        if($stmt->rowCount() > 0) {
            $stmt = $pdo->prepare("SELECT order_id FROM transport_requests WHERE id = ?");
            $stmt->execute([$request_id]);
            $request = $stmt->fetch();
            
            $stmt = $pdo->prepare("UPDATE orders SET status = 'shipped' WHERE id = ?");
            $stmt->execute([$request['order_id']]);
            
            // Send notification to buyer
            $stmt = $pdo->prepare("SELECT buyer_id FROM orders WHERE id = ?");
            $stmt->execute([$request['order_id']]);
            $order = $stmt->fetch();
            
            notifyUser($pdo, $order['buyer_id'], 
                "🚚 Delivery on the Way!", 
                "Your order #" . $request['order_id'] . " has been picked up and is on its way.",
                "delivery", 
                "../delivery/track.php?order_id=" . $request['order_id']
            );
            
            $success = "Delivery request accepted successfully!";
        } else {
            $error = "Request already taken or invalid!";
        }
    } catch(PDOException $e) {
        $error = "Failed to accept request: " . $e->getMessage();
    }
}

// Handle Complete Delivery
if(isset($_GET['complete']) && isset($_GET['request_id'])) {
    $request_id = $_GET['request_id'];
    
    try {
        $stmt = $pdo->prepare("UPDATE transport_requests SET status = 'completed' WHERE id = ? AND transport_id = ?");
        $stmt->execute([$request_id, $transport_id]);
        
        if($stmt->rowCount() > 0) {
            $stmt = $pdo->prepare("SELECT order_id FROM transport_requests WHERE id = ?");
            $stmt->execute([$request_id]);
            $request = $stmt->fetch();
            
            $stmt = $pdo->prepare("UPDATE orders SET status = 'delivered' WHERE id = ?");
            $stmt->execute([$request['order_id']]);
            
            // Send notification to buyer
            $stmt = $pdo->prepare("SELECT buyer_id FROM orders WHERE id = ?");
            $stmt->execute([$request['order_id']]);
            $order = $stmt->fetch();
            
            notifyUser($pdo, $order['buyer_id'], 
                "📦 Order Delivered!", 
                "Your order #" . $request['order_id'] . " has been delivered successfully.",
                "delivery", 
                "../dashboard/user.php?section=orders"
            );
            
            $success = "Delivery completed successfully!";
        } else {
            $error = "Failed to complete delivery!";
        }
    } catch(PDOException $e) {
        $error = "Failed to complete delivery: " . $e->getMessage();
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

// Get user info
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$transport_id]);
$user = $stmt->fetch();

// Set profile image
$profile_image = 'https://ui-avatars.com/api/?background=ef6c00&color=fff&name=' . urlencode($user['username']);
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
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #0f2027, #203a43, #2c5364);
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* Notification Styles */
        .notification-icon {
            position: relative;
            cursor: pointer;
            transition: transform 0.3s;
        }
        .notification-icon:hover {
            transform: scale(1.1);
        }
        .notification-badge {
            position: absolute;
            top: -8px;
            right: -8px;
            background: #f44336;
            color: white;
            border-radius: 50%;
            padding: 2px 6px;
            font-size: 10px;
            min-width: 18px;
            text-align: center;
            animation: pulse 1.5s infinite;
            font-weight: bold;
        }
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.2); }
        }
        .notification-dropdown {
            position: absolute;
            top: 55px;
            right: 20px;
            width: 400px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            z-index: 1000;
            display: none;
            max-height: 500px;
            overflow-y: auto;
            border: 1px solid #e0e0e0;
        }
        .notification-dropdown.show {
            display: block;
            animation: slideDown 0.3s ease;
        }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .notification-header {
            padding: 15px 20px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, #ef6c00, #e65100);
            border-radius: 12px 12px 0 0;
            color: white;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .notification-header strong {
            font-size: 1rem;
        }
        .mark-all-btn {
            font-size: 0.75rem;
            color: #ffd700;
            text-decoration: none;
            transition: opacity 0.3s;
        }
        .mark-all-btn:hover {
            opacity: 0.8;
        }
        .notification-list {
            max-height: 420px;
            overflow-y: auto;
        }
        .notification-item {
            padding: 15px 20px;
            border-bottom: 1px solid #f0f0f0;
            transition: background 0.3s;
            text-decoration: none;
            display: block;
            cursor: pointer;
        }
        .notification-item:hover {
            background: #f9f9f9;
        }
        .notification-item.unread {
            background: #e3f2fd;
            border-left: 3px solid #2196f3;
        }
        .notification-title {
            font-weight: bold;
            margin-bottom: 5px;
            color: #333;
            font-size: 0.95rem;
        }
        .notification-message {
            font-size: 0.85rem;
            color: #666;
            margin-bottom: 5px;
            line-height: 1.4;
        }
        .notification-time {
            font-size: 0.7rem;
            color: #999;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .mark-read {
            float: right;
            font-size: 0.7rem;
            color: #2196f3;
            text-decoration: none;
            padding: 2px 8px;
            border-radius: 10px;
            background: #e3f2fd;
        }
        .mark-read:hover {
            background: #bbdef5;
        }
        .empty-notifications {
            text-align: center;
            padding: 50px 20px;
            color: #999;
        }
        .empty-notifications i {
            font-size: 3rem;
            margin-bottom: 15px;
            color: #ccc;
        }
        .notification-footer {
            padding: 10px 20px;
            border-top: 1px solid #e0e0e0;
            text-align: center;
            background: #f8f9fa;
            border-radius: 0 0 12px 12px;
        }
        .view-all-link {
            text-decoration: none;
            color: #ef6c00;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .notification-type-product { border-left-color: #4caf50 !important; }
        .notification-type-order { border-left-color: #ff9800 !important; }
        .notification-type-payment { border-left-color: #2196f3 !important; }
        .notification-type-delivery { border-left-color: #9c27b0 !important; }

        /* Animated Background */
        .bg-animation {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            overflow: hidden;
        }

        .bg-animation .circle {
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.05);
            animation: float 20s infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-50px) rotate(180deg); }
        }

        /* Sidebar Styles - Transport Theme */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100%;
            background: linear-gradient(135deg, #1a2980, #26d0ce);
            color: white;
            box-shadow: 5px 0 30px rgba(0,0,0,0.3);
            transition: all 0.3s;
            z-index: 1000;
            backdrop-filter: blur(10px);
        }

        .sidebar-header {
            padding: 30px 20px;
            text-align: center;
            border-bottom: 2px solid rgba(255,255,255,0.2);
        }

        .sidebar-header i {
            font-size: 3.5rem;
            margin-bottom: 10px;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }

        .sidebar-header h3 {
            font-size: 1.3rem;
            margin-bottom: 5px;
        }

        .sidebar-header p {
            font-size: 0.85rem;
            opacity: 0.9;
        }

        .sidebar-menu {
            padding: 20px 0;
        }

        .menu-item {
            padding: 12px 25px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 12px;
            position: relative;
            overflow: hidden;
        }

        .menu-item::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            width: 0;
            height: 100%;
            background: rgba(255,255,255,0.1);
            transition: width 0.3s;
        }

        .menu-item:hover::before {
            width: 100%;
        }

        .menu-item:hover {
            padding-left: 30px;
        }

        .menu-item.active {
            background: rgba(255,255,255,0.2);
            border-left: 4px solid #ffd700;
        }

        .menu-item i {
            width: 25px;
            font-size: 1.2rem;
            z-index: 1;
        }

        .menu-item span {
            z-index: 1;
        }

        .badge {
            background: #ff5722;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
            margin-left: auto;
            z-index: 1;
            animation: bounce 1s infinite;
        }

        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-3px); }
        }

        /* Main Content */
        .main-content {
            margin-left: 280px;
            padding: 20px;
        }

        /* Top Navbar */
        .top-navbar {
            background: rgba(255,255,255,0.95);
            padding: 15px 30px;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            backdrop-filter: blur(10px);
            position: sticky;
            top: 0;
            z-index: 999;
        }

        .page-title h2 {
            background: linear-gradient(135deg, #1a2980, #26d0ce);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-size: 1.5rem;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 20px;
            position: relative;
        }

        .user-info img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
        }

        .logout-btn {
            background: linear-gradient(135deg, #f44336, #d32f2f);
            color: white;
            padding: 8px 20px;
            border-radius: 25px;
            text-decoration: none;
            transition: all 0.3s;
        }

        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(244,67,54,0.3);
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: rgba(255,255,255,0.95);
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: all 0.3s;
            backdrop-filter: blur(10px);
        }

        .stat-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 40px rgba(0,0,0,0.2);
        }

        .stat-info h3 {
            font-size: 0.85rem;
            color: #666;
            margin-bottom: 5px;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            background: linear-gradient(135deg, #1a2980, #26d0ce);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .stat-icon i {
            font-size: 2.5rem;
            color: #26d0ce;
            opacity: 0.7;
        }

        /* Sections */
        .section {
            background: rgba(255,255,255,0.95);
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            display: none;
            backdrop-filter: blur(10px);
        }

        .section.active {
            display: block;
            animation: fadeInUp 0.6s ease;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .section-title {
            font-size: 1.5rem;
            background: linear-gradient(135deg, #1a2980, #26d0ce);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e0e0e0;
        }

        /* Delivery Cards Grid */
        .delivery-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 25px;
            margin-top: 20px;
        }

        .delivery-card {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            transition: all 0.3s;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            position: relative;
        }

        .delivery-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 40px rgba(0,0,0,0.2);
        }

        .delivery-header {
            background: linear-gradient(135deg, #1a2980, #26d0ce);
            color: white;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .delivery-header h4 {
            margin: 0;
        }

        .delivery-badge {
            background: rgba(255,255,255,0.3);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
        }

        .delivery-body {
            padding: 20px;
        }

        .delivery-info {
            margin-bottom: 15px;
        }

        .info-row {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 12px;
            padding: 8px;
            background: #f8f9fa;
            border-radius: 10px;
            transition: all 0.3s;
        }

        .info-row:hover {
            background: #e8f5e9;
            transform: translateX(5px);
        }

        .info-row i {
            width: 25px;
            background: linear-gradient(135deg, #1a2980, #26d0ce);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-top: 3px;
        }

        .info-row strong {
            color: #333;
            min-width: 100px;
        }

        .info-row span {
            color: #666;
            flex: 1;
        }

        .delivery-actions {
            padding: 15px 20px;
            border-top: 1px solid #e0e0e0;
            display: flex;
            gap: 10px;
        }

        .btn-accept {
            background: linear-gradient(135deg, #4caf50, #45a049);
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 25px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            flex: 1;
            justify-content: center;
        }

        .btn-complete {
            background: linear-gradient(135deg, #2196f3, #1976d2);
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 25px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            flex: 1;
            justify-content: center;
        }

        .btn-accept:hover, .btn-complete:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        /* Tables */
        .data-table {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }

        th {
            background: linear-gradient(135deg, #1a2980, #26d0ce);
            color: white;
            font-weight: 600;
        }

        tr:hover {
            background: #f5f5f5;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .status-pending { background: #ff9800; color: white; animation: blink 1s infinite; }
        .status-accepted { background: #2196f3; color: white; }
        .status-completed { background: #4caf50; color: white; }

        @keyframes blink {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }

        /* Alert Messages */
        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.5s ease;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #4caf50;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #f44336;
        }

        /* Empty States */
        .empty-state {
            text-align: center;
            padding: 50px;
        }

        .empty-state i {
            font-size: 4rem;
            background: linear-gradient(135deg, #1a2980, #26d0ce);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 20px;
        }

        .empty-state p {
            color: #666;
            margin-bottom: 20px;
        }

        /* Welcome Banner */
        .welcome-banner {
            background: linear-gradient(135deg, #1a2980, #26d0ce);
            color: white;
            padding: 30px;
            border-radius: 15px;
            text-align: center;
            margin-bottom: 30px;
            animation: glow 2s infinite;
        }

        @keyframes glow {
            0%, 100% { box-shadow: 0 0 20px rgba(38,208,206,0.3); }
            50% { box-shadow: 0 0 40px rgba(38,208,206,0.6); }
        }

        .welcome-banner i {
            font-size: 3rem;
            margin-bottom: 10px;
        }

        .welcome-banner h3 {
            font-size: 1.5rem;
            margin-bottom: 10px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                left: -280px;
            }
            .main-content {
                margin-left: 0;
            }
            .delivery-grid {
                grid-template-columns: 1fr;
            }
            .stats-grid {
                grid-template-columns: 1fr;
            }
            .notification-dropdown {
                width: 320px;
                right: 10px;
            }
        }
    </style>
</head>
<body>
    <!-- Animated Background -->
    <div class="bg-animation">
        <div class="circle" style="width: 300px; height: 300px; top: 10%; left: -100px;"></div>
        <div class="circle" style="width: 500px; height: 500px; bottom: 20%; right: -150px; animation-duration: 25s;"></div>
        <div class="circle" style="width: 200px; height: 200px; top: 50%; left: 30%; animation-duration: 15s;"></div>
    </div>

    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <i class="fas fa-truck-fast"></i>
            <h3>Transport Hub</h3>
            <p><?php echo htmlspecialchars($_SESSION['username']); ?></p>
        </div>
        <div class="sidebar-menu">
            <div class="menu-item active" data-section="dashboard">
                <i class="fas fa-chart-line"></i>
                <span>Dashboard</span>
            </div>
            <div class="menu-item" data-section="available">
                <i class="fas fa-bell"></i>
                <span>Available Requests</span>
                <?php if($available_requests > 0): ?>
                    <span class="badge"><?php echo $available_requests; ?></span>
                <?php endif; ?>
            </div>
            <div class="menu-item" data-section="active">
                <i class="fas fa-truck-moving"></i>
                <span>Active Deliveries</span>
                <?php if($pending_deliveries > 0): ?>
                    <span class="badge"><?php echo $pending_deliveries; ?></span>
                <?php endif; ?>
            </div>
            <div class="menu-item" data-section="history">
                <i class="fas fa-history"></i>
                <span>Delivery History</span>
            </div>
            <div class="menu-item" data-section="earnings">
                <i class="fas fa-chart-simple"></i>
                <span>Earnings</span>
            </div>
            <div class="menu-item" data-section="profile">
                <i class="fas fa-user-circle"></i>
                <span>My Profile</span>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="top-navbar">
            <div class="page-title">
                <h2><i class="fas fa-truck"></i> Transport Dashboard</h2>
            </div>
            <div class="user-info">
                <!-- Notification Bell -->
                <div class="notification-icon" onclick="toggleNotifications()">
                    <i class="fas fa-bell"></i>
                    <?php if($unread_count > 0): ?>
                        <span class="notification-badge"><?php echo $unread_count; ?></span>
                    <?php endif; ?>
                </div>
                
                <!-- Notification Dropdown -->
                <div class="notification-dropdown" id="notificationDropdown">
                    <div class="notification-header">
                        <strong><i class="fas fa-bell"></i> Notifications</strong>
                        <?php if($unread_count > 0): ?>
                            <a href="?mark_all_read=1" class="mark-all-btn" onclick="return confirm('Mark all notifications as read?')">
                                Mark all as read
                            </a>
                        <?php endif; ?>
                    </div>
                    <div class="notification-list">
                        <?php if(count($notifications) > 0): ?>
                            <?php foreach($notifications as $notif): ?>
                                <a href="<?php echo $notif['link']; ?>" class="notification-item <?php echo $notif['is_read'] ? '' : 'unread'; ?> notification-type-<?php echo $notif['type']; ?>">
                                    <div class="notification-title">
                                        <?php echo $notif['title']; ?>
                                        <?php if(!$notif['is_read']): ?>
                                            <a href="?mark_read=1&notif_id=<?php echo $notif['id']; ?>" class="mark-read" onclick="event.stopPropagation();">Mark read</a>
                                        <?php endif; ?>
                                    </div>
                                    <div class="notification-message"><?php echo $notif['message']; ?></div>
                                    <div class="notification-time">
                                        <i class="fas fa-clock"></i>
                                        <?php 
                                        $time = strtotime($notif['created_at']);
                                        $now = time();
                                        $diff = $now - $time;
                                        if($diff < 60) {
                                            echo "Just now";
                                        } elseif($diff < 3600) {
                                            echo floor($diff/60) . " minutes ago";
                                        } elseif($diff < 86400) {
                                            echo floor($diff/3600) . " hours ago";
                                        } else {
                                            echo date('M d, H:i', $time);
                                        }
                                        ?>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-notifications">
                                <i class="fas fa-bell-slash"></i>
                                <p>No notifications yet</p>
                                <small>When you receive notifications, they'll appear here</small>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <img src="<?php echo $profile_image; ?>" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover;">
                <span><?php echo htmlspecialchars($_SESSION['username']); ?></span>
                <a href="../auth/logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>

        <?php if($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo $success; ?></span>
            </div>
        <?php endif; ?>

        <?php if($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo $error; ?></span>
            </div>
        <?php endif; ?>

        <!-- Dashboard Section -->
        <div id="dashboard" class="section active">
            <div class="welcome-banner">
                <i class="fas fa-truck-fast"></i>
                <h3>Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?>!</h3>
                <p>You're making a difference by delivering fresh products to customers</p>
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Available Requests</h3>
                        <div class="stat-number"><?php echo $available_requests; ?></div>
                        <small>New delivery opportunities</small>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-bell"></i>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Active Deliveries</h3>
                        <div class="stat-number"><?php echo $pending_deliveries; ?></div>
                        <small>In progress</small>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-truck-moving"></i>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Completed Deliveries</h3>
                        <div class="stat-number"><?php echo $completed_deliveries; ?></div>
                        <small>Successfully delivered</small>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Total Earnings</h3>
                        <div class="stat-number">₱<?php echo number_format($total_earnings, 2); ?></div>
                        <small>Delivery fees earned</small>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                </div>
            </div>

            <div style="background: linear-gradient(135deg, #1a2980, #26d0ce); color: white; padding: 30px; border-radius: 15px; text-align: center;">
                <i class="fas fa-trophy" style="font-size: 3rem; margin-bottom: 10px;"></i>
                <h3>Delivery Star!</h3>
                <p>You have completed <?php echo $completed_deliveries; ?> deliveries successfully</p>
                <a href="#" onclick="showSection('available')" style="display: inline-block; margin-top: 15px; background: white; color: #1a2980; padding: 10px 20px; border-radius: 25px; text-decoration: none; font-weight: bold;">
                    <i class="fas fa-bell"></i> View Available Requests
                </a>
            </div>
        </div>

        <!-- Available Requests Section -->
        <div id="available" class="section">
            <h2 class="section-title"><i class="fas fa-bell"></i> Available Delivery Requests</h2>
            <?php if($available_requests_list->rowCount() > 0): ?>
                <div class="delivery-grid">
                    <?php while($request = $available_requests_list->fetch(PDO::FETCH_ASSOC)): ?>
                        <div class="delivery-card">
                            <div class="delivery-header">
                                <h4><i class="fas fa-shopping-cart"></i> Order #<?php echo $request['order_id']; ?></h4>
                                <span class="delivery-badge"><i class="fas fa-clock"></i> New Request</span>
                            </div>
                            <div class="delivery-body">
                                <div class="delivery-info">
                                    <div class="info-row">
                                        <i class="fas fa-box"></i>
                                        <strong>Product:</strong>
                                        <span><?php echo htmlspecialchars($request['product_name']); ?> (<?php echo $request['quantity']; ?> <?php echo $request['unit']; ?>)</span>
                                    </div>
                                    <div class="info-row">
                                        <i class="fas fa-user-tie"></i>
                                        <strong>Farmer:</strong>
                                        <span><?php echo htmlspecialchars($request['farmer_name']); ?> | 📞 <?php echo htmlspecialchars($request['farmer_phone']); ?></span>
                                    </div>
                                    <div class="info-row">
                                        <i class="fas fa-map-marker-alt"></i>
                                        <strong>Pickup:</strong>
                                        <span><?php echo htmlspecialchars($request['pickup_address']); ?></span>
                                    </div>
                                    <div class="info-row">
                                        <i class="fas fa-user"></i>
                                        <strong>Buyer:</strong>
                                        <span><?php echo htmlspecialchars($request['buyer_name']); ?> | 📞 <?php echo htmlspecialchars($request['buyer_phone']); ?></span>
                                    </div>
                                    <div class="info-row">
                                        <i class="fas fa-home"></i>
                                        <strong>Delivery:</strong>
                                        <span><?php echo htmlspecialchars($request['delivery_address']); ?></span>
                                    </div>
                                    <div class="info-row">
                                        <i class="fas fa-tag"></i>
                                        <strong>Order Value:</strong>
                                        <span>₱<?php echo number_format($request['total_price'], 2); ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="delivery-actions">
                                <a href="?accept=1&request_id=<?php echo $request['id']; ?>" class="btn-accept" onclick="return confirm('Accept this delivery request?')">
                                    <i class="fas fa-check"></i> Accept Delivery
                                </a>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>No available delivery requests at the moment.</p>
                    <p>Check back later for new requests!</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Active Deliveries Section -->
        <div id="active" class="section">
            <h2 class="section-title"><i class="fas fa-truck-moving"></i> My Active Deliveries</h2>
            <?php if($active_deliveries->rowCount() > 0): ?>
                <div class="delivery-grid">
                    <?php while($delivery = $active_deliveries->fetch(PDO::FETCH_ASSOC)): ?>
                        <div class="delivery-card">
                            <div class="delivery-header">
                                <h4><i class="fas fa-truck"></i> Delivery #<?php echo $delivery['id']; ?></h4>
                                <span class="delivery-badge"><i class="fas fa-spinner fa-pulse"></i> In Progress</span>
                            </div>
                            <div class="delivery-body">
                                <div class="delivery-info">
                                    <div class="info-row">
                                        <i class="fas fa-box"></i>
                                        <strong>Product:</strong>
                                        <span><?php echo htmlspecialchars($delivery['product_name']); ?> (<?php echo $delivery['quantity']; ?> <?php echo $delivery['unit']; ?>)</span>
                                    </div>
                                    <div class="info-row">
                                        <i class="fas fa-user-tie"></i>
                                        <strong>Farmer:</strong>
                                        <span><?php echo htmlspecialchars($delivery['farmer_name']); ?> | 📞 <?php echo htmlspecialchars($delivery['farmer_phone']); ?></span>
                                    </div>
                                    <div class="info-row">
                                        <i class="fas fa-map-marker-alt"></i>
                                        <strong>Pickup:</strong>
                                        <span><?php echo htmlspecialchars($delivery['pickup_address']); ?></span>
                                    </div>
                                    <div class="info-row">
                                        <i class="fas fa-user"></i>
                                        <strong>Buyer:</strong>
                                        <span><?php echo htmlspecialchars($delivery['buyer_name']); ?> | 📞 <?php echo htmlspecialchars($delivery['buyer_phone']); ?></span>
                                    </div>
                                    <div class="info-row">
                                        <i class="fas fa-home"></i>
                                        <strong>Delivery:</strong>
                                        <span><?php echo htmlspecialchars($delivery['delivery_address']); ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="delivery-actions">
                                <a href="?complete=1&request_id=<?php echo $delivery['id']; ?>" class="btn-complete" onclick="return confirm('Mark this delivery as completed?')">
                                    <i class="fas fa-check-double"></i> Complete Delivery
                                </a>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-truck"></i>
                    <p>No active deliveries.</p>
                    <a href="#" onclick="showSection('available')" class="btn-accept" style="display: inline-block;">
                        <i class="fas fa-bell"></i> Accept New Requests
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Delivery History Section -->
        <div id="history" class="section">
            <h2 class="section-title"><i class="fas fa-history"></i> Delivery History</h2>
            <?php if($delivery_history->rowCount() > 0): ?>
                <div class="data-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Delivery ID</th>
                                <th>Order #</th>
                                <th>Product</th>
                                <th>From (Farmer)</th>
                                <th>To (Buyer)</th>
                                <th>Completed Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($history = $delivery_history->fetch(PDO::FETCH_ASSOC)): ?>
                                <tr>
                                    <td>#<?php echo $history['id']; ?></td>
                                    <td>#<?php echo $history['order_id']; ?></td>
                                    <td><?php echo htmlspecialchars($history['product_name']); ?></td>
                                    <td><?php echo htmlspecialchars($history['farmer_name']); ?></td>
                                    <td><?php echo htmlspecialchars($history['buyer_name']); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($history['created_at'])); ?></td>
                                    <td><span class="status-badge status-completed"><i class="fas fa-check-circle"></i> Completed</span></td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-history"></i>
                    <p>No delivery history yet.</p>
                    <p>Start accepting deliveries to build your history!</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Earnings Section -->
        <div id="earnings" class="section">
            <h2 class="section-title"><i class="fas fa-chart-line"></i> Earnings Overview</h2>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Total Earnings</h3>
                        <div class="stat-number">₱<?php echo number_format($total_earnings, 2); ?></div>
                        <small>Overall earnings</small>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Completed Deliveries</h3>
                        <div class="stat-number"><?php echo $completed_deliveries; ?></div>
                        <small>Successful deliveries</small>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Average per Delivery</h3>
                        <div class="stat-number">₱50.00</div>
                        <small>Per delivery fee</small>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                </div>
            </div>

            <div style="background: linear-gradient(135deg, #1a2980, #26d0ce); color: white; padding: 30px; border-radius: 15px; text-align: center; margin-top: 20px;">
                <i class="fas fa-chart-simple" style="font-size: 3rem; margin-bottom: 10px;"></i>
                <h3>Keep Up the Great Work!</h3>
                <p>You've earned ₱<?php echo number_format($total_earnings, 2); ?> from <?php echo $completed_deliveries; ?> deliveries</p>
                <div style="margin-top: 20px;">
                    <div style="background: rgba(255,255,255,0.2); border-radius: 10px; padding: 10px;">
                        <small>Next milestone: <?php echo 10 - ($completed_deliveries % 10); ?> more deliveries to earn a bonus!</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Profile Section -->
        <div id="profile" class="section">
            <h2 class="section-title"><i class="fas fa-user-circle"></i> My Profile</h2>
            <div class="delivery-grid">
                <div class="delivery-card">
                    <div class="delivery-header">
                        <h4><i class="fas fa-user"></i> Personal Information</h4>
                    </div>
                    <div class="delivery-body">
                        <div class="info-row">
                            <i class="fas fa-user"></i>
                            <strong>Username:</strong>
                            <span><?php echo htmlspecialchars($user['username']); ?></span>
                        </div>
                        <div class="info-row">
                            <i class="fas fa-envelope"></i>
                            <strong>Email:</strong>
                            <span><?php echo htmlspecialchars($user['email']); ?></span>
                        </div>
                        <div class="info-row">
                            <i class="fas fa-phone"></i>
                            <strong>Phone:</strong>
                            <span><?php echo htmlspecialchars($user['phone'] ?: 'Not provided'); ?></span>
                        </div>
                        <div class="info-row">
                            <i class="fas fa-map-marker-alt"></i>
                            <strong>Address:</strong>
                            <span><?php echo htmlspecialchars($user['address'] ?: 'Not provided'); ?></span>
                        </div>
                        <div class="info-row">
                            <i class="fas fa-calendar"></i>
                            <strong>Member since:</strong>
                            <span><?php echo date('F d, Y', strtotime($user['created_at'])); ?></span>
                        </div>
                    </div>
                </div>
                <div class="delivery-card">
                    <div class="delivery-header">
                        <h4><i class="fas fa-chart-bar"></i> Performance Stats</h4>
                    </div>
                    <div class="delivery-body">
                        <div class="info-row">
                            <i class="fas fa-truck"></i>
                            <strong>Total Deliveries:</strong>
                            <span><?php echo $completed_deliveries; ?></span>
                        </div>
                        <div class="info-row">
                            <i class="fas fa-star"></i>
                            <strong>Completion Rate:</strong>
                            <span><?php echo $completed_deliveries > 0 ? '100%' : '0%'; ?></span>
                        </div>
                        <div class="info-row">
                            <i class="fas fa-tachometer-alt"></i>
                            <strong>Active Deliveries:</strong>
                            <span><?php echo $pending_deliveries; ?></span>
                        </div>
                        <div class="info-row">
                            <i class="fas fa-money-bill-wave"></i>
                            <strong>Total Earnings:</strong>
                            <span>₱<?php echo number_format($total_earnings, 2); ?></span>
                        </div>
                        <div class="info-row">
                            <i class="fas fa-award"></i>
                            <strong>Rating:</strong>
                            <span><i class="fas fa-star" style="color: #ffd700;"></i> <i class="fas fa-star" style="color: #ffd700;"></i> <i class="fas fa-star" style="color: #ffd700;"></i> <i class="fas fa-star" style="color: #ffd700;"></i> <i class="fas fa-star" style="color: #ffd700;"></i> (5.0)</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Section switching
        function showSection(sectionId) {
            document.querySelectorAll('.section').forEach(section => {
                section.classList.remove('active');
            });
            document.getElementById(sectionId).classList.add('active');
            
            document.querySelectorAll('.menu-item').forEach(item => {
                item.classList.remove('active');
                if(item.getAttribute('data-section') === sectionId) {
                    item.classList.add('active');
                }
            });
        }

        // Add click handlers to menu items
        document.querySelectorAll('.menu-item').forEach(item => {
            item.addEventListener('click', function() {
                const sectionId = this.getAttribute('data-section');
                showSection(sectionId);
            });
        });

        // Toggle notifications dropdown
        function toggleNotifications() {
            const dropdown = document.getElementById('notificationDropdown');
            dropdown.classList.toggle('show');
        }
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            const dropdown = document.getElementById('notificationDropdown');
            const icon = document.querySelector('.notification-icon');
            if (icon && dropdown && !icon.contains(event.target) && !dropdown.contains(event.target)) {
                dropdown.classList.remove('show');
            }
        });

        function markAsRead(notifId) {
            fetch('../ajax/mark_notification_read.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'notification_id=' + notifId
            })
            .then(response => response.json())
            .then(data => {
                if(data.success) {
                    location.reload();
                }
            });
        }

        function markAllNotificationsRead() {
            if(confirm('Mark all notifications as read?')) {
                fetch('../ajax/mark_all_read.php', {
                    method: 'POST'
                })
                .then(response => response.json())
                .then(data => {
                    if(data.success) {
                        location.reload();
                    }
                });
            }
        }

        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.display = 'none';
            });
        }, 5000);

        // Add animation to stat cards on scroll
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if(entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);

        document.querySelectorAll('.stat-card, .delivery-card').forEach(el => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(30px)';
            el.style.transition = 'all 0.6s ease';
            observer.observe(el);
        });

        // Real-time clock update
        function updateClock() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
            const dateString = now.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            const clockElement = document.querySelector('.top-navbar .user-info');
            if(clockElement && !document.querySelector('.clock-display')) {
                const clockDiv = document.createElement('div');
                clockDiv.className = 'clock-display';
                clockDiv.style.cssText = 'margin-right: 15px; font-size: 0.9rem; color: #666;';
                clockDiv.innerHTML = `<i class="fas fa-calendar-alt"></i> ${dateString} | <i class="fas fa-clock"></i> ${timeString}`;
                clockElement.insertBefore(clockDiv, clockElement.firstChild);
                
                setInterval(() => {
                    const now = new Date();
                    const newTimeString = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
                    const newDateString = now.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                    clockDiv.innerHTML = `<i class="fas fa-calendar-alt"></i> ${newDateString} | <i class="fas fa-clock"></i> ${newTimeString}`;
                }, 60000);
            }
        }
        updateClock();
        
        // Auto-refresh notifications every 30 seconds
        setInterval(function() {
            fetch('../ajax/get_notification_count.php')
                .then(response => response.json())
                .then(data => {
                    const badge = document.querySelector('.notification-badge');
                    if(data.count > 0) {
                        if(badge) {
                            badge.textContent = data.count;
                        } else {
                            const icon = document.querySelector('.notification-icon');
                            const newBadge = document.createElement('span');
                            newBadge.className = 'notification-badge';
                            newBadge.textContent = data.count;
                            icon.appendChild(newBadge);
                        }
                    } else if(badge) {
                        badge.remove();
                    }
                })
                .catch(error => console.log('Error:', error));
        }, 30000);
    </script>
</body>
</html>