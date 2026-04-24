<?php
// Include database connection
$pdo = require_once __DIR__ . '/../config/db.php';

// Check if user is logged in and is an admin
if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

$success = '';
$error = '';
// Include notification functions
require_once __DIR__ . '/../includes/notification_functions.php';

// Get notification data
$unread_count = getUnreadCount($pdo, $user_id);
$notifications = getRecentNotifications($pdo, $user_id, 10);

// Handle User Management Actions
if(isset($_GET['action']) && isset($_GET['user_id'])) {
    $action = $_GET['action'];
    $user_id = $_GET['user_id'];
    
    if($action == 'delete') {
        try {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role != 'admin'");
            $stmt->execute([$user_id]);
            $success = "User deleted successfully!";
        } catch(PDOException $e) {
            $error = "Failed to delete user: " . $e->getMessage();
        }
    }
    
    if($action == 'make_farmer') {
        try {
            $stmt = $pdo->prepare("UPDATE users SET role = 'farmer' WHERE id = ?");
            $stmt->execute([$user_id]);
            $success = "User role updated to Farmer!";
        } catch(PDOException $e) {
            $error = "Failed to update role: " . $e->getMessage();
        }
    }
    
    if($action == 'make_user') {
        try {
            $stmt = $pdo->prepare("UPDATE users SET role = 'user' WHERE id = ?");
            $stmt->execute([$user_id]);
            $success = "User role updated to Buyer!";
        } catch(PDOException $e) {
            $error = "Failed to update role: " . $e->getMessage();
        }
    }
    
    if($action == 'make_transport') {
        try {
            $stmt = $pdo->prepare("UPDATE users SET role = 'transport' WHERE id = ?");
            $stmt->execute([$user_id]);
            $success = "User role updated to Transport Provider!";
        } catch(PDOException $e) {
            $error = "Failed to update role: " . $e->getMessage();
        }
    }
}

// Handle Product Management Actions
if(isset($_GET['product_action']) && isset($_GET['product_id'])) {
    $action = $_GET['product_action'];
    $product_id = $_GET['product_id'];
    
    if($action == 'delete') {
        try {
            $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
            $stmt->execute([$product_id]);
            $success = "Product deleted successfully!";
        } catch(PDOException $e) {
            $error = "Failed to delete product: " . $e->getMessage();
        }
    }
    
    if($action == 'available') {
        try {
            $stmt = $pdo->prepare("UPDATE products SET status = 'available' WHERE id = ?");
            $stmt->execute([$product_id]);
            $success = "Product status updated to Available!";
        } catch(PDOException $e) {
            $error = "Failed to update status: " . $e->getMessage();
        }
    }
}

// Handle Order Management Actions
if(isset($_GET['order_action']) && isset($_GET['order_id'])) {
    $action = $_GET['order_action'];
    $order_id = $_GET['order_id'];
    
    $allowed_statuses = ['confirmed', 'shipped', 'delivered', 'cancelled'];
    if(in_array($action, $allowed_statuses)) {
        try {
            $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
            $stmt->execute([$action, $order_id]);
            $success = "Order status updated to " . ucfirst($action) . "!";
        } catch(PDOException $e) {
            $error = "Failed to update order status: " . $e->getMessage();
        }
    }
}

// Get Statistics
// User counts
$stmt = $pdo->query("SELECT COUNT(*) FROM users");
$total_users = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'farmer'");
$total_farmers = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'user'");
$total_buyers = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'transport'");
$total_transport = $stmt->fetchColumn();

// Product counts
$stmt = $pdo->query("SELECT COUNT(*) FROM products");
$total_products = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM products WHERE status = 'available'");
$available_products = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM products WHERE status = 'sold'");
$sold_products = $stmt->fetchColumn();

// Order counts
$stmt = $pdo->query("SELECT COUNT(*) FROM orders");
$total_orders = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'");
$pending_orders = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'delivered'");
$delivered_orders = $stmt->fetchColumn();

// Revenue
$stmt = $pdo->query("SELECT SUM(total_price) FROM orders WHERE status = 'delivered'");
$total_revenue = $stmt->fetchColumn() ?: 0;

