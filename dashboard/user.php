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

// Handle Purchase
if(isset($_GET['buy']) && isset($_GET['id'])) {
    $product_id = $_GET['id'];
    
    try {
        // Get product details
        $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND status = 'available'");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch();
        
        if($product) {
            // Create order
            $stmt = $pdo->prepare("INSERT INTO orders (product_id, buyer_id, quantity, total_price, delivery_address) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$product_id, $user_id, $product['quantity'], $product['price'] * $product['quantity'], $_SESSION['address'] ?? '']);
            
            // Update product status
            $stmt = $pdo->prepare("UPDATE products SET status = 'pending' WHERE id = ?");
            $stmt->execute([$product_id]);
            
            $success = "Order placed successfully! The farmer will confirm your order soon.";
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
            
            $success = "Order cancelled successfully!";
        } else {
            $error = "Cannot cancel this order!";
        }
    } catch(PDOException $e) {
        $error = "Failed to cancel order: " . $e->getMessage();
    }
}

// Get user's orders
$orders = $pdo->prepare("
    SELECT o.*, p.name as product_name, p.price as product_price, p.unit,
           u.username as farmer_name, u.phone as farmer_phone
    FROM orders o 
    JOIN products p ON o.product_id = p.id 
    JOIN users u ON p.farmer_id = u.id 
    WHERE o.buyer_id = ? 
    ORDER BY o.order_date DESC
");
$orders->execute([$user_id]);

// Get available products
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

// Get user info
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();
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
        }

        .page-title h2 {
            color: #1976d2;
            font-size: 1.5rem;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .user-info i {
            font-size: 2rem;
            color: #1976d2;
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

        /* Product Grid */
        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 25px;
            margin-top: 20px;
        }

        .product-card {
            background: #f9f9f9;
            border-radius: 10px;
            overflow: hidden;
            transition: all 0.3s;
            border: 1px solid #e0e0e0;
            position: relative;
        }

        .product-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
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
            z-index: 1;
        }

        .product-image {
            height: 180px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
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
            background: linear-gradient(135deg, #1976d2, #1565c0);
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 8px;
            margin-top: 10px;
            width: 100%;
            text-align: center;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
        }

        .btn-buy:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(25,118,210,0.3);
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
        }

        .btn-cancel:hover {
            background: #d32f2f;
        }

        /* Profile Section */
        .profile-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }

        .info-card {
            background: #f9f9f9;
            padding: 20px;
            border-radius: 10px;
            border: 1px solid #e0e0e0;
        }

        .info-card h3 {
            color: #1976d2;
            margin-bottom: 15px;
        }

        .info-item {
            padding: 10px 0;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            gap: 10px;
        }

        .info-item i {
            width: 25px;
            color: #1976d2;
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
                <i class="fas fa-user-circle"></i>
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
                                <i class="fas fa-apple-alt"></i>
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
                                <a href="?buy=1&id=<?php echo $product['id']; ?>" class="btn-buy" onclick="return confirm('Confirm purchase of <?php echo addslashes($product['name']); ?>?')">
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
                                    <td><?php echo htmlspecialchars($order['product_name']); ?></td>
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
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($order['order_date'])); ?></td>
                                    <td>
                                        <?php if($order['status'] == 'pending'): ?>
                                            <a href="?cancel=1&order_id=<?php echo $order['id']; ?>" class="btn-cancel" onclick="return confirm('Cancel this order?')">
                                                <i class="fas fa-times"></i> Cancel
                                            </a>
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

        <!-- Profile Section -->
        <div id="profile" class="section">
            <h2 class="section-title"><i class="fas fa-user-circle"></i> My Profile</h2>
            <div class="profile-info">
                <div class="info-card">
                    <h3><i class="fas fa-user"></i> Personal Information</h3>
                    <div class="info-item">
                        <i class="fas fa-user"></i>
                        <strong>Username:</strong> <?php echo htmlspecialchars($user['username']); ?>
                    </div>
                    <div class="info-item">
                        <i class="fas fa-envelope"></i>
                        <strong>Email:</strong> <?php echo htmlspecialchars($user['email']); ?>
                    </div>
                    <div class="info-item">
                        <i class="fas fa-phone"></i>
                        <strong>Phone:</strong> <?php echo htmlspecialchars($user['phone'] ?: 'Not provided'); ?>
                    </div>
                    <div class="info-item">
                        <i class="fas fa-calendar"></i>
                        <strong>Member since:</strong> <?php echo date('F d, Y', strtotime($user['created_at'])); ?>
                    </div>
                </div>
                <div class="info-card">
                    <h3><i class="fas fa-map-marker-alt"></i> Delivery Address</h3>
                    <div class="info-item">
                        <i class="fas fa-home"></i>
                        <strong>Address:</strong> <?php echo htmlspecialchars($user['address'] ?: 'No address provided'); ?>
                    </div>
                </div>
                <div class="info-card">
                    <h3><i class="fas fa-chart-bar"></i> Shopping Statistics</h3>
                    <div class="info-item">
                        <i class="fas fa-shopping-cart"></i>
                        <strong>Total Orders:</strong> <?php echo $total_orders; ?>
                    </div>
                    <div class="info-item">
                        <i class="fas fa-check-circle"></i>
                        <strong>Completed Orders:</strong> <?php echo $delivered_orders; ?>
                    </div>
                    <div class="info-item">
                        <i class="fas fa-money-bill-wave"></i>
                        <strong>Total Spent:</strong> ₱<?php echo number_format($total_spent, 2); ?>
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

        // Auto-hide alerts
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.display = 'none';
            });
        }, 5000);
    </script>
</body>
</html>