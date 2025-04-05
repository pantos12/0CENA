<?php
// Script to generate a placeholder logo

// Define image parameters
$width = 200;
$height = 200;

// Create the images directory if it doesn't exist
if (!file_exists('images') && !is_dir('images')) {
    mkdir('images', 0755, true);
}

echo "Generating placeholder logo...\n";

// Create the image
$img = imagecreatetruecolor($width, $height);

// Colors
$background = imagecolorallocate($img, 35, 87, 35);
$light = imagecolorallocate($img, 255, 255, 255);
$accent = imagecolorallocate($img, 120, 180, 80);

// Fill background with dark green
imagefill($img, 0, 0, $background);

// Draw a simple tree silhouette
$treePoints = [
    $width/2, $height*0.2,  // Top point
    $width*0.3, $height*0.6, // Left point
    $width*0.4, $height*0.6, // Left inner point
    $width*0.35, $height*0.75, // Left lower point
    $width*0.45, $height*0.75, // Left inner lower point
    $width*0.45, $height*0.9, // Left bottom
    $width*0.55, $height*0.9, // Right bottom
    $width*0.55, $height*0.75, // Right inner lower point
    $width*0.65, $height*0.75, // Right lower point
    $width*0.6, $height*0.6, // Right inner point
    $width*0.7, $height*0.6, // Right point
];

// Fill the tree silhouette
imagefilledpolygon($img, $treePoints, count($treePoints)/2, $light);

// Draw a circle for the sun/moon
imagefilledellipse($img, $width*0.75, $height*0.35, $width*0.3, $height*0.3, $accent);

// Add text "MP" (for Metroparks)
$fontSize = 5;
$textColor = $background;
imagestring($img, $fontSize, $width*0.7, $height*0.33, "MP", $textColor);

// Draw a border
imagerectangle($img, 0, 0, $width-1, $height-1, $light);

// Save the image
$filename = "images/logo.png";
imagepng($img, $filename);
imagedestroy($img);

echo "Created {$filename}\n";
echo "Done!\n";
?> 