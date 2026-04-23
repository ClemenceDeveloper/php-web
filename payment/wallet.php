<?php
require_once __DIR__ . '/../config/db.php';

if(!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$success = '';
$error = '';

// Get wallet balance
$stmt = $pdo->prepare("SELECT * FROM user_wallets WHERE user_id = ?");
$stmt->execute([$user_id]);
$wallet = $stmt->fetch();

if(!$wallet) {
    $stmt = $pdo->prepare("INSERT INTO user_wallets (user_id, balance) VALUES (?, 0)");
    $stmt->execute([$user_id]);
    $wallet = ['balance' => 0, 'total_deposited' => 0, 'total_spent' => 0];
}

// Handle deposit
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['deposit_amount'])) {
    $amount = floatval($_POST['deposit_amount']);
    $payment_method = $_POST['payment_method'];
    
    if($amount < 10) {
        $error = "Minimum deposit is ₱10.00";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Add to wallet
            $stmt = $pdo->prepare("UPDATE user_wallets SET balance = balance + ?, total_deposited = total_deposited + ? WHERE user_id = ?");
            $stmt->execute([$amount, $amount, $user_id]);
            
            // Record transaction
            $transaction_id = 'DEP' . time() . rand(1000, 9999);
            $stmt = $pdo->prepare("
                INSERT INTO wallet_transactions (user_id, amount, type, description, reference_id, status) 
                VALUES (?, ?, 'deposit', ?, ?, 'completed')
            ");
            $stmt->execute([$user_id, $amount, "Wallet deposit via $payment_method", $transaction_id]);
            
            $pdo->commit();
            $success = "Successfully added ₱" . number_format($amount, 2) . " to your wallet!";
            
            // Refresh wallet data
            $stmt = $pdo->prepare("SELECT * FROM user_wallets WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $wallet = $stmt->fetch();
            
        } catch(PDOException $e) {
            $pdo->rollBack();
            $error = "Deposit failed: " . $e->getMessage();
        }
    }
}

// Handle withdrawal request
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['withdraw_amount'])) {
    $amount = floatval($_POST['withdraw_amount']);
    $payment_method = $_POST['withdraw_method'];
    $account_details = $_POST['account_details'];
    
    if($amount < 100) {
        $error = "Minimum withdrawal is ₱100.00";
    } elseif($amount > $wallet['balance']) {
        $error = "Insufficient balance! Available: ₱" . number_format($wallet['balance'], 2);
    } else {
        try {
            // Create withdrawal request
            $stmt = $pdo->prepare("
                INSERT INTO withdrawal_requests (user_id, amount, payment_method, account_details, status) 
                VALUES (?, ?, ?, ?, 'pending')
            ");
            $stmt->execute([$user_id, $amount, $payment_method, $account_details]);
            
            $success = "Withdrawal request submitted successfully! We will process it within 2-3 business days.";
            
        } catch(PDOException $e) {
            $error = "Withdrawal request failed: " . $e->getMessage();
        }
    }
}

// Get transaction history
$transactions = $pdo->prepare("
    SELECT * FROM wallet_transactions 
    WHERE user_id = ? 
    ORDER BY transaction_date DESC 
    LIMIT 20
");
$transactions->execute([$user_id]);

// Get withdrawal requests
$withdrawals = $pdo->prepare("
    SELECT * FROM withdrawal_requests 
    WHERE user_id = ? 
    ORDER BY requested_at DESC
");
$withdrawals->execute([$user_id]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Wallet - Agriculture Marketplace</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 40px 20px;
        }
        .wallet-container { max-width: 1200px; margin: 0 auto; }
        
        .wallet-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
            margin-bottom: 30px;
        }
        
        .wallet-header {
            background: linear-gradient(135deg, #2e7d32, #1b5e20);
            padding: 30px;
            color: white;
            text-align: center;
        }
        
        .wallet-header i { font-size: 3rem; margin-bottom: 10px; }
        .wallet-header h2 { font-size: 1.8rem; margin-bottom: 5px; }
        
        .balance-section {
            background: linear-gradient(135deg, #ff9800, #f57c00);
            padding: 30px;
            text-align: center;
            color: white;
        }
        
        .balance-amount {
            font-size: 3rem;
            font-weight: bold;
            margin: 10px 0;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            padding: 30px;
            background: #f8f9fa;
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-label {
            color: #666;
            margin-bottom: 5px;
        }
        
        .stat-value {
            font-size: 1.5rem;
            font-weight: bold;
            color: #2e7d32;
        }
        
        .action-buttons {
            display: flex;
            gap: 15px;
            padding: 0 30px 30px;
        }
        
        .btn-deposit, .btn-withdraw {
            flex: 1;
            padding: 15px;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-deposit {
            background: linear-gradient(135deg, #2e7d32, #1b5e20);
            color: white;
        }
        
        .btn-withdraw {
            background: linear-gradient(135deg, #2196f3, #1976d2);
            color: white;
        }
        
        .btn-deposit:hover, .btn-withdraw:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
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
            border-radius: 20px;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .close {
            cursor: pointer;
            font-size: 1.5rem;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
        }
        
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 8px;
        }
        
        .btn-submit {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #2e7d32, #1b5e20);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: bold;
            cursor: pointer;
        }
        
        .transaction-table {
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
        }
        
        .credit { color: #4caf50; }
        .debit { color: #f44336; }
        
        .alert {
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        .alert-success { background: #d4edda; color: #155724; border-left: 4px solid #4caf50; }
        .alert-error { background: #f8d7da; color: #721c24; border-left: 4px solid #f44336; }
        
        .status-pending { color: #ff9800; }
        .status-approved { color: #2196f3; }
        .status-completed { color: #4caf50; }
        .status-rejected { color: #f44336; }
        
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: 1fr; }
            .action-buttons { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="wallet-container">
        <div class="wallet-card">
            <div class="wallet-header">
                <i class="fas fa-wallet"></i>
                <h2>My Wallet</h2>
                <p>Manage your funds and transactions</p>
            </div>
            
            <div class="balance-section">
                <p>Current Balance</p>
                <div class="balance-amount">₱<?php echo number_format($wallet['balance'], 2); ?></div>
            </div>
            
            <div class="stats-grid">
                <div class="stat-item">
                    <div class="stat-label">Total Deposited</div>
                    <div class="stat-value">₱<?php echo number_format($wallet['total_deposited'], 2); ?></div>
                </div>
                <div class="stat-item">
                    <div class="stat-label">Total Spent</div>
                    <div class="stat-value">₱<?php echo number_format($wallet['total_spent'], 2); ?></div>
                </div>
                <div class="stat-item">
                    <div class="stat-label">Available for Withdrawal</div>
                    <div class="stat-value">₱<?php echo number_format($wallet['balance'], 2); ?></div>
                </div>
            </div>
            
            <div class="action-buttons">
                <button class="btn-deposit" onclick="openModal('deposit')">
                    <i class="fas fa-plus-circle"></i> Add Money
                </button>
                <button class="btn-withdraw" onclick="openModal('withdraw')">
                    <i class="fas fa-arrow-circle-up"></i> Withdraw
                </button>
            </div>
        </div>
        
        <!-- Transaction History -->
        <div class="wallet-card">
            <div style="padding: 25px;">
                <h3><i class="fas fa-history"></i> Transaction History</h3>
                <?php if($transactions->rowCount() > 0): ?>
                    <div class="transaction-table">
                        <table>
                            <thead>
                                <tr><th>Date</th><th>Description</th><th>Type</th><th>Amount</th><th>Status</th></tr>
                            </thead>
                            <tbody>
                                <?php while($txn = $transactions->fetch()): ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($txn['transaction_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($txn['description']); ?></td>
                                    <td><?php echo ucfirst($txn['type']); ?></td>
                                    <td class="<?php echo $txn['amount'] > 0 ? 'credit' : 'debit'; ?>">
                                        <?php echo $txn['amount'] > 0 ? '+' : ''; ?>₱<?php echo number_format(abs($txn['amount']), 2); ?>
                                    </td>
                                    <td><span class="status-<?php echo $txn['status']; ?>"><?php echo ucfirst($txn['status']); ?></span></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p style="text-align: center; padding: 30px;">No transactions yet.</p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Withdrawal Requests -->
        <?php if($withdrawals->rowCount() > 0): ?>
        <div class="wallet-card">
            <div style="padding: 25px;">
                <h3><i class="fas fa-clock"></i> Withdrawal Requests</h3>
                <div class="transaction-table">
                    <table>
                        <thead><tr><th>Date</th><th>Amount</th><th>Method</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php while($wd = $withdrawals->fetch()): ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($wd['requested_at'])); ?></td>
                                <td>₱<?php echo number_format($wd['amount'], 2); ?></td>
                                <td><?php echo htmlspecialchars($wd['payment_method']); ?></td>
                                <td><span class="status-<?php echo $wd['status']; ?>"><?php echo ucfirst($wd['status']); ?></span></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Deposit Modal -->
    <div id="depositModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus-circle"></i> Add Money to Wallet</h3>
                <span class="close" onclick="closeModal('depositModal')">&times;</span>
            </div>
            <?php if($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>
            <?php if($error): ?>
                <div class="alert alert-error"><?php echo $error; ?></div>
            <?php endif; ?>
            <form method="POST">
                <div class="form-group">
                    <label>Amount (₱)</label>
                    <input type="number" name="deposit_amount" step="0.01" min="10" required placeholder="Minimum ₱10">
                </div>
                <div class="form-group">
                    <label>Payment Method</label>
                    <select name="payment_method" required>
                        <option value="M-Pesa">M-Pesa</option>
                        <option value="Airtel Money">Airtel Money</option>
                        <option value="Bank Card">Bank Card</option>
                        <option value="Bank Transfer">Bank Transfer</option>
                    </select>
                </div>
                <button type="submit" class="btn-submit">Proceed to Payment</button>
            </form>
        </div>
    </div>
    
    <!-- Withdraw Modal -->
    <div id="withdrawModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-arrow-circle-up"></i> Withdraw Funds</h3>
                <span class="close" onclick="closeModal('withdrawModal')">&times;</span>
            </div>
            <form method="POST">
                <div class="form-group">
                    <label>Amount (₱)</label>
                    <input type="number" name="withdraw_amount" step="0.01" min="100" max="<?php echo $wallet['balance']; ?>" required placeholder="Min ₱100, Max ₱<?php echo number_format($wallet['balance'], 2); ?>">
                </div>
                <div class="form-group">
                    <label>Withdrawal Method</label>
                    <select name="withdraw_method" required>
                        <option value="M-Pesa">M-Pesa</option>
                        <option value="Airtel Money">Airtel Money</option>
                        <option value="Bank Transfer">Bank Transfer</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Account Details</label>
                    <textarea name="account_details" rows="3" placeholder="Enter your phone number or bank account details" required></textarea>
                </div>
                <button type="submit" class="btn-submit">Request Withdrawal</button>
            </form>
        </div>
    </div>
    
    <script>
        function openModal(type) {
            if(type === 'deposit') {
                document.getElementById('depositModal').style.display = 'flex';
            } else {
                document.getElementById('withdrawModal').style.display = 'flex';
            }
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
    </script>
</body>
</html>