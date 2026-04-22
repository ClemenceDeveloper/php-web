<?php
// Include database connection - FIXED PATH
require_once dirname(__DIR__) . '/config/db.php';

// Check if user is already logged in
if(isset($_SESSION['user_id'])) {
    header("Location: ../dashboard/{$_SESSION['role']}.php");
    exit();
}

$error = '';
$success = '';

// Check if form is submitted
if($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Get form data
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $role = $_POST['role'];
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);
    $fullname = trim($_POST['fullname']);
    
    // Validation
    if(empty($username) || empty($email) || empty($password)) {
        $error = "Please fill in all required fields!";
    } elseif($password !== $confirm_password) {
        $error = "Passwords do not match!";
    } elseif(strlen($password) < 6) {
        $error = "Password must be at least 6 characters long!";
    } elseif(!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format!";
    } elseif(strlen($username) < 3) {
        $error = "Username must be at least 3 characters long!";
    } else {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        try {
            // Check if $pdo exists
            if(!isset($pdo)) {
                throw new Exception("Database connection not established");
            }
            
            $stmt = $pdo->prepare("INSERT INTO users (username, email, password, role, phone, address) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$username, $email, $hashed_password, $role, $phone, $address]);
            $success = "Registration successful! You can now login.";
            
            // Clear form data
            $username = $email = $phone = $address = $fullname = '';
            
            // Redirect to login after 2 seconds
            echo "<meta http-equiv='refresh' content='2;url=login.php'>";
        } catch(PDOException $e) {
            if($e->errorInfo[1] == 1062) {
                $error = "Username or email already exists!";
            } else {
                $error = "Registration failed: " . $e->getMessage();
            }
        } catch(Exception $e) {
            $error = "System error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Agriculture Marketplace</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 40px 20px;
            position: relative;
            overflow-x: hidden;
        }

        .register-container {
            max-width: 550px;
            margin: 0 auto;
            animation: slideUp 0.6s ease;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .register-card {
            background: rgba(255, 255, 255, 0.98);
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
        }

        .register-header {
            background: linear-gradient(135deg, #2e7d32, #1b5e20);
            padding: 2rem;
            text-align: center;
            color: white;
        }

        .register-header .icon {
            font-size: 3.5rem;
            margin-bottom: 1rem;
            animation: bounce 2s infinite;
            display: inline-block;
        }

        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }

        .register-header h2 {
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
        }

        .register-body {
            padding: 2rem;
        }

        .form-group {
            margin-bottom: 1.2rem;
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #333;
            font-size: 0.85rem;
        }

        .form-group label i {
            margin-right: 0.5rem;
            color: #2e7d32;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 0.95rem;
            transition: all 0.3s;
            font-family: inherit;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #2e7d32;
            box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.1);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }

        .role-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .role-card {
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            padding: 1rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }

        .role-card:hover {
            transform: translateY(-3px);
            border-color: #2e7d32;
        }

        .role-card.selected {
            background: linear-gradient(135deg, #2e7d32, #1b5e20);
            border-color: #2e7d32;
            color: white;
        }

        .role-card.selected i,
        .role-card.selected .role-title {
            color: white;
        }

        .role-card i {
            font-size: 2rem;
            color: #2e7d32;
            margin-bottom: 0.5rem;
            display: block;
        }

        .role-title {
            font-weight: 600;
            font-size: 0.9rem;
        }

        .role-card input {
            display: none;
        }

        .alert {
            padding: 12px 15px;
            border-radius: 10px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert-error {
            background: #fee;
            color: #c33;
            border-left: 4px solid #c33;
        }

        .alert-success {
            background: #efe;
            color: #3c3;
            border-left: 4px solid #3c3;
        }

        .register-btn {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #2e7d32, #1b5e20);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 1rem;
        }

        .register-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(46, 125, 50, 0.3);
        }

        .login-link {
            text-align: center;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid #e0e0e0;
        }

        .login-link a {
            color: #2e7d32;
            text-decoration: none;
            font-weight: 600;
        }

        .back-home {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: white;
            text-decoration: none;
            margin-top: 1rem;
            padding: 0.5rem 1rem;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50px;
        }

        .terms {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 1rem 0;
        }

        .terms input {
            width: auto;
        }

        @media (max-width: 600px) {
            .register-container {
                padding: 10px;
            }
            
            .register-header h2 {
                font-size: 1.5rem;
            }
            
            .register-body {
                padding: 1.5rem;
            }
            
            .role-cards {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="register-card">
            <div class="register-header">
                <div class="icon">
                    <i class="fas fa-user-plus"></i>
                </div>
                <h2>Join Our Community</h2>
                <p>Create your account and start your agricultural journey</p>
            </div>

            <div class="register-body">
                <?php if($error): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <span><?php echo htmlspecialchars($error); ?></span>
                    </div>
                <?php endif; ?>

                <?php if($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <span><?php echo htmlspecialchars($success); ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <div class="form-group">
                        <label><i class="fas fa-user"></i> Full Name</label>
                        <input type="text" name="fullname" placeholder="Enter your full name" 
                               value="<?php echo isset($fullname) ? htmlspecialchars($fullname) : ''; ?>">
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-user-circle"></i> Username *</label>
                        <input type="text" name="username" placeholder="Choose a username (min. 3 characters)" 
                               value="<?php echo isset($username) ? htmlspecialchars($username) : ''; ?>" required>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-envelope"></i> Email Address *</label>
                        <input type="email" name="email" placeholder="Enter your email address" 
                               value="<?php echo isset($email) ? htmlspecialchars($email) : ''; ?>" required>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-lock"></i> Password *</label>
                        <input type="password" id="password" name="password" placeholder="Create a password (min. 6 characters)" required>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-lock"></i> Confirm Password *</label>
                        <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm your password" required>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-phone"></i> Phone Number</label>
                        <input type="tel" name="phone" placeholder="+63 XXX XXX XXXX" 
                               value="<?php echo isset($phone) ? htmlspecialchars($phone) : ''; ?>">
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-map-marker-alt"></i> Address</label>
                        <textarea name="address" placeholder="Enter your complete address"><?php echo isset($address) ? htmlspecialchars($address) : ''; ?></textarea>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-user-tag"></i> Register as *</label>
                        <div class="role-cards">
                            <label class="role-card" data-role="user">
                                <i class="fas fa-shopping-bag"></i>
                                <div class="role-title">Buyer</div>
                                <input type="radio" name="role" value="user" required>
                            </label>

                            <label class="role-card" data-role="farmer">
                                <i class="fas fa-tractor"></i>
                                <div class="role-title">Farmer</div>
                                <input type="radio" name="role" value="farmer" required>
                            </label>

                            <label class="role-card" data-role="transport">
                                <i class="fas fa-truck"></i>
                                <div class="role-title">Transport</div>
                                <input type="radio" name="role" value="transport" required>
                            </label>
                        </div>
                    </div>

                    <div class="terms">
                        <input type="checkbox" id="terms" required>
                        <label for="terms">
                            I agree to the <a href="#">Terms of Service</a> and <a href="#">Privacy Policy</a>
                        </label>
                    </div>

                    <button type="submit" class="register-btn">
                        <i class="fas fa-check-circle"></i> Register
                    </button>
                </form>

                <div class="login-link">
                    <p>Already have an account? <a href="login.php">Login here</a></p>
                </div>
            </div>
        </div>

        <a href="../index.php" class="back-home">
            <i class="fas fa-arrow-left"></i> Back to Home
        </a>
    </div>

    <script>
        // Role card selection
        document.querySelectorAll('.role-card').forEach(card => {
            card.addEventListener('click', function() {
                document.querySelectorAll('.role-card').forEach(c => c.classList.remove('selected'));
                this.classList.add('selected');
                const radio = this.querySelector('input[type="radio"]');
                radio.checked = true;
            });
        });

        // Password match validation
        const password = document.getElementById('password');
        const confirmPassword = document.getElementById('confirm_password');
        
        function validatePassword() {
            if(password.value !== confirmPassword.value) {
                confirmPassword.setCustomValidity("Passwords don't match");
            } else {
                confirmPassword.setCustomValidity('');
            }
        }
        
        password.onchange = validatePassword;
        confirmPassword.onkeyup = validatePassword;
        
        // Auto-select first role card if none selected
        if(!document.querySelector('input[name="role"]:checked')) {
            document.querySelector('.role-card').classList.add('selected');
            document.querySelector('input[name="role"]').checked = true;
        }
    </script>
</body>
</html>