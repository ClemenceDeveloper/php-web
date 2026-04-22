<?php
// Database configuration
$host = 'localhost';
$dbname = 'agriculture_market';
$username = 'root';
$password = '';

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    // Create PDO connection
    $pdo = new PDO("mysql:host=$host", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create database if not exists
    $pdo->exec("CREATE DATABASE IF NOT EXISTS $dbname");
    $pdo->exec("USE $dbname");
    
    // Create users table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            email VARCHAR(100) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            role ENUM('admin', 'farmer', 'user', 'transport') DEFAULT 'user',
            phone VARCHAR(20),
            address TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    // Create products table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            farmer_id INT,
            name VARCHAR(100) NOT NULL,
            category VARCHAR(50),
            quantity DECIMAL(10,2),
            unit VARCHAR(20),
            price DECIMAL(10,2),
            description TEXT,
            status ENUM('available', 'sold', 'pending') DEFAULT 'available',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (farmer_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
    
    // Create orders table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT,
            buyer_id INT,
            quantity DECIMAL(10,2),
            total_price DECIMAL(10,2),
            status ENUM('pending', 'confirmed', 'shipped', 'delivered', 'cancelled') DEFAULT 'pending',
            order_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            delivery_address TEXT,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
            FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
    
    // Create transport_requests table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS transport_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT,
            transport_id INT,
            status ENUM('pending', 'accepted', 'completed') DEFAULT 'pending',
            pickup_location TEXT,
            delivery_location TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
            FOREIGN KEY (transport_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
    // Add these tables to your existing db.php
$pdo->exec("
    CREATE TABLE IF NOT EXISTS payment_methods (
        id INT AUTO_INCREMENT PRIMARY KEY,
        method_name VARCHAR(50) NOT NULL,
        method_icon VARCHAR(50),
        is_active BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        user_id INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        payment_method VARCHAR(50) NOT NULL,
        payment_status ENUM('pending', 'completed', 'failed', 'refunded') DEFAULT 'pending',
        transaction_id VARCHAR(100),
        payment_details TEXT,
        payment_date TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )
");
    
    // Create default admin if not exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin'");
    $stmt->execute();
    $adminCount = $stmt->fetchColumn();
    
    if($adminCount == 0) {
        $defaultPassword = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (username, email, password, role, phone, address) VALUES (?, ?, ?, 'admin', ?, ?)");
        $stmt->execute(['admin', 'admin@agrimarket.com', $defaultPassword, '1234567890', 'Admin Office']);
    }
    
    // Start session if not started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Return the connection
    return $pdo;
    
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>