// Get recent data for tables
$recent_users = $pdo->query("SELECT * FROM users ORDER BY created_at DESC LIMIT 10");
$recent_products = $pdo->query("
    SELECT p.*, u.username as farmer_name 
    FROM products p 
    JOIN users u ON p.farmer_id = u.id 
    ORDER BY p.created_at DESC 
    LIMIT 10
");
$recent_orders = $pdo->query("
    SELECT o.*, p.name as product_name, u.username as buyer_name 
    FROM orders o 
    JOIN products p ON o.product_id = p.id 
    JOIN users u ON o.buyer_id = u.id 
    ORDER BY o.order_date DESC 
    LIMIT 10
");

// Get all users for management
$all_users = $pdo->query("SELECT * FROM users ORDER BY created_at DESC");

// Get all products for management
$all_products = $pdo->query("
    SELECT p.*, u.username as farmer_name 
    FROM products p 
    JOIN users u ON p.farmer_id = u.id 
    ORDER BY p.created_at DESC
");

// Get all orders for management
$all_orders = $pdo->query("
    SELECT o.*, p.name as product_name, u.username as buyer_name, f.username as farmer_name
    FROM orders o 
    JOIN products p ON o.product_id = p.id 
    JOIN users u ON o.buyer_id = u.id 
    JOIN users f ON p.farmer_id = f.id
    ORDER BY o.order_date DESC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Agriculture Marketplace</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        /* Sidebar Styles */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100%;
            background: linear-gradient(135deg, #1a1a2e, #16213e);
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
            color: #ffd700;
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
            border-left: 4px solid #ffd700;
        }

        .menu-item i {
            width: 25px;
            font-size: 1.2rem;
        }

        .badge {
            background: #ff5722;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
            margin-left: auto;
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
        }

        .page-title h2 {
            color: #1a1a2e;
            font-size: 1.5rem;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .user-info i {
            font-size: 2rem;
            color: #1a1a2e;
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
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
            font-size: 0.85rem;
            color: #666;
            margin-bottom: 5px;
        }

        .stat-number {
            font-size: 1.8rem;
            font-weight: bold;
            color: #1a1a2e;
        }

        .stat-icon i {
            font-size: 2.5rem;
            color: #ffd700;
            opacity: 0.7;
        }

        /* Charts Row */
        .charts-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .chart-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .chart-card h3 {
            margin-bottom: 20px;
            color: #1a1a2e;
        }

        canvas {
            max-height: 300px;
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
            color: #1a1a2e;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
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
            background: #f5f5f5;
            color: #333;
            font-weight: 600;
        }

        tr:hover {
            background: #f9f9f9;
        }

        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .status-pending { background: #ff9800; color: white; }
        .status-confirmed { background: #2196f3; color: white; }
        .status-shipped { background: #9c27b0; color: white; }
        .status-delivered { background: #4caf50; color: white; }
        .status-cancelled { background: #f44336; color: white; }
        .status-available { background: #4caf50; color: white; }
        .status-sold { background: #f44336; color: white; }

        .role-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .role-admin { background: #f44336; color: white; }
        .role-farmer { background: #4caf50; color: white; }
        .role-user { background: #2196f3; color: white; }
        .role-transport { background: #ff9800; color: white; }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }

        .btn-icon {
            padding: 5px 10px;
            border-radius: 5px;
            text-decoration: none;
            font-size: 0.75rem;
            transition: all 0.3s;
        }

        .btn-edit { background: #2196f3; color: white; }
        .btn-delete { background: #f44336; color: white; }
        .btn-farmer { background: #4caf50; color: white; }
        .btn-user { background: #2196f3; color: white; }
        .btn-transport { background: #ff9800; color: white; }
        .btn-confirm { background: #2196f3; color: white; }
        .btn-ship { background: #9c27b0; color: white; }
        .btn-deliver { background: #4caf50; color: white; }
        .btn-cancel { background: #f44336; color: white; }
        .btn-available { background: #4caf50; color: white; }

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

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                left: -280px;
            }
            .main-content {
                margin-left: 0;
            }
            .charts-row {
                grid-template-columns: 1fr;
            }
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <i class="fas fa-chart-line"></i>
            <h3>Admin Panel</h3>
            <p><?php echo htmlspecialchars($_SESSION['username']); ?></p>
        </div>
        <div class="sidebar-menu">
            <div class="menu-item active" data-section="dashboard">
                <i class="fas fa-chart-line"></i>
                <span>Dashboard</span>
            </div>
            <div class="menu-item" data-section="users">
                <i class="fas fa-users"></i>
                <span>User Management</span>
            </div>
            <div class="menu-item" data-section="products">
                <i class="fas fa-box"></i>
                <span>Product Management</span>
            </div>
            <div class="menu-item" data-section="orders">
                <i class="fas fa-shopping-cart"></i>
                <span>Order Management</span>
                <?php if($pending_orders > 0): ?>
                    <span class="badge"><?php echo $pending_orders; ?></span>
                <?php endif; ?>
            </div>
            <div class="menu-item" data-section="reports">
                <i class="fas fa-file-alt"></i>
                <span>Reports</span>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="top-navbar">
            <div class="page-title">
                <h2><i class="fas fa-chart-line"></i> Admin Dashboard</h2>
            </div>
            <div class="user-info">
                <i class="fas fa-user-shield"></i>
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
            <h2 class="section-title">Overview Dashboard</h2>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Total Users</h3>
                        <div class="stat-number"><?php echo $total_users; ?></div>
                        <small>👨‍🌾 Farmers: <?php echo $total_farmers; ?> | 👤 Buyers: <?php echo $total_buyers; ?></small>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Total Products</h3>
                        <div class="stat-number"><?php echo $total_products; ?></div>
                        <small>✅ Available: <?php echo $available_products; ?> | ❌ Sold: <?php echo $sold_products; ?></small>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-box"></i>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Total Orders</h3>
                        <div class="stat-number"><?php echo $total_orders; ?></div>
                        <small>⏳ Pending: <?php echo $pending_orders; ?> | ✅ Delivered: <?php echo $delivered_orders; ?></small>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-shopping-cart"></i>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Total Revenue</h3>
                        <div class="stat-number">₱<?php echo number_format($total_revenue, 2); ?></div>
                        <small>From completed orders</small>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                </div>
            </div>

            <div class="charts-row">
                <div class="chart-card">
                    <h3><i class="fas fa-chart-pie"></i> User Distribution</h3>
                    <canvas id="userChart"></canvas>
                </div>
                <div class="chart-card">
                    <h3><i class="fas fa-chart-bar"></i> Product Status</h3>
                    <canvas id="productChart"></canvas>
                </div>
            </div>

            <div class="chart-card" style="margin-top: 20px;">
                <h3><i class="fas fa-chart-line"></i> Recent Orders Overview</h3>
                <div class="data-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Order ID</th>
                                <th>Product</th>
                                <th>Buyer</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $preview_orders = $pdo->query("
                                SELECT o.*, p.name as product_name, u.username as buyer_name 
                                FROM orders o 
                                JOIN products p ON o.product_id = p.id 
                                JOIN users u ON o.buyer_id = u.id 
                                ORDER BY o.order_date DESC 
                                LIMIT 5
                            ");
                            while($order = $preview_orders->fetch(PDO::FETCH_ASSOC)): ?>
                            <tr>
                                <td>#<?php echo $order['id']; ?></td>
                                <td><?php echo htmlspecialchars($order['product_name']); ?></td>
                                <td><?php echo htmlspecialchars($order['buyer_name']); ?></td>
                                <td>₱<?php echo number_format($order['total_price'], 2); ?></td>
                                <td><span class="status-badge status-<?php echo $order['status']; ?>"><?php echo ucfirst($order['status']); ?></span></td>
                                <td><?php echo date('M d, Y', strtotime($order['order_date'])); ?></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- User Management Section -->
        <div id="users" class="section">
            <h2 class="section-title">
                <i class="fas fa-users"></i> User Management
                <span style="font-size: 0.9rem;">Total: <?php echo $total_users; ?> users</span>
            </h2>
            <div class="data-table">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Role</th>
                            <th>Joined</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($user = $all_users->fetch(PDO::FETCH_ASSOC)): ?>
                            <tr>
                                <td>#<?php echo $user['id']; ?></td>
                                <td><?php echo htmlspecialchars($user['username']); ?></td>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                <td><?php echo htmlspecialchars($user['phone'] ?: 'N/A'); ?></td>
                                <td>
                                    <span class="role-badge role-<?php echo $user['role']; ?>">
                                        <?php echo ucfirst($user['role']); ?>
                                    </span>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                <td class="action-buttons">
                                    <?php if($user['role'] != 'admin'): ?>
                                        <?php if($user['role'] != 'farmer'): ?>
                                            <a href="?action=make_farmer&user_id=<?php echo $user['id']; ?>" class="btn-icon btn-farmer" onclick="return confirm('Make this user a Farmer?')">
                                                <i class="fas fa-tractor"></i> Farmer
                                            </a>
                                        <?php endif; ?>
                                        <?php if($user['role'] != 'user'): ?>
                                            <a href="?action=make_user&user_id=<?php echo $user['id']; ?>" class="btn-icon btn-user" onclick="return confirm('Make this user a Buyer?')">
                                                <i class="fas fa-user"></i> Buyer
                                            </a>
                                        <?php endif; ?>
                                        <?php if($user['role'] != 'transport'): ?>
                                            <a href="?action=make_transport&user_id=<?php echo $user['id']; ?>" class="btn-icon btn-transport" onclick="return confirm('Make this user a Transport Provider?')">
                                                <i class="fas fa-truck"></i> Transport
                                            </a>
                                        <?php endif; ?>
                                        <a href="?action=delete&user_id=<?php echo $user['id']; ?>" class="btn-icon btn-delete" onclick="return confirm('Delete this user?')">
                                            <i class="fas fa-trash"></i> Delete
                                        </a>
                                    <?php else: ?>
                                        <span style="color: #999;">Admin Account</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Product Management Section -->
        <div id="products" class="section">
            <h2 class="section-title">
                <i class="fas fa-box"></i> Product Management
                <span style="font-size: 0.9rem;">Total: <?php echo $total_products; ?> products</span>
            </h2>
            <div class="data-table">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Product Name</th>
                            <th>Farmer</th>
                            <th>Category</th>
                            <th>Quantity</th>
                            <th>Price</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($product = $all_products->fetch(PDO::FETCH_ASSOC)): ?>
                            <tr>
                                <td>#<?php echo $product['id']; ?></td>
                                <td><?php echo htmlspecialchars($product['name']); ?></td>
                                <td><?php echo htmlspecialchars($product['farmer_name']); ?></td>
                                <td><?php echo $product['category']; ?></td>
                                <td><?php echo $product['quantity']; ?> <?php echo $product['unit']; ?></td>
                                <td>₱<?php echo number_format($product['price'], 2); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo $product['status']; ?>">
                                        <?php echo ucfirst($product['status']); ?>
                                    </span>
                                </td>
                                <td class="action-buttons">
                                    <?php if($product['status'] != 'available'): ?>
                                        <a href="?product_action=available&product_id=<?php echo $product['id']; ?>" class="btn-icon btn-available" onclick="return confirm('Mark this product as available?')">
                                            <i class="fas fa-check"></i> Available
                                        </a>
                                    <?php endif; ?>
                                    <a href="?product_action=delete&product_id=<?php echo $product['id']; ?>" class="btn-icon btn-delete" onclick="return confirm('Delete this product?')">
                                        <i class="fas fa-trash"></i> Delete
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Order Management Section -->
        <div id="orders" class="section">
            <h2 class="section-title">
                <i class="fas fa-shopping-cart"></i> Order Management
                <span style="font-size: 0.9rem;">Total: <?php echo $total_orders; ?> orders</span>
            </h2>
            <div class="data-table">
                <table>
                    <thead>
                        <tr>
                            <th>Order ID</th>
                            <th>Product</th>
                            <th>Buyer</th>
                            <th>Farmer</th>
                            <th>Quantity</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($order = $all_orders->fetch(PDO::FETCH_ASSOC)): ?>
                            <tr>
                                <td>#<?php echo $order['id']; ?></td>
                                <td><?php echo htmlspecialchars($order['product_name']); ?></td>
                                <td><?php echo htmlspecialchars($order['buyer_name']); ?></td>
                                <td><?php echo htmlspecialchars($order['farmer_name']); ?></td>
                                <td><?php echo $order['quantity']; ?></td>
                                <td>₱<?php echo number_format($order['total_price'], 2); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo $order['status']; ?>">
                                        <?php echo ucfirst($order['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($order['order_date'])); ?></td>
                                <td class="action-buttons">
                                    <?php if($order['status'] == 'pending'): ?>
                                        <a href="?order_action=confirmed&order_id=<?php echo $order['id']; ?>" class="btn-icon btn-confirm">Confirm</a>
                                    <?php endif; ?>
                                    <?php if($order['status'] == 'confirmed'): ?>
                                        <a href="?order_action=shipped&order_id=<?php echo $order['id']; ?>" class="btn-icon btn-ship">Ship</a>
                                    <?php endif; ?>
                                    <?php if($order['status'] == 'shipped'): ?>
                                        <a href="?order_action=delivered&order_id=<?php echo $order['id']; ?>" class="btn-icon btn-deliver">Deliver</a>
                                    <?php endif; ?>
                                    <?php if(in_array($order['status'], ['pending', 'confirmed', 'shipped'])): ?>
                                        <a href="?order_action=cancelled&order_id=<?php echo $order['id']; ?>" class="btn-icon btn-cancel" onclick="return confirm('Cancel this order?')">Cancel</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Reports Section -->
        <div id="reports" class="section">
            <h2 class="section-title"><i class="fas fa-file-alt"></i> System Reports</h2>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Platform Summary</h3>
                        <div class="stat-number"><?php echo $total_users; ?></div>
                        <small>Total Users</small>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Active Farmers</h3>
                        <div class="stat-number"><?php echo $total_farmers; ?></div>
                        <small>with <?php echo $total_products; ?> products</small>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Active Buyers</h3>
                        <div class="stat-number"><?php echo $total_buyers; ?></div>
                        <small><?php echo $total_orders; ?> orders placed</small>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Transport Providers</h3>
                        <div class="stat-number"><?php echo $total_transport; ?></div>
                        <small>Available for deliveries</small>
                    </div>
                </div>
            </div>

            <div class="chart-card">
                <h3><i class="fas fa-chart-line"></i> Revenue Overview</h3>
                <canvas id="revenueChart"></canvas>
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

        document.querySelectorAll('.menu-item').forEach(item => {
            item.addEventListener('click', function() {
                const sectionId = this.getAttribute('data-section');
                showSection(sectionId);
            });
        });

        // User Distribution Chart
        const userCtx = document.getElementById('userChart').getContext('2d');
        new Chart(userCtx, {
            type: 'pie',
            data: {
                labels: ['Farmers', 'Buyers', 'Transport Providers'],
                datasets: [{
                    data: [<?php echo $total_farmers; ?>, <?php echo $total_buyers; ?>, <?php echo $total_transport; ?>],
                    backgroundColor: ['#4caf50', '#2196f3', '#ff9800'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });

        // Product Status Chart
        const productCtx = document.getElementById('productChart').getContext('2d');
        new Chart(productCtx, {
            type: 'doughnut',
            data: {
                labels: ['Available', 'Sold', 'Pending'],
                datasets: [{
                    data: [<?php echo $available_products; ?>, <?php echo $sold_products; ?>, <?php echo $total_products - $available_products - $sold_products; ?>],
                    backgroundColor: ['#4caf50', '#f44336', '#ff9800'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });

        // Revenue Chart
        const revenueCtx = document.getElementById('revenueChart').getContext('2d');
        new Chart(revenueCtx, {
            type: 'line',
            data: {
                labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                datasets: [{
                    label: 'Monthly Revenue (₱)',
                    data: [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
                    borderColor: '#4caf50',
                    backgroundColor: 'rgba(76, 175, 80, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'bottom' }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '₱' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });

        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.display = 'none';
            });
        }, 5000);
    </script>
</body>
</html>