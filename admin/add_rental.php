<?php
require_once 'includes/auth.php';
require_once '../db_connect.php';

$stmt = $conn->prepare("SELECT * FROM categories ORDER BY name");
$stmt->execute();
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

$colCheck = $conn->prepare(
    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rentals' AND COLUMN_NAME IN ('name','title','daily_rate','price_per_day','status','image','description','category_id')"
);
$colCheck->execute();
$hasColumn = [];
while ($r = $colCheck->fetch(PDO::FETCH_ASSOC)) {
    $hasColumn[$r['COLUMN_NAME']] = true;
}

$titleColumn = !empty($hasColumn['name']) ? 'name' : (!empty($hasColumn['title']) ? 'title' : null);
$rateColumn = !empty($hasColumn['daily_rate']) ? 'daily_rate' : (!empty($hasColumn['price_per_day']) ? 'price_per_day' : null);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'] ?? '';
    $category_id = $_POST['category_id'] ?? '';
    $description = $_POST['description'] ?? '';
    $daily_rate = $_POST['daily_rate'] ?? '';
    $status = $_POST['status'] ?? 'available';
    
    $errors = [];
    if (empty($name)) $errors[] = "Name is required";
    if (empty($category_id)) $errors[] = "Category is required";
    if (empty($daily_rate)) $errors[] = "Daily rate is required";
    if (!is_numeric($daily_rate)) $errors[] = "Daily rate must be a number";
    if (is_numeric($daily_rate) && $daily_rate <= 0) $errors[] = "Daily rate must be greater than 0";
    
    if (is_numeric($daily_rate)) {
        $daily_rate = abs($daily_rate);
    }
    
    if (empty($errors)) {
        $image = '';
        if (!empty($_FILES['image']['name'])) {
            $upload = handleImageUpload($_FILES['image']);
            if ($upload['success']) {
                $image = $upload['filename'];
            } else {
                $errors[] = $upload['error'];
            }
        }
        
        if (empty($errors)) {
            try {
                $insertCols = [];
                $placeholders = [];
                $insertVals = [];

                if ($titleColumn) {
                    $insertCols[] = $titleColumn;
                    $placeholders[] = '?';
                    $insertVals[] = $name;
                }

                if (!empty($hasColumn['category_id'])) {
                    $insertCols[] = 'category_id';
                    $placeholders[] = '?';
                    $insertVals[] = $category_id;
                }

                if (!empty($hasColumn['description'])) {
                    $insertCols[] = 'description';
                    $placeholders[] = '?';
                    $insertVals[] = $description;
                }

                if ($rateColumn) {
                    $insertCols[] = $rateColumn;
                    $placeholders[] = '?';
                    $insertVals[] = $daily_rate;
                }

                if (!empty($hasColumn['status'])) {
                    $insertCols[] = 'status';
                    $placeholders[] = '?';
                    $insertVals[] = $status;
                }

                if (!empty($hasColumn['image'])) {
                    $insertCols[] = 'image';
                    $placeholders[] = '?';
                    $insertVals[] = $image;
                }

                if (empty($insertCols)) {
                    throw new Exception('No writable columns found for rentals table');
                }

                $sql = "INSERT INTO rentals (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $placeholders) . ")";
                $stmt = $conn->prepare($sql);
                $success = $stmt->execute($insertVals);

                if ($success) {
                    header('Location: rentals.php');
                    exit;
                } else {
                    $errors[] = "Failed to add rental";
                }
            } catch (PDOException $e) {
                $errors[] = "Database error: " . $e->getMessage();
            } catch (Exception $e) {
                $errors[] = "Error: " . $e->getMessage();
            }
        }
    }
}

$page_title = "Add New Rental";
require_once 'includes/header.php';
?>

