<?php
require_once 'db.php';

try {
    // Check if conversations table exists
    $check = $pdo->query("SHOW TABLES LIKE 'conversations'");
    if($check->rowCount() == 0) {
        // Create conversations table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS conversations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                participant1_id INT NOT NULL,
                participant2_id INT NOT NULL,
                last_message TEXT,
                last_message_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (participant1_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (participant2_id) REFERENCES users(id) ON DELETE CASCADE,
                UNIQUE KEY unique_conversation (participant1_id, participant2_id),
                INDEX idx_participants (participant1_id, participant2_id)
            )
        ");
        echo "✅ Conversations table created.<br>";
    } else {
        echo "• Conversations table already exists.<br>";
    }
    
    // Check if messages table exists
    $check = $pdo->query("SHOW TABLES LIKE 'messages'");
    if($check->rowCount() == 0) {
        // Create messages table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                conversation_id INT NOT NULL,
                sender_id INT NOT NULL,
                receiver_id INT NOT NULL,
                message TEXT NOT NULL,
                is_read BOOLEAN DEFAULT FALSE,
                read_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
                FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_conversation (conversation_id),
                INDEX idx_sender (sender_id),
                INDEX idx_receiver (receiver_id),
                INDEX idx_read (is_read)
            )
        ");
        echo "✅ Messages table created.<br>";
    } else {
        echo "• Messages table already exists.<br>";
    }
    
    echo "<br><strong>Messaging system is ready!</strong>";
    echo "<br><a href='../dashboard/farmer.php'>Go to Farmer Dashboard</a>";
    
} catch(PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>