<?php
require_once 'includes/auth.php';
require_once '../db_connect.php';

// Handle category create/edit/delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'create') {
            $name = $_POST['name'] ?? '';
            $description = $_POST['description'] ?? '';
            
            if (!empty($name)) {
                $stmt = $conn->prepare("INSERT INTO categories (name, description) VALUES (?, ?)");
                $stmt->execute([$name, $description]);
            }
        }
        elseif ($_POST['action'] === 'edit') {
            $id = $_POST['id'] ?? '';
            $name = $_POST['name'] ?? '';
            $description = $_POST['description'] ?? '';
            
            if (!empty($id) && !empty($name)) {
                $stmt = $conn->prepare("UPDATE categories SET name = ?, description = ? WHERE id = ?");
                $stmt->execute([$name, $description, $id]);
            }
        }
        elseif ($_POST['action'] === 'delete') {
            $id = $_POST['id'] ?? '';

            if (!empty($id)) {
                // Make deletion robust: if rentals reference this category, try to reassign them
                try {
                    $conn->beginTransaction();

                    // Count rentals referencing this category
                    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM rentals WHERE category_id = ?");
                    $stmt->execute([$id]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $rentalCount = intval($result['count'] ?? 0);

                    if ($rentalCount > 0) {
                        // First try to set category_id = NULL (if column allows NULL)
                        $updated = false;
                        try {
                            $u = $conn->prepare("UPDATE rentals SET category_id = NULL WHERE category_id = ?");
                            $u->execute([$id]);
                            $updated = true;
                        } catch (Exception $e) {
                            // Could not set NULL (column may be NOT NULL or other constraint). We'll try a fallback.
                            $updated = false;
                        }

                        if (!$updated) {
                            // Find a fallback category to reassign rentals to (first category that's not the one being deleted)
                            $fallbackStmt = $conn->prepare("SELECT id FROM categories WHERE id != ? LIMIT 1");
                            $fallbackStmt->execute([$id]);
                            $fb = $fallbackStmt->fetch(PDO::FETCH_ASSOC);
                            if ($fb && !empty($fb['id'])) {
                                $fallbackId = intval($fb['id']);
                                $u2 = $conn->prepare("UPDATE rentals SET category_id = ? WHERE category_id = ?");
                                $u2->execute([$fallbackId, $id]);
                                $updated = true;
                            }
                        }

                        // If we couldn't reassign rentals (no fallback and cannot set NULL), abort
                        if (!$updated) {
                            $conn->rollBack();
                            header('Location: categories.php?msg=delete_blocked');
                            exit;
                        }
                    }

                    // Now safe to delete category
                    $d = $conn->prepare("DELETE FROM categories WHERE id = ?");
                    $d->execute([$id]);

                    $conn->commit();

                    // Redirect with message indicating what happened
                    if ($rentalCount > 0) {
                        header('Location: categories.php?msg=deleted_reassigned');
                    } else {
                        header('Location: categories.php?msg=deleted');
                    }
                    exit;
                } catch (Exception $e) {
                    if ($conn->inTransaction()) $conn->rollBack();
                    // Fallback: redirect with an error flag so admin can see why
                    header('Location: categories.php?msg=delete_error');
                    exit;
                }
            }
        }
        
        // Redirect to prevent form resubmission
        header('Location: categories.php');
        exit;
    }
}

// Get all categories
$stmt = $conn->query("SELECT * FROM categories ORDER BY name");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Categories";
require_once 'includes/header.php';

// Show optional status message after actions
if (isset($_GET['msg'])) {
    $m = $_GET['msg'];
    $alert = '';
    if ($m === 'deleted') {
        $alert = 'Category deleted successfully.';
    } elseif ($m === 'deleted_reassigned') {
        $alert = 'Category deleted and its rentals were reassigned.';
    } elseif ($m === 'delete_blocked') {
        $alert = 'Delete blocked: rentals reference this category and could not be reassigned. Reassign or remove rentals first.';
    } else {
        $alert = 'An error occurred while deleting the category.';
    }
    echo '<div class="max-w-4xl mx-auto my-4"><div class="p-3 rounded bg-yellow-50 border border-yellow-200 text-sm">' . htmlspecialchars($alert) . '</div></div>';
}
?>

<div class="container px-6 mx-auto grid">
    <div class="flex justify-between items-center my-6">
        <h2 class="text-2xl font-semibold text-gray-700">Manage Categories</h2>
        <button onclick="openCreateModal()"
                class="px-4 py-2 text-sm font-medium leading-5 text-white transition-colors duration-150 bg-purple-600 border border-transparent rounded-lg hover:bg-purple-700">
            Add Category
        </button>
    </div>

    <!-- Categories Table -->
    <div class="w-full overflow-hidden rounded-lg shadow-xs">
        <div class="w-full overflow-x-auto">
            <table class="w-full whitespace-no-wrap">
                <thead>
                    <tr class="text-xs font-semibold tracking-wide text-left text-gray-500 uppercase border-b bg-gray-50">
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3">Description</th>
                        <th class="px-4 py-3">Created</th>
                        <th class="px-4 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y">
                    <?php foreach ($categories as $category): ?>
                    <tr class="text-gray-700">
                        <td class="px-4 py-3">
                            <div class="flex items-center text-sm">
                                <div>
                                    <p class="font-semibold"><?php echo htmlspecialchars($category['name']); ?></p>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <?php echo htmlspecialchars($category['description'] ?? ''); ?>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <?php echo date('M j, Y', strtotime($category['created_at'])); ?>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center space-x-4 text-sm">
                                <button onclick='openEditModal(<?php echo json_encode($category); ?>)'
                                        class="flex items-center justify-between px-2 py-2 text-sm font-medium leading-5 text-purple-600 rounded-lg hover:bg-gray-100">
                                    <svg class="w-5 h-5" aria-hidden="true" fill="currentColor" viewBox="0 0 20 20">
                                        <path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"></path>
                                    </svg>
                                </button>
                                <button onclick="deleteCategory(<?php echo $category['id']; ?>, '<?php echo htmlspecialchars($category['name']); ?>')"
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

