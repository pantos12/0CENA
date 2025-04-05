<?php
// Simple setup script to create required directories

// Define the directories to create
$directories = [
    'uploads',
    'database',
    'images',
    'videos',
    'css',
    'js'
];

echo "Cleveland Metroparks Assessment System - Setup\n";
echo "=============================================\n\n";

// Create directories if they don't exist
foreach ($directories as $dir) {
    if (!file_exists($dir)) {
        if (mkdir($dir, 0755, true)) {
            echo "✓ Created directory: $dir\n";
        } else {
            echo "✗ Failed to create directory: $dir\n";
        }
    } else {
        echo "✓ Directory already exists: $dir\n";
    }
}

echo "\nSetup complete!\n";
echo "Don't forget to add your background video as videos/background.mp4\n";
echo "and leaf images (leaf1.png, leaf2.png, leaf3.png) to the images directory.\n";
echo "\nYou can now access the application through your web server.\n";
?> 