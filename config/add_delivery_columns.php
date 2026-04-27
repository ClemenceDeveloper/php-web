<?php
require_once 'db.php';

try {
    // Add accepted_at column
    $pdo->exec("ALTER TABLE transport_requests ADD COLUMN IF NOT EXISTS accepted_at TIMESTAMP NULL");
    echo "✅ Added 'accepted_at' column<br>";
    
    // Add picked_up_at column
    $pdo->exec("ALTER TABLE transport_requests ADD COLUMN IF NOT EXISTS picked_up_at TIMESTAMP NULL");
    echo "✅ Added 'picked_up_at' column<br>";
    
    // Add delivered_at column
    $pdo->exec("ALTER TABLE transport_requests ADD COLUMN IF NOT EXISTS delivered_at TIMESTAMP NULL");
    echo "✅ Added 'delivered_at' column<br>";
    
    // Add in_transit_at column
    $pdo->exec("ALTER TABLE transport_requests ADD COLUMN IF NOT EXISTS in_transit_at TIMESTAMP NULL");
    echo "✅ Added 'in_transit_at' column<br>";
    
    // Add out_for_delivery_at column
    $pdo->exec("ALTER TABLE transport_requests ADD COLUMN IF NOT EXISTS out_for_delivery_at TIMESTAMP NULL");
    echo "✅ Added 'out_for_delivery_at' column<br>";
    
    echo "<br><strong>All columns added successfully!</strong><br>";
    echo "<a href='../dashboard/transport.php'>Go to Transport Dashboard</a>";
    
} catch(PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>