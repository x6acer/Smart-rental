<?php
require_once 'includes/auth.php';
require_once '../db_connect.php';

// Get rental ID from URL
$rental_id = $_GET['id'] ?? null;
if (!$rental_id) {
    header('Location: rentals.php');
    exit;
}

// Check for all possible columns we might need
$colCheck = $conn->prepare(
    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'rentals' 
    AND COLUMN_NAME IN (
        'id', 'name', 'title', 'category_id', 'description', 
        'image', 'daily_rate', 'price_per_day', 'status',
        'is_deleted', 'created_at'
    )"
);
$colCheck->execute();
$hasColumn = [];
while ($row = $colCheck->fetch(PDO::FETCH_ASSOC)) {
    $hasColumn[$row['COLUMN_NAME']] = true;
}

// Determine which fields to use
$nameField = !empty($hasColumn['name']) ? 'name' : 'title';
$rateField = !empty($hasColumn['daily_rate']) ? 'daily_rate' : 'price_per_day';
$hasIsDeleted = !empty($hasColumn['is_deleted']);

// Build select query with correct field names
$sql = "SELECT id, {$nameField} as name";

if (!empty($hasColumn['category_id'])) {
    $sql .= ", category_id";
}
if (!empty($hasColumn['description'])) {
    $sql .= ", description";
}
if (!empty($hasColumn['image'])) {
    $sql .= ", image";
}
if (!empty($hasColumn[$rateField])) {
    $sql .= ", {$rateField} as daily_rate";
}
if (!empty($hasColumn['status'])) {
    $sql .= ", status";
}

$sql .= " FROM rentals WHERE id = ?";

if ($hasIsDeleted) {
    $sql .= " AND is_deleted = 0";
}

// Get rental data with proper field aliasing
$stmt = $conn->prepare($sql);
$stmt->execute([$rental_id]);
$rental = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rental) {
    header('Location: rentals.php');
    exit;
}

// Get all categories for the dropdown
$stmt = $conn->prepare("SELECT * FROM categories ORDER BY name");
$stmt->execute();
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get values using correct field names
    $name = $_POST[$nameField] ?? '';
    $category_id = !empty($hasColumn['category_id']) ? ($_POST['category_id'] ?? '') : '';
    $description = !empty($hasColumn['description']) ? ($_POST['description'] ?? '') : '';
    $daily_rate = $_POST[$rateField] ?? '';
    $status = !empty($hasColumn['status']) ? ($_POST['status'] ?? 'available') : 'available';
    
    // Basic validation
    $errors = [];
    if (empty($name)) $errors[] = "Name is required";
    if (!empty($hasColumn['category_id']) && empty($category_id)) $errors[] = "Category is required";
    if (!empty($hasColumn[$rateField])) {
        if (empty($daily_rate)) {
            $errors[] = "Daily rate is required";
        } elseif (!is_numeric($daily_rate)) {
            $errors[] = "Daily rate must be a number";
        } elseif ($daily_rate <= 0) {
            $errors[] = "Daily rate must be greater than 0";
        }
        
        // Convert negative numbers to positive
        if (is_numeric($daily_rate)) {
            $daily_rate = abs($daily_rate);
        }
    }
    
    // Process image upload if no errors
    if (empty($errors)) {
        $image = $rental['image']; // Keep existing image by default
        if (!empty($_FILES['image']['name'])) {
            $upload = handleImageUpload($_FILES['image']);
            if ($upload['success']) {
                // Delete old image if exists
                if (!empty($rental['image'])) {
                    @unlink("uploads/" . $rental['image']);
                    @unlink("uploads/thumbs/" . $rental['image']);
                }
                $image = $upload['filename'];
            } else {
                $errors[] = $upload['error'];
            }
        }
        
                // Update rental if no errors
                if (empty($errors)) {
                    try {
                        $sql = "UPDATE rentals SET {$nameField} = ?";
                        $params = [$name];
                        
                        if (!empty($hasColumn['category_id'])) {
                            $sql .= ", category_id = ?";
                            $params[] = $category_id;
                        }
                        if (!empty($hasColumn['description'])) {
                            $sql .= ", description = ?";
                            $params[] = $description;
                        }
                        if (!empty($hasColumn[$rateField])) {
                            $sql .= ", {$rateField} = ?";
                            $params[] = $daily_rate;
                        }
                        if (!empty($hasColumn['status'])) {
                            $sql .= ", status = ?";
                            $params[] = $status;
                        }
                        if (!empty($hasColumn['image'])) {
                            $sql .= ", image = ?";
                            $params[] = $image;
                        }
                        
                        $sql .= " WHERE id = ?";
                        $params[] = $rental_id;

                        $stmt = $conn->prepare($sql);
                        $success = $stmt->execute($params);                if ($success) {
                    header('Location: rentals.php');
                    exit;
                } else {
                    $errors[] = "Failed to update rental";
                }
            } catch (PDOException $e) {
                $errors[] = "Database error: " . $e->getMessage();
            }
        }
    }
}

