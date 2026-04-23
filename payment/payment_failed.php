<?php
require_once __DIR__ . '/../config/db.php';

if(!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$order_id = $_GET['order_id'] ?? 0;
$error_message = $_GET['error'] ?? 'Payment processing failed. Please try again.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Failed - Agriculture Marketplace</title>
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
        .failed-container { max-width: 500px; width: 100%; }
        .failed-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
            text-align: center;
            padding: 40px;
            animation: shake 0.5s ease;
        }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-10px); }
            75% { transform: translateX(10px); }
        }
        .failed-icon {
            width: 80px;
            height: 80px;
            background: #f44336;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
        }
        .failed-icon i { font-size: 3rem; color: white; }
        h2 { color: #f44336; margin-bottom: 10px; }
        .error-message {
            background: #fee;
            color: #c33;
            padding: 15px;
            border-radius: 10px;
            margin: 20px 0;
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
        .btn:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.2); }
        @media (max-width: 600px) {
            .btn-group { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="failed-container">
        <div class="failed-card">
            <div class="failed-icon">
                <i class="fas fa-times"></i>
            </div>
            <h2>Payment Failed!</h2>
            <p>We couldn't process your payment</p>
            
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($error_message); ?></span>
            </div>
            
            <div class="btn-group">
                <a href="process_payment.php?order_id=<?php echo $order_id; ?>" class="btn btn-primary">
                    <i class="fas fa-redo"></i> Try Again
                </a>
                <a href="../dashboard/user.php" class="btn btn-secondary">
                    <i class="fas fa-shopping-cart"></i> View Orders
                </a>
            </div>
        </div>
    </div>
</body>
</html>