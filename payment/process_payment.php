<?php
require_once 'config/db.php';

if(!isset($_SESSION['user_id'])) {
    header("Location: auth/login.php");
    exit();
}

$order_id = $_GET['order_id'] ?? 0;
$amount = $_GET['amount'] ?? 0;

// Get order details
$stmt = $pdo->prepare("
    SELECT o.*, p.name as product_name 
    FROM orders o 
    JOIN products p ON o.product_id = p.id 
    WHERE o.id = ? AND o.buyer_id = ?
");
$stmt->execute([$order_id, $_SESSION['user_id']]);
$order = $stmt->fetch();

if(!$order) {
    die("Order not found!");
}

// Get payment methods
$payment_methods = $pdo->query("SELECT * FROM payment_methods WHERE is_active = 1");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment - Agriculture Marketplace</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 40px 20px;
        }
        .payment-container { max-width: 800px; margin: 0 auto; }
        .payment-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
            margin-bottom: 20px;
        }
        .payment-header {
            background: linear-gradient(135deg, #2e7d32, #1b5e20);
            padding: 30px;
            text-align: center;
            color: white;
        }
        .payment-header i { font-size: 3rem; margin-bottom: 10px; }
        .payment-header h2 { font-size: 1.8rem; margin-bottom: 5px; }
        .payment-body { padding: 30px; }
        .order-summary {
            background: #f5f5f5;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
        }
        .order-summary h3 { margin-bottom: 15px; color: #2e7d32; }
        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #ddd;
        }
        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 15px 0;
            font-size: 1.2rem;
            font-weight: bold;
            color: #ff9800;
        }
        .payment-methods {
            display: grid;
            gap: 15px;
            margin-bottom: 30px;
        }
        .payment-method {
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            padding: 15px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .payment-method:hover { border-color: #2e7d32; transform: translateX(5px); }
        .payment-method.selected {
            border-color: #2e7d32;
            background: #e8f5e9;
        }
        .payment-method i { font-size: 2rem; width: 50px; color: #2e7d32; }
        .method-info h4 { margin-bottom: 5px; }
        .method-info p { font-size: 0.85rem; color: #666; }
        .payment-btn {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #2e7d32, #1b5e20);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 1.1rem;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s;
        }
        .payment-btn:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(46,125,50,0.3); }
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
            z-index: 1000;
        }
        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 15px;
            max-width: 400px;
            text-align: center;
        }
        .loading {
            display: inline-block;
            width: 30px;
            height: 30px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #2e7d32;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="payment-container">
        <div class="payment-card">
            <div class="payment-header">
                <i class="fas fa-credit-card"></i>
                <h2>Complete Payment</h2>
                <p>Secure payment for your order</p>
            </div>
            <div class="payment-body">
                <div class="order-summary">
                    <h3><i class="fas fa-shopping-cart"></i> Order Summary</h3>
                    <div class="summary-row">
                        <span>Order #<?php echo $order['id']; ?></span>
                        <span><?php echo htmlspecialchars($order['product_name']); ?></span>
                    </div>
                    <div class="summary-row">
                        <span>Quantity</span>
                        <span><?php echo $order['quantity']; ?></span>
                    </div>
                    <div class="total-row">
                        <span>Total Amount</span>
                        <span>₱<?php echo number_format($order['total_price'], 2); ?></span>
                    </div>
                </div>

                <h3 style="margin-bottom: 15px;"><i class="fas fa-credit-card"></i> Select Payment Method</h3>
                <div class="payment-methods" id="paymentMethods">
                    <?php while($method = $payment_methods->fetch(PDO::FETCH_ASSOC)): ?>
                        <div class="payment-method" data-method="<?php echo $method['method_name']; ?>">
                            <i class="<?php echo $method['method_icon']; ?>"></i>
                            <div class="method-info">
                                <h4><?php echo $method['method_name']; ?></h4>
                                <p>Pay using <?php echo $method['method_name']; ?></p>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>

                <button class="payment-btn" onclick="processPayment()">
                    <i class="fas fa-lock"></i> Pay ₱<?php echo number_format($order['total_price'], 2); ?>
                </button>
            </div>
        </div>
    </div>

    <!-- Payment Modal -->
    <div id="paymentModal" class="modal">
        <div class="modal-content">
            <div class="loading"></div>
            <h3 style="margin-top: 20px;">Processing Payment...</h3>
            <p>Please wait while we process your payment</p>
        </div>
    </div>

    <script>
        let selectedMethod = null;
        
        // Select payment method
        document.querySelectorAll('.payment-method').forEach(method => {
            method.addEventListener('click', function() {
                document.querySelectorAll('.payment-method').forEach(m => m.classList.remove('selected'));
                this.classList.add('selected');
                selectedMethod = this.getAttribute('data-method');
            });
        });

        function processPayment() {
            if(!selectedMethod) {
                alert('Please select a payment method!');
                return;
            }
            
            // Show modal
            document.getElementById('paymentModal').style.display = 'flex';
            
            // Simulate payment processing
            setTimeout(() => {
                // Redirect to payment success
                window.location.href = `payment_success.php?order_id=<?php echo $order_id; ?>&method=${selectedMethod}&amount=<?php echo $amount; ?>`;
            }, 2000);
        }
    </script>
</body>
</html>