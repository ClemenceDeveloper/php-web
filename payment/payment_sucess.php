<?php
require_once 'config/db.php';

if(!isset($_SESSION['user_id'])) {
    header("Location: auth/login.php");
    exit();
}

$order_id = $_GET['order_id'] ?? 0;
$payment_method = $_GET['method'] ?? '';
$amount = $_GET['amount'] ?? 0;

// Record payment in database
try {
    $stmt = $pdo->prepare("
        INSERT INTO payments (order_id, user_id, amount, payment_method, payment_status, transaction_id, payment_date) 
        VALUES (?, ?, ?, ?, 'completed', ?, NOW())
    ");
    $transaction_id = 'TXN' . time() . rand(1000, 9999);
    $stmt->execute([$order_id, $_SESSION['user_id'], $amount, $payment_method, $transaction_id]);
    
    // Update order status
    $stmt = $pdo->prepare("UPDATE orders SET status = 'confirmed' WHERE id = ?");
    $stmt->execute([$order_id]);
    
} catch(PDOException $e) {
    // Handle error
}
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
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .success-container {
            max-width: 500px;
            width: 100%;
            animation: slideUp 0.6s ease;
        }
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .success-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
            text-align: center;
            padding: 40px;
        }
        .success-icon {
            width: 80px;
            height: 80px;
            background: #4caf50;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
        }
        .success-icon i { font-size: 3rem; color: white; }
        h2 { color: #2e7d32; margin-bottom: 10px; }
        .payment-details {
            background: #f5f5f5;
            padding: 20px;
            border-radius: 10px;
            margin: 20px 0;
            text-align: left;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #ddd;
        }
        .btn {
            display: inline-block;
            padding: 12px 30px;
            background: linear-gradient(135deg, #2e7d32, #1b5e20);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            margin: 10px;
            transition: all 0.3s;
        }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(46,125,50,0.3); }
    </style>
</head>
<body>
    <div class="success-container">
        <div class="success-card">
            <div class="success-icon">
                <i class="fas fa-check"></i>
            </div>
            <h2>Payment Successful!</h2>
            <p>Your order has been confirmed</p>
            
            <div class="payment-details">
                <div class="detail-row">
                    <strong>Order ID:</strong>
                    <span>#<?php echo $order_id; ?></span>
                </div>
                <div class="detail-row">
                    <strong>Amount Paid:</strong>
                    <span>₱<?php echo number_format($amount, 2); ?></span>
                </div>
                <div class="detail-row">
                    <strong>Payment Method:</strong>
                    <span><?php echo htmlspecialchars($payment_method); ?></span>
                </div>
                <div class="detail-row">
                    <strong>Transaction ID:</strong>
                    <span><?php echo $transaction_id ?? 'N/A'; ?></span>
                </div>
            </div>
            
            <a href="dashboard/user.php" class="btn">
                <i class="fas fa-shopping-cart"></i> View My Orders
            </a>
            <a href="index.php" class="btn" style="background: #666;">
                <i class="fas fa-home"></i> Continue Shopping
            </a>
        </div>
    </div>
</body>
</html>