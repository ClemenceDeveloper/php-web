<?php
echo "<h1>Path Test</h1>";
echo "<p>This file is working!</p>";
echo "<p>Your project URL is: <strong>" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] . "</strong></p>";
echo "<br><a href='index.php'>Go to Homepage</a> | ";
echo "<a href='messages.php'>Go to Messages</a>";
?>