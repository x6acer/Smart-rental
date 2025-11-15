<?php
require_once 'includes/auth.php';
require_once '../db_connect.php';

// Handle AJAX delete requests sent to this same file
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!empty($_POST['action']) && $_POST['action'] === 'delete')) {
    header('Content-Type: application/json');
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    if (!$id) {
        echo json_encode(['success' => false, 'error' => 'Invalid ID']);
        exit;
    }

    try {
        // First ensure is_deleted column exists
        $conn->exec("ALTER TABLE rentals ADD COLUMN IF NOT EXISTS is_deleted TINYINT(1) NOT NULL DEFAULT 0");
        
        // Soft delete by setting is_deleted=1 and status='deleted' if those columns exist
        $updates = ["is_deleted = 1"];
        
        // Check if status column exists and add it to updates if it does
        $chkStatus = $conn->prepare("SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rentals' AND COLUMN_NAME = 'status'");
        $chkStatus->execute();
        $hasStatus = !empty($chkStatus->fetch(PDO::FETCH_ASSOC)['cnt']);
        if ($hasStatus) {
            $updates[] = "status = 'deleted'";
        }
        
        // Perform soft delete
        $upd = $conn->prepare("UPDATE rentals SET " . implode(', ', $updates) . " WHERE id = :id AND (is_deleted = 0 OR is_deleted IS NULL)");
        $upd->execute([':id' => $id]);
        $affected = $upd->rowCount();
        
        if ($affected > 0) {
            echo json_encode(['success' => true, 'affected' => $affected]);
        } else {
            // No rows updated - check if row exists and get its current state
            $fields = ['id', 'is_deleted'];
            if ($hasStatus) $fields[] = 'status';
            $check = $conn->prepare("SELECT " . implode(', ', $fields) . " FROM rentals WHERE id = :id LIMIT 1");
            $check->execute([':id' => $id]);
            $row = $check->fetch(PDO::FETCH_ASSOC);
            
            if (!$row) {
                echo json_encode(['success' => false, 'error' => 'Rental not found', 'row' => null]);
            } else if ($row['is_deleted'] == 1) {
                echo json_encode(['success' => false, 'error' => 'Rental is already deleted', 'row' => $row]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to update rental status', 'row' => $row]);
            }
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}




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
// Check for required ID and name/title columns
if (empty($hasColumn['id'])) {
    die("Error: Required column 'id' is missing from rentals table");
}

if (empty($hasColumn['name']) && empty($hasColumn['title'])) {
    die("Error: Neither 'name' nor 'title' column found in rentals table");
}

// Determine which name field to use
$nameField = !empty($hasColumn['name']) ? 'name' : 'title';
$rateField = !empty($hasColumn['daily_rate']) ? 'daily_rate' : 'price_per_day';

$hasIsDeleted = !empty($hasColumn['is_deleted']);
$hasStatus = !empty($hasColumn['status']);
$hasCreatedAt = !empty($hasColumn['created_at']);

// Build query selecting existing columns and aliasing fields for consistency
$sql = "SELECT r.id, r.{$nameField} as name";

if (!empty($hasColumn['category_id'])) {
    $sql .= ", r.category_id, c.name as category_name";
}
if (!empty($hasColumn['description'])) {
    $sql .= ", r.description";
}
if (!empty($hasColumn['image'])) {
    $sql .= ", r.image";
}
if (!empty($hasColumn[$rateField])) {
    $sql .= ", r.{$rateField} as daily_rate";
}
if (!empty($hasColumn['status'])) {
    $sql .= ", r.status";
}
if ($hasIsDeleted) {
    $sql .= ", r.is_deleted";
}
if (!empty($hasColumn['created_at'])) {
    $sql .= ", r.created_at";
}

$sql .= " FROM rentals r";

if (!empty($hasColumn['category_id'])) {
    $sql .= " LEFT JOIN categories c ON r.category_id = c.id";
}

// Apply filtering: only use columns that exist
$where = [];
if ($hasIsDeleted) {
    $where[] = "(r.is_deleted = 0 OR r.is_deleted IS NULL)";  // Show non-deleted items
}
if ($hasStatus) {
    $where[] = "(r.status IS NULL OR r.status = '' OR LOWER(r.status) != 'deleted')";
}

// Only add WHERE clause if we have conditions
if (!empty($where)) {
    $sql .= " WHERE " . implode(' AND ', $where);
}

$sql .= " ORDER BY " . ($hasCreatedAt ? 'r.created_at DESC' : 'r.id DESC');

$stmt = $conn->prepare($sql);
$stmt->execute();
$rentals = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Manage Rentals";
require_once 'includes/header.php';
?>

<div class="container px-6 mx-auto grid">
    <div class="flex justify-between items-center my-6">
        <h2 class="text-2xl font-semibold text-gray-700">Manage Rentals</h2>
        <a href="add_rental.php" 
           class="px-4 py-2 text-sm font-medium leading-5 text-white transition-colors duration-150 bg-purple-600 border border-transparent rounded-lg hover:bg-purple-700">
            Add New Rental
        </a>
    </div>

    <!-- Rentals Table -->
    <div class="w-full overflow-hidden rounded-lg shadow-xs">
        <div class="w-full overflow-x-auto">
            <table class="w-full whitespace-no-wrap">
                <thead>
                    <tr class="text-xs font-semibold tracking-wide text-left text-gray-500 uppercase border-b bg-gray-50">
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3">Category</th>
                        <th class="px-4 py-3">Daily Rate</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y">
                    <?php foreach ($rentals as $rental): ?>
                    <tr class="text-gray-700" data-rental-id="<?php echo $rental['id']; ?>">
                        <td class="px-4 py-3">
                            <div class="flex items-center text-sm">
                                <?php if (!empty($hasColumn['image']) && !empty($rental['image'])): ?>
                                <div class="relative hidden w-8 h-8 mr-3 rounded-full md:block">
                                    <img class="object-cover w-full h-full rounded-full" 
                                         src="uploads/thumbs/<?php echo htmlspecialchars($rental['image']); ?>" 
                                         alt="<?php echo htmlspecialchars($rental['name']); ?>" 
                                         loading="lazy">
                                </div>
                                <?php endif; ?>
                                <div>
                                    <p class="font-semibold"><?php echo htmlspecialchars($rental['name']); ?></p>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <?php 
                            if (!empty($hasColumn['category_id'])) {
                                echo htmlspecialchars($rental['category_name'] ?? 'Uncategorized');
                            } else {
                                echo 'N/A';
                            }
                            ?>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <?php 
                            if (!empty($hasColumn[$rateField])) {
                                echo format_money($rental['daily_rate'] ?? 0);
                            } else {
                                echo 'N/A';
                            }
                            ?>
                        </td>
                        <td class="px-4 py-3 text-xs">
                            <?php 
                            $status = 'available';
                            if ($hasIsDeleted && !empty($rental['is_deleted']) && $rental['is_deleted'] == 1) {
                                $status = 'deleted';
                            } elseif ($hasStatus && !empty($rental['status'])) {
                                $status = strtolower($rental['status']);
                            }
                            
                            // Map display text and colors for the three rental statuses
                            $statusColors = [
                                'available' => 'text-green-700 bg-green-100',  // Available vehicles (ready to rent)
                                'rented' => 'text-blue-700 bg-blue-100',      // Currently rented out
                                'maintenance' => 'text-yellow-700 bg-yellow-100', // In maintenance/unavailable
                                'default' => 'text-gray-700 bg-gray-100'      // Fallback for unknown status
                            ];
                            
                            $colorClass = $statusColors[$status] ?? $statusColors['default'];
                            ?>
                            <span class="px-2 py-1 font-semibold leading-tight rounded-full <?php echo $colorClass; ?>">
                                <?php echo ucfirst($status); ?>
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center space-x-4 text-sm">
                                <a href="edit_rental.php?id=<?php echo $rental['id']; ?>" 
                                   class="flex items-center justify-between px-2 py-2 text-sm font-medium leading-5 text-purple-600 rounded-lg hover:bg-gray-100">
                                    <svg class="w-5 h-5" aria-hidden="true" fill="currentColor" viewBox="0 0 20 20">
                                        <path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"></path>
                                    </svg>
                                </a>
                                <button onclick="deleteRental(<?php echo $rental['id']; ?>)" 
                                        class="flex items-center justify-between px-2 py-2 text-sm font-medium leading-5 text-red-600 rounded-lg hover:bg-gray-100">
                                    <svg class="w-5 h-5" aria-hidden="true" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                                    </svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function deleteRental(id) {
    if (!confirm('Are you sure you want to delete this rental?')) {
        return;
    }
    
    const row = document.querySelector(`tr[data-rental-id="${id}"]`);
    if (!row) {
        alert('Error: Could not find rental in the table');
        return;
    }
    
    // Show loading state
    row.style.opacity = '0.5';
    
    console.log('Sending delete request for rental ID:', id);
    fetch(window.location.pathname, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin',
        body: new URLSearchParams({ action: 'delete', id: id })
    })
    .then(response => {
        console.log('Response status:', response.status);
        return response.text().then(text => {
            console.log('Raw response:', text);
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('Failed to parse response:', text);
                throw new Error(`Invalid JSON response: ${text}`);
            }
        });
    })
    .then(data => {
        console.log('Parsed JSON response:', data);
        if (data.success) {
            // Disable any buttons in the row to prevent double-clicking
            row.querySelectorAll('button').forEach(btn => btn.disabled = true);
            
            // Add a class to handle the removal animation
            row.style.transition = 'all 0.3s ease-out';
            row.style.opacity = '0';
            row.style.maxHeight = '0';
            row.style.overflow = 'hidden';
            row.style.padding = '0';
            row.style.margin = '0';
            
            // Remove the row from DOM after animation
            setTimeout(() => {
                row.parentNode.removeChild(row);
            }, 300);
            
            // Show success message
            alert('Rental deleted successfully');
        } else {
            // Restore row appearance
            row.style.opacity = '1';
            console.error('Delete failed response:', data);

            // Compose a helpful message including any diagnostics the server returned
            let msg = data.error || data.message || 'Error deleting rental';
            if (data.affected !== undefined) {
                msg += '\nAffected rows: ' + data.affected;
            }
            if (data.row) {
                try {
                    msg += '\nRow: ' + JSON.stringify(data.row);
                } catch (e) {
                    msg += '\nRow: (failed to serialize)';
                }
            }

            // Show the composed message to the admin for debugging
            alert(msg);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        row.style.opacity = '1';
        alert('Network error while deleting rental: ' + (error.message || error));
    });
}
</script>

<?php require_once 'includes/footer.php'; ?>