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

// Handle Add Product
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
            $stmt = $pdo->prepare("INSERT INTO products (farmer_id, name, category, quantity, unit, price, description) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$farmer_id, $name, $category, $quantity, $unit, $price, $description]);
            $success = "Product added successfully!";
        } catch(PDOException $e) {
            $error = "Failed to add product: " . $e->getMessage();
        }
    }
}

// Handle Delete Product
if(isset($_GET['delete'])) {
    $product_id = $_GET['delete'];
    try {
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
        }

        .page-title h2 {
            color: #2e7d32;
            font-size: 1.5rem;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .user-info i {
            font-size: 2rem;
            color: #2e7d32;
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
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
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
            color: #2e7d32;
        }

        .stat-icon i {
            font-size: 3rem;
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
            font-size: 1.5rem;
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
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .product-card {
            background: #f9f9f9;
            border-radius: 10px;
            padding: 20px;
            transition: all 0.3s;
            border: 1px solid #e0e0e0;
        }

        .product-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }

        .product-card h3 {
            color: #2e7d32;
            margin-bottom: 10px;
        }

        .product-price {
            font-size: 1.5rem;
            color: #ff9800;
            font-weight: bold;
            margin: 10px 0;
        }

        .product-status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            margin: 10px 0;
        }

        .status-available {
            background: #d4edda;
            color: #155724;
        }

        .status-sold {
            background: #f8d7da;
            color: #721c24;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .product-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }

        .btn-edit, .btn-delete, .btn-sold {
            padding: 6px 12px;
            border-radius: 5px;
            text-decoration: none;
            font-size: 0.85rem;
            transition: all 0.3s;
        }

        .btn-edit {
            background: #2196f3;
            color: white;
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
        }

        .status-pending { background: #ff9800; color: white; }
        .status-confirmed { background: #2196f3; color: white; }
        .status-shipped { background: #9c27b0; color: white; }
        .status-delivered { background: #4caf50; color: white; }
        .status-cancelled { background: #f44336; color: white; }

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
            .form-row {
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
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="top-navbar">
            <div class="page-title">
                <h2><i class="fas fa-tractor"></i> Farmer Dashboard</h2>
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

            <div style="background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 30px; border-radius: 10px; text-align: center;">
                <i class="fas fa-chart-line" style="font-size: 3rem; margin-bottom: 10px;"></i>
                <h3>Welcome to Your Farm Dashboard!</h3>
                <p>Manage your products, track orders, and grow your business.</p>
            </div>
        </div>

        <!-- Add Product Section -->
        <div id="add-product" class="section">
            <h2 class="section-title"><i class="fas fa-plus-circle"></i> Add New Product</h2>
            <form method="POST" action="">
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
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div style="text-align: center; padding: 50px;">
                    <i class="fas fa-box-open" style="font-size: 4rem; color: #ccc;"></i>
                    <p style="margin-top: 20px;">No products added yet.</p>
                    <a href="#" onclick="showSection('add-product')" class="btn-submit" style="display: inline-block; margin-top: 20px;">
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
                <div style="text-align: center; padding: 50px;">
                    <i class="fas fa-inbox" style="font-size: 4rem; color: #ccc;"></i>
                    <p style="margin-top: 20px;">No orders yet.</p>
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

            <div style="background: linear-gradient(135deg, #2e7d32, #1b5e20); color: white; padding: 30px; border-radius: 10px; text-align: center; margin-top: 20px;">
                <i class="fas fa-trophy" style="font-size: 3rem; margin-bottom: 10px;"></i>
                <h3>Great Job, Farmer!</h3>
                <p>Keep providing quality products to your customers.</p>
            </div>
        </div>
    </div>

    <script>
        // Section switching
        function showSection(sectionId) {
            // Hide all sections
            document.querySelectorAll('.section').forEach(section => {
                section.classList.remove('active');
            });
            // Show selected section
            document.getElementById(sectionId).classList.add('active');
            
            // Update active menu item
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

        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.display = 'none';
            });
        }, 5000);
    </script>
</body>
</html>