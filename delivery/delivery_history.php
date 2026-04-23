<?php
require_once __DIR__ . '/../config/db.php';

if(!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];
$limit = $_GET['limit'] ?? 20;
$page = $_GET['page'] ?? 1;
$offset = ($page - 1) * $limit;

// Build query based on user role
if($user_role == 'admin') {
    // Admin sees all deliveries
    $count_sql = "SELECT COUNT(*) FROM delivery_tracking";
    $count_stmt = $pdo->query($count_sql);
    
    $sql = "
        SELECT dt.*, o.order_number, o.total_price, o.order_date,
               p.name as product_name, p.quantity, p.unit,
               u.username as farmer_name,
               buyer.username as buyer_name, buyer.address as delivery_address
        FROM delivery_tracking dt
        JOIN orders o ON dt.order_id = o.id
        JOIN products p ON o.product_id = p.id
        JOIN users u ON p.farmer_id = u.id
        JOIN users buyer ON o.buyer_id = buyer.id
        ORDER BY dt.created_at DESC
        LIMIT ? OFFSET ?
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$limit, $offset]);
    
} elseif($user_role == 'farmer') {
    // Farmer sees deliveries for their products
    $count_sql = "
        SELECT COUNT(*) FROM delivery_tracking dt
        JOIN orders o ON dt.order_id = o.id
        JOIN products p ON o.product_id = p.id
        WHERE p.farmer_id = ?
    ";
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute([$user_id]);
    
    $sql = "
        SELECT dt.*, o.order_number, o.total_price, o.order_date,
               p.name as product_name, p.quantity, p.unit,
               buyer.username as buyer_name, buyer.address as delivery_address
        FROM delivery_tracking dt
        JOIN orders o ON dt.order_id = o.id
        JOIN products p ON o.product_id = p.id
        JOIN users buyer ON o.buyer_id = buyer.id
        WHERE p.farmer_id = ?
        ORDER BY dt.created_at DESC
        LIMIT ? OFFSET ?
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id, $limit, $offset]);
    
} elseif($user_role == 'transport') {
    // Transport sees their assigned deliveries
    $count_sql = "
        SELECT COUNT(*) FROM delivery_tracking dt
        JOIN transport_requests tr ON dt.order_id = tr.order_id
        WHERE tr.transport_id = ?
    ";
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute([$user_id]);
    
    $sql = "
        SELECT dt.*, o.order_number, o.total_price, o.order_date,
               p.name as product_name, p.quantity, p.unit,
               u.username as farmer_name, u.address as pickup_address,
               buyer.username as buyer_name, buyer.address as delivery_address
        FROM delivery_tracking dt
        JOIN orders o ON dt.order_id = o.id
        JOIN products p ON o.product_id = p.id
        JOIN users u ON p.farmer_id = u.id
        JOIN users buyer ON o.buyer_id = buyer.id
        JOIN transport_requests tr ON dt.order_id = tr.order_id
        WHERE tr.transport_id = ?
        ORDER BY dt.created_at DESC
        LIMIT ? OFFSET ?
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id, $limit, $offset]);
    
} else {
    // Buyer sees their own deliveries
    $count_sql = "
        SELECT COUNT(*) FROM delivery_tracking dt
        JOIN orders o ON dt.order_id = o.id
        WHERE o.buyer_id = ?
    ";
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute([$user_id]);
    
    $sql = "
        SELECT dt.*, o.order_number, o.total_price, o.order_date,
               p.name as product_name, p.quantity, p.unit,
               u.username as farmer_name, u.phone as farmer_phone
        FROM delivery_tracking dt
        JOIN orders o ON dt.order_id = o.id
        JOIN products p ON o.product_id = p.id
        JOIN users u ON p.farmer_id = u.id
        WHERE o.buyer_id = ?
        ORDER BY dt.created_at DESC
        LIMIT ? OFFSET ?
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id, $limit, $offset]);
}

$total_records = $count_stmt->fetchColumn();
$total_pages = ceil($total_records / $limit);

