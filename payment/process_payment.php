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
    SELECT o.*, p.name as product_name, p.farmer_id, u.username as farmer_name,
           p.price, p.unit, p.quantity as product_quantity
    FROM orders o 
    JOIN products p ON o.product_id = p.id 
    JOIN users u ON p.farmer_id = u.id
    WHERE o.id = ? AND o.buyer_id = ? AND o.payment_status = 'pending'
");
$stmt->execute([$order_id, $user_id]);
$order = $stmt->fetch();

if(!$order) {
    die("Order not found or already paid!");
}

// Get user wallet balance
$stmt = $pdo->prepare("SELECT balance FROM user_wallets WHERE user_id = ?");
$stmt->execute([$user_id]);
$wallet = $stmt->fetch();
$wallet_balance = $wallet['balance'] ?? 0;

// Get active payment methods
$payment_methods = $pdo->query("SELECT * FROM payment_methods WHERE is_active = 1 ORDER BY display_order");

// Handle payment submission
$payment_error = '';
$payment_success = '';

if($_SERVER['REQUEST_METHOD'] == 'POST') {
    $payment_method = $_POST['payment_method'];
    $reference_number = $_POST['reference_number'] ?? '';
    $phone_number = $_POST['phone_number'] ?? '';
    $transaction_id = 'TXN' . time() . rand(1000, 9999);
    
    // Validate based on payment method
    if(in_array($payment_method, ['M-Pesa', 'Airtel Money', 'Tigo Cash']) && empty($phone_number)) {
        $payment_error = "Please enter your mobile money number!";
    } elseif(in_array($payment_method, ['M-Pesa', 'Airtel Money', 'Tigo Cash', 'Bank Transfer']) && empty($reference_number)) {
        $payment_error = "Please enter the transaction reference number!";
    } elseif($payment_method == 'Wallet Balance' && $wallet_balance < $order['total_price']) {
        $payment_error = "Insufficient wallet balance! Available: ₱" . number_format($wallet_balance, 2);
    }
    
    if(empty($payment_error)) {
        try {
            $pdo->beginTransaction();
            
            // Process payment based on method
            if($payment_method == 'Wallet Balance') {
                // Deduct from wallet
                $stmt = $pdo->prepare("UPDATE user_wallets SET balance = balance - ?, total_spent = total_spent + ? WHERE user_id = ?");
                $stmt->execute([$order['total_price'], $order['total_price'], $user_id]);
                
                // Record wallet transaction
                $stmt = $pdo->prepare("
                    INSERT INTO wallet_transactions (user_id, amount, type, description, reference_id, status) 
                    VALUES (?, ?, 'payment', ?, ?, 'completed')
                ");
                $stmt->execute([$user_id, -$order['total_price'], "Payment for order #$order_id", $transaction_id]);
            }
            
            // Insert payment record
            $stmt = $pdo->prepare("
                INSERT INTO payments (order_id, user_id, amount, payment_method, payment_status, transaction_id, reference_number, payment_date) 
                VALUES (?, ?, ?, ?, 'completed', ?, ?, NOW())
            ");
            $stmt->execute([$order_id, $user_id, $order['total_price'], $payment_method, $transaction_id, $reference_number]);
            
            // Update order status
            $stmt = $pdo->prepare("UPDATE orders SET payment_status = 'paid', status = 'confirmed' WHERE id = ?");
            $stmt->execute([$order_id]);
            
            // Add to farmer earnings (after 5% platform fee)
            $platform_fee = $order['total_price'] * 0.05;
            $farmer_earning = $order['total_price'] - $platform_fee;
            
            $stmt = $pdo->prepare("
                INSERT INTO farmer_earnings (farmer_id, order_id, amount, commission, net_amount, status) 
                VALUES (?, ?, ?, ?, ?, 'pending')
            ");
            $stmt->execute([$order['farmer_id'], $order_id, $order['total_price'], $platform_fee, $farmer_earning]);
            
            // Create delivery tracking
            $stmt = $pdo->prepare("
                INSERT INTO delivery_tracking (order_id, status, tracking_code) 
                VALUES (?, 'pending', ?)
            ");
            $tracking_code = 'DEL' . strtoupper(uniqid());
            $stmt->execute([$order_id, $tracking_code]);
            
            // Create notification for farmer
            $stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, title, message, type, link) 
                VALUES (?, 'New Order!', ?, 'order', ?)
            ");
            $stmt->execute([$order['farmer_id'], "You have received a new order for {$order['product_name']}", "dashboard/farmer.php?section=orders"]);
            
            $pdo->commit();
            
            // Redirect to success page
            header("Location: payment_success.php?order_id=$order_id");
            exit();
            
        } catch(PDOException $e) {
            $pdo->rollBack();
            $payment_error = "Payment failed: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secure Payment - Agriculture Marketplace</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 40px 20px;
        }
        .payment-container { max-width: 1000px; margin: 0 auto; }
        .payment-grid { display: grid; grid-template-columns: 1fr 1.5fr; gap: 30px; }
        
        .payment-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        
        .payment-header {
            background: linear-gradient(135deg, #2e7d32, #1b5e20);
            padding: 25px;
            color: white;
        }
        
        .payment-header h2 { margin-bottom: 5px; }
        .payment-header i { font-size: 2rem; margin-bottom: 10px; }
        
        .payment-body { padding: 25px; }
        
        .order-summary {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 20px;
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 15px 0;
            font-size: 1.2rem;
            font-weight: bold;
            color: #ff9800;
            border-top: 2px solid #e0e0e0;
            margin-top: 10px;
        }
        
        .wallet-info {
            background: linear-gradient(135deg, #2196f3, #1976d2);
            color: white;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .payment-methods {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-bottom: 25px;
            max-height: 400px;
            overflow-y: auto;
        }
        
        .payment-method {
            border: 2px solid #e0e0e0;
            border-radius: 12px;
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
        .method-info p { font-size: 0.8rem; color: #666; }
        
        .payment-details-form {
            display: none;
            margin-top: 20px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
        }
        
        .payment-details-form.active { display: block; animation: fadeIn 0.3s ease; }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .form-group { margin-bottom: 15px; }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #333;
        }
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
        }
        .form-group input:focus { outline: none; border-color: #2e7d32; }
        
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
            margin-top: 20px;
        }
        
        .payment-btn:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(46,125,50,0.3); }
        
        .alert {
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-error { background: #fee; color: #c33; border-left: 4px solid #c33; }
        .alert-success { background: #efe; color: #3c3; border-left: 4px solid #3c3; }
        
        .btn-add-money {
            background: #ff9800;
            color: white;
            padding: 8px 15px;
            border-radius: 5px;
            text-decoration: none;
            font-size: 0.85rem;
        }
        
        @media (max-width: 768px) {
            .payment-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="payment-container">
        <div class="payment-grid">
            <!-- Order Summary Column -->
            <div class="payment-card">
                <div class="payment-header">
                    <i class="fas fa-shopping-cart"></i>
                    <h2>Order Summary</h2>
                    <p>Review your order details</p>
                </div>
                <div class="payment-body">
                    <div class="order-summary">
                        <div class="summary-row">
                            <span>Order #</span>
                            <strong><?php echo $order['id']; ?></strong>
                        </div>
                        <div class="summary-row">
                            <span>Product</span>
                            <strong><?php echo htmlspecialchars($order['product_name']); ?></strong>
                        </div>
                        <div class="summary-row">
                            <span>Quantity</span>
                            <span><?php echo $order['quantity']; ?> <?php echo $order['unit']; ?></span>
                        </div>
                        <div class="summary-row">
                            <span>Unit Price</span>
                            <span>₱<?php echo number_format($order['price'], 2); ?></span>
                        </div>
                        <div class="summary-row">
                            <span>Delivery Fee</span>
                            <span>₱<?php echo number_format($order['delivery_fee'], 2); ?></span>
                        </div>
                        <div class="total-row">
                            <span>Total Amount</span>
                            <span>₱<?php echo number_format($order['total_price'], 2); ?></span>
                        </div>
                    </div>
                    
                    <div class="wallet-info">
                        <div>
                            <i class="fas fa-wallet"></i> Your Wallet Balance
                        </div>
                        <div>
                            <strong>₱<?php echo number_format($wallet_balance, 2); ?></strong>
                            <a href="wallet.php" class="btn-add-money"><i class="fas fa-plus"></i> Add Money</a>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Payment Column -->
            <div class="payment-card">
                <div class="payment-header">
                    <i class="fas fa-credit-card"></i>
                    <h2>Select Payment Method</h2>
                    <p>Choose how you want to pay</p>
                </div>
                <div class="payment-body">
                    <?php if($payment_error): ?>
                        <div class="alert alert-error">
                            <i class="fas fa-exclamation-circle"></i>
                            <span><?php echo $payment_error; ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" id="paymentForm">
                        <div class="payment-methods" id="paymentMethods">
                            <?php while($method = $payment_methods->fetch(PDO::FETCH_ASSOC)): ?>
                                <div class="payment-method" data-method="<?php echo $method['method_name']; ?>">
                                    <i class="<?php echo $method['method_icon']; ?>"></i>
                                    <div class="method-info">
                                        <h4><?php echo $method['method_name']; ?></h4>
                                        <p><?php echo $method['description']; ?></p>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                        
                        <input type="hidden" name="payment_method" id="selectedMethod" required>
                        
                        <!-- M-Pesa Form -->
                        <div id="mpesaForm" class="payment-details-form">
                            <div class="form-group">
                                <label><i class="fas fa-phone"></i> M-Pesa Phone Number</label>
                                <input type="tel" name="phone_number" placeholder="07XX XXX XXX" pattern="[0-9]{10}">
                                <small>Enter the number registered with M-Pesa</small>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-hashtag"></i> M-Pesa Transaction Code</label>
                                <input type="text" name="reference_number" placeholder="Enter M-Pesa transaction code">
                                <small>Enter the code received after payment</small>
                            </div>
                        </div>
                        
                        <!-- Airtel Money Form -->
                        <div id="airtelForm" class="payment-details-form">
                            <div class="form-group">
                                <label><i class="fas fa-phone"></i> Airtel Money Number</label>
                                <input type="tel" name="phone_number" placeholder="07XX XXX XXX">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-hashtag"></i> Transaction Reference</label>
                                <input type="text" name="reference_number" placeholder="Enter transaction code">
                            </div>
                        </div>
                        
                        <!-- Bank Transfer Form -->
                        <div id="bankForm" class="payment-details-form">
                            <div class="alert alert-info" style="background: #e3f2fd; border-left-color: #2196f3;">
                                <i class="fas fa-university"></i>
                                <div>
                                    <strong>Bank Account Details:</strong><br>
                                    Bank: Union Bank<br>
                                    Account Name: AgriMarket PH<br>
                                    Account Number: 1234-5678-9012<br>
                                    Reference: Order #<?php echo $order['id']; ?>
                                </div>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-hashtag"></i> Bank Reference Number</label>
                                <input type="text" name="reference_number" placeholder="Enter bank transaction reference">
                            </div>
                        </div>
                        
                        <!-- Bank Card Form -->
                        <div id="cardForm" class="payment-details-form">
                            <div class="form-group">
                                <label><i class="fas fa-credit-card"></i> Card Number</label>
                                <input type="text" id="cardNumber" placeholder="1234 5678 9012 3456" maxlength="19">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-user"></i> Cardholder Name</label>
                                <input type="text" id="cardName" placeholder="Name on card">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-calendar"></i> Expiry Date</label>
                                <input type="text" id="expiryDate" placeholder="MM/YY" maxlength="5">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-lock"></i> CVV</label>
                                <input type="password" id="cvv" placeholder="123" maxlength="4">
                            </div>
                            <input type="hidden" name="reference_number" value="CARD_PAYMENT">
                        </div>
                        
                        <!-- Cash on Delivery Form -->
                        <div id="codForm" class="payment-details-form">
                            <div class="alert alert-success" style="background: #e8f5e9; border-left-color: #4caf50;">
                                <i class="fas fa-info-circle"></i>
                                <span>You will pay when you receive the product. Please prepare exact cash amount of ₱<?php echo number_format($order['total_price'], 2); ?>.</span>
                            </div>
                            <input type="hidden" name="reference_number" value="COD_ORDER">
                        </div>
                        
                        <!-- Wallet Form -->
                        <div id="walletForm" class="payment-details-form">
                            <div class="alert alert-success" style="background: #e8f5e9; border-left-color: #4caf50;">
                                <i class="fas fa-wallet"></i>
                                <span>Pay using your wallet balance. Available balance: ₱<?php echo number_format($wallet_balance, 2); ?></span>
                            </div>
                            <input type="hidden" name="reference_number" value="WALLET_PAYMENT">
                        </div>
                        
                        <button type="submit" class="payment-btn" id="payButton">
                            <i class="fas fa-lock"></i> Pay ₱<?php echo number_format($order['total_price'], 2); ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        let selectedMethod = null;
        
        // Payment method selection
        document.querySelectorAll('.payment-method').forEach(method => {
            method.addEventListener('click', function() {
                document.querySelectorAll('.payment-method').forEach(m => m.classList.remove('selected'));
                this.classList.add('selected');
                selectedMethod = this.getAttribute('data-method');
                document.getElementById('selectedMethod').value = selectedMethod;
                
                // Hide all forms
                document.querySelectorAll('.payment-details-form').forEach(form => {
                    form.classList.remove('active');
                });
                
                // Show relevant form
                if(selectedMethod === 'M-Pesa') {
                    document.getElementById('mpesaForm').classList.add('active');
                } else if(selectedMethod === 'Airtel Money') {
                    document.getElementById('airtelForm').classList.add('active');
                } else if(selectedMethod === 'Bank Transfer') {
                    document.getElementById('bankForm').classList.add('active');
                } else if(selectedMethod === 'Bank Card') {
                    document.getElementById('cardForm').classList.add('active');
                } else if(selectedMethod === 'Cash on Delivery') {
                    document.getElementById('codForm').classList.add('active');
                } else if(selectedMethod === 'Wallet Balance') {
                    document.getElementById('walletForm').classList.add('active');
                }
            });
        });
        
        // Format card number
        const cardInput = document.getElementById('cardNumber');
        if(cardInput) {
            cardInput.addEventListener('input', function(e) {
                let value = this.value.replace(/\s/g, '');
                if(value.length > 16) value = value.slice(0, 16);
                this.value = value.replace(/(\d{4})/g, '$1 ').trim();
            });
        }
        
        // Format expiry date
        const expiryInput = document.getElementById('expiryDate');
        if(expiryInput) {
            expiryInput.addEventListener('input', function(e) {
                let value = this.value.replace(/\//g, '');
                if(value.length > 2) {
                    value = value.slice(0,2) + '/' + value.slice(2,4);
                }
                this.value = value;
            });
        }
        
        // Form validation
        document.getElementById('paymentForm').addEventListener('submit', function(e) {
            if(!selectedMethod) {
                e.preventDefault();
                alert('Please select a payment method!');
                return false;
            }
            
            const payBtn = document.getElementById('payButton');
            payBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            payBtn.disabled = true;
        });
    </script>
</body>
</html>