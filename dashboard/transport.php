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

// Handle Accept Delivery Request
if(isset($_GET['accept']) && isset($_GET['request_id'])) {
    $request_id = $_GET['request_id'];
    
    try {
        $stmt = $pdo->prepare("UPDATE transport_requests SET transport_id = ?, status = 'accepted' WHERE id = ? AND status = 'pending'");
        $stmt->execute([$transport_id, $request_id]);
        
        if($stmt->rowCount() > 0) {
            // Get order details to update order status
            $stmt = $pdo->prepare("SELECT order_id FROM transport_requests WHERE id = ?");
            $stmt->execute([$request_id]);
            $request = $stmt->fetch();
            
            $stmt = $pdo->prepare("UPDATE orders SET status = 'shipped' WHERE id = ?");
            $stmt->execute([$request['order_id']]);
            
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
            // Get order details
            $stmt = $pdo->prepare("SELECT order_id FROM transport_requests WHERE id = ?");
            $stmt->execute([$request_id]);
            $request = $stmt->fetch();
            
            // Update order status
            $stmt = $pdo->prepare("UPDATE orders SET status = 'delivered' WHERE id = ?");
            $stmt->execute([$request['order_id']]);
            
            $success = "Delivery completed successfully!";
        } else {
            $error = "Failed to complete delivery!";
        }
    } catch(PDOException $e) {
        $error = "Failed to complete delivery: " . $e->getMessage();
    }
}

// Get statistics
// Total deliveries completed
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM transport_requests WHERE transport_id = ? AND status = 'completed'");
$stmt->execute([$transport_id]);
$completed_deliveries = $stmt->fetchColumn();

// Pending deliveries
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM transport_requests WHERE transport_id = ? AND status = 'accepted'");
$stmt->execute([$transport_id]);
$pending_deliveries = $stmt->fetchColumn();

// Available requests
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM transport_requests WHERE status = 'pending'");
$stmt->execute([]);
$available_requests = $stmt->fetchColumn();

// Total earnings (you can add delivery fee later)
$total_earnings = $completed_deliveries * 50; // Example: ₱50 per delivery

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
            background: linear-gradient(135deg, #e65100, #ef6c00);
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
            border-left: 4px solid #ffd700;
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
            color: #ef6c00;
            font-size: 1.5rem;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .user-info i {
            font-size: 2rem;
            color: #ef6c00;
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
            color: #ef6c00;
        }

        .stat-icon i {
            font-size: 3rem;
            color: #ff9800;
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
            color: #ef6c00;
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
            background: #f9f9f9;
            border-radius: 10px;
            overflow: hidden;
            transition: all 0.3s;
            border: 1px solid #e0e0e0;
            position: relative;
        }

        .delivery-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        }

        .delivery-header {
            background: linear-gradient(135deg, #ef6c00, #e65100);
            color: white;
            padding: 15px;
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
            background: white;
            border-radius: 8px;
        }

        .info-row i {
            width: 25px;
            color: #ef6c00;
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
            padding: 15px;
            border-top: 1px solid #e0e0e0;
            display: flex;
            gap: 10px;
        }

        .btn-accept {
            background: #4caf50;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }

        .btn-complete {
            background: #2196f3;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
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
            background: #f5f5f5;
            color: #333;
            font-weight: 600;
        }

        tr:hover {
            background: #f9f9f9;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .status-pending { background: #ff9800; color: white; }
        .status-accepted { background: #2196f3; color: white; }
        .status-completed { background: #4caf50; color: white; }

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
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <i class="fas fa-truck"></i>
            <h3>Transport Panel</h3>
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
                    <span style="background: #ff5722; padding: 2px 8px; border-radius: 20px; font-size: 0.75rem;"><?php echo $available_requests; ?></span>
                <?php endif; ?>
            </div>
            <div class="menu-item" data-section="active">
                <i class="fas fa-truck-moving"></i>
                <span>My Active Deliveries</span>
                <?php if($pending_deliveries > 0): ?>
                    <span style="background: #4caf50; padding: 2px 8px; border-radius: 20px; font-size: 0.75rem;"><?php echo $pending_deliveries; ?></span>
                <?php endif; ?>
            </div>
            <div class="menu-item" data-section="history">
                <i class="fas fa-history"></i>
                <span>Delivery History</span>
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
            <h2 class="section-title">Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?>!</h2>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Available Requests</h3>
                        <div class="stat-number"><?php echo $available_requests; ?></div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-bell"></i>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Active Deliveries</h3>
                        <div class="stat-number"><?php echo $pending_deliveries; ?></div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-truck-moving"></i>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Completed Deliveries</h3>
                        <div class="stat-number"><?php echo $completed_deliveries; ?></div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
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

            <div style="background: linear-gradient(135deg, #ef6c00, #e65100); color: white; padding: 30px; border-radius: 10px; text-align: center;">
                <i class="fas fa-truck" style="font-size: 3rem; margin-bottom: 10px;"></i>
                <h3>Ready for Delivery!</h3>
                <p>Accept delivery requests and earn money by delivering fresh products.</p>
                <a href="#" onclick="showSection('available')" style="display: inline-block; margin-top: 15px; background: white; color: #ef6c00; padding: 10px 20px; border-radius: 5px; text-decoration: none;">
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
                                <span class="delivery-badge">New Request</span>
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
                                <span class="delivery-badge">In Progress</span>
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
                                    <td><span class="status-badge status-completed">Completed</span></td>
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
                            <i class="fas fa-money-bill-wave"></i>
                            <strong>Total Earnings:</strong>
                            <span>₱<?php echo number_format($total_earnings, 2); ?></span>
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

        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.display = 'none';
            });
        }, 5000);
    </script>
</body>
</html>