// Get statistics
$stats = [];
if($user_role == 'admin') {
    $stats['total'] = $pdo->query("SELECT COUNT(*) FROM delivery_tracking")->fetchColumn();
    $stats['delivered'] = $pdo->query("SELECT COUNT(*) FROM delivery_tracking WHERE status = 'delivered'")->fetchColumn();
    $stats['in_transit'] = $pdo->query("SELECT COUNT(*) FROM delivery_tracking WHERE status IN ('picked_up', 'in_transit', 'out_for_delivery')")->fetchColumn();
    $stats['pending'] = $pdo->query("SELECT COUNT(*) FROM delivery_tracking WHERE status = 'pending'")->fetchColumn();
} else {
    // Role-specific statistics
    if($user_role == 'farmer') {
        $stats['total'] = $pdo->prepare("
            SELECT COUNT(*) FROM delivery_tracking dt
            JOIN orders o ON dt.order_id = o.id
            JOIN products p ON o.product_id = p.id
            WHERE p.farmer_id = ?
        ")->execute([$user_id]);
    } elseif($user_role == 'transport') {
        $stats['total'] = $pdo->prepare("
            SELECT COUNT(*) FROM delivery_tracking dt
            JOIN transport_requests tr ON dt.order_id = tr.order_id
            WHERE tr.transport_id = ?
        ")->execute([$user_id]);
    } else {
        $stats['total'] = $pdo->prepare("
            SELECT COUNT(*) FROM delivery_tracking dt
            JOIN orders o ON dt.order_id = o.id
            WHERE o.buyer_id = ?
        ")->execute([$user_id]);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery History - Agriculture Marketplace</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 40px 20px;
        }
        .history-container { max-width: 1400px; margin: 0 auto; }
        
        .history-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
            margin-bottom: 20px;
        }
        
        .history-header {
            background: linear-gradient(135deg, #2e7d32, #1b5e20);
            padding: 25px 30px;
            color: white;
        }
        
        .history-header h2 { margin-bottom: 5px; }
        .history-header i { margin-right: 10px; }
        
        .stats-bar {
            display: flex;
            gap: 20px;
            padding: 20px 30px;
            background: #f8f9fa;
            border-bottom: 1px solid #e0e0e0;
            flex-wrap: wrap;
        }
        
        .stat-pill {
            background: white;
            padding: 10px 20px;
            border-radius: 10px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .stat-pill .label { font-size: 0.8rem; color: #666; }
        .stat-pill .value { font-size: 1.3rem; font-weight: bold; color: #2e7d32; }
        
        .filter-bar {
            padding: 20px 30px;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .filter-bar select, .filter-bar input {
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
        }
        
        .table-container {
            padding: 0 30px 30px;
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
            font-weight: 600;
            color: #333;
        }
        
        tr:hover { background: #f9f9f9; }
        
        .status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .status-delivered { background: #d4edda; color: #155724; }
        .status-in_transit, .status-picked_up, .status-out_for_delivery { background: #cce5ff; color: #004085; }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-cancelled { background: #f8d7da; color: #721c24; }
        
        .track-link {
            color: #2e7d32;
            text-decoration: none;
            font-weight: 600;
        }
        
        .track-link:hover { text-decoration: underline; }
        
        .pagination {
            display: flex;
            justify-content: center;
            gap: 10px;
            padding: 20px;
        }
        
        .pagination a, .pagination span {
            padding: 8px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            text-decoration: none;
            color: #333;
        }
        
        .pagination .active {
            background: #2e7d32;
            color: white;
            border-color: #2e7d32;
        }
        
        .empty-state {
            text-align: center;
            padding: 50px;
        }
        
        .empty-state i { font-size: 4rem; color: #ccc; margin-bottom: 20px; }
        
        @media (max-width: 768px) {
            th, td { font-size: 0.85rem; }
            .stats-bar { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="history-container">
        <div class="history-card">
            <div class="history-header">
                <h2><i class="fas fa-history"></i> Delivery History</h2>
                <p>Track and manage all your deliveries</p>
            </div>
            
            <div class="stats-bar">
                <div class="stat-pill">
                    <div class="label">Total Deliveries</div>
                    <div class="value"><?php echo $total_records; ?></div>
                </div>
                <?php if(isset($stats['delivered'])): ?>
                <div class="stat-pill">
                    <div class="label">Delivered</div>
                    <div class="value"><?php echo $stats['delivered']; ?></div>
                </div>
                <div class="stat-pill">
                    <div class="label">In Transit</div>
                    <div class="value"><?php echo $stats['in_transit']; ?></div>
                </div>
                <div class="stat-pill">
                    <div class="label">Pending</div>
                    <div class="value"><?php echo $stats['pending']; ?></div>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="filter-bar">
                <select id="statusFilter">
                    <option value="">All Status</option>
                    <option value="pending">Pending</option>
                    <option value="picked_up">Picked Up</option>
                    <option value="in_transit">In Transit</option>
                    <option value="out_for_delivery">Out for Delivery</option>
                    <option value="delivered">Delivered</option>
                    <option value="cancelled">Cancelled</option>
                </select>
                <input type="text" id="searchInput" placeholder="Search by tracking code or product...">
                <button onclick="applyFilters()" class="track-link" style="background: #2e7d32; color: white; padding: 10px 20px; border: none; border-radius: 8px;">
                    <i class="fas fa-search"></i> Filter
                </button>
            </div>
            
            <div class="table-container">
                <?php if($stmt->rowCount() > 0): ?>
                <table id="deliveryTable">
                    <thead>
                        <tr>
                            <th>Tracking Code</th>
                            <th>Order #</th>
                            <th>Product</th>
                            <th><?php echo $user_role == 'farmer' ? 'Buyer' : ($user_role == 'transport' ? 'Route' : 'Farmer'); ?></th>
                            <th>Status</th>
                            <th>Order Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($delivery = $stmt->fetch(PDO::FETCH_ASSOC)): ?>
                        <tr data-status="<?php echo $delivery['status']; ?>" data-search="<?php echo strtolower($delivery['tracking_code'] . ' ' . $delivery['product_name']); ?>">
                            <td>
                                <code><?php echo htmlspecialchars($delivery['tracking_code']); ?></code>
                            </td>
                            <td>#<?php echo $delivery['order_number'] ?? $delivery['order_id']; ?></td>
                            <td><?php echo htmlspecialchars($delivery['product_name']); ?></td>
                            <td>
                                <?php if($user_role == 'farmer'): ?>
                                    <?php echo htmlspecialchars($delivery['buyer_name']); ?>
                                <?php elseif($user_role == 'transport'): ?>
                                    <?php echo substr(htmlspecialchars($delivery['pickup_address'] ?? ''), 0, 30); ?> → <?php echo substr(htmlspecialchars($delivery['delivery_address']), 0, 30); ?>
                                <?php else: ?>
                                    <?php echo htmlspecialchars($delivery['farmer_name']); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status status-<?php echo $delivery['status']; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $delivery['status'])); ?>
                                </span>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($delivery['order_date'])); ?></td>
                            <td>
                                <a href="track.php?code=<?php echo urlencode($delivery['tracking_code']); ?>" class="track-link">
                                    <i class="fas fa-eye"></i> Track
                                </a>
                                <?php if(in_array($user_role, ['admin', 'transport']) && $delivery['status'] != 'delivered' && $delivery['status'] != 'cancelled'): ?>
                                    | <a href="update_status.php?id=<?php echo $delivery['id']; ?>&status=delivered" class="track-link" style="color: #4caf50;" onclick="return confirm('Mark as delivered?')">
                                        <i class="fas fa-check"></i> Complete
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-truck"></i>
                    <h3>No Deliveries Found</h3>
                    <p>You don't have any deliveries yet.</p>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if($total_pages > 1): ?>
            <div class="pagination">
                <?php if($page > 1): ?>
                    <a href="?page=<?php echo $page-1; ?>&limit=<?php echo $limit; ?>">« Previous</a>
                <?php endif; ?>
                
                <?php for($i = 1; $i <= $total_pages; $i++): ?>
                    <?php if($i == $page): ?>
                        <span class="active"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="?page=<?php echo $i; ?>&limit=<?php echo $limit; ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if($page < $total_pages): ?>
                    <a href="?page=<?php echo $page+1; ?>&limit=<?php echo $limit; ?>">Next »</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        function applyFilters() {
            const status = document.getElementById('statusFilter').value;
            const search = document.getElementById('searchInput').value.toLowerCase();
            const rows = document.querySelectorAll('#deliveryTable tbody tr');
            
            rows.forEach(row => {
                let show = true;
                
                if(status && row.getAttribute('data-status') !== status) {
                    show = false;
                }
                
                if(search && !row.getAttribute('data-search').includes(search)) {
                    show = false;
                }
                
                row.style.display = show ? '' : 'none';
            });
        }
        
        // Auto-refresh every 60 seconds for transport and admin
        <?php if(in_array($user_role, ['admin', 'transport'])): ?>
        setTimeout(function() {
            location.reload();
        }, 60000);
        <?php endif; ?>
    </script>
</body>
</html>