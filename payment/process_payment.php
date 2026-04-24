<?php
require_once __DIR__ . '/../config/db.php';

if(!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$order_id = $_GET['order_id'] ?? 0;
$user_id = $_SESSION['user_id'];

// Get order details with product image
$stmt = $pdo->prepare("
    SELECT o.*, p.name as product_name, p.farmer_id, u.username as farmer_name,
           p.price, p.unit, p.quantity as product_quantity, p.product_image,
           p.category, p.description
    FROM orders o 
    JOIN products p ON o.product_id = p.id 
    JOIN users u ON p.farmer_id = u.id
    WHERE o.id = ? AND o.buyer_id = ? AND o.status = 'pending'
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
            
            if($payment_method == 'Wallet Balance') {
                $stmt = $pdo->prepare("UPDATE user_wallets SET balance = balance - ?, total_spent = total_spent + ? WHERE user_id = ?");
                $stmt->execute([$order['total_price'], $order['total_price'], $user_id]);
                
                $stmt = $pdo->prepare("
                    INSERT INTO wallet_transactions (user_id, amount, type, description, reference_id, status) 
                    VALUES (?, ?, 'payment', ?, ?, 'completed')
                ");
                $stmt->execute([$user_id, -$order['total_price'], "Payment for order #$order_id", $transaction_id]);
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO payments (order_id, user_id, amount, payment_method, payment_status, transaction_id, reference_number, payment_date) 
                VALUES (?, ?, ?, ?, 'completed', ?, ?, NOW())
            ");
            $stmt->execute([$order_id, $user_id, $order['total_price'], $payment_method, $transaction_id, $reference_number]);
            
            $stmt = $pdo->prepare("UPDATE orders SET status = 'confirmed' WHERE id = ?");
            $stmt->execute([$order_id]);
            
            $stmt = $pdo->prepare("UPDATE products SET status = 'sold' WHERE id = ?");
            $stmt->execute([$order['product_id']]);
            
            $platform_fee = $order['total_price'] * 0.05;
            $farmer_earning = $order['total_price'] - $platform_fee;
            
            $stmt = $pdo->prepare("
                INSERT INTO farmer_earnings (farmer_id, order_id, amount, commission, net_amount, status) 
                VALUES (?, ?, ?, ?, ?, 'pending')
            ");
            $stmt->execute([$order['farmer_id'], $order_id, $order['total_price'], $platform_fee, $farmer_earning]);
            
            $stmt = $pdo->prepare("
                INSERT INTO delivery_tracking (order_id, status, tracking_code) 
                VALUES (?, 'pending', ?)
            ");
            $tracking_code = 'DEL' . strtoupper(uniqid());
            $stmt->execute([$order_id, $tracking_code]);
            
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, title, message, type, link) 
                    VALUES (?, 'New Order!', ?, 'order', ?)
                ");
                $stmt->execute([$order['farmer_id'], "You have received a new order for {$order['product_name']}", "dashboard/farmer.php?section=orders"]);
            } catch(PDOException $e) {}
            
            $pdo->commit();
            header("Location: payment_success.php?order_id=$order_id");
            exit();
            
        } catch(PDOException $e) {
            $pdo->rollBack();
            $payment_error = "Payment failed: " . $e->getMessage();
        }
    }
}

// Get product image path
$product_image_path = '';
if(!empty($order['product_image']) && file_exists(__DIR__ . '/../' . $order['product_image'])) {
    $product_image_path = '../' . $order['product_image'];
} else {
    // Use default image based on category
    $category_images = [
        'Vegetables' => '🥬',
        'Fruits' => '🍎',
        'Grains' => '🌾',
        'Dairy' => '🥛',
        'Meat' => '🍖',
        'Organic' => '🌱'
    ];
    $default_icon = $category_images[$order['category']] ?? '🌾';
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
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #0f2027, #203a43, #2c5364);
            min-height: 100vh;
            padding: 40px 20px;
        }

        /* Main Container */
        .payment-container {
            max-width: 500px;
            margin: 0 auto;
        }

        /* Header */
        .payment-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .payment-header h1 {
            color: white;
            font-size: 1.8rem;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .payment-header h1 i {
            background: rgba(255,255,255,0.15);
            padding: 12px;
            border-radius: 50%;
            margin-right: 10px;
            font-size: 1.5rem;
        }

        .payment-header p {
            color: rgba(255,255,255,0.7);
            font-size: 0.85rem;
        }

        /* Main Payment Card */
        .payment-card {
            background: white;
            border-radius: 32px;
            overflow: hidden;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.3);
        }

        /* Progress Steps */
        .progress-steps {
            display: flex;
            padding: 20px 25px;
            background: #f8f9fa;
            border-bottom: 1px solid #e9ecef;
        }

        .step {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.75rem;
            color: #6c757d;
        }

        .step.active {
            color: #2e7d32;
            font-weight: 600;
        }

        .step.completed {
            color: #4caf50;
        }

        .step-number {
            width: 24px;
            height: 24px;
            background: #e9ecef;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: bold;
        }

        .step.active .step-number {
            background: #2e7d32;
            color: white;
        }

        .step.completed .step-number {
            background: #4caf50;
            color: white;
        }

        /* Product Image Section */
        .product-image-section {
            padding: 25px;
            background: white;
            border-bottom: 1px solid #e9ecef;
        }

        .product-image-card {
            background: linear-gradient(135deg, #f8f9fa, #ffffff);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
        }

        .image-container {
            position: relative;
            height: 200px;
            overflow: hidden;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .product-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s;
        }

        .product-img:hover {
            transform: scale(1.05);
        }

        .no-image {
            font-size: 4rem;
            color: rgba(255,255,255,0.8);
        }

        .category-badge {
            position: absolute;
            top: 15px;
            left: 15px;
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(5px);
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .fresh-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            background: linear-gradient(135deg, #ff9800, #f57c00);
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: bold;
        }

        /* Product Info */
        .product-info {
            padding: 20px;
        }

        .product-title {
            font-size: 1.3rem;
            color: #2e7d32;
            margin-bottom: 5px;
            font-weight: 700;
        }

        .product-farmer {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 15px;
            color: #666;
            font-size: 0.8rem;
        }

        .product-farmer i {
            color: #ff9800;
        }

        /* Price Breakdown */
        .price-breakdown {
            margin-top: 15px;
            background: #f8f9fa;
            border-radius: 16px;
            padding: 15px;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e9ecef;
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            font-size: 0.85rem;
            color: #6c757d;
        }

        .detail-value {
            font-weight: 600;
            color: #333;
        }

        .total-row {
            background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
            margin-top: 10px;
            padding: 12px;
            border-radius: 12px;
            display: flex;
            justify-content: space-between;
            font-weight: bold;
        }

        .total-label {
            color: #2e7d32;
        }

        .total-value {
            color: #ff9800;
            font-size: 1.1rem;
        }

        /* Wallet Section */
        .wallet-section {
            background: linear-gradient(135deg, #667eea, #764ba2);
            margin-top: 20px;
            padding: 20px;
            border-radius: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .wallet-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .wallet-info i {
            font-size: 1.8rem;
            color: #ffd700;
        }

        .wallet-text small {
            font-size: 0.7rem;
            color: rgba(255,255,255,0.7);
            display: block;
        }

        .wallet-text strong {
            font-size: 1.3rem;
            color: #ffd700;
        }

        .btn-add {
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            padding: 8px 16px;
            border-radius: 25px;
            text-decoration: none;
            font-size: 0.75rem;
            font-weight: 600;
            transition: all 0.3s;
        }

        .btn-add:hover {
            background: rgba(255,255,255,0.3);
        }

        /* Payment Methods */
        .payment-section {
            padding: 25px;
        }

        .section-title {
            font-size: 1rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: #2e7d32;
        }

        .payment-methods-grid {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-bottom: 25px;
        }

        .payment-method-card {
            border: 2px solid #e9ecef;
            border-radius: 16px;
            padding: 12px 15px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 15px;
            background: white;
        }

        .payment-method-card:hover {
            border-color: #2e7d32;
            transform: translateX(5px);
        }

        .payment-method-card.selected {
            border-color: #2e7d32;
            background: linear-gradient(135deg, #e8f5e9, #ffffff);
            box-shadow: 0 3px 10px rgba(46,125,50,0.1);
        }

        .method-icon {
            width: 45px;
            height: 45px;
            background: #f8f9fa;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
        }

        .payment-method-card.selected .method-icon {
            background: #2e7d32;
        }

        .payment-method-card.selected .method-icon i {
            color: white;
        }

        .method-icon i {
            font-size: 1.3rem;
            color: #2e7d32;
        }

        .method-info h4 {
            font-size: 0.9rem;
            margin-bottom: 2px;
            font-weight: 600;
        }

        .method-info p {
            font-size: 0.65rem;
            color: #6c757d;
        }

        /* Payment Forms */
        .payment-form-details {
            background: #f8f9fa;
            border-radius: 20px;
            padding: 20px;
            margin: 20px 0;
            display: none;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .payment-form-details.active {
            display: block;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: #333;
            font-size: 0.8rem;
        }

        .form-group label i {
            margin-right: 6px;
            color: #2e7d32;
        }

        .form-group input {
            width: 100%;
            padding: 10px 15px;
            border: 2px solid #e9ecef;
            border-radius: 12px;
            font-size: 0.9rem;
            transition: all 0.3s;
        }

        .form-group input:focus {
            outline: none;
            border-color: #2e7d32;
            box-shadow: 0 0 0 3px rgba(46,125,50,0.1);
        }

        .bank-info {
            background: #e3f2fd;
            border-radius: 12px;
            padding: 12px;
            margin-bottom: 15px;
            border-left: 3px solid #2196f3;
        }

        .bank-info p {
            font-size: 0.75rem;
            margin: 3px 0;
        }

        .card-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        /* Alert */
        .alert {
            padding: 12px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.85rem;
        }

        .alert-error {
            background: #fee;
            color: #c33;
            border-left: 3px solid #f44336;
        }

        /* Pay Button */
        .btn-pay {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #2e7d32, #1b5e20);
            color: white;
            border: none;
            border-radius: 50px;
            font-size: 1rem;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 20px;
        }

        .btn-pay:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(46,125,50,0.3);
        }

        /* Security Footer */
        .security-footer {
            text-align: center;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #e9ecef;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .security-footer span {
            font-size: 0.7rem;
            color: #6c757d;
        }

        .security-footer i {
            color: #4caf50;
        }

        /* Responsive */
        @media (max-width: 550px) {
            body {
                padding: 20px 15px;
            }
            
            .payment-container {
                max-width: 100%;
            }
            
            .product-image-section {
                padding: 20px;
            }
            
            .payment-section {
                padding: 20px;
            }
            
            .wallet-section {
                flex-direction: column;
                text-align: center;
                gap: 12px;
            }
            
            .wallet-info {
                justify-content: center;
            }
            
            .progress-steps {
                padding: 15px;
            }
            
            .step span {
                display: none;
            }
            
            .image-container {
                height: 160px;
            }
        }
    </style>
</head>
<body>
    <div class="payment-container">
        <div class="payment-header">
            <h1><i class="fas fa-shield-alt"></i> Secure Checkout</h1>
            <p>Complete your purchase securely</p>
        </div>

        <div class="payment-card">
            <!-- Progress Steps -->
            <div class="progress-steps">
                <div class="step completed">
                    <div class="step-number"><i class="fas fa-check" style="font-size: 0.6rem;"></i></div>
                    <span>Order</span>
                </div>
                <div class="step active">
                    <div class="step-number">2</div>
                    <span>Payment</span>
                </div>
                <div class="step">
                    <div class="step-number">3</div>
                    <span>Confirm</span>
                </div>
            </div>

            <!-- Product Image Section -->
            <div class="product-image-section">
                <div class="product-image-card">
                    <div class="image-container">
                        <?php if($product_image_path): ?>
                            <img src="<?php echo $product_image_path; ?>" alt="<?php echo htmlspecialchars($order['product_name']); ?>" class="product-img">
                        <?php else: ?>
                            <div class="no-image"><?php echo $default_icon; ?></div>
                        <?php endif; ?>
                        <span class="category-badge"><i class="fas fa-tag"></i> <?php echo htmlspecialchars($order['category'] ?? 'Fresh Product'); ?></span>
                        <span class="fresh-badge"><i class="fas fa-leaf"></i> Fresh</span>
                    </div>
                    <div class="product-info">
                        <h3 class="product-title"><?php echo htmlspecialchars($order['product_name']); ?></h3>
                        <div class="product-farmer">
                            <i class="fas fa-store"></i>
                            <span>Sold by: <strong><?php echo htmlspecialchars($order['farmer_name']); ?></strong></span>
                        </div>
                        <?php if(!empty($order['description'])): ?>
                            <div class="product-description" style="font-size: 0.8rem; color: #666; margin-top: 8px;">
                                <i class="fas fa-align-left"></i> <?php echo htmlspecialchars(substr($order['description'], 0, 80)); ?>...
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Price Breakdown -->
                <div class="price-breakdown">
                    <div class="detail-row">
                        <span class="detail-label"><i class="fas fa-box"></i> Quantity</span>
                        <span class="detail-value"><?php echo $order['quantity']; ?> <?php echo $order['unit']; ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label"><i class="fas fa-tag"></i> Unit Price</span>
                        <span class="detail-value">₱<?php echo number_format($order['price'], 2); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label"><i class="fas fa-calculator"></i> Subtotal</span>
                        <span class="detail-value">₱<?php echo number_format($order['price'] * $order['quantity'], 2); ?></span>
                    </div>
                    <?php if(isset($order['delivery_fee']) && $order['delivery_fee'] > 0): ?>
                    <div class="detail-row">
                        <span class="detail-label"><i class="fas fa-truck"></i> Delivery Fee</span>
                        <span class="detail-value">₱<?php echo number_format($order['delivery_fee'], 2); ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="total-row">
                        <span class="total-label"><i class="fas fa-receipt"></i> Total Amount</span>
                        <span class="total-value">FRW<?php echo number_format($order['total_price'], 2); ?></span>
                    </div>
                </div>

                <!-- Wallet Section -->
                <div class="wallet-section">
                    <div class="wallet-info">
                        <i class="fas fa-wallet"></i>
                        <div class="wallet-text">
                            <small>Available Balance</small>
                            <strong>FRW<?php echo number_format($wallet_balance, 2); ?></strong>
                        </div>
                    </div>
                    <a href="wallet.php" class="btn-add">
                        <i class="fas fa-plus-circle"></i> Add Money
                    </a>
                </div>
            </div>

            <!-- Payment Section -->
            <div class="payment-section">
                <div class="section-title">
                    <i class="fas fa-credit-card"></i>
                    <span>Select Payment Method</span>
                </div>

                <?php if($payment_error): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span><?php echo $payment_error; ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" id="paymentForm">
                    <div class="payment-methods-grid" id="paymentMethods">
                        <?php while($method = $payment_methods->fetch(PDO::FETCH_ASSOC)): ?>
                            <div class="payment-method-card" data-method="<?php echo $method['method_name']; ?>">
                                <div class="method-icon">
                                    <i class="<?php echo $method['method_icon']; ?>"></i>
                                </div>
                                <div class="method-info">
                                    <h4><?php echo $method['method_name']; ?></h4>
                                    <p><?php echo $method['description']; ?></p>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                    
                    <input type="hidden" name="payment_method" id="selectedMethod" required>
                    
                    <!-- M-Pesa Form -->
                    <div id="mpesaForm" class="payment-form-details">
                        <div class="form-group">
                            <label><i class="fas fa-phone"></i> Phone Number</label>
                            <input type="tel" name="phone_number" placeholder="07XX XXX XXX">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-hashtag"></i> Transaction Code</label>
                            <input type="text" name="reference_number" placeholder="Enter M-Pesa transaction code">
                        </div>
                    </div>
                    
                    <!-- Airtel Money Form -->
                    <div id="airtelForm" class="payment-form-details">
                        <div class="form-group">
                            <label><i class="fas fa-phone"></i> Phone Number</label>
                            <input type="tel" name="phone_number" placeholder="07XX XXX XXX">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-hashtag"></i> Transaction Code</label>
                            <input type="text" name="reference_number" placeholder="Enter transaction code">
                        </div>
                    </div>
                    
                    <!-- Bank Transfer Form -->
                    <div id="bankForm" class="payment-form-details">
                        <div class="bank-info">
                            <p><strong>🏦 Union Bank of Philippines</strong></p>
                            <p>Account Name: AgriMarket PH Inc.</p>
                            <p>Account Number: 1234-5678-9012</p>
                            <p>Reference: Order <?php echo $order['id']; ?></p>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-hashtag"></i> Reference Number</label>
                            <input type="text" name="reference_number" placeholder="Enter bank reference number">
                        </div>
                    </div>
                    
                    <!-- Card Form -->
                    <div id="cardForm" class="payment-form-details">
                        <div class="form-group">
                            <label><i class="fas fa-credit-card"></i> Card Number</label>
                            <input type="text" id="cardNumber" placeholder="1234 5678 9012 3456">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Cardholder Name</label>
                            <input type="text" id="cardName" placeholder="Name on card">
                        </div>
                        <div class="card-row">
                            <div class="form-group">
                                <label><i class="fas fa-calendar"></i> Expiry</label>
                                <input type="text" id="expiryDate" placeholder="MM/YY">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-lock"></i> CVV</label>
                                <input type="password" id="cvv" placeholder="123">
                            </div>
                        </div>
                        <input type="hidden" name="reference_number" value="CARD_PAYMENT">
                    </div>
                    
                    <!-- COD Form -->
                    <div id="codForm" class="payment-form-details">
                        <div class="bank-info" style="background: #e8f5e9; border-left-color: #4caf50;">
                            <p><i class="fas fa-truck"></i> Cash on Delivery</p>
                            <p>Pay <strong>FRW<?php echo number_format($order['total_price'], 2); ?></strong> when you receive the product</p>
                            <small>Please prepare exact cash amount</small>
                        </div>
                        <input type="hidden" name="reference_number" value="COD_ORDER">
                    </div>
                    
                    <!-- Wallet Form -->
                    <div id="walletForm" class="payment-form-details">
                        <div class="bank-info" style="background: #e8f5e9; border-left-color: #4caf50;">
                            <p><i class="fas fa-wallet"></i> Wallet Balance</p>
                            <p>Available: <strong>₱<?php echo number_format($wallet_balance, 2); ?></strong></p>
                            <?php if($wallet_balance < $order['total_price']): ?>
                                <p style="color: #f44336;">Insufficient balance!</p>
                            <?php endif; ?>
                        </div>
                        <input type="hidden" name="reference_number" value="WALLET_PAYMENT">
                    </div>
                    
                    <button type="submit" class="btn-pay" id="payButton">
                        <i class="fas fa-lock"></i> Pay FRW<?php echo number_format($order['total_price'], 2); ?>
                    </button>
                    
                    <div class="security-footer">
                        <i class="fas fa-shield-alt"></i>
                        <span>256-bit SSL Secure</span>
                        <i class="fab fa-cc-visa"></i>
                        <i class="fab fa-cc-mastercard"></i>
                        <i class="fab fa-cc-paypal"></i>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        let selectedMethod = null;
        
        // Payment method selection
        document.querySelectorAll('.payment-method-card').forEach(method => {
            method.addEventListener('click', function() {
                document.querySelectorAll('.payment-method-card').forEach(m => m.classList.remove('selected'));
                this.classList.add('selected');
                selectedMethod = this.getAttribute('data-method');
                document.getElementById('selectedMethod').value = selectedMethod;
                
                document.querySelectorAll('.payment-form-details').forEach(form => {
                    form.classList.remove('active');
                });
                
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
        
        // Auto-select first payment method
        if(document.querySelectorAll('.payment-method-card').length > 0 && !selectedMethod) {
            document.querySelectorAll('.payment-method-card')[0].click();
        }
    </script>
</body>
</html>