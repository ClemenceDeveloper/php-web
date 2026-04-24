<?php
// Include database connection
$pdo = require_once __DIR__ . '/../config/db.php';

// Check if user is logged in and is a farmer
if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'farmer') {
    header("Location: ../auth/login.php");
    exit();
}

$farmer_id = $_SESSION['user_id'];
$success = '';
$error = '';

// Include notification functions
require_once __DIR__ . '/../includes/notification_functions.php';

// Get notification data - FIXED: Use $farmer_id instead of $user_id
$unread_count = getUnreadCount($pdo, $farmer_id);
$notifications = getRecentNotifications($pdo, $farmer_id, 10);

// Check if profile_image column exists
$stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'profile_image'");
$has_profile_image = $stmt->rowCount() > 0;

// Get farmer data for profile
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$farmer_id]);
$farmer = $stmt->fetch();

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
            $stmt->execute([$fullname, $phone, $address, $email, $farmer_id]);
            $success = "Profile updated successfully!";
            
            // Update session username
            $_SESSION['username'] = $fullname;
            
            // Refresh farmer data
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$farmer_id]);
            $farmer = $stmt->fetch();
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
        if(password_verify($current_password, $farmer['password'])) {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashed_password, $farmer_id]);
            $success = "Password changed successfully!";
        } else {
            $error = "Current password is incorrect!";
        }
    }
}

// Handle Profile Image Upload
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['upload_image'])) {
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
            $filename = 'farmer_' . $farmer_id . '_' . time() . '.' . $ext;
            $filepath = 'assets/uploads/profiles/' . $filename;
            
            if (move_uploaded_file($_FILES['profile_image']['tmp_name'], __DIR__ . '/../' . $filepath)) {
                // Delete old image if exists
                if(!empty($farmer['profile_image']) && file_exists(__DIR__ . '/../' . $farmer['profile_image'])) {
                    unlink(__DIR__ . '/../' . $farmer['profile_image']);
                }
                
                $stmt = $pdo->prepare("UPDATE users SET profile_image = ? WHERE id = ?");
                $stmt->execute([$filepath, $farmer_id]);
                $success = "Profile picture updated successfully!";
                
                // Refresh farmer data
                $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$farmer_id]);
                $farmer = $stmt->fetch();
            } else {
                $error = "Failed to upload image!";
            }
        }
    } else {
        $error = "Please select an image to upload!";
    }
}

// Function to send notification to all buyers when product is added
function sendProductAddedNotification($pdo, $product_id, $product_name, $farmer_id, $farmer_name) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'user'");
    $stmt->execute();
    $buyers = $stmt->fetchAll();
    
    $title = "🌾 New Product Available!";
    $message = "$farmer_name has added: " . $product_name;
    $link = "../index.php#products";
    
    foreach($buyers as $buyer) {
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, title, message, type, link, created_at) 
            VALUES (?, ?, ?, 'product', ?, NOW())
        ");
        $stmt->execute([$buyer['id'], $title, $message, $link]);
    }
    return count($buyers);
}

// Mark notification as read
if(isset($_GET['mark_read']) && isset($_GET['notif_id'])) {
    $notif_id = $_GET['notif_id'];
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->execute([$notif_id, $farmer_id]);
    header("Location: farmer.php");
    exit();
}

// Mark all as read
if(isset($_GET['mark_all_read'])) {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->execute([$farmer_id]);
    header("Location: farmer.php");
    exit();
}

// Create uploads directory if not exists
$upload_dir = __DIR__ . '/../assets/uploads/products/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Function to upload product image
function uploadProductImage($file, $product_id) {
    $target_dir = __DIR__ . '/../assets/uploads/products/';
    
    $allowed = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif', 'image/webp'];
    if (!in_array($file['type'], $allowed)) {
        return ['success' => false, 'message' => 'Invalid file type'];
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        return ['success' => false, 'message' => 'File too large'];
    }
    
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'product_' . $product_id . '_' . time() . '.' . $ext;
    $filepath = 'assets/uploads/products/' . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $target_dir . $filename)) {
        return ['success' => true, 'filepath' => $filepath];
    }
    return ['success' => false, 'message' => 'Upload failed'];
}

