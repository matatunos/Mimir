<?php
/**
 * Color Extractor - Extract dominant colors from images
 */

class ColorExtractor {
    
    /**
     * Extract dominant colors from an image
     * 
     * @param string $imagePath Path to the image file
     * @param int $numColors Number of colors to extract
     * @return array Array of hex colors
     */
    public function extractColors($imagePath, $numColors = 3) {
        if (!file_exists($imagePath)) {
            throw new Exception("Image file not found: $imagePath");
        }
        
        // Get image info
        $imageInfo = getimagesize($imagePath);
        if (!$imageInfo) {
            throw new Exception("Invalid image file");
        }
        
        $mimeType = $imageInfo['mime'];
        
        // Create image resource based on type
        switch ($mimeType) {
            case 'image/jpeg':
                $image = imagecreatefromjpeg($imagePath);
                break;
            case 'image/png':
                $image = imagecreatefrompng($imagePath);
                break;
            case 'image/gif':
                $image = imagecreatefromgif($imagePath);
                break;
            case 'image/webp':
                $image = imagecreatefromwebp($imagePath);
                break;
            default:
                throw new Exception("Unsupported image type: $mimeType");
        }
        
        if (!$image) {
            throw new Exception("Failed to create image resource");
        }
        
        // Resize image for faster processing
        $resizedImage = $this->resizeImage($image, 150, 150);
        imagedestroy($image);
        
        // Extract colors
        $colors = $this->getImageColors($resizedImage);
        imagedestroy($resizedImage);
        
        // Get dominant colors
        $dominantColors = $this->getDominantColors($colors, $numColors);
        
        return $dominantColors;
    }
    
    /**
     * Extract brand colors (primary, secondary, accent) from logo
     * 
     * @param string $imagePath Path to the logo file
     * @return array Array with keys: primary, secondary, accent
     */
    public function extractBrandColors($imagePath) {
        $colors = $this->extractColors($imagePath, 5);
        
        // Remove very light colors (too close to white) and very dark colors
        $filteredColors = array_filter($colors, function($color) {
            $rgb = $this->hexToRgb($color);
            $brightness = ($rgb['r'] * 299 + $rgb['g'] * 587 + $rgb['b'] * 114) / 1000;
            return $brightness > 40 && $brightness < 240; // Not too dark, not too light
        });
        
        // If we filtered out too many, use original
        if (count($filteredColors) < 3) {
            $filteredColors = $colors;
        }
        
        $filteredColors = array_values($filteredColors);
        
        // Primary: Most dominant color
        $primary = $filteredColors[0] ?? '#1e40af';
        
        // Secondary: Second most dominant, but ensure it's different enough
        $secondary = $this->findDistinctColor($filteredColors, $primary, 1);
        
        // Accent: A brighter/more saturated variant or distinct color
        $accent = $this->findAccentColor($filteredColors, $primary, $secondary);
        
        return [
            'primary' => $primary,
            'secondary' => $secondary,
            'accent' => $accent
        ];
    }
    
