<?php
$source = "C:/Users/sunda/.gemini/antigravity/brain/0885e9e9-798d-47f2-a8f9-aafe787566c9/media__1779298220076.png";
if (!file_exists($source)) {
    $source = "C:/Users/sunda/.gemini/antigravity/brain/0885e9e9-798d-47f2-a8f9-aafe787566c9/media__1779297721838.png";
}
$dest = "c:/xampp/htdocs/gisticox/frontend/public/images/login-bg.webp";

if (!file_exists($source)) {
    die("Source file not found at: " . $source . "\n");
}

if (!function_exists('imagecreatefrompng')) {
    die("GD library is not enabled in PHP\n");
}

$img = @imagecreatefrompng($source);
if (!$img) {
    die("Failed to create image from PNG\n");
}

// Enable alpha blending and save alpha channel
imagealphablending($img, true);
imagesavealpha($img, true);

// Convert to webp with quality 80 (low size, high quality)
$success = imagewebp($img, $dest, 80);
imagedestroy($img);

if ($success) {
    echo "Successfully converted and optimized to $dest\n";
    echo "Original size: " . round(filesize($source) / 1024, 2) . " KB\n";
    echo "Optimized size: " . round(filesize($dest) / 1024, 2) . " KB\n";
} else {
    echo "Failed to save WebP image\n";
}
