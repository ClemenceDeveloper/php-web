<?php
require_once 'db.php';

// First, check if there are any orders
$stmt = $pdo->query("SELECT COUNT(*) FROM orders");
$orders_count = $stmt->fetchColumn();

if($orders_count == 0) {
    // Create sample products if none exist
    $stmt = $pdo->query("SELECT COUNT(*) FROM products");
    $products_count = $stmt->fetchColumn();
    
    if($products_count == 0) {
        // Get a farmer user
        $stmt = $pdo->query("SELECT id FROM users WHERE role = 'farmer' LIMIT 1");
        $farmer = $stmt->fetch();
        
        if(!$farmer) {
            die("No farmer found. Please register a farmer first.");
        }
        
        // Add sample products
        $products = [
            ['Organic Rice', 'Grains', 100, 'kg', 45.00, 'Freshly harvested organic rice'],
            ['Fresh Tomatoes', 'Vegetables', 50, 'kg', 30.00, 'Farm fresh tomatoes'],
            ['Green Apples', 'Fruits', 75, 'kg', 60.00, 'Sweet and crispy apples'],
            ['Fresh Milk', 'Dairy', 30, 'liter', 55.00, 'Pure fresh milk'],
            ['Organic Eggs', 'Dairy', 200, 'piece', 15.00, 'Free-range organic eggs']
        ];
        
        foreach($products as $product) {
            $stmt = $pdo->prepare("INSERT INTO products (farmer_id, name, category, quantity, unit, price, description, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'available')");
            $stmt->execute([$farmer['id'], $product[0], $product[1], $product[2], $product[3], $product[4], $product[5]]);
        }
        echo "Sample products added.<br>";
    }
    
    // Create sample orders
    $stmt = $pdo->query("SELECT id FROM products LIMIT 3");
    $products = $stmt->fetchAll();
    
    $stmt = $pdo->query("SELECT id FROM users WHERE role = 'user' LIMIT 1");
    $buyer = $stmt->fetch();
    
    if($buyer) {
        foreach($products as $product) {
            $stmt = $pdo->prepare("INSERT INTO orders (product_id, buyer_id, quantity, total_price, status) VALUES (?, ?, ?, ?, 'pending')");
            $stmt->execute([$product['id'], $buyer['id'], 10, 500, 'pending']);
            $order_id = $pdo->lastInsertId();
            
            // Create transport request for each order
            $stmt = $pdo->prepare("
                INSERT INTO transport_requests (order_id, status, pickup_location, delivery_location) 
                VALUES (?, 'pending', 'Farm Location', 'Buyer Address')
            ");
            $stmt->execute([$order_id]);
        }
        echo "Sample orders and delivery requests added.<br>";
    }
}

// Now create transport requests for pending orders
$stmt = $pdo->query("
    SELECT o.id as order_id, o.total_price, p.name as product_name, 
           farmer.address as pickup_address, buyer.address as delivery_address,
           farmer.username as farmer_name, buyer.username as buyer_name,
           farmer.phone as farmer_phone, buyer.phone as buyer_phone
    FROM orders o
    JOIN products p ON o.product_id = p.id
    JOIN users farmer ON p.farmer_id = farmer.id
    JOIN users buyer ON o.buyer_id = buyer.id
    WHERE o.status = 'pending'
");

$orders = $stmt->fetchAll();

foreach($orders as $order) {
    // Check if transport request already exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM transport_requests WHERE order_id = ?");
    $stmt->execute([$order['order_id']]);
    if($stmt->fetchColumn() == 0) {
        $stmt = $pdo->prepare("
            INSERT INTO transport_requests (order_id, status, pickup_location, delivery_location) 
            VALUES (?, 'pending', ?, ?)
        ");
        $stmt->execute([$order['order_id'], $order['pickup_address'] ?: 'Farm Location', $order['delivery_address'] ?: 'Buyer Address']);
        echo "Transport request created for order #{$order['order_id']}<br>";
    }
}

echo "<br><strong>✅ Delivery requests have been created!</strong><br>";
echo "<a href='../dashboard/transport.php'>Go to Transport Dashboard</a>";
?>