$page_title = "Edit Rental";
require_once 'includes/header.php';
?>

<div class="container px-6 mx-auto grid">
    <h2 class="my-6 text-2xl font-semibold text-gray-700">
        Edit Rental
    </h2>

    <?php if (!empty($errors)): ?>
    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <div class="px-4 py-3 mb-8 bg-white rounded-lg shadow-md">
        <form action="edit_rental.php?id=<?php echo $rental_id; ?>" method="POST" enctype="multipart/form-data">
            <div class="grid gap-6 mb-4 md:grid-cols-2">
                <div>
                    <label class="block text-sm">
                        <span class="text-gray-700">Name</span>
                        <input type="text" name="<?php echo $nameField; ?>" required
                            class="block w-full mt-1 text-sm rounded-lg border-gray-300 focus:border-purple-400 focus:outline-none focus:shadow-outline-purple"
                            value="<?php echo isset($_POST[$nameField]) ? htmlspecialchars($_POST[$nameField]) : htmlspecialchars($rental['name']); ?>">
                    </label>
                </div>

                <div>
                    <label class="block text-sm">
                        <span class="text-gray-700">Category</span>
                        <select name="category_id" <?php echo !empty($hasColumn['category_id']) ? 'required' : 'disabled'; ?>
                            class="block w-full mt-1 text-sm rounded-lg border-gray-300 focus:border-purple-400 focus:outline-none focus:shadow-outline-purple">
                            <option value="">Select a category</option>
                            <?php if (!empty($hasColumn['category_id'])): ?>
                            <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category['id']; ?>" 
                                <?php echo (isset($_POST['category_id']) ? $_POST['category_id'] : ($rental['category_id'] ?? '')) == $category['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($category['name']); ?>
                            </option>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </label>
                </div>

                <div>
                    <label class="block text-sm">
                        <span class="text-gray-700">Daily Rate (GHS)</span>
                        <input type="number" name="<?php echo $rateField; ?>" step="0.01" min="0.01" required
                            class="block w-full mt-1 text-sm rounded-lg border-gray-300 focus:border-purple-400 focus:outline-none focus:shadow-outline-purple"
                            value="<?php echo isset($_POST['daily_rate']) ? htmlspecialchars(abs($_POST['daily_rate'])) : htmlspecialchars(abs($rental['daily_rate'])); ?>"
                            oninput="this.value = this.value <= 0 ? Math.abs(this.value) : this.value"
                            onchange="this.value = this.value <= 0 ? 0.01 : this.value">
                    </label>
                </div>

                <div>
                    <label class="block text-sm">
                        <span class="text-gray-700">Status</span>
                        <select name="status" required
                            class="block w-full mt-1 text-sm rounded-lg border-gray-300 focus:border-purple-400 focus:outline-none focus:shadow-outline-purple">
                            <option value="available" <?php echo (isset($_POST['status']) ? $_POST['status'] : $rental['status']) == 'available' ? 'selected' : ''; ?>>Available</option>
                            <option value="rented" <?php echo (isset($_POST['status']) ? $_POST['status'] : $rental['status']) == 'rented' ? 'selected' : ''; ?>>Rented</option>
                            <option value="maintenance" <?php echo (isset($_POST['status']) ? $_POST['status'] : $rental['status']) == 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                        </select>
                    </label>
                </div>
            </div>

            <div class="mb-4">
                <label class="block text-sm">
                    <span class="text-gray-700">Description</span>
                    <textarea name="description" rows="4"
                        class="block w-full mt-1 text-sm rounded-lg border-gray-300 focus:border-purple-400 focus:outline-none focus:shadow-outline-purple"
                    ><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : htmlspecialchars($rental['description']); ?></textarea>
                </label>
            </div>

            <?php if (!empty($rental['image'])): ?>
            <div class="mb-4">
                <span class="text-sm text-gray-700 block mb-2">Current Image</span>
                <img src="uploads/thumbs/<?php echo htmlspecialchars($rental['image']); ?>" 
                     alt="Current rental image" 
                     class="w-32 h-32 object-cover rounded">
            </div>
            <?php endif; ?>

            <div class="mb-4">
                <label class="block text-sm">
                    <span class="text-gray-700">New Image (leave empty to keep current)</span>
                    <input type="file" name="image" accept="image/*"
                        class="block w-full mt-1 text-sm">
                </label>
            </div>

            <div class="flex justify-end mt-6">
                <button type="submit" 
                    class="px-4 py-2 text-sm font-medium leading-5 text-white transition-colors duration-150 bg-purple-600 border border-transparent rounded-lg hover:bg-purple-700 focus:outline-none focus:shadow-outline-purple">
                    Update Rental
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

<?php
function handleImageUpload($file) {
    $hasGD = extension_loaded('gd') && function_exists('imagecreatefromjpeg');
    
    $target_dir = "uploads/";
    $thumbs_dir = "uploads/thumbs/";
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }
    if (!file_exists($thumbs_dir)) {
        mkdir($thumbs_dir, 0777, true);
    }
    
    $filename = uniqid() . '_' . basename($file["name"]);
    $target_file = $target_dir . $filename;
    $imageFileType = strtolower(pathinfo($target_file,PATHINFO_EXTENSION));
    
    $check = getimagesize($file["tmp_name"]);
    if($check === false) {
        return ['success' => false, 'error' => "File is not an image."];
    }
    

    if ($file["size"] > 10000000) {
        return ['success' => false, 'error' => "File is too large."];
    }
    

    if($imageFileType != "jpg" && $imageFileType != "png" && $imageFileType != "jpeg" && $imageFileType != "gif" ) {
        return ['success' => false, 'error' => "Only JPG, JPEG, PNG & GIF files are allowed."];
    }
    

    if (move_uploaded_file($file["tmp_name"], $target_file)) {
        if ($hasGD) {
 
            createThumbnail($target_file, $thumbs_dir . $filename, 100);
        } else {
            copy($target_file, $thumbs_dir . $filename);
        }
        return ['success' => true, 'filename' => $filename];
    } else {
        return ['success' => false, 'error' => "Failed to upload file."];
    }
}

