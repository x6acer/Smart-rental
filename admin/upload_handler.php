<?php
require_once 'includes/auth.php';

class UploadHandler {
    private $upload_dir;
    private $thumbs_dir;
    private $max_file_size = 5000000; // 5MB
    private $allowed_types = ['jpg', 'jpeg', 'png', 'gif'];
    
    public function __construct() {
        $this->upload_dir = __DIR__ . '/uploads/';
        $this->thumbs_dir = __DIR__ . '/uploads/thumbs/';
        
        // Create directories if they don't exist
        if (!file_exists($this->upload_dir)) {
            mkdir($this->upload_dir, 0755, true);
        }
        if (!file_exists($this->thumbs_dir)) {
            mkdir($this->thumbs_dir, 0755, true);
        }
    }
    
    public function handleUpload($file) {
        // Validate file
        $validation = $this->validateFile($file);
        if (!$validation['success']) {
            return $validation;
        }
        
        // Generate unique filename
        $filename = uniqid() . '_' . basename($file["name"]);
        $target_file = $this->upload_dir . $filename;
        
        // Upload file
        if (move_uploaded_file($file["tmp_name"], $target_file)) {
            // Create thumbnail
            $this->createThumbnail($target_file, $this->thumbs_dir . $filename);
            return ['success' => true, 'filename' => $filename];
        }
        
        return ['success' => false, 'error' => 'Failed to upload file.'];
    }
    
    private function validateFile($file) {
        // Check if file was uploaded
        if (!isset($file['tmp_name']) || empty($file['tmp_name'])) {
            return ['success' => false, 'error' => 'No file uploaded.'];
        }
        
        // Check if image file is a actual image
        $check = getimagesize($file["tmp_name"]);
        if ($check === false) {
            return ['success' => false, 'error' => 'File is not an image.'];
        }
        
        // Check file size
        if ($file["size"] > $this->max_file_size) {
            return ['success' => false, 'error' => 'File is too large.'];
        }
        
        // Check file type
        $extension = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));
        if (!in_array($extension, $this->allowed_types)) {
            return ['success' => false, 'error' => 'Only JPG, JPEG, PNG & GIF files are allowed.'];
        }
        
        return ['success' => true];
    }
    
    private function createThumbnail($source_path, $thumb_path, $thumb_width = 100) {
        $source_image = null;
        $extension = strtolower(pathinfo($source_path, PATHINFO_EXTENSION));
        
        // Create source image based on file type
        if ($extension == 'jpg' || $extension == 'jpeg') {
            $source_image = imagecreatefromjpeg($source_path);
        } elseif ($extension == 'png') {
            $source_image = imagecreatefrompng($source_path);
        } elseif ($extension == 'gif') {
            $source_image = imagecreatefromgif($source_path);
        }
        
        if ($source_image === null) return false;
        
        // Get dimensions
        $width = imagesx($source_image);
        $height = imagesy($source_image);
        
        // Calculate new dimensions
        $thumb_height = floor($height * ($thumb_width / $width));
        
        // Create thumbnail image
        $thumb_image = imagecreatetruecolor($thumb_width, $thumb_height);
        
        // Preserve transparency for PNG images
        if ($extension == 'png') {
            imagealphablending($thumb_image, false);
            imagesavealpha($thumb_image, true);
        }
        
        // Resize
        imagecopyresampled($thumb_image, $source_image, 0, 0, 0, 0, $thumb_width, $thumb_height, $width, $height);
        
        // Save thumbnail
        if ($extension == 'jpg' || $extension == 'jpeg') {
            imagejpeg($thumb_image, $thumb_path, 90);
        } elseif ($extension == 'png') {
            imagepng($thumb_image, $thumb_path);
        } elseif ($extension == 'gif') {
            imagegif($thumb_image, $thumb_path);
        }
        
        // Clean up
        imagedestroy($source_image);
        imagedestroy($thumb_image);
        
        return true;
    }
    
    public function deleteFile($filename) {
        if (empty($filename)) return;
        
        // Delete main image
        $main_file = $this->upload_dir . $filename;
        if (file_exists($main_file)) {
            unlink($main_file);
        }
        
        // Delete thumbnail
        $thumb_file = $this->thumbs_dir . $filename;
        if (file_exists($thumb_file)) {
            unlink($thumb_file);
        }
    }
}

// Handle AJAX upload requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['file'])) {
    $handler = new UploadHandler();
    $result = $handler->handleUpload($_FILES['file']);
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}
?>