<div class="container px-6 mx-auto grid">
    <h2 class="my-6 text-2xl font-semibold text-gray-700">
        Add New Rental
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
        <form action="add_rental.php" method="POST" enctype="multipart/form-data">
            <div class="grid gap-6 mb-4 md:grid-cols-2">
                <div>
                    <label class="block text-sm">
                        <span class="text-gray-700">Name</span>
                        <input type="text" name="name" required
                            class="block w-full mt-1 text-sm rounded-lg border-gray-300 focus:border-purple-400 focus:outline-none focus:shadow-outline-purple"
                            value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>">
                    </label>
                </div>

                <div>
                    <label class="block text-sm">
                        <span class="text-gray-700">Category</span>
                        <select name="category_id" required
                            class="block w-full mt-1 text-sm rounded-lg border-gray-300 focus:border-purple-400 focus:outline-none focus:shadow-outline-purple">
                            <option value="">Select a category</option>
                            <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category['id']; ?>" 
                                <?php echo (isset($_POST['category_id']) && $_POST['category_id'] == $category['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($category['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>

                <div>
                    <label class="block text-sm">
                        <span class="text-gray-700">Daily Rate ($)</span>
                        <input type="number" name="daily_rate" step="0.01" min="0.01" required
                               oninput="this.value = this.value <= 0 ? Math.abs(this.value) : this.value"
                               onchange="this.value = this.value <= 0 ? 0.01 : this.value"
                            class="block w-full mt-1 text-sm rounded-lg border-gray-300 focus:border-purple-400 focus:outline-none focus:shadow-outline-purple"
                            value="<?php echo isset($_POST['daily_rate']) ? htmlspecialchars($_POST['daily_rate']) : ''; ?>">
                    </label>
                </div>

                <div>
                    <label class="block text-sm">
                        <span class="text-gray-700">Status</span>
                        <select name="status" required
                            class="block w-full mt-1 text-sm rounded-lg border-gray-300 focus:border-purple-400 focus:outline-none focus:shadow-outline-purple">
                            <option value="available" <?php echo (isset($_POST['status']) && $_POST['status'] == 'available') ? 'selected' : ''; ?>>Available</option>
                            <option value="maintenance" <?php echo (isset($_POST['status']) && $_POST['status'] == 'maintenance') ? 'selected' : ''; ?>>Maintenance</option>
                        </select>
                    </label>
                </div>
            </div>

            <div class="mb-4">
                <label class="block text-sm">
                    <span class="text-gray-700">Description</span>
                    <textarea name="description" rows="4"
                        class="block w-full mt-1 text-sm rounded-lg border-gray-300 focus:border-purple-400 focus:outline-none focus:shadow-outline-purple"
                    ><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
                </label>
            </div>

            <div class="mb-4">
                <label class="block text-sm">
                    <span class="text-gray-700">Image</span>
                    <input type="file" name="image" accept="image/*"
                        class="block w-full mt-1 text-sm">
                </label>
            </div>

            <div class="flex justify-end mt-6">
                <button type="submit" 
                    class="px-4 py-2 text-sm font-medium leading-5 text-white transition-colors duration-150 bg-purple-600 border border-transparent rounded-lg hover:bg-purple-700 focus:outline-none focus:shadow-outline-purple">
                    Add Rental
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

<?php
function handleImageUpload($file) {
    $target_dir = "uploads/";
    $thumbs_dir = "uploads/thumbs/";
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
    

    if (!is_dir($target_dir)) @mkdir($target_dir, 0755, true);
    if (!is_dir($thumbs_dir)) @mkdir($thumbs_dir, 0755, true);

    if (move_uploaded_file($file["tmp_name"], $target_file)) {

        createThumbnail($target_file, $thumbs_dir . $filename, 100);
        return ['success' => true, 'filename' => $filename];
    } else {
        return ['success' => false, 'error' => "Failed to upload file."];
    }
}

function createThumbnail($source_path, $thumb_path, $thumb_width) {
    $extension = strtolower(pathinfo($source_path, PATHINFO_EXTENSION));


    $thumb_dir = dirname($thumb_path);
    if (!is_dir($thumb_dir)) @mkdir($thumb_dir, 0755, true);

    $gd_available = (
        ($extension === 'jpg' || $extension === 'jpeg') ? function_exists('imagecreatefromjpeg') : true
    ) && (
        ($extension === 'png') ? function_exists('imagecreatefrompng') : true
    );

    $gd_any = function_exists('imagecreatetruecolor') && (function_exists('imagecreatefromjpeg') || function_exists('imagecreatefrompng') || function_exists('imagecreatefromgif'));

    if ($gd_any) {
        $source_image = null;
        if ($extension == 'jpg' || $extension == 'jpeg') {
            $source_image = @imagecreatefromjpeg($source_path);
        } elseif ($extension == 'png') {
            $source_image = @imagecreatefrompng($source_path);
        } elseif ($extension == 'gif') {
            $source_image = @imagecreatefromgif($source_path);
        }

        if ($source_image !== false && $source_image !== null) {
            $width = imagesx($source_image);
            $height = imagesy($source_image);

           
            $thumb_height = floor($height * ($thumb_width / $width));

            $thumb_image = imagecreatetruecolor($thumb_width, $thumb_height);

            if ($extension == 'png' || $extension == 'gif') {
                imagealphablending($thumb_image, false);
                imagesavealpha($thumb_image, true);
                $transparent = imagecolorallocatealpha($thumb_image, 255, 255, 255, 127);
                imagefilledrectangle($thumb_image, 0, 0, $thumb_width, $thumb_height, $transparent);
            }

            // Resize
            imagecopyresampled($thumb_image, $source_image, 0, 0, 0, 0, $thumb_width, $thumb_height, $width, $height);

            if ($extension == 'jpg' || $extension == 'jpeg') {
                imagejpeg($thumb_image, $thumb_path, 90);
            } elseif ($extension == 'png') {
                imagepng($thumb_image, $thumb_path);
            } elseif ($extension == 'gif') {
                imagegif($thumb_image, $thumb_path);
            }

            // Clean up
            @imagedestroy($source_image);
            @imagedestroy($thumb_image);
            return true;
        }
    }

    if (class_exists('Imagick')) {
        try {
            $im = new Imagick($source_path);
            $im->setImageOrientation(0);
            $im->thumbnailImage($thumb_width, 0);

            $im->writeImage($thumb_path);
            $im->clear();
            $im->destroy();
            return true;
        } catch (Exception $e) {
        }
    }

    if (@copy($source_path, $thumb_path)) {
        return true;
    }

    return false;
}
?>