    /**
     * Resize image while maintaining aspect ratio
     */
    private function resizeImage($image, $maxWidth, $maxHeight) {
        $width = imagesx($image);
        $height = imagesy($image);
        
        $ratio = min($maxWidth / $width, $maxHeight / $height);
        $newWidth = intval($width * $ratio);
        $newHeight = intval($height * $ratio);
        
        $newImage = imagecreatetruecolor($newWidth, $newHeight);
        
        // Preserve transparency
        imagealphablending($newImage, false);
        imagesavealpha($newImage, true);
        
        imagecopyresampled($newImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        
        return $newImage;
    }
    
    /**
     * Get all colors from image with their frequency
     */
    private function getImageColors($image) {
        $width = imagesx($image);
        $height = imagesy($image);
        $colors = [];
        
        // Sample every few pixels for performance
        $step = 2;
        
        for ($x = 0; $x < $width; $x += $step) {
            for ($y = 0; $y < $height; $y += $step) {
                $rgb = imagecolorat($image, $x, $y);
                $colors[] = [
                    'r' => ($rgb >> 16) & 0xFF,
                    'g' => ($rgb >> 8) & 0xFF,
                    'b' => $rgb & 0xFF,
                    'a' => ($rgb >> 24) & 0x7F
                ];
            }
        }
        
        return $colors;
    }
    
    /**
     * Get dominant colors using color quantization
     */
    private function getDominantColors($colors, $numColors) {
        // Filter out transparent pixels
        $colors = array_filter($colors, function($color) {
            return $color['a'] < 100; // Keep mostly opaque colors
        });
        
        // Group similar colors
        $buckets = [];
        foreach ($colors as $color) {
            // Quantize to reduce color space
            $bucket = sprintf('%02x%02x%02x',
                intval($color['r'] / 32) * 32,
                intval($color['g'] / 32) * 32,
                intval($color['b'] / 32) * 32
            );
            
            if (!isset($buckets[$bucket])) {
                $buckets[$bucket] = [
                    'count' => 0,
                    'r' => 0,
                    'g' => 0,
                    'b' => 0
                ];
            }
            
            $buckets[$bucket]['count']++;
            $buckets[$bucket]['r'] += $color['r'];
            $buckets[$bucket]['g'] += $color['g'];
            $buckets[$bucket]['b'] += $color['b'];
        }
        
        // Calculate average color for each bucket
        foreach ($buckets as &$bucket) {
            $bucket['r'] = intval($bucket['r'] / $bucket['count']);
            $bucket['g'] = intval($bucket['g'] / $bucket['count']);
            $bucket['b'] = intval($bucket['b'] / $bucket['count']);
        }
        
        // Sort by frequency
        uasort($buckets, function($a, $b) {
            return $b['count'] - $a['count'];
        });
        
        // Get top N colors
        $dominantColors = [];
        $count = 0;
        foreach ($buckets as $bucket) {
            if ($count >= $numColors) break;
            $dominantColors[] = $this->rgbToHex($bucket['r'], $bucket['g'], $bucket['b']);
            $count++;
        }
        
        return $dominantColors;
    }
    
    /**
     * Find a color distinct from the given color
     */
    private function findDistinctColor($colors, $referenceColor, $startIndex = 0) {
        $refRgb = $this->hexToRgb($referenceColor);
        
        for ($i = $startIndex; $i < count($colors); $i++) {
            $rgb = $this->hexToRgb($colors[$i]);
            $distance = $this->colorDistance($refRgb, $rgb);
            
            // If color is distinct enough (distance > 100), use it
            if ($distance > 100) {
                return $colors[$i];
            }
        }
        
        // If no distinct color found, darken/lighten the reference
        return $this->adjustBrightness($referenceColor, 0.6);
    }
    
    /**
     * Find an accent color - should be vibrant
     */
    private function findAccentColor($colors, $primary, $secondary) {
        $primaryRgb = $this->hexToRgb($primary);
        $secondaryRgb = $this->hexToRgb($secondary);
        
        // Look for a saturated color
        foreach ($colors as $color) {
            $rgb = $this->hexToRgb($color);
            $hsl = $this->rgbToHsl($rgb['r'], $rgb['g'], $rgb['b']);
            
            // High saturation and medium-high lightness = good accent
            if ($hsl['s'] > 0.5 && $hsl['l'] > 0.3 && $hsl['l'] < 0.7) {
                $distance1 = $this->colorDistance($primaryRgb, $rgb);
                $distance2 = $this->colorDistance($secondaryRgb, $rgb);
                
                if ($distance1 > 80 && $distance2 > 80) {
                    return $color;
                }
            }
        }
        
        // Fallback: brighten and saturate the primary color
        return $this->createAccentFromPrimary($primary);
    }
    
    /**
     * Create an accent color from primary by adjusting saturation and brightness
     */
    private function createAccentFromPrimary($hexColor) {
        $rgb = $this->hexToRgb($hexColor);
        $hsl = $this->rgbToHsl($rgb['r'], $rgb['g'], $rgb['b']);
        
        // Increase saturation and adjust lightness
        $hsl['s'] = min(1, $hsl['s'] * 1.3);
        $hsl['l'] = max(0.4, min(0.6, $hsl['l'] * 1.2));
        
        $rgb = $this->hslToRgb($hsl['h'], $hsl['s'], $hsl['l']);
        return $this->rgbToHex($rgb['r'], $rgb['g'], $rgb['b']);
    }
    
    /**
     * Calculate color distance (Euclidean distance in RGB space)
     */
    private function colorDistance($rgb1, $rgb2) {
        return sqrt(
            pow($rgb1['r'] - $rgb2['r'], 2) +
            pow($rgb1['g'] - $rgb2['g'], 2) +
            pow($rgb1['b'] - $rgb2['b'], 2)
        );
    }
    
    /**
     * Adjust brightness of a color
     */
    private function adjustBrightness($hexColor, $factor) {
        $rgb = $this->hexToRgb($hexColor);
        $rgb['r'] = max(0, min(255, intval($rgb['r'] * $factor)));
        $rgb['g'] = max(0, min(255, intval($rgb['g'] * $factor)));
        $rgb['b'] = max(0, min(255, intval($rgb['b'] * $factor)));
        return $this->rgbToHex($rgb['r'], $rgb['g'], $rgb['b']);
    }
    
    /**
     * Convert RGB to Hex
     */
    private function rgbToHex($r, $g, $b) {
        return sprintf("#%02x%02x%02x", $r, $g, $b);
    }
    
    /**
     * Convert Hex to RGB
     */
    private function hexToRgb($hex) {
        $hex = ltrim($hex, '#');
        return [
            'r' => hexdec(substr($hex, 0, 2)),
            'g' => hexdec(substr($hex, 2, 2)),
            'b' => hexdec(substr($hex, 4, 2))
        ];
    }
    
    /**
     * Convert RGB to HSL
     */
    private function rgbToHsl($r, $g, $b) {
        $r /= 255;
        $g /= 255;
        $b /= 255;
        
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        
        if ($max == $min) {
            $h = $s = 0;
        } else {
            $d = $max - $min;
            $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
            
            switch ($max) {
                case $r:
                    $h = (($g - $b) / $d + ($g < $b ? 6 : 0)) / 6;
                    break;
                case $g:
                    $h = (($b - $r) / $d + 2) / 6;
                    break;
                case $b:
                    $h = (($r - $g) / $d + 4) / 6;
                    break;
            }
        }
        
        return ['h' => $h, 's' => $s, 'l' => $l];
    }
    
    /**
     * Convert HSL to RGB
     */
    private function hslToRgb($h, $s, $l) {
        if ($s == 0) {
            $r = $g = $b = $l;
        } else {
            $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
            $p = 2 * $l - $q;
            
            $r = $this->hueToRgb($p, $q, $h + 1/3);
            $g = $this->hueToRgb($p, $q, $h);
            $b = $this->hueToRgb($p, $q, $h - 1/3);
        }
        
        return [
            'r' => intval($r * 255),
            'g' => intval($g * 255),
            'b' => intval($b * 255)
        ];
    }
    
    private function hueToRgb($p, $q, $t) {
        if ($t < 0) $t += 1;
        if ($t > 1) $t -= 1;
        if ($t < 1/6) return $p + ($q - $p) * 6 * $t;
        if ($t < 1/2) return $q;
        if ($t < 2/3) return $p + ($q - $p) * (2/3 - $t) * 6;
        return $p;
    }
}