<!-- Create Modal -->
<div id="createModal" class="fixed inset-0 z-30 hidden items-center justify-center overflow-auto bg-black bg-opacity-50">
    <div class="relative px-6 py-4 w-full max-w-md mx-auto bg-white rounded-lg shadow-lg">
        <h3 class="text-lg font-semibold text-gray-700 mb-4">Add New Category</h3>
        <form action="categories.php" method="POST">
            <input type="hidden" name="action" value="create">
            
            <label class="block text-sm mb-4">
                <span class="text-gray-700">Name</span>
                <input type="text" name="name" required
                    class="block w-full mt-1 text-sm rounded-lg border-gray-300 focus:border-purple-400 focus:outline-none focus:shadow-outline-purple">
            </label>
            
            <label class="block text-sm mb-4">
                <span class="text-gray-700">Description</span>
                <textarea name="description" rows="3"
                    class="block w-full mt-1 text-sm rounded-lg border-gray-300 focus:border-purple-400 focus:outline-none focus:shadow-outline-purple"></textarea>
            </label>
            
            <div class="flex justify-end mt-6">
                <button type="button" onclick="closeModals()"
                    class="px-4 py-2 text-sm font-medium text-gray-600 border border-gray-300 rounded-lg mr-2 hover:bg-gray-100">
                    Cancel
                </button>
                <button type="submit"
                    class="px-4 py-2 text-sm font-medium leading-5 text-white transition-colors duration-150 bg-purple-600 border border-transparent rounded-lg hover:bg-purple-700">
                    Create Category
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="fixed inset-0 z-30 hidden items-center justify-center overflow-auto bg-black bg-opacity-50">
    <div class="relative px-6 py-4 w-full max-w-md mx-auto bg-white rounded-lg shadow-lg">
        <h3 class="text-lg font-semibold text-gray-700 mb-4">Edit Category</h3>
        <form action="categories.php" method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="editId">
            
            <label class="block text-sm mb-4">
                <span class="text-gray-700">Name</span>
                <input type="text" name="name" id="editName" required
                    class="block w-full mt-1 text-sm rounded-lg border-gray-300 focus:border-purple-400 focus:outline-none focus:shadow-outline-purple">
            </label>
            
            <label class="block text-sm mb-4">
                <span class="text-gray-700">Description</span>
                <textarea name="description" id="editDescription" rows="3"
                    class="block w-full mt-1 text-sm rounded-lg border-gray-300 focus:border-purple-400 focus:outline-none focus:shadow-outline-purple"></textarea>
            </label>
            
            <div class="flex justify-end mt-6">
                <button type="button" onclick="closeModals()"
                    class="px-4 py-2 text-sm font-medium text-gray-600 border border-gray-300 rounded-lg mr-2 hover:bg-gray-100">
                    Cancel
                </button>
                <button type="submit"
                    class="px-4 py-2 text-sm font-medium leading-5 text-white transition-colors duration-150 bg-purple-600 border border-transparent rounded-lg hover:bg-purple-700">
                    Update Category
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Form -->
<form id="deleteForm" action="categories.php" method="POST" class="hidden">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="deleteId">
</form>

<script>
function openCreateModal() {
    document.getElementById('createModal').classList.remove('hidden');
    document.getElementById('createModal').classList.add('flex');
}

function openEditModal(category) {
    document.getElementById('editId').value = category.id;
    document.getElementById('editName').value = category.name;
    document.getElementById('editDescription').value = category.description || '';
    
    document.getElementById('editModal').classList.remove('hidden');
    document.getElementById('editModal').classList.add('flex');
}

function closeModals() {
    document.getElementById('createModal').classList.add('hidden');
    document.getElementById('createModal').classList.remove('flex');
    document.getElementById('editModal').classList.add('hidden');
    document.getElementById('editModal').classList.remove('flex');
}

function deleteCategory(id, name) {
    if (confirm(`Are you sure you want to delete the category "${name}"?\nThis action cannot be undone if the category has no rentals.`)) {
        document.getElementById('deleteId').value = id;
        document.getElementById('deleteForm').submit();
    }
}

// Close modals when clicking outside
window.onclick = function(event) {
    if (event.target === document.getElementById('createModal')) {
        closeModals();
    }
    if (event.target === document.getElementById('editModal')) {
        closeModals();
    }
}
</script>

<?php require_once 'includes/footer.php'; ?>
