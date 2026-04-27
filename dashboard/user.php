
<?php
// Include database connection
$pdo = require_once __DIR__ . '/../config/db.php';

// Check if user is logged in and is a buyer
if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'user') {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$success = '';
$error = '';

// Include notification functions
require_once __DIR__ . '/../includes/notification_functions.php';

// Get notification data
$unread_count = getUnreadCount($pdo, $user_id);
$notifications = getRecentNotifications($pdo, $user_id, 10);

// Get unread message count (with error handling - returns 0 if table doesn't exist)
$unread_msg_count = 0;
try {
    // Check if messages table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'messages'");
    if($stmt->rowCount() > 0) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
        $stmt->execute([$user_id]);
        $unread_msg_count = $stmt->fetchColumn();
    }
} catch(PDOException $e) {
    $unread_msg_count = 0;
}



// Check if profile_image column exists
$stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'profile_image'");
$has_profile_image = $stmt->rowCount() > 0;

// Check if product_image column exists
$stmt = $pdo->query("SHOW COLUMNS FROM products LIKE 'product_image'");
$has_image_column = $stmt->rowCount() > 0;

// Get user info
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Handle Profile Update
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    $fullname = trim($_POST['fullname']);
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);
    $email = trim($_POST['email']);
    
    if(empty($fullname) || empty($phone) || empty($address)) {
        $error = "Please fill in all required fields!";
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE users SET username = ?, phone = ?, address = ?, email = ? WHERE id = ?");
            $stmt->execute([$fullname, $phone, $address, $email, $user_id]);
            $success = "Profile updated successfully!";
            
            // Update session username
            $_SESSION['username'] = $fullname;
            
            // Refresh user data
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
        } catch(PDOException $e) {
            $error = "Failed to update profile: " . $e->getMessage();
        }
    }
}

// Handle Password Change
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    if(empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $error = "Please fill in all password fields!";
    } elseif($new_password !== $confirm_password) {
        $error = "New passwords do not match!";
    } elseif(strlen($new_password) < 6) {
        $error = "Password must be at least 6 characters long!";
    } else {
        // Verify current password
        if(password_verify($current_password, $user['password'])) {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashed_password, $user_id]);
            $success = "Password changed successfully!";
        } else {
            $error = "Current password is incorrect!";
        }
    }
}

// Handle Profile Image Upload
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['upload_image']) && $has_profile_image) {
    if(isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] == 0) {
        $upload_dir = __DIR__ . '/../assets/uploads/profiles/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $allowed = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif', 'image/webp'];
        if (!in_array($_FILES['profile_image']['type'], $allowed)) {
            $error = "Invalid file type. Only JPG, PNG, GIF, WEBP allowed.";
        } elseif ($_FILES['profile_image']['size'] > 5 * 1024 * 1024) {
            $error = "File too large. Max 5MB.";
        } else {
            $ext = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
            $filename = 'buyer_' . $user_id . '_' . time() . '.' . $ext;
            $filepath = 'assets/uploads/profiles/' . $filename;
            
            if (move_uploaded_file($_FILES['profile_image']['tmp_name'], __DIR__ . '/../' . $filepath)) {
                // Delete old image if exists
                if(!empty($user['profile_image']) && file_exists(__DIR__ . '/../' . $user['profile_image'])) {
                    unlink(__DIR__ . '/../' . $user['profile_image']);
                }
                
                $stmt = $pdo->prepare("UPDATE users SET profile_image = ? WHERE id = ?");
                $stmt->execute([$filepath, $user_id]);
                $success = "Profile picture updated successfully!";
                
                // Refresh user data
                $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch();
            } else {
                $error = "Failed to upload image!";
            }
        }
    } else {
        $error = "Please select an image to upload!";
    }
}

