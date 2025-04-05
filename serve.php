<?php
// Launch PHP's built-in web server for development
$host = '127.0.0.1';
$port = 8000;
$root = __DIR__;

echo "0CENA - Document Assessment System - Development Server\n";
echo "=====================================================\n\n";
echo "Server starting at http://$host:$port\n";
echo "Press Ctrl+C to stop\n\n";

// Execute the PHP built-in server command
$command = "php -S $host:$port -t " . escapeshellarg($root);
passthru($command);
?> 