<?php
// Script to generate placeholder leaf images

// Define image parameters
$width = 100;
$height = 100;
$leafColors = [
    [35, 87, 35],   // Dark green
    [70, 130, 50],  // Medium green
    [120, 160, 80]  // Light green
];

// Create the images directory if it doesn't exist
if (!file_exists('images') && !is_dir('images')) {
    mkdir('images', 0755, true);
}

echo "Generating placeholder leaf images...\n";

// Generate three different leaf shapes
for ($i = 1; $i <= 3; $i++) {
    $img = imagecreatetruecolor($width, $height);
    
    // Make the background transparent
    imagealphablending($img, false);
    imagesavealpha($img, true);
    $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
    imagefill($img, 0, 0, $transparent);
    
    // Get a leaf color
    $color = imagecolorallocate($img, $leafColors[$i-1][0], $leafColors[$i-1][1], $leafColors[$i-1][2]);
    
    // Create a simple leaf shape based on index
    switch ($i) {
        case 1:
            // Simple oval leaf
            imagefilledellipse($img, $width/2, $height/2, $width*0.8, $height*0.6, $color);
            imageline($img, $width/2, $height*0.3, $width/2, $height*0.7, imagecolorallocate($img, 30, 60, 30));
            break;
            
        case 2:
            // Maple-like leaf
            $points = [
                $width/2, $height*0.2,      // Top
                $width*0.7, $height*0.35,   // Top right
                $width*0.8, $height*0.5,    // Right
                $width*0.7, $height*0.65,   // Bottom right
                $width/2, $height*0.8,      // Bottom
                $width*0.3, $height*0.65,   // Bottom left
                $width*0.2, $height*0.5,    // Left
                $width*0.3, $height*0.35    // Top left
            ];
            imagefilledpolygon($img, $points, count($points)/2, $color);
            break;
            
        case 3:
            // Simple rounded triangle leaf
            $points = [
                $width/2, $height*0.2,     // Top
                $width*0.8, $height*0.7,   // Bottom right
                $width*0.2, $height*0.7    // Bottom left
            ];
            imagefilledpolygon($img, $points, count($points)/2, $color);
            // Add a stem
            imageline($img, $width/2, $height*0.7, $width/2, $height*0.9, imagecolorallocate($img, 30, 60, 30));
            break;
    }
    
    // Save the image
    $filename = "images/leaf{$i}.png";
    imagepng($img, $filename);
    imagedestroy($img);
    
    echo "Created {$filename}\n";
}

echo "Done!\n";
?> 