// Handle Purchase - Redirect to payment page
if(isset($_GET['buy']) && isset($_GET['id'])) {
    $product_id = $_GET['id'];
    
    try {
        // Get product details
        $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND status = 'available'");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch();
        
        if($product) {
            // Create order
            $stmt = $pdo->prepare("
                INSERT INTO orders (product_id, buyer_id, quantity, total_price, delivery_address, status) 
                VALUES (?, ?, ?, ?, ?, 'pending')
            ");
            $stmt->execute([
                $product_id, 
                $user_id, 
                $product['quantity'], 
                $product['price'] * $product['quantity'], 
                $user['address'] ?? ''
            ]);
            $order_id = $pdo->lastInsertId();
            
            // Update product status to pending
            $stmt = $pdo->prepare("UPDATE products SET status = 'pending' WHERE id = ?");
            $stmt->execute([$product_id]);
            
            // Send notification to farmer
            sendNotification($pdo, $product['farmer_id'], 
                "🛒 New Order Received!", 
                $_SESSION['username'] . " has ordered your product: " . $product['name'],
                "order", 
                "../dashboard/farmer.php?section=orders"
            );
            
            // Send notification to buyer (confirmation)
            sendNotification($pdo, $user_id,
                "✅ Order Placed Successfully!",
                "Your order for " . $product['name'] . " has been placed. Please complete payment.",
                "order",
                "../payment/process_payment.php?order_id=" . $order_id
            );
            
            // Redirect to payment page
            header("Location: ../payment/process_payment.php?order_id=" . $order_id);
            exit();
        } else {
            $error = "Product is no longer available!";
        }
    } catch(PDOException $e) {
        $error = "Failed to place order: " . $e->getMessage();
    }
}

// Handle Cancel Order
if(isset($_GET['cancel']) && isset($_GET['order_id'])) {
    $order_id = $_GET['order_id'];
    
    try {
        // Get order details
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND buyer_id = ? AND status = 'pending'");
        $stmt->execute([$order_id, $user_id]);
        $order = $stmt->fetch();
        
        if($order) {
            // Update product status back to available
            $stmt = $pdo->prepare("UPDATE products SET status = 'available' WHERE id = ?");
            $stmt->execute([$order['product_id']]);
            
            // Cancel order
            $stmt = $pdo->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ?");
            $stmt->execute([$order_id]);
            
            // Send notification
            sendNotification($pdo, $user_id,
                "❌ Order Cancelled",
                "Your order has been cancelled successfully.",
                "order",
                "user.php?section=orders"
            );
            
            $success = "Order cancelled successfully!";
        } else {
            $error = "Cannot cancel this order!";
        }
    } catch(PDOException $e) {
        $error = "Failed to cancel order: " . $e->getMessage();
    }
}

// Handle notification actions
if(isset($_GET['mark_read']) && isset($_GET['notif_id'])) {
    markNotificationRead($pdo, $_GET['notif_id'], $user_id);
    header("Location: user.php");
    exit();
}

if(isset($_GET['mark_all_read'])) {
    markAllNotificationsRead($pdo, $user_id);
    header("Location: user.php");
    exit();
}

// Get user's orders with product images
$orders = $pdo->prepare("
    SELECT o.*, p.name as product_name, p.price as product_price, p.unit, p.product_image,
           u.username as farmer_name, u.phone as farmer_phone
    FROM orders o 
    JOIN products p ON o.product_id = p.id 
    JOIN users u ON p.farmer_id = u.id 
    WHERE o.buyer_id = ? 
    ORDER BY o.order_date DESC
");
$orders->execute([$user_id]);

// Get available products with images
$products = $pdo->query("
    SELECT p.*, u.username as farmer_name, u.phone as farmer_phone, u.address as farmer_address
    FROM products p 
    JOIN users u ON p.farmer_id = u.id 
    WHERE p.status = 'available' 
    ORDER BY p.created_at DESC
");

// Get statistics
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM orders WHERE buyer_id = ?");
$stmt->execute([$user_id]);
$total_orders = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM orders WHERE buyer_id = ? AND status = 'pending'");
$stmt->execute([$user_id]);
$pending_orders = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM orders WHERE buyer_id = ? AND status = 'delivered'");
$stmt->execute([$user_id]);
$delivered_orders = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT SUM(total_price) as total FROM orders WHERE buyer_id = ? AND status = 'delivered'");
$stmt->execute([$user_id]);
$total_spent = $stmt->fetchColumn() ?: 0;

// Get total notifications count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ?");
$stmt->execute([$user_id]);
$total_notifications = $stmt->fetchColumn();

// Set profile image
$profile_image = 'https://ui-avatars.com/api/?background=1976d2&color=fff&name=' . urlencode($user['username']);
if($has_profile_image && !empty($user['profile_image']) && file_exists(__DIR__ . '/../' . $user['profile_image'])) {
    $profile_image = '../' . $user['profile_image'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buyer Dashboard - Agriculture Marketplace</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            overflow-x: hidden;
        }

        /* Icon and Badge Styles */
        .icon-link {
            position: relative;
            text-decoration: none;
            color: #333;
            margin-right: 15px;
        }
        .icon-link i {
            font-size: 1.3rem;
        }
        .icon-link:hover {
            color: #1976d2;
        }
        .badge {
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
        }
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.2); }
        }

        /* Notification Dropdown */
        .notification-dropdown {
            position: absolute;
            top: 55px;
            right: 20px;
            width: 380px;
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
            background: linear-gradient(135deg, #1976d2, #1565c0);
            border-radius: 12px 12px 0 0;
            color: white;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .mark-all-btn {
            font-size: 0.75rem;
            color: #ffd700;
            text-decoration: none;
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
        .notification-type-product { border-left-color: #4caf50 !important; }
        .notification-type-order { border-left-color: #ff9800 !important; }
        .notification-type-payment { border-left-color: #2196f3 !important; }
        .notification-type-delivery { border-left-color: #9c27b0 !important; }
        .notification-type-message { border-left-color: #00bcd4 !important; }

        /* Sidebar Styles */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100%;
            background: linear-gradient(135deg, #1565c0, #1976d2);
            color: white;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
            transition: all 0.3s;
            z-index: 1000;
        }

        .sidebar-header {
            padding: 30px 20px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .sidebar-header i {
            font-size: 3rem;
            margin-bottom: 10px;
        }

        .sidebar-header h3 {
            font-size: 1.3rem;
            margin-bottom: 5px;
        }

        .sidebar-header p {
            font-size: 0.85rem;
            opacity: 0.8;
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
        }

        .menu-item:hover {
            background: rgba(255,255,255,0.1);
            padding-left: 30px;
        }

        .menu-item.active {
            background: rgba(255,255,255,0.2);
            border-left: 4px solid #ff9800;
        }

        .menu-item i {
            width: 25px;
            font-size: 1.2rem;
        }

        /* Main Content */
        .main-content {
            margin-left: 280px;
            padding: 20px;
        }

        /* Top Navbar */
        .top-navbar {
            background: white;
            padding: 15px 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            position: sticky;
            top: 0;
            z-index: 999;
        }

        .page-title h2 {
            color: #1976d2;
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
            background: #f44336;
            color: white;
            padding: 8px 20px;
            border-radius: 5px;
            text-decoration: none;
            transition: all 0.3s;
        }

        .logout-btn:hover {
            background: #d32f2f;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: all 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
        }

        .stat-info h3 {
            font-size: 0.9rem;
            color: #666;
            margin-bottom: 5px;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #1976d2;
        }

        .stat-icon i {
            font-size: 3rem;
            color: #42a5f5;
            opacity: 0.7;
        }

        /* Sections */
        .section {
            background: white;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: none;
        }

        .section.active {
            display: block;
            animation: fadeIn 0.5s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .section-title {
            font-size: 1.5rem;
            color: #1976d2;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e0e0e0;
        }

        /* Profile Section Styles */
        .profile-container {
            background: white;
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 25px;
        }
        
        .profile-header {
            display: flex;
            align-items: center;
            gap: 25px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }
        
        .profile-avatar {
            position: relative;
            display: inline-block;
        }
        
        .profile-avatar img {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid #1976d2;
        }
        
        .profile-avatar .edit-photo {
            position: absolute;
            bottom: 5px;
            right: 5px;
            background: #1976d2;
            color: white;
            border-radius: 50%;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .profile-avatar .edit-photo:hover {
            transform: scale(1.1);
        }
        
        .profile-info h2 {
            color: #1976d2;
            margin-bottom: 5px;
        }
        
        .profile-info p {
            color: #666;
        }
        
        .profile-stats {
            display: flex;
            gap: 30px;
            margin-top: 15px;
        }
        
        .profile-stats .stat {
            text-align: center;
        }
        
        .profile-stats .stat .number {
            font-size: 1.5rem;
            font-weight: bold;
            color: #1976d2;
        }
        
        .profile-stats .stat .label {
            font-size: 0.75rem;
            color: #666;
        }
        
        .profile-form {
            margin-top: 25px;
            padding-top: 25px;
            border-top: 1px solid #e0e0e0;
        }
        
        .profile-form .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 20px;
        }
        
        .btn-save {
            background: linear-gradient(135deg, #1976d2, #1565c0);
            color: white;
            padding: 10px 25px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-save:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(25,118,210,0.3);
        }

        /* Product Grid */
        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 25px;
            margin-top: 20px;
        }

        .product-card {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            transition: all 0.3s;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            position: relative;
        }

        .product-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 40px rgba(0,0,0,0.2);
        }

        .product-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            background: #4caf50;
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: bold;
            z-index: 10;
        }

        .product-image {
            height: 220px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        .product-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s;
        }

        .product-card:hover .product-image img {
            transform: scale(1.1);
        }

        .product-image i {
            font-size: 4rem;
            color: rgba(255,255,255,0.8);
        }

        .product-info {
            padding: 20px;
        }

        .product-info h3 {
            font-size: 1.3rem;
            color: #1976d2;
            margin-bottom: 10px;
        }

        .farmer-info {
            color: #666;
            font-size: 0.85rem;
            margin: 5px 0;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .farmer-info i {
            color: #ff9800;
            width: 20px;
        }

        .category {
            display: inline-block;
            background: #e3f2fd;
            color: #1976d2;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            margin: 10px 0;
        }

        .quantity {
            color: #666;
            font-size: 0.9rem;
            margin: 5px 0;
        }

        .price {
            font-size: 1.8rem;
            color: #ff9800;
            font-weight: bold;
            margin: 10px 0;
        }

        .description {
            color: #999;
            font-size: 0.85rem;
            margin: 10px 0;
            line-height: 1.4;
        }

        .btn-buy {
            display: inline-block;
            background: linear-gradient(135deg, #4caf50, #45a049);
            color: white;
            padding: 12px 20px;
            text-decoration: none;
            border-radius: 8px;
            margin-top: 10px;
            width: 100%;
            text-align: center;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
            font-weight: bold;
            font-size: 1rem;
        }

        .btn-buy:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(76,175,80,0.3);
        }

        .btn-pay {
            background: linear-gradient(135deg, #ff9800, #f57c00);
            color: white;
            padding: 5px 12px;
            border-radius: 5px;
            text-decoration: none;
            font-size: 0.85rem;
            display: inline-block;
            margin-right: 5px;
            transition: all 0.3s;
        }

        .btn-pay:hover {
            transform: translateY(-2px);
            box-shadow: 0 3px 10px rgba(255,152,0,0.3);
        }

        .btn-track {
            background: #2196f3;
            color: white;
            padding: 5px 12px;
            border-radius: 5px;
            text-decoration: none;
            font-size: 0.85rem;
            display: inline-block;
            transition: all 0.3s;
        }

        .btn-track:hover {
            transform: translateY(-2px);
            box-shadow: 0 3px 10px rgba(33,150,243,0.3);
        }

        /* Orders Table */
        .orders-table {
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
            background: #f5f5f5;
            color: #333;
            font-weight: 600;
        }

        tr:hover {
            background: #f9f9f9;
        }

        .product-thumb {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 8px;
            margin-right: 10px;
            vertical-align: middle;
        }

        .order-status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .status-pending { background: #ff9800; color: white; }
        .status-confirmed { background: #2196f3; color: white; }
        .status-shipped { background: #9c27b0; color: white; }
        .status-delivered { background: #4caf50; color: white; }
        .status-cancelled { background: #f44336; color: white; }

        .btn-cancel {
            background: #f44336;
            color: white;
            padding: 5px 12px;
            border-radius: 5px;
            text-decoration: none;
            font-size: 0.85rem;
            transition: all 0.3s;
            display: inline-block;
        }

        .btn-cancel:hover {
            background: #d32f2f;
        }

        /* Alert Messages */
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
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
            color: #ccc;
            margin-bottom: 20px;
        }

        .empty-state p {
            color: #666;
            margin-bottom: 20px;
        }

        /* Search Bar */
        .search-bar {
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
        }

        .search-input {
            flex: 1;
            padding: 10px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1rem;
        }

        .search-input:focus {
            outline: none;
            border-color: #1976d2;
        }

        .search-btn {
            padding: 10px 20px;
            background: #1976d2;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            justify-content: center;
            align-items: center;
            z-index: 2000;
        }
        
        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 20px;
            max-width: 400px;
            width: 90%;
            text-align: center;
        }
        
        .modal-content input {
            margin: 15px 0;
            padding: 10px;
            width: 100%;
            border: 1px solid #ddd;
            border-radius: 8px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                left: -280px;
            }
            .main-content {
                margin-left: 0;
            }
            .product-grid {
                grid-template-columns: 1fr;
            }
            .stats-grid {
                grid-template-columns: 1fr;
            }
            .product-thumb {
                width: 40px;
                height: 40px;
            }
            .action-buttons {
                display: flex;
                flex-direction: column;
                gap: 5px;
            }
            .profile-header {
                flex-direction: column;
                text-align: center;
            }
            .profile-stats {
                justify-content: center;
            }
            .profile-form .form-row {
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
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <i class="fas fa-user"></i>
            <h3>Buyer Panel</h3>
            <p><?php echo htmlspecialchars($_SESSION['username']); ?></p>
        </div>
        <div class="sidebar-menu">
            <div class="menu-item active" data-section="dashboard">
                <i class="fas fa-chart-line"></i>
                <span>Dashboard</span>
            </div>
            <div class="menu-item" data-section="products">
                <i class="fas fa-store"></i>
                <span>Browse Products</span>
            </div>
            <div class="menu-item" data-section="orders">
                <i class="fas fa-shopping-cart"></i>
                <span>My Orders</span>
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
                <h2><i class="fas fa-shopping-bag"></i> Buyer Dashboard</h2>
            </div>
            <div class="user-info">
                <!-- Messages Icon -->
                <a href="../messages.php" class="icon-link">
                    <i class="fas fa-envelope"></i>
                    <?php if($unread_msg_count > 0): ?>
                        <span class="badge"><?php echo $unread_msg_count > 9 ? '9+' : $unread_msg_count; ?></span>
                    <?php endif; ?>
                </a>

                <!-- Notification Bell -->
                <div class="icon-link" onclick="toggleNotifications()">
                    <i class="fas fa-bell"></i>
                    <?php if($unread_count > 0): ?>
                        <span class="badge"><?php echo $unread_count; ?></span>
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
                    
                    <?php if($total_notifications > 10): ?>
                    <div class="notification-footer">
                        <a href="../notifications.php" class="view-all-link">
                            View all notifications <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
                
                <img src="<?php echo $profile_image; ?>" alt="Profile">
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
            <h2 class="section-title">Welcome Back, <?php echo htmlspecialchars($_SESSION['username']); ?>!</h2>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Total Orders</h3>
                        <div class="stat-number"><?php echo $total_orders; ?></div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-shopping-cart"></i>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Pending Orders</h3>
                        <div class="stat-number"><?php echo $pending_orders; ?></div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Delivered Orders</h3>
                        <div class="stat-number"><?php echo $delivered_orders; ?></div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Total Spent</h3>
                        <div class="stat-number">₱<?php echo number_format($total_spent, 2); ?></div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                </div>
            </div>

            <div style="background: linear-gradient(135deg, #1976d2, #1565c0); color: white; padding: 30px; border-radius: 10px; text-align: center;">
                <i class="fas fa-shopping-bag" style="font-size: 3rem; margin-bottom: 10px;"></i>
                <h3>Start Shopping!</h3>
                <p>Browse fresh agricultural products directly from local farmers.</p>
                <a href="#" onclick="showSection('products')" style="display: inline-block; margin-top: 15px; background: white; color: #1976d2; padding: 10px 20px; border-radius: 5px; text-decoration: none;">
                    <i class="fas fa-store"></i> Shop Now
                </a>
            </div>
        </div>

        <!-- Products Section -->
        <div id="products" class="section">
            <h2 class="section-title"><i class="fas fa-store"></i> Available Products</h2>
            <div class="search-bar">
                <input type="text" id="searchInput" class="search-input" placeholder="Search products by name, farmer, or category...">
                <button class="search-btn" onclick="searchProducts()"><i class="fas fa-search"></i> Search</button>
            </div>
            <div class="product-grid" id="productGrid">
                <?php if($products->rowCount() > 0): ?>
                    <?php while($product = $products->fetch(PDO::FETCH_ASSOC)): ?>
                        <div class="product-card" data-name="<?php echo strtolower($product['name']); ?>" data-farmer="<?php echo strtolower($product['farmer_name']); ?>" data-category="<?php echo strtolower($product['category']); ?>">
                            <div class="product-badge">Fresh</div>
                            <div class="product-image">
                                <?php if($has_image_column && !empty($product['product_image']) && file_exists(__DIR__ . '/../' . $product['product_image'])): ?>
                                    <img src="../<?php echo $product['product_image']; ?>" alt="<?php echo htmlspecialchars($product['name']); ?>">
                                <?php else: ?>
                                    <i class="fas fa-apple-alt"></i>
                                <?php endif; ?>
                            </div>
                            <div class="product-info">
                                <h3><?php echo htmlspecialchars($product['name']); ?></h3>
                                <div class="farmer-info">
                                    <i class="fas fa-user-tie"></i>
                                    <span><?php echo htmlspecialchars($product['farmer_name']); ?></span>
                                </div>
                                <div class="farmer-info">
                                    <i class="fas fa-phone"></i>
                                    <span><?php echo htmlspecialchars($product['farmer_phone']); ?></span>
                                </div>
                                <span class="category"><?php echo $product['category']; ?></span>
                                <div class="quantity">
                                    <i class="fas fa-weight-hanging"></i> <?php echo $product['quantity']; ?> <?php echo $product['unit']; ?>
                                </div>
                                <div class="price">₱<?php echo number_format($product['price'], 2); ?></div>
                                <div class="description"><?php echo htmlspecialchars(substr($product['description'], 0, 100)); ?>...</div>
                                <a href="?buy=1&id=<?php echo $product['id']; ?>" class="btn-buy">
                                    <i class="fas fa-shopping-cart"></i> Buy Now
                                </a>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-box-open"></i>
                        <p>No products available at the moment.</p>
                        <p>Check back later for fresh products!</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Orders Section -->
        <div id="orders" class="section">
            <h2 class="section-title"><i class="fas fa-shopping-cart"></i> My Orders</h2>
            <?php if($orders->rowCount() > 0): ?>
                <div class="orders-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Order ID</th>
                                <th>Product</th>
                                <th>Farmer</th>
                                <th>Quantity</th>
                                <th>Total Price</th>
                                <th>Status</th>
                                <th>Order Date</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($order = $orders->fetch(PDO::FETCH_ASSOC)): ?>
                                <tr>
                                    <td>#<?php echo $order['id']; ?></td>
                                    <td>
                                        <?php if($has_image_column && !empty($order['product_image']) && file_exists(__DIR__ . '/../' . $order['product_image'])): ?>
                                            <img src="../<?php echo $order['product_image']; ?>" class="product-thumb" alt="<?php echo htmlspecialchars($order['product_name']); ?>">
                                        <?php endif; ?>
                                        <?php echo htmlspecialchars($order['product_name']); ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($order['farmer_name']); ?><br>
                                        <small><?php echo htmlspecialchars($order['farmer_phone']); ?></small>
                                    </td>
                                    <td><?php echo $order['quantity']; ?> <?php echo $order['unit']; ?></td>
                                    <td>₱<?php echo number_format($order['total_price'], 2); ?></td>
                                    <td>
                                        <span class="order-status status-<?php echo $order['status']; ?>">
                                            <?php echo ucfirst($order['status']); ?>
                                        </span>
                                    </table>
                                    <td><?php echo date('M d, Y', strtotime($order['order_date'])); ?></td>
                                    <td class="action-buttons">
                                        <?php if($order['status'] == 'pending'): ?>
                                            <a href="../payment/process_payment.php?order_id=<?php echo $order['id']; ?>" class="btn-pay">
                                                <i class="fas fa-credit-card"></i> Pay Now
                                            </a>
                                            <a href="?cancel=1&order_id=<?php echo $order['id']; ?>" class="btn-cancel" onclick="return confirm('Cancel this order?')">
                                                <i class="fas fa-times"></i> Cancel
                                            </a>
                                        <?php elseif($order['status'] == 'confirmed' || $order['status'] == 'shipped'): ?>
                                            <a href="../delivery/track.php?order_id=<?php echo $order['id']; ?>" class="btn-track">
                                                <i class="fas fa-truck"></i> Track Order
                                            </a>
                                        <?php elseif($order['status'] == 'delivered'): ?>
                                            <span style="color: #4caf50;">
                                                <i class="fas fa-check-circle"></i> Received
                                            </span>
                                        <?php elseif($order['status'] == 'cancelled'): ?>
                                            <span style="color: #f44336;">
                                                <i class="fas fa-times-circle"></i> Cancelled
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>No orders yet.</p>
                    <a href="#" onclick="showSection('products')" class="btn-buy" style="display: inline-block; width: auto;">
                        <i class="fas fa-store"></i> Start Shopping
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- My Profile Section -->
        <div id="profile" class="section">
            <h2 class="section-title"><i class="fas fa-user-circle"></i> My Profile</h2>
            
            <div class="profile-container">
                <div class="profile-header">
                    <div class="profile-avatar">
                        <img src="<?php echo $profile_image; ?>" alt="Profile Picture">
                        <div class="edit-photo" onclick="openImageModal()">
                            <i class="fas fa-camera"></i>
                        </div>
                    </div>
                    <div class="profile-info">
                        <h2><?php echo htmlspecialchars($user['username']); ?></h2>
                        <p><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($user['email']); ?></p>
                        <p><i class="fas fa-phone"></i> <?php echo htmlspecialchars($user['phone'] ?: 'Not provided'); ?></p>
                        <div class="profile-stats">
                            <div class="stat">
                                <div class="number"><?php echo $total_orders; ?></div>
                                <div class="label">Orders</div>
                            </div>
                            <div class="stat">
                                <div class="number"><?php echo $delivered_orders; ?></div>
                                <div class="label">Delivered</div>
                            </div>
                            <div class="stat">
                                <div class="number">₱<?php echo number_format($total_spent, 2); ?></div>
                                <div class="label">Spent</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Profile Information Form -->
                <div class="profile-form">
                    <h3 style="margin-bottom: 20px; color: #1976d2;">Edit Profile Information</h3>
                    <form method="POST" action="">
                        <div class="form-row">
                            <div class="form-group">
                                <label><i class="fas fa-user"></i> Full Name</label>
                                <input type="text" name="fullname" value="<?php echo htmlspecialchars($user['username']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-envelope"></i> Email Address</label>
                                <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label><i class="fas fa-phone"></i> Phone Number</label>
                                <input type="tel" name="phone" value="<?php echo htmlspecialchars($user['phone']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-map-marker-alt"></i> Delivery Address</label>
                                <input type="text" name="address" value="<?php echo htmlspecialchars($user['address']); ?>" required>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" name="update_profile" class="btn-save">
                                <i class="fas fa-save"></i> Save Changes
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Change Password Form -->
                <div class="profile-form">
                    <h3 style="margin-bottom: 20px; color: #1976d2;">Change Password</h3>
                    <form method="POST" action="">
                        <div class="form-row">
                            <div class="form-group">
                                <label><i class="fas fa-lock"></i> Current Password</label>
                                <input type="password" name="current_password" placeholder="Enter current password" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label><i class="fas fa-key"></i> New Password</label>
                                <input type="password" name="new_password" placeholder="Enter new password (min 6 characters)" required>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-check"></i> Confirm New Password</label>
                                <input type="password" name="confirm_password" placeholder="Confirm new password" required>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" name="change_password" class="btn-save">
                                <i class="fas fa-key"></i> Change Password
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Image Upload Modal -->
    <div id="imageModal" class="modal">
        <div class="modal-content">
            <h3><i class="fas fa-camera"></i> Update Profile Picture</h3>
            <form method="POST" enctype="multipart/form-data">
                <input type="file" name="profile_image" accept="image/jpeg,image/png,image/jpg,image/gif,image/webp" required>
                <button type="submit" name="upload_image" class="btn-save" style="width: 100%;">Upload Photo</button>
                <button type="button" onclick="closeImageModal()" style="margin-top: 10px; background: #666; color: white; padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; width: 100%;">Cancel</button>
            </form>
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

        // Add click handlers
        document.querySelectorAll('.menu-item').forEach(item => {
            item.addEventListener('click', function() {
                const sectionId = this.getAttribute('data-section');
                showSection(sectionId);
            });
        });

        // Search products
        function searchProducts() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const products = document.querySelectorAll('#productGrid .product-card');
            
            products.forEach(product => {
                const name = product.getAttribute('data-name');
                const farmer = product.getAttribute('data-farmer');
                const category = product.getAttribute('data-category');
                
                if(name.includes(searchTerm) || farmer.includes(searchTerm) || category.includes(searchTerm)) {
                    product.style.display = 'block';
                } else {
                    product.style.display = 'none';
                }
            });
        }

        // Notification functions
        function toggleNotifications() {
            const dropdown = document.getElementById('notificationDropdown');
            dropdown.classList.toggle('show');
        }
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            const dropdown = document.getElementById('notificationDropdown');
            const icon = document.querySelector('.icon-link');
            if (icon && dropdown && !icon.contains(event.target) && !dropdown.contains(event.target)) {
                dropdown.classList.remove('show');
            }
        });

        // Image modal functions
        function openImageModal() {
            document.getElementById('imageModal').style.display = 'flex';
        }
        
        function closeImageModal() {
            document.getElementById('imageModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('imageModal');
            if (event.target == modal) {
                modal.style.display = 'none';
            }
        }

        // Auto-hide alerts
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.display = 'none';
            });
        }, 5000);
    </script>
</body>
</html>