// Handle Add Product with Image and Notification
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_product'])) {
    $name = trim($_POST['name']);
    $category = $_POST['category'];
    $quantity = $_POST['quantity'];
    $unit = $_POST['unit'];
    $price = $_POST['price'];
    $description = trim($_POST['description']);
    
    if(empty($name) || empty($quantity) || empty($price)) {
        $error = "Please fill in all required fields!";
    } else {
        try {
            // Check if product_image column exists
            $stmt = $pdo->query("SHOW COLUMNS FROM products LIKE 'product_image'");
            if($stmt->rowCount() == 0) {
                $pdo->exec("ALTER TABLE products ADD COLUMN product_image VARCHAR(255) DEFAULT NULL");
            }
            
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("INSERT INTO products (farmer_id, name, category, quantity, unit, price, description) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$farmer_id, $name, $category, $quantity, $unit, $price, $description]);
            $product_id = $pdo->lastInsertId();
            
            if(isset($_FILES['product_image']) && $_FILES['product_image']['error'] == 0) {
                $upload_result = uploadProductImage($_FILES['product_image'], $product_id);
                if($upload_result['success']) {
                    $stmt = $pdo->prepare("UPDATE products SET product_image = ? WHERE id = ?");
                    $stmt->execute([$upload_result['filepath'], $product_id]);
                }
            }
            
            // Send notification to all buyers
            $notified_count = sendProductAddedNotification($pdo, $product_id, $name, $farmer_id, $_SESSION['username']);
            
            $pdo->commit();
            $success = "Product added successfully! $notified_count buyers have been notified.";
        } catch(PDOException $e) {
            $pdo->rollBack();
            $error = "Failed to add product: " . $e->getMessage();
        }
    }
}

// Handle Delete Product
if(isset($_GET['delete'])) {
    $product_id = $_GET['delete'];
    try {
        $stmt = $pdo->prepare("SELECT product_image FROM products WHERE id = ? AND farmer_id = ?");
        $stmt->execute([$product_id, $farmer_id]);
        $product = $stmt->fetch();
        
        if($product && !empty($product['product_image']) && file_exists(__DIR__ . '/../' . $product['product_image'])) {
            unlink(__DIR__ . '/../' . $product['product_image']);
        }
        
        $stmt = $pdo->prepare("DELETE FROM products WHERE id = ? AND farmer_id = ?");
        $stmt->execute([$product_id, $farmer_id]);
        $success = "Product deleted successfully!";
    } catch(PDOException $e) {
        $error = "Failed to delete product";
    }
}

// Handle Update Product Status
if(isset($_GET['status']) && isset($_GET['id'])) {
    $status = $_GET['status'];
    $product_id = $_GET['id'];
    try {
        $stmt = $pdo->prepare("UPDATE products SET status = ? WHERE id = ? AND farmer_id = ?");
        $stmt->execute([$status, $product_id, $farmer_id]);
        $success = "Product status updated!";
    } catch(PDOException $e) {
        $error = "Failed to update status";
    }
}

// Get farmer's products
$products = $pdo->prepare("SELECT * FROM products WHERE farmer_id = ? ORDER BY created_at DESC");
$products->execute([$farmer_id]);

// Get statistics
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM products WHERE farmer_id = ?");
$stmt->execute([$farmer_id]);
$total_products = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM products WHERE farmer_id = ? AND status = 'available'");
$stmt->execute([$farmer_id]);
$available_products = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM products WHERE farmer_id = ? AND status = 'sold'");
$stmt->execute([$farmer_id]);
$sold_products = $stmt->fetchColumn();

