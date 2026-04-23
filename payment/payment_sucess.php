<?php
require_once __DIR__ . '/../config/db.php';

if(!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$order_id = $_GET['order_id'] ?? 0;
$user_id = $_SESSION['user_id'];

// Get order details
$stmt = $pdo->prepare("
    SELECT o.*, p.name as product_name, p.unit, u.username as farmer_name,
           p.price as unit_price
    FROM orders o 
    JOIN products p ON o.product_id = p.id 
    JOIN users u ON p.farmer_id = u.id 
    WHERE o.id = ? AND o.buyer_id = ?
");
$stmt->execute([$order_id, $user_id]);
$order = $stmt->fetch();

if(!$order) {
    header("Location: ../index.php");
    exit();
}

// Get payment details
$stmt = $pdo->prepare("SELECT * FROM payments WHERE order_id = ?");
$stmt->execute([$order_id]);
$payment = $stmt->fetch();

// Get delivery tracking
$stmt = $pdo->prepare("SELECT * FROM delivery_tracking WHERE order_id = ?");
$stmt->execute([$order_id]);
$tracking = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Successful - Agriculture Marketplace</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 40px 20px;
        }
        .success-container { max-width: 600px; margin: 0 auto; }
        .success-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
            text-align: center;
            animation: slideUp 0.6s ease;
        }
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .success-header {
            background: linear-gradient(135deg, #2e7d32, #1b5e20);
            padding: 40px;
            color: white;
        }
        .success-icon {
            width: 80px;
            height: 80px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
        }
        .success-icon i { font-size: 3rem; color: #2e7d32; }
        .success-header h2 { font-size: 1.8rem; margin-bottom: 10px; }
        .success-header p { opacity: 0.9; }
        .success-body { padding: 30px; }
        .payment-details {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 15px;
            margin: 20px 0;
            text-align: left;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e0e0e0;
        }
        .detail-row:last-child { border-bottom: none; }
        .tracking-code {
            background: #e8f5e9;
            padding: 15px;
            border-radius: 10px;
            margin: 20px 0;
            text-align: center;
        }
        .tracking-code span {
            font-family: monospace;
            font-size: 1.2rem;
            letter-spacing: 2px;
            font-weight: bold;
            color: #2e7d32;
        }
        .btn-group {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 20px;
        }
        .btn {
            display: inline-block;
            padding: 12px 25px;
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.3s;
        }
        .btn-primary {
            background: linear-gradient(135deg, #2e7d32, #1b5e20);
            color: white;
        }
        .btn-secondary {
            background: #666;
            color: white;
        }
        .btn-outline {
            border: 2px solid #2e7d32;
            color: #2e7d32;
            background: transparent;
        }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.2); }
        
        @media (max-width: 600px) {
            .btn-group { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="success-container">
        <div class="success-card">
            <div class="success-header">
                <div class="success-icon">
                    <i class="fas fa-check"></i>
                </div>
                <h2>Payment Successful!</h2>
                <p>Your order has been confirmed</p>
            </div>
            
            <div class="success-body">
                <div class="payment-details">
                    <div class="detail-row">
                        <strong>Order Number:</strong>
                        <span><?php echo $order['order_number'] ?? '#' . $order['id']; ?></span>
                    </div>
                    <div class="detail-row">
                        <strong>Product:</strong>
                        <span><?php echo htmlspecialchars($order['product_name']); ?></span>
                    </div>
                    <div class="detail-row">
                        <strong>Quantity:</strong>
                        <span><?php echo $order['quantity']; ?> <?php echo $order['unit']; ?></span>
                    </div>
                    <div class="detail-row">
                        <strong>Amount Paid:</strong>
                        <span>₱<?php echo number_format($order['total_price'], 2); ?></span>
                    </div>
                    <div class="detail-row">
                        <strong>Payment Method:</strong>
                        <span><?php echo htmlspecialchars($payment['payment_method'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="detail-row">
                        <strong>Transaction ID:</strong>
                        <span><?php echo htmlspecialchars($payment['transaction_id'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="detail-row">
                        <strong>Payment Date:</strong>
                        <span><?php echo date('F d, Y h:i A', strtotime($payment['payment_date'] ?? 'now')); ?></span>
                    </div>
                </div>
                
                <?php if($tracking): ?>
                <div class="tracking-code">
                    <i class="fas fa-barcode"></i><br>
                    <strong>Tracking Code:</strong><br>
                    <span><?php echo htmlspecialchars($tracking['tracking_code']); ?></span>
                    <p style="margin-top: 10px; font-size: 0.85rem;">
                        Use this code to track your delivery
                    </p>
                </div>
                <?php endif; ?>
                
                <div class="btn-group">
                    <a href="../dashboard/track_delivery.php?order_id=<?php echo $order_id; ?>" class="btn btn-primary">
                        <i class="fas fa-truck"></i> Track Delivery
                    </a>
                    <a href="../dashboard/user.php" class="btn btn-outline">
                        <i class="fas fa-shopping-cart"></i> My Orders
                    </a>
                    <a href="../index.php" class="btn btn-secondary">
                        <i class="fas fa-home"></i> Continue Shopping
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>