function createThumbnail($source_path, $thumb_path, $thumb_width) {

    if (!extension_loaded('gd') || !function_exists('imagecreatefromjpeg')) {
  
        return copy($source_path, $thumb_path);
    }

    try {
        $source_image = null;
        $extension = strtolower(pathinfo($source_path, PATHINFO_EXTENSION));
        
     
        if ($extension == 'jpg' || $extension == 'jpeg') {
            if (!function_exists('imagecreatefromjpeg')) {
                return copy($source_path, $thumb_path);
            }
            $source_image = imagecreatefromjpeg($source_path);
        } elseif ($extension == 'png') {
            if (!function_exists('imagecreatefrompng')) {
                return copy($source_path, $thumb_path);
            }
            $source_image = imagecreatefrompng($source_path);
        } elseif ($extension == 'gif') {
            if (!function_exists('imagecreatefromgif')) {
                return copy($source_path, $thumb_path);
            }
            $source_image = imagecreatefromgif($source_path);
        }
        
        if ($source_image === null) {
            return copy($source_path, $thumb_path);
        }
        
        // Get dimensions
        $width = imagesx($source_image);
        $height = imagesy($source_image);
        
        // Calculate new dimensions
        $thumb_height = floor($height * ($thumb_width / $width));
        
        // Create thumbnail image
        $thumb_image = imagecreatetruecolor($thumb_width, $thumb_height);
        if (!$thumb_image) {
            imagedestroy($source_image);
            return copy($source_path, $thumb_path);
        }
        
        // Preserve transparency for PNG images
        if ($extension == 'png') {
            imagealphablending($thumb_image, false);
            imagesavealpha($thumb_image, true);
        }
        
        // Resize
        imagecopyresampled($thumb_image, $source_image, 0, 0, 0, 0, $thumb_width, $thumb_height, $width, $height);
        
        // Save thumbnail
        $success = false;
        if ($extension == 'jpg' || $extension == 'jpeg') {
            $success = imagejpeg($thumb_image, $thumb_path, 90);
        } elseif ($extension == 'png') {
            $success = imagepng($thumb_image, $thumb_path);
        } elseif ($extension == 'gif') {
            $success = imagegif($thumb_image, $thumb_path);
        }
        
        // Clean up
        imagedestroy($source_image);
        imagedestroy($thumb_image);
        
        return $success;
    } catch (Exception $e) {
        // If anything goes wrong, fall back to copying
        return copy($source_path, $thumb_path);
    }
}
?>