// Get orders for farmer's products
$orders = $pdo->prepare("
    SELECT o.*, p.name as product_name, u.username as buyer_name, u.phone as buyer_phone, u.address as buyer_address
    FROM orders o 
    JOIN products p ON o.product_id = p.id 
    JOIN users u ON o.buyer_id = u.id 
    WHERE p.farmer_id = ? 
    ORDER BY o.order_date DESC
");
$orders->execute([$farmer_id]);
$total_orders = $orders->rowCount();

// Calculate total earnings
$earnings = $pdo->prepare("
    SELECT SUM(o.total_price) as total 
    FROM orders o 
    JOIN products p ON o.product_id = p.id 
    WHERE p.farmer_id = ? AND o.status = 'delivered'
");
$earnings->execute([$farmer_id]);
$total_earnings = $earnings->fetchColumn() ?: 0;

// Get unread notification count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([$farmer_id]);
$unread_count = $stmt->fetchColumn();

// Get recent notifications
$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 15");
$stmt->execute([$farmer_id]);
$notifications = $stmt->fetchAll();

// Get all notifications count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ?");
$stmt->execute([$farmer_id]);
$total_notifications = $stmt->fetchColumn();

// Check if product_image column exists for display
$stmt = $pdo->query("SHOW COLUMNS FROM products LIKE 'product_image'");
$has_image_column = $stmt->rowCount() > 0;

// Set profile image
$profile_image = 'https://ui-avatars.com/api/?background=2e7d32&color=fff&name=' . urlencode($farmer['username']);
if($has_profile_image && !empty($farmer['profile_image']) && file_exists(__DIR__ . '/../' . $farmer['profile_image'])) {
    $profile_image = '../' . $farmer['profile_image'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Farmer Dashboard - Agriculture Marketplace</title>
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
            background: linear-gradient(135deg, #1b5e20, #2e7d32);
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
            color: #2e7d32;
            font-size: 0.85rem;
            font-weight: 600;
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
            border: 4px solid #2e7d32;
        }
        
        .profile-avatar .edit-photo {
            position: absolute;
            bottom: 5px;
            right: 5px;
            background: #2e7d32;
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
            color: #2e7d32;
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
            color: #2e7d32;
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
            background: linear-gradient(135deg, #2e7d32, #1b5e20);
            color: white;
            padding: 10px 25px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
        }
        
        /* Notification type colors */
        .notification-type-product { border-left-color: #4caf50 !important; }
        .notification-type-order { border-left-color: #ff9800 !important; }
        .notification-type-payment { border-left-color: #2196f3 !important; }
        .notification-type-delivery { border-left-color: #9c27b0 !important; }

        /* Sidebar Styles */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100%;
            background: linear-gradient(135deg, #1b5e20, #2e7d32);
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
            color: #2e7d32;
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
            font-size: 1.8rem;
            font-weight: bold;
            color: #2e7d32;
        }

        .stat-icon i {
            font-size: 2.5rem;
            color: #4caf50;
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
            font-size: 1.3rem;
            color: #2e7d32;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e0e0e0;
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #2e7d32;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .btn-submit {
            background: linear-gradient(135deg, #2e7d32, #1b5e20);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            transition: all 0.3s;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(46,125,50,0.3);
        }

        /* Product Grid */
        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .product-card {
            background: #f9f9f9;
            border-radius: 10px;
            overflow: hidden;
            transition: all 0.3s;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .product-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
        }

        .product-image {
            height: 180px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .product-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .product-image i {
            font-size: 3rem;
            color: rgba(255,255,255,0.8);
        }

        .product-info {
            padding: 15px;
        }

        .product-info h3 {
            color: #2e7d32;
            margin-bottom: 8px;
            font-size: 1.1rem;
        }

        .product-price {
            font-size: 1.3rem;
            color: #ff9800;
            font-weight: bold;
            margin: 8px 0;
        }

        .product-status {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            margin: 8px 0;
        }

        .status-available {
            background: #d4edda;
            color: #155724;
        }

        .status-sold {
            background: #f8d7da;
            color: #721c24;
        }

        .product-actions {
            display: flex;
            gap: 8px;
            margin-top: 10px;
        }

        .btn-delete, .btn-sold {
            padding: 5px 10px;
            border-radius: 5px;
            text-decoration: none;
            font-size: 0.75rem;
            transition: all 0.3s;
        }

        .btn-sold {
            background: #ff9800;
            color: white;
        }

        .btn-delete {
            background: #f44336;
            color: white;
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
            padding: 10px;
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

        .order-status {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
        }

        .status-pending { background: #ff9800; color: white; }
        .status-confirmed { background: #2196f3; color: white; }
        .status-shipped { background: #9c27b0; color: white; }
        .status-delivered { background: #4caf50; color: white; }
        .status-cancelled { background: #f44336; color: white; }

        /* Alert Messages */
        .alert {
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.5s ease;
        }
        @keyframes slideIn {
            from { opacity: 0; transform: translateX(-30px); }
            to { opacity: 1; transform: translateX(0); }
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

        .image-preview-container {
            margin-top: 10px;
            position: relative;
            display: inline-block;
        }

        .image-preview {
            width: 120px;
            height: 120px;
            border-radius: 8px;
            object-fit: cover;
            border: 2px solid #e0e0e0;
            display: none;
        }

        .image-preview.active {
            display: block;
        }

        .remove-image {
            position: absolute;
            top: -8px;
            right: -8px;
            background: #f44336;
            color: white;
            border-radius: 50%;
            width: 22px;
            height: 22px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 0.7rem;
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

        @media (max-width: 768px) {
            .sidebar { left: -280px; }
            .main-content { margin-left: 0; }
            .form-row { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: 1fr; }
            .notification-dropdown { width: 320px; right: 10px; }
            .user-info { gap: 10px; }
            .user-info span { display: none; }
            .profile-header { flex-direction: column; text-align: center; }
            .profile-stats { justify-content: center; }
            .profile-form .form-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <i class="fas fa-tractor"></i>
            <h3>Farmer Panel</h3>
            <p><?php echo htmlspecialchars($_SESSION['username']); ?></p>
        </div>
        <div class="sidebar-menu">
            <div class="menu-item active" data-section="dashboard">
                <i class="fas fa-chart-line"></i>
                <span>Dashboard</span>
            </div>
            <div class="menu-item" data-section="add-product">
                <i class="fas fa-plus-circle"></i>
                <span>Add Product</span>
            </div>
            <div class="menu-item" data-section="my-products">
                <i class="fas fa-box"></i>
                <span>My Products</span>
            </div>
            <div class="menu-item" data-section="orders">
                <i class="fas fa-shopping-cart"></i>
                <span>Orders</span>
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

    <div class="main-content">
        <div class="top-navbar">
            <div class="page-title">
                <h2><i class="fas fa-tractor"></i> Farmer Dashboard</h2>
            </div>
            <div class="user-info">
                <div class="notification-icon" onclick="toggleNotifications()">
                    <i class="fas fa-bell"></i>
                    <?php if($unread_count > 0): ?>
                        <span class="notification-badge"><?php echo $unread_count; ?></span>
                    <?php endif; ?>
                </div>
                
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
                        <a href="#" class="view-all-link" onclick="showAllNotifications(); return false;">
                            View all notifications <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                    <?php endif; ?>
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
            <h2 class="section-title">Dashboard Overview</h2>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Total Products</h3>
                        <div class="stat-number"><?php echo $total_products; ?></div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-box"></i>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Available Products</h3>
                        <div class="stat-number"><?php echo $available_products; ?></div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Sold Products</h3>
                        <div class="stat-number"><?php echo $sold_products; ?></div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-shopping-cart"></i>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Total Orders</h3>
                        <div class="stat-number"><?php echo $total_orders; ?></div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-truck"></i>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Total Earnings</h3>
                        <div class="stat-number">₱<?php echo number_format($total_earnings, 2); ?></div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                </div>
            </div>

            <div style="background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 25px; border-radius: 10px; text-align: center;">
                <i class="fas fa-chart-line" style="font-size: 2.5rem; margin-bottom: 10px;"></i>
                <h3>Welcome to Your Farm Dashboard!</h3>
                <p>Manage your products, track orders, and grow your business.</p>
            </div>
        </div>

        <!-- Add Product Section -->
        <div id="add-product" class="section">
            <h2 class="section-title"><i class="fas fa-plus-circle"></i> Add New Product</h2>
            <form method="POST" action="" enctype="multipart/form-data">
                <div class="form-row">
                    <div class="form-group">
                        <label>Product Name *</label>
                        <input type="text" name="name" placeholder="e.g., Organic Rice" required>
                    </div>
                    <div class="form-group">
                        <label>Category *</label>
                        <select name="category" required>
                            <option value="Vegetables">🥬 Vegetables</option>
                            <option value="Fruits">🍎 Fruits</option>
                            <option value="Grains">🌾 Grains</option>
                            <option value="Dairy">🥛 Dairy</option>
                            <option value="Meat">🍖 Meat</option>
                            <option value="Organic">🌱 Organic</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Quantity *</label>
                        <input type="number" step="0.01" name="quantity" placeholder="e.g., 100" required>
                    </div>
                    <div class="form-group">
                        <label>Unit *</label>
                        <select name="unit" required>
                            <option value="kg">Kilogram (kg)</option>
                            <option value="g">Gram (g)</option>
                            <option value="lb">Pound (lb)</option>
                            <option value="piece">Piece</option>
                            <option value="bundle">Bundle</option>
                            <option value="liter">Liter (L)</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Price per Unit (₱) *</label>
                        <input type="number" step="0.01" name="price" placeholder="e.g., 50.00" required>
                    </div>
                    <div class="form-group">
                        <label>Product Image</label>
                        <input type="file" name="product_image" id="product_image" accept="image/jpeg,image/png,image/jpg,image/gif,image/webp" onchange="previewImage(this)">
                        <small style="color: #666;">Max file size: 5MB. Allowed formats: JPG, PNG, GIF, WEBP</small>
                        <div class="image-preview-container">
                            <img id="imagePreview" class="image-preview" alt="Product preview">
                            <span class="remove-image" onclick="removeImage()" style="display: none;">×</span>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" rows="4" placeholder="Describe your product..."></textarea>
                </div>
                <button type="submit" name="add_product" class="btn-submit">
                    <i class="fas fa-save"></i> Add Product
                </button>
            </form>
        </div>

        <!-- My Products Section -->
        <div id="my-products" class="section">
            <h2 class="section-title"><i class="fas fa-box"></i> My Products</h2>
            <?php if($products->rowCount() > 0): ?>
                <div class="product-grid">
                    <?php while($product = $products->fetch(PDO::FETCH_ASSOC)): ?>
                        <div class="product-card">
                            <div class="product-image">
                                <?php if($has_image_column && !empty($product['product_image']) && file_exists(__DIR__ . '/../' . $product['product_image'])): ?>
                                    <img src="../<?php echo $product['product_image']; ?>" alt="<?php echo htmlspecialchars($product['name']); ?>">
                                <?php else: ?>
                                    <i class="fas fa-box"></i>
                                <?php endif; ?>
                            </div>
                            <div class="product-info">
                                <h3><?php echo htmlspecialchars($product['name']); ?></h3>
                                <p><i class="fas fa-tag"></i> <?php echo $product['category']; ?></p>
                                <p><i class="fas fa-weight-hanging"></i> <?php echo $product['quantity']; ?> <?php echo $product['unit']; ?></p>
                                <div class="product-price">₱<?php echo number_format($product['price'], 2); ?></div>
                                <span class="product-status status-<?php echo $product['status']; ?>">
                                    <i class="fas <?php echo $product['status'] == 'available' ? 'fa-check-circle' : 'fa-clock'; ?>"></i>
                                    <?php echo ucfirst($product['status']); ?>
                                </span>
                                <div class="product-actions">
                                    <?php if($product['status'] == 'available'): ?>
                                        <a href="?status=sold&id=<?php echo $product['id']; ?>" class="btn-sold" onclick="return confirm('Mark this product as sold?')">
                                            <i class="fas fa-check"></i> Mark Sold
                                        </a>
                                    <?php endif; ?>
                                    <a href="?delete=<?php echo $product['id']; ?>" class="btn-delete" onclick="return confirm('Are you sure you want to delete this product?')">
                                        <i class="fas fa-trash"></i> Delete
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div style="text-align: center; padding: 40px;">
                    <i class="fas fa-box-open" style="font-size: 3rem; color: #ccc;"></i>
                    <p style="margin-top: 15px;">No products added yet.</p>
                    <a href="#" onclick="showSection('add-product')" class="btn-submit" style="display: inline-block; margin-top: 15px;">
                        <i class="fas fa-plus-circle"></i> Add Your First Product
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Orders Section -->
        <div id="orders" class="section">
            <h2 class="section-title"><i class="fas fa-shopping-cart"></i> Customer Orders</h2>
            <?php if($orders->rowCount() > 0): ?>
                <div class="orders-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Order ID</th>
                                <th>Product</th>
                                <th>Buyer</th>
                                <th>Quantity</th>
                                <th>Total Price</th>
                                <th>Status</th>
                                <th>Order Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($order = $orders->fetch(PDO::FETCH_ASSOC)): ?>
                                <tr>
                                    <td>#<?php echo $order['id']; ?></td>
                                    <td><?php echo htmlspecialchars($order['product_name']); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($order['buyer_name']); ?><br>
                                        <small><?php echo htmlspecialchars($order['buyer_phone']); ?></small>
                                    </td>
                                    <td><?php echo $order['quantity']; ?></td>
                                    <td>₱<?php echo number_format($order['total_price'], 2); ?></td>
                                    <td>
                                        <span class="order-status status-<?php echo $order['status']; ?>">
                                            <?php echo ucfirst($order['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($order['order_date'])); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div style="text-align: center; padding: 40px;">
                    <i class="fas fa-inbox" style="font-size: 3rem; color: #ccc;"></i>
                    <p style="margin-top: 15px;">No orders yet.</p>
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
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Total Products Sold</h3>
                        <div class="stat-number"><?php echo $sold_products; ?></div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-boxes"></i>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Average Price per Product</h3>
                        <div class="stat-number">₱<?php 
                            $avg = $sold_products > 0 ? $total_earnings / $sold_products : 0;
                            echo number_format($avg, 2);
                        ?></div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                </div>
            </div>
            <div style="background: linear-gradient(135deg, #2e7d32, #1b5e20); color: white; padding: 25px; border-radius: 10px; text-align: center; margin-top: 20px;">
                <i class="fas fa-trophy" style="font-size: 2.5rem; margin-bottom: 10px;"></i>
                <h3>Great Job, Farmer!</h3>
                <p>Keep providing quality products to your customers.</p>
            </div>
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
                        <h2><?php echo htmlspecialchars($farmer['username']); ?></h2>
                        <p><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($farmer['email']); ?></p>
                        <p><i class="fas fa-phone"></i> <?php echo htmlspecialchars($farmer['phone'] ?: 'Not provided'); ?></p>
                        <div class="profile-stats">
                            <div class="stat">
                                <div class="number"><?php echo $total_products; ?></div>
                                <div class="label">Products</div>
                            </div>
                            <div class="stat">
                                <div class="number"><?php echo $total_orders; ?></div>
                                <div class="label">Orders</div>
                            </div>
                            <div class="stat">
                                <div class="number"><?php echo $sold_products; ?></div>
                                <div class="label">Sold</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Profile Information Form -->
                <div class="profile-form">
                    <h3 style="margin-bottom: 20px; color: #2e7d32;">Edit Profile Information</h3>
                    <form method="POST" action="">
                        <div class="form-row">
                            <div class="form-group">
                                <label><i class="fas fa-user"></i> Full Name</label>
                                <input type="text" name="fullname" value="<?php echo htmlspecialchars($farmer['username']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-envelope"></i> Email Address</label>
                                <input type="email" name="email" value="<?php echo htmlspecialchars($farmer['email']); ?>" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label><i class="fas fa-phone"></i> Phone Number</label>
                                <input type="tel" name="phone" value="<?php echo htmlspecialchars($farmer['phone']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-map-marker-alt"></i> Address</label>
                                <input type="text" name="address" value="<?php echo htmlspecialchars($farmer['address']); ?>" required>
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
                    <h3 style="margin-bottom: 20px; color: #2e7d32;">Change Password</h3>
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
                <button type="submit" name="upload_image" class="btn-submit">Upload Photo</button>
                <button type="button" onclick="closeImageModal()" style="margin-top: 10px; background: #666; color: white; padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer;">Cancel</button>
            </form>
        </div>
    </div>

    <script>
        // Image preview function
        function previewImage(input) {
            const preview = document.getElementById('imagePreview');
            const removeBtn = document.querySelector('.remove-image');
            
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    preview.classList.add('active');
                    removeBtn.style.display = 'flex';
                }
                reader.readAsDataURL(input.files[0]);
            }
        }
        
        function removeImage() {
            const fileInput = document.getElementById('product_image');
            const preview = document.getElementById('imagePreview');
            const removeBtn = document.querySelector('.remove-image');
            
            fileInput.value = '';
            preview.src = '';
            preview.classList.remove('active');
            removeBtn.style.display = 'none';
        }
        
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

        // Image modal functions
        function openImageModal() {
            document.getElementById('imageModal').style.display = 'flex';
        }
        
        function closeImageModal() {
            document.getElementById('imageModal').style.display = 'none';
        }
        
        // Show all notifications
        function showAllNotifications() {
            window.location.href = 'notifications.php';
        }

        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.display = 'none';
            });
        }, 5000);
        
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
                .catch(error => console.log('Error updating notifications:', error));
        }, 30000);
    </script>
</body>
</html>