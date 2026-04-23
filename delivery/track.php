<?php
require_once __DIR__ . '/../config/db.php';

if(!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$tracking_code = $_GET['code'] ?? '';
$order_id = $_GET['order_id'] ?? 0;
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Get delivery tracking info
if($tracking_code) {
    $stmt = $pdo->prepare("
        SELECT dt.*, o.id as order_id, o.order_number, o.total_price, o.order_date,
               p.name as product_name, p.quantity, p.unit, p.price,
               u.username as farmer_name, u.phone as farmer_phone, u.address as farmer_address,
               buyer.username as buyer_name, buyer.phone as buyer_phone, buyer.address as delivery_address
        FROM delivery_tracking dt
        JOIN orders o ON dt.order_id = o.id
        JOIN products p ON o.product_id = p.id
        JOIN users u ON p.farmer_id = u.id
        JOIN users buyer ON o.buyer_id = buyer.id
        WHERE dt.tracking_code = ?
    ");
    $stmt->execute([$tracking_code]);
    $delivery = $stmt->fetch();
} elseif($order_id) {
    $stmt = $pdo->prepare("
        SELECT dt.*, o.id as order_id, o.order_number, o.total_price, o.order_date,
               p.name as product_name, p.quantity, p.unit, p.price,
               u.username as farmer_name, u.phone as farmer_phone, u.address as farmer_address,
               buyer.username as buyer_name, buyer.phone as buyer_phone, buyer.address as delivery_address
        FROM delivery_tracking dt
        JOIN orders o ON dt.order_id = o.id
        JOIN products p ON o.product_id = p.id
        JOIN users u ON p.farmer_id = u.id
        JOIN users buyer ON o.buyer_id = buyer.id
        WHERE dt.order_id = ? AND (o.buyer_id = ? OR p.farmer_id = ? OR ? = 'admin')
    ");
    $stmt->execute([$order_id, $user_id, $user_id, $user_role]);
    $delivery = $stmt->fetch();
}

if(!$delivery) {
    die("Delivery not found or you don't have permission to view it!");
}

// Get tracking history
$history = $pdo->prepare("
    SELECT * FROM delivery_tracking_history 
    WHERE delivery_id = ? 
    ORDER BY created_at DESC
");
$history->execute([$delivery['id']]);

// Get estimated delivery time remaining
$estimated = strtotime($delivery['estimated_delivery']);
$now = time();
$time_remaining = $estimated - $now;
$hours_remaining = floor($time_remaining / 3600);
$minutes_remaining = floor(($time_remaining % 3600) / 60);

// Calculate progress percentage based on status
$status_progress = [
    'pending' => 0,
    'confirmed' => 10,
    'preparing' => 25,
    'picked_up' => 50,
    'in_transit' => 75,
    'out_for_delivery' => 90,
    'delivered' => 100
];
$progress = $status_progress[$delivery['status']] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Track Delivery - Agriculture Marketplace</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 40px 20px;
        }
        .tracking-container { max-width: 900px; margin: 0 auto; }
        
        .tracking-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
            margin-bottom: 20px;
            animation: slideUp 0.6s ease;
        }
        
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .tracking-header {
            background: linear-gradient(135deg, #2e7d32, #1b5e20);
            padding: 30px;
            color: white;
            text-align: center;
        }
        
        .tracking-header i { font-size: 3rem; margin-bottom: 10px; }
        .tracking-header h2 { font-size: 1.8rem; margin-bottom: 5px; }
        
        .tracking-body { padding: 30px; }
        
        .tracking-code-box {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 15px;
            text-align: center;
            margin-bottom: 30px;
        }
        
        .tracking-code-box span {
            font-family: monospace;
            font-size: 1.5rem;
            letter-spacing: 3px;
            font-weight: bold;
            color: #2e7d32;
            background: white;
            padding: 8px 20px;
            border-radius: 10px;
            display: inline-block;
        }
        
        /* Progress Bar */
        .progress-section { margin-bottom: 40px; }
        .progress-bar-container {
            background: #e0e0e0;
            border-radius: 10px;
            height: 10px;
            overflow: hidden;
            margin: 20px 0;
        }
        .progress-bar {
            background: linear-gradient(90deg, #2e7d32, #4caf50);
            width: <?php echo $progress; ?>%;
            height: 100%;
            transition: width 0.5s ease;
            position: relative;
        }
        .progress-bar::after {
            content: '<?php echo $progress; ?>%';
            position: absolute;
            right: 0;
            top: -25px;
            font-size: 0.8rem;
            color: #2e7d32;
            font-weight: bold;
        }
        
        /* Status Steps */
        .status-steps {
            display: flex;
            justify-content: space-between;
            margin-top: 30px;
            position: relative;
            flex-wrap: wrap;
        }
        
        .status-steps::before {
            content: '';
            position: absolute;
            top: 25px;
            left: 0;
            width: 100%;
            height: 3px;
            background: #e0e0e0;
            z-index: 0;
        }
        
        .step {
            text-align: center;
            flex: 1;
            position: relative;
            z-index: 1;
            min-width: 80px;
        }
        
        .step-circle {
            width: 50px;
            height: 50px;
            background: white;
            border: 3px solid #e0e0e0;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
            transition: all 0.3s;
        }
        
        .step.completed .step-circle {
            background: #4caf50;
            border-color: #4caf50;
            color: white;
        }
        
        .step.active .step-circle {
            border-color: #ff9800;
            background: #fff3e0;
        }
        
        .step.active .step-circle i { color: #ff9800; }
        .step.completed .step-circle i { color: white; }
        
        .step-circle i { font-size: 1.3rem; color: #ccc; }
        .step-label {
            font-size: 0.8rem;
            color: #666;
        }
        .step.completed .step-label { color: #4caf50; font-weight: bold; }
        .step.active .step-label { color: #ff9800; font-weight: bold; }
        
        /* Info Cards */
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin: 20px 0;
        }
        
        .info-card {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 20px;
        }
        
        .info-card h4 {
            color: #2e7d32;
            margin-bottom: 15px;
            border-bottom: 2px solid #e0e0e0;
            padding-bottom: 8px;
        }
        
        .info-row {
            display: flex;
            padding: 8px 0;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .info-row:last-child { border-bottom: none; }
        .info-row .label {
            width: 100px;
            font-weight: bold;
            color: #666;
        }
        .info-row .value { flex: 1; color: #333; }
        
        /* Timeline */
        .timeline {
            margin-top: 30px;
        }
        
        .timeline-item {
            display: flex;
            gap: 15px;
            padding: 15px 0;
            border-left: 2px solid #e0e0e0;
            margin-left: 25px;
            position: relative;
        }
        
        .timeline-icon {
            width: 40px;
            height: 40px;
            background: white;
            border: 2px solid #2e7d32;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            position: absolute;
            left: -21px;
            top: 10px;
        }
        
        .timeline-icon i { color: #2e7d32; }
        .timeline-content { flex: 1; padding-left: 30px; }
        .timeline-title { font-weight: bold; color: #333; }
        .timeline-date { font-size: 0.8rem; color: #999; margin-top: 5px; }
        .timeline-location { font-size: 0.85rem; color: #666; margin-top: 5px; }
        
        /* Driver Info */
        .driver-card {
            background: linear-gradient(135deg, #2196f3, #1976d2);
            color: white;
            padding: 20px;
            border-radius: 15px;
            margin-top: 20px;
        }
        
        .driver-card h4 { margin-bottom: 15px; }
        .driver-info { display: flex; align-items: center; gap: 15px; flex-wrap: wrap; }
        .driver-info i { font-size: 2rem; }
        
        /* Map Placeholder */
        .map-placeholder {
            background: #e8eaf6;
            height: 200px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 20px 0;
            background-image: url('https://maps.googleapis.com/maps/api/staticmap?center=Manila,Philippines&zoom=12&size=600x200&key=YOUR_API_KEY');
            background-size: cover;
            background-position: center;
        }
        
        .search-box {
            background: white;
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
        }
        
        .search-box input {
            flex: 1;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
        }
        
        .search-box button {
            padding: 12px 25px;
            background: #2e7d32;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
        }
        
        .btn-refresh {
            display: inline-block;
            background: #ff9800;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 8px;
            margin-top: 20px;
        }
        
        @media (max-width: 768px) {
            .info-grid { grid-template-columns: 1fr; }
            .status-steps::before { display: none; }
            .step { margin-bottom: 15px; }
            .step-circle { width: 40px; height: 40px; }
            .step-circle i { font-size: 1rem; }
        }
    </style>
</head>
<body>
    <div class="tracking-container">
        <!-- Search Box -->
        <div class="tracking-card">
            <div class="search-box">
                <input type="text" id="trackingInput" placeholder="Enter tracking code (e.g., DEL-20241234-5678)" value="<?php echo htmlspecialchars($tracking_code); ?>">
                <button onclick="searchTracking()"><i class="fas fa-search"></i> Track</button>
            </div>
        </div>
        
        <div class="tracking-card">
            <div class="tracking-header">
                <i class="fas fa-truck"></i>
                <h2>Track Your Delivery</h2>
                <p>Real-time delivery status</p>
            </div>
            
            <div class="tracking-body">
                <div class="tracking-code-box">
                    <i class="fas fa-barcode"></i> Tracking Code: 
                    <span><?php echo htmlspecialchars($delivery['tracking_code']); ?></span>
                </div>
                
                <!-- Progress Bar -->
                <div class="progress-section">
                    <div class="progress-bar-container">
                        <div class="progress-bar"></div>
                    </div>
                </div>
                
                <!-- Status Steps -->
                <div class="status-steps">
                    <div class="step <?php echo in_array($delivery['status'], ['confirmed', 'preparing', 'picked_up', 'in_transit', 'out_for_delivery', 'delivered']) ? 'completed' : ($delivery['status'] == 'pending' ? 'active' : ''); ?>">
                        <div class="step-circle"><i class="fas fa-check"></i></div>
                        <div class="step-label">Order Placed</div>
                    </div>
                    <div class="step <?php echo in_array($delivery['status'], ['preparing', 'picked_up', 'in_transit', 'out_for_delivery', 'delivered']) ? 'completed' : ($delivery['status'] == 'confirmed' ? 'active' : ''); ?>">
                        <div class="step-circle"><i class="fas fa-clipboard-check"></i></div>
                        <div class="step-label">Confirmed</div>
                    </div>
                    <div class="step <?php echo in_array($delivery['status'], ['picked_up', 'in_transit', 'out_for_delivery', 'delivered']) ? 'completed' : ($delivery['status'] == 'preparing' ? 'active' : ''); ?>">
                        <div class="step-circle"><i class="fas fa-box"></i></div>
                        <div class="step-label">Preparing</div>
                    </div>
                    <div class="step <?php echo in_array($delivery['status'], ['in_transit', 'out_for_delivery', 'delivered']) ? 'completed' : ($delivery['status'] == 'picked_up' ? 'active' : ''); ?>">
                        <div class="step-circle"><i class="fas fa-store"></i></div>
                        <div class="step-label">Picked Up</div>
                    </div>
                    <div class="step <?php echo in_array($delivery['status'], ['out_for_delivery', 'delivered']) ? 'completed' : ($delivery['status'] == 'in_transit' ? 'active' : ''); ?>">
                        <div class="step-circle"><i class="fas fa-truck"></i></div>
                        <div class="step-label">In Transit</div>
                    </div>
                    <div class="step <?php echo $delivery['status'] == 'delivered' ? 'completed' : ($delivery['status'] == 'out_for_delivery' ? 'active' : ''); ?>">
                        <div class="step-circle"><i class="fas fa-bell"></i></div>
                        <div class="step-label">Out for Delivery</div>
                    </div>
                    <div class="step <?php echo $delivery['status'] == 'delivered' ? 'completed' : ''; ?>">
                        <div class="step-circle"><i class="fas fa-home"></i></div>
                        <div class="step-label">Delivered</div>
                    </div>
                </div>
                
                <!-- Estimated Delivery -->
                <?php if($delivery['estimated_delivery'] && $delivery['status'] != 'delivered'): ?>
                <div style="text-align: center; margin: 20px 0; padding: 15px; background: #e8f5e9; border-radius: 10px;">
                    <i class="fas fa-clock"></i>
                    <strong>Estimated Delivery:</strong>
                    <?php if($time_remaining > 0): ?>
                        <?php echo date('F d, Y h:i A', strtotime($delivery['estimated_delivery'])); ?>
                        (<?php echo $hours_remaining > 0 ? $hours_remaining . ' hours' : $minutes_remaining . ' minutes'; ?> remaining)
                    <?php else: ?>
                        Today (Any moment now)
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <!-- Current Status Message -->
                <div style="text-align: center; margin: 20px 0;">
                    <span style="display: inline-block; padding: 8px 20px; background: <?php 
                        echo $delivery['status'] == 'delivered' ? '#4caf50' : ($delivery['status'] == 'pending' ? '#ff9800' : '#2196f3'); 
                    ?>; color: white; border-radius: 25px;">
                        <i class="fas <?php 
                            echo $delivery['status'] == 'delivered' ? 'fa-check-circle' : ($delivery['status'] == 'pending' ? 'fa-clock' : 'fa-truck'); 
                        ?>"></i>
                        Current Status: <?php echo ucfirst(str_replace('_', ' ', $delivery['status'])); ?>
                    </span>
                </div>
                
                <!-- Information Grid -->
                <div class="info-grid">
                    <div class="info-card">
                        <h4><i class="fas fa-box"></i> Order Details</h4>
                        <div class="info-row">
                            <div class="label">Order #:</div>
                            <div class="value"><?php echo $delivery['order_number'] ?? '#' . $delivery['order_id']; ?></div>
                        </div>
                        <div class="info-row">
                            <div class="label">Product:</div>
                            <div class="value"><?php echo htmlspecialchars($delivery['product_name']); ?></div>
                        </div>
                        <div class="info-row">
                            <div class="label">Quantity:</div>
                            <div class="value"><?php echo $delivery['quantity']; ?> <?php echo $delivery['unit']; ?></div>
                        </div>
                        <div class="info-row">
                            <div class="label">Total Amount:</div>
                            <div class="value">₱<?php echo number_format($delivery['total_price'], 2); ?></div>
                        </div>
                        <div class="info-row">
                            <div class="label">Order Date:</div>
                            <div class="value"><?php echo date('F d, Y h:i A', strtotime($delivery['order_date'])); ?></div>
                        </div>
                    </div>
                    
                    <div class="info-card">
                        <h4><i class="fas fa-map-marker-alt"></i> Delivery Address</h4>
                        <div class="info-row">
                            <div class="label">Recipient:</div>
                            <div class="value"><?php echo htmlspecialchars($delivery['buyer_name']); ?></div>
                        </div>
                        <div class="info-row">
                            <div class="label">Phone:</div>
                            <div class="value"><?php echo htmlspecialchars($delivery['buyer_phone']); ?></div>
                        </div>
                        <div class="info-row">
                            <div class="label">Address:</div>
                            <div class="value"><?php echo htmlspecialchars($delivery['delivery_address']); ?></div>
                        </div>
                    </div>
                </div>
                
                <!-- Driver Information -->
                <?php if($delivery['driver_name']): ?>
                <div class="driver-card">
                    <h4><i class="fas fa-user"></i> Driver Information</h4>
                    <div class="driver-info">
                        <i class="fas fa-user-circle"></i>
                        <div>
                            <strong><?php echo htmlspecialchars($delivery['driver_name']); ?></strong><br>
                            <i class="fas fa-phone"></i> <?php echo htmlspecialchars($delivery['driver_phone']); ?>
                        </div>
                        <?php if($delivery['driver_vehicle']): ?>
                        <div>
                            <i class="fas fa-truck"></i> <?php echo htmlspecialchars($delivery['driver_vehicle']); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Tracking Timeline -->
                <?php if($history->rowCount() > 0): ?>
                <h3 style="margin: 30px 0 15px;"><i class="fas fa-history"></i> Tracking History</h3>
                <div class="timeline">
                    <?php while($item = $history->fetch()): ?>
                    <div class="timeline-item">
                        <div class="timeline-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="timeline-content">
                            <div class="timeline-title"><?php echo ucfirst(str_replace('_', ' ', $item['status'])); ?></div>
                            <div class="timeline-date"><?php echo date('F d, Y h:i A', strtotime($item['created_at'])); ?></div>
                            <?php if($item['location']): ?>
                            <div class="timeline-location"><i class="fas fa-map-pin"></i> <?php echo htmlspecialchars($item['location']); ?></div>
                            <?php endif; ?>
                            <?php if($item['notes']): ?>
                            <div class="timeline-location"><i class="fas fa-comment"></i> <?php echo htmlspecialchars($item['notes']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
                <?php endif; ?>
                
                <!-- Refresh Button -->
                <div style="text-align: center; margin-top: 20px;">
                    <a href="#" onclick="location.reload();" class="btn-refresh">
                        <i class="fas fa-sync-alt"></i> Refresh Status
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        function searchTracking() {
            const code = document.getElementById('trackingInput').value;
            if(code) {
                window.location.href = 'track.php?code=' + encodeURIComponent(code);
            } else {
                alert('Please enter a tracking code');
            }
        }
        
        // Auto-refresh every 30 seconds
        setTimeout(function() {
            location.reload();
        }, 30000);
        
        // Enter key search
        document.getElementById('trackingInput').addEventListener('keypress', function(e) {
            if(e.key === 'Enter') {
                searchTracking();
            }
        });
    </script>
</body>
</html>