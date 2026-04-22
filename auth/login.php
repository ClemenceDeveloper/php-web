<?php
require_once '../config/db.php';

if(isset($_SESSION['user_id'])) {
    header("Location: ../dashboard/{$_SESSION['role']}.php");
    exit();
}

$error = '';
$success = '';

if($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch();
    
    if($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['email'] = $user['email'];
        
        header("Location: ../dashboard/{$user['role']}.php");
        exit();
    } else {
        $error = "Invalid username/email or password!";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Agriculture Marketplace</title>
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
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        /* Animated background elements */
        body::before {
            content: '🌾';
            position: absolute;
            font-size: 300px;
            opacity: 0.1;
            bottom: -100px;
            left: -100px;
            animation: float 20s infinite ease-in-out;
        }

        body::after {
            content: '🍅';
            position: absolute;
            font-size: 250px;
            opacity: 0.1;
            top: -80px;
            right: -80px;
            animation: float 15s infinite ease-in-out reverse;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(10deg); }
        }

        /* Main container */
        .login-container {
            width: 100%;
            max-width: 450px;
            padding: 20px;
            z-index: 1;
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

        /* Login Card */
        .login-card {
            background: rgba(255, 255, 255, 0.98);
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            backdrop-filter: blur(10px);
        }

        /* Header */
        .login-header {
            background: linear-gradient(135deg, #2e7d32, #1b5e20);
            padding: 2rem;
            text-align: center;
            color: white;
        }

        .login-header .icon {
            font-size: 4rem;
            margin-bottom: 1rem;
            animation: bounce 2s infinite;
        }

        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }

        .login-header h2 {
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
        }

        .login-header p {
            opacity: 0.9;
            font-size: 0.9rem;
        }

        /* Body */
        .login-body {
            padding: 2rem;
        }

        /* Form groups */
        .form-group {
            margin-bottom: 1.5rem;
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #333;
            font-size: 0.9rem;
        }

        .form-group i {
            position: absolute;
            left: 15px;
            top: 42px;
            color: #999;
            transition: all 0.3s;
        }

        .form-group input {
            width: 100%;
            padding: 12px 15px 12px 45px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s;
            font-family: inherit;
        }

        .form-group input:focus {
            outline: none;
            border-color: #2e7d32;
            box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.1);
        }

        .form-group input:focus + i {
            color: #2e7d32;
        }

        /* Remember me and forgot password */
        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .checkbox {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
        }

        .checkbox input {
            width: auto;
            cursor: pointer;
        }

        .checkbox span {
            color: #666;
            font-size: 0.9rem;
        }

        .forgot-password {
            color: #2e7d32;
            text-decoration: none;
            font-size: 0.9rem;
            transition: color 0.3s;
        }

        .forgot-password:hover {
            color: #1b5e20;
            text-decoration: underline;
        }

        /* Login Button */
        .login-btn {
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
            margin-bottom: 1rem;
        }

        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(46, 125, 50, 0.3);
        }

        .login-btn:active {
            transform: translateY(0);
        }

        /* Register link */
        .register-link {
            text-align: center;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid #e0e0e0;
        }

        .register-link p {
            color: #666;
            font-size: 0.9rem;
        }

        .register-link a {
            color: #2e7d32;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s;
        }

        .register-link a:hover {
            color: #1b5e20;
            text-decoration: underline;
        }

        /* Back to home */
        .back-home {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: white;
            text-decoration: none;
            margin-top: 1rem;
            transition: all 0.3s;
            padding: 0.5rem 1rem;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50px;
            backdrop-filter: blur(5px);
        }

        .back-home:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateX(-5px);
        }

        /* Alert messages */
        .alert {
            padding: 12px 15px;
            border-radius: 10px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            animation: shake 0.5s ease;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-10px); }
            75% { transform: translateX(10px); }
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

        .alert i {
            font-size: 1.2rem;
        }

        /* Role icons */
        .role-icons {
            display: flex;
            justify-content: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .role-icon {
            text-align: center;
            padding: 0.5rem;
            border-radius: 10px;
            transition: all 0.3s;
            cursor: pointer;
        }

        .role-icon i {
            font-size: 1.5rem;
            display: block;
            margin-bottom: 0.3rem;
        }

        .role-icon span {
            font-size: 0.75rem;
            color: #666;
        }

        /* Responsive */
        @media (max-width: 480px) {
            .login-container {
                padding: 15px;
            }
            
            .login-header h2 {
                font-size: 1.5rem;
            }
            
            .login-body {
                padding: 1.5rem;
            }
        }

        /* Loading animation */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="icon">
                    <i class="fas fa-seedling"></i>
                </div>
                <h2>Welcome Back!</h2>
                <p>Login to your agriculture marketplace account</p>
            </div>
            
            <div class="login-body">
                <!-- Role Icons Display -->
                <div class="role-icons">
                    <div class="role-icon">
                        <i class="fas fa-user"></i>
                        <span>Buyer</span>
                    </div>
                    <div class="role-icon">
                        <i class="fas fa-tractor"></i>
                        <span>Farmer</span>
                    </div>
                    <div class="role-icon">
                        <i class="fas fa-truck"></i>
                        <span>Transport</span>
                    </div>
                    <div class="role-icon">
                        <i class="fas fa-chart-line"></i>
                        <span>Admin</span>
                    </div>
                </div>

                <?php if($error): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <span><?php echo $error; ?></span>
                    </div>
                <?php endif; ?>

                <?php if($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <span><?php echo $success; ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" id="loginForm">
                    <div class="form-group">
                        <label for="username">Username or Email</label>
                        <i class="fas fa-user"></i>
                        <input type="text" id="username" name="username" 
                               placeholder="Enter your username or email" required autocomplete="off">
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <i class="fas fa-lock"></i>
                        <input type="password" id="password" name="password" 
                               placeholder="Enter your password" required>
                    </div>

                    <div class="form-options">
                        <label class="checkbox">
                            <input type="checkbox" id="remember">
                            <span>Remember me</span>
                        </label>
                        <a href="#" class="forgot-password">Forgot Password?</a>
                    </div>

                    <button type="submit" class="login-btn" id="loginBtn">
                        <i class="fas fa-sign-in-alt"></i> Login
                    </button>
                </form>

                <div class="register-link">
                    <p>Don't have an account? <a href="register.php">Create Account</a></p>
                    <p style="margin-top: 0.5rem; font-size: 0.8rem;">
                        <i class="fas fa-shield-alt"></i> Secure login with SSL encryption
                    </p>
                </div>
            </div>
        </div>

        <a href="../index.php" class="back-home">
            <i class="fas fa-arrow-left"></i> Back to Home
        </a>
    </div>

    <script>
        // Add loading animation on form submit
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const btn = document.getElementById('loginBtn');
            btn.innerHTML = '<span class="loading"></span> Logging in...';
            btn.disabled = true;
        });

        // Remember me functionality
        const rememberCheckbox = document.getElementById('remember');
        const usernameInput = document.getElementById('username');
        
        // Load saved username if exists
        if(localStorage.getItem('savedUsername')) {
            usernameInput.value = localStorage.getItem('savedUsername');
            rememberCheckbox.checked = true;
        }
        
        // Save username when form is submitted with remember me checked
        document.getElementById('loginForm').addEventListener('submit', function() {
            if(rememberCheckbox.checked) {
                localStorage.setItem('savedUsername', usernameInput.value);
            } else {
                localStorage.removeItem('savedUsername');
            }
        });

        // Password visibility toggle (optional)
        const passwordInput = document.getElementById('password');
        const togglePassword = document.createElement('i');
        togglePassword.className = 'fas fa-eye';
        togglePassword.style.cssText = `
            position: absolute;
            right: 15px;
            top: 42px;
            cursor: pointer;
            color: #999;
            z-index: 10;
        `;
        
        const passwordGroup = document.querySelector('.form-group:last-of-type');
        passwordGroup.style.position = 'relative';
        passwordGroup.appendChild(togglePassword);
        
        togglePassword.addEventListener('click', function() {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            this.classList.toggle('fa-eye-slash');
        });

        // Add floating label effect
        const inputs = document.querySelectorAll('.form-group input');
        inputs.forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.classList.add('focused');
            });
            
            input.addEventListener('blur', function() {
                if(!this.value) {
                    this.parentElement.classList.remove('focused');
                }
            });
        });

        // Demo credentials hint (remove in production)
        console.log('Demo Accounts:');
        console.log('Admin: admin / admin123');
        console.log('Farmer: farmer / farmer123');
        console.log('User: user / user123');
    </script>
</body>
</html>