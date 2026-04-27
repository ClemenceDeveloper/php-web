<?php
require_once 'db.php';

try {
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
            INDEX idx_participants (participant1_id, participant2_id)
        )
    ");
    
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
            INDEX idx_receiver_read (receiver_id, is_read)
        )
    ");
    
    echo "✅ Messaging tables created successfully!";
} catch(PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>