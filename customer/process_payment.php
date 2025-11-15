<?php
require_once __DIR__ . '/../db_connect.php';
$db = db_get_conn();

session_start();
if (!isset($_SESSION['customer_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: cart.php');
    exit;
}

// Get order data
$order_data = json_decode($_POST['order_data'], true);
if (!$order_data || empty($order_data['items'])) {
    header('Location: cart.php');
    exit;
}

try {
    // Start transaction
    $db->begin_transaction();
    
    // Debug logging
    $debug_log = fopen(__DIR__ . '/order_debug.log', 'a');
    fwrite($debug_log, "\n=== " . date('Y-m-d H:i:s') . " - New Order Processing ===\n");
    fwrite($debug_log, "Session customer_id: " . $_SESSION['customer_id'] . "\n");
    
    // Check if users table exists, if not create it
    $tableCheck = $db->query("SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users'");
    $tableExists = $tableCheck && ($row = $tableCheck->fetch_assoc()) && $row['cnt'] > 0;
    
    if (!$tableExists) {
        fwrite($debug_log, "Creating users table...\n");
        $db->query("CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id INT NULL,
            email VARCHAR(255),
            type VARCHAR(50) DEFAULT 'customer',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_customer_id (customer_id)
        )");
    } else {
        // Check if customer_id and type columns exist; add them if missing
        $colRes = $db->query("SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND COLUMN_NAME IN ('customer_id','type')");
        $existing = [];
        if ($colRes) {
            while ($c = $colRes->fetch_assoc()) {
                $existing[$c['COLUMN_NAME']] = true;
            }
        }

        if (empty($existing['customer_id'])) {
            fwrite($debug_log, "Adding customer_id column to users table...\n");
            $db->query("ALTER TABLE users ADD COLUMN customer_id INT NULL AFTER id, ADD UNIQUE KEY unique_customer_id (customer_id)");
        }

        if (empty($existing['type'])) {
            fwrite($debug_log, "Adding type column to users table...\n");
            $db->query("ALTER TABLE users ADD COLUMN type VARCHAR(50) DEFAULT 'customer' AFTER email");
        }
    }

    // Verify customer exists
    fwrite($debug_log, "Verifying customer record...\n");
    $customer = $db->prepare("SELECT * FROM customers WHERE id = ? LIMIT 1");
    $customer->bind_param('i', $_SESSION['customer_id']);
    $customer->execute();
    $cust_data = $customer->get_result()->fetch_assoc();
    
    if (!$cust_data) {
        fwrite($debug_log, "ERROR: Customer not found!\n");
        throw new Exception("Invalid customer account");
    }

        // Check for name column in users table
        // Legacy user-insert logic removed: user creation and mapping are handled
        // later in this script with a safer dynamic insert that does not
        // attempt to write the primary `id` column (avoids duplicate PK errors).

    // Ensure orders.order_date exists (capture exact datetime of order)
    $colCheck = $db->query("SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'order_date'");
    $colRow = $colCheck ? $colCheck->fetch_assoc() : null;
    if (empty($colRow['cnt'])) {
        // Try to add the column; if the server version doesn't support IF NOT EXISTS, this may throw and we'll ignore
        try {
            $db->query("ALTER TABLE orders ADD COLUMN order_date DATETIME DEFAULT CURRENT_TIMESTAMP");
        } catch (Exception $ex) {
            // ignore - will rely on DB defaults or manual migration
        }
    }

    // Create order - build INSERT dynamically depending on orders table schema
    $colRes = $db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME IN ('user_id','customer_id','customer_email','customer_name','total_amount','status','created_at','order_date')");
    $orderCols = [];
    if ($colRes) {
        while ($r = $colRes->fetch_assoc()) {
            $orderCols[$r['COLUMN_NAME']] = true;
        }
    }

    $fields = [];
    $placeholders = [];
    $types = '';
    $values = [];

    // Get or create user_id for the customer
    $userId = null;
    
    // Try to find existing user
    $customerCheck = $db->prepare("SELECT id FROM users WHERE customer_id = ?");
    $customerCheck->bind_param('i', $_SESSION['customer_id']);
    $customerCheck->execute();
    $result = $customerCheck->get_result();
    
    if ($user = $result->fetch_assoc()) {
        $userId = $user['id'];
    }
    
    if (!$userId) {
        // Create user record for this customer
        $customer = $db->prepare("SELECT name, email FROM customers WHERE id = ?");
        $customer->bind_param('i', $_SESSION['customer_id']);
        $customer->execute();
        $customerData = $customer->get_result()->fetch_assoc();
        
        if ($customerData) {
            // First try to find an existing user mapping by customer_id
            fwrite($debug_log, "Looking for existing user by customer_id...\n");
            $userId = null;

            $chk = $db->prepare("SELECT id, customer_id FROM users WHERE customer_id = ? LIMIT 1");
            if ($chk) {
                $chk->bind_param('i', $_SESSION['customer_id']);
                $chk->execute();
                $cres = $chk->get_result();
                if ($cres && ($row = $cres->fetch_assoc())) {
                    $userId = $row['id'];
                    fwrite($debug_log, "Found user by customer_id: " . $userId . "\n");
                }
            }

            // If not found by customer_id, try by email
            if (!$userId && !empty($customerData['email'])) {
                fwrite($debug_log, "Looking for existing user by email...\n");
                $chk2 = $db->prepare("SELECT id, customer_id FROM users WHERE email = ? LIMIT 1");
                if ($chk2) {
                    $chk2->bind_param('s', $customerData['email']);
                    $chk2->execute();
                    $cres2 = $chk2->get_result();
                    if ($cres2 && ($row2 = $cres2->fetch_assoc())) {
                        $userId = $row2['id'];
                        fwrite($debug_log, "Found user by email: " . $userId . "\n");
                        // If user exists but customer_id is null, update it
                        if (empty($row2['customer_id'])) {
                            $upd = $db->prepare("UPDATE users SET customer_id = ? WHERE id = ?");
                            if ($upd) {
                                $upd->bind_param('ii', $_SESSION['customer_id'], $userId);
                                $upd->execute();
                                fwrite($debug_log, "Updated user $userId with customer_id " . $_SESSION['customer_id'] . "\n");
                            }
                        }
                    }
                }
            }

            // If still not found, insert a new user record
            if (!$userId) {
                fwrite($debug_log, "No existing user found; inserting new user...\n");
                $insertFields = ['customer_id', 'email', 'type'];
                $insertValues = ['?', '?', '?'];
                $insertTypes = 'iss';
                $insertParams = [
                    $_SESSION['customer_id'],
                    $customerData['email'],
                    'customer'
                ];

                $sql = "INSERT INTO users (" . implode(', ', $insertFields) . ") VALUES (" . implode(', ', $insertValues) . ")";
                $createUser = $db->prepare($sql);

                if ($createUser && !empty($insertParams)) {
                    // Ensure users.id is AUTO_INCREMENT to avoid duplicate primary key errors
                    $colInfoRes = $db->query("SELECT EXTRA FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'id'");
                    if ($colInfoRes) {
                        $colInfo = $colInfoRes->fetch_assoc();
                        $extra = $colInfo['EXTRA'] ?? '';
                        if (stripos($extra, 'auto_increment') === false) {
                            // determine next auto-increment value
                            $mxRes = $db->query("SELECT MAX(id) AS mx FROM users");
                            $mx = 0;
                            if ($mxRes && ($r = $mxRes->fetch_assoc())) $mx = intval($r['mx']);
                            $next = $mx + 1;
                            // modify the id column to be AUTO_INCREMENT and set next value
                            $db->query("ALTER TABLE users MODIFY id INT NOT NULL AUTO_INCREMENT PRIMARY KEY");
                            $db->query("ALTER TABLE users AUTO_INCREMENT = " . intval($next));
                        }
                    }

                    $createUser->bind_param($insertTypes, ...$insertParams);
                    $createUser->execute();
                    // handle race condition: if duplicate email occurred, fetch the existing id
                    if ($createUser->errno) {
                        fwrite($debug_log, "User insert failed: " . $createUser->error . "\n");
                        // If duplicate email, fetch existing user id
                        if (stripos($createUser->error, 'Duplicate entry') !== false && !empty($customerData['email'])) {
                            $retry = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                            if ($retry) {
                                $retry->bind_param('s', $customerData['email']);
                                $retry->execute();
                                $rres = $retry->get_result();
                                if ($rres && ($rrow = $rres->fetch_assoc())) {
                                    $userId = $rrow['id'];
                                    fwrite($debug_log, "Recovered userId after race: " . $userId . "\n");
                                }
                            }
                        }
                    } else {
                        $userId = $db->insert_id;
                        fwrite($debug_log, "Inserted new user id: " . $userId . "\n");
                    }
                }
            }
        }
    }
    
    // Only require user ID if the orders table has user_id column
    if (!empty($orderCols['user_id']) && !$userId) {
        throw new Exception("Could not process user account");
    }

    // Add user_id to order
    $fields[] = 'user_id';
    $placeholders[] = '?';
    $types .= 'i';
    $values[] = $userId;
    
    // If no customer_id column, try customer_email
    if (empty($orderCols['customer_id'])) {
        // try customer_email
        $customer_email = $_SESSION['customer_email'] ?? null;
        if (!$customer_email && !empty($_SESSION['customer_id'])) {
            // fetch email from customers table
            $cstmt = $db->prepare("SELECT email FROM customers WHERE id = ? LIMIT 1");
            if ($cstmt) {
                $cstmt->bind_param('i', $_SESSION['customer_id']);
                $cstmt->execute();
                $cres = $cstmt->get_result()->fetch_assoc();
                if ($cres) $customer_email = $cres['email'];
            }
        }
        if (!empty($orderCols['customer_email']) && $customer_email) {
            $fields[] = 'customer_email'; $placeholders[] = '?'; $types .= 's'; $values[] = $customer_email;
        }
        // also include customer_name if available
        $customer_name = $_SESSION['customer_name'] ?? null;
        if (!empty($orderCols['customer_name']) && $customer_name) {
            $fields[] = 'customer_name'; $placeholders[] = '?'; $types .= 's'; $values[] = $customer_name;
        }
    }

    // total_amount is required by business logic; prefer inserting if column exists
    if (!empty($orderCols['total_amount'])) {
        $fields[] = 'total_amount'; $placeholders[] = '?'; $types .= 'd'; $values[] = floatval($order_data['total']);
    } else {
        // fallback: try inserting into a generic amount column if present (rare)
        // if not present, still proceed without total_amount
    }

    // status default
    if (!empty($orderCols['status'])) {
        $fields[] = 'status'; $placeholders[] = '?'; $types .= 's'; $values[] = 'pending';
    }

    // If orders table has order_date, include current datetime (so we have precise order timestamp)
    if (!empty($orderCols['order_date'])) {
        $fields[] = 'order_date'; $placeholders[] = '?'; $types .= 's'; $values[] = date('Y-m-d H:i:s');
    }

    if (empty($fields)) {
        throw new Exception('Orders table has no writable customer/amount columns');
    }

    $sql = "INSERT INTO orders (" . implode(',', $fields) . ") VALUES (" . implode(',', $placeholders) . ")";
    fwrite($debug_log, "Order INSERT SQL: " . $sql . "\n");
    fwrite($debug_log, "Fields: " . implode(', ', $fields) . "\n");
    fwrite($debug_log, "Values: " . implode(', ', array_map('strval', $values)) . "\n");
    $stmt = $db->prepare($sql);
    if (!$stmt) throw new Exception('Prepare failed: ' . $db->error);

    // bind_param requires references
    $bind_names[] = $types;
    for ($i=0; $i<count($values); $i++) {
        $bind_names[] = &$values[$i];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind_names);
    $stmt->execute();
    if ($stmt->errno) throw new Exception('Insert order failed: ' . $stmt->error);
    $order_id = $db->insert_id;
    fwrite($debug_log, "New order ID: " . $order_id . "\n");
    
    // Create order items
    $stmt = $db->prepare("
        INSERT INTO order_items (order_id, rental_id, days, daily_rate, total_amount)
        VALUES (?, ?, ?, ?, ?)
    ");
    
    foreach ($order_data['items'] as $item) {
        $stmt->bind_param('iiidd', 
            $order_id,
            $item['rental_id'],
            $item['days'],
            $item['price_per_day'],
            $item['total']
        );
        $stmt->execute();
        
    // Update rental status to indicate it's now rented
    $db->query("UPDATE rentals SET status = 'rented' WHERE id = " . intval($item['rental_id']));
    }
    
    $db->commit();
    fwrite($debug_log, "Transaction committed successfully\n");
    
    // Mark that client-side cart should be cleared after redirect
    // (cart is stored in localStorage in the client). We pass a flag and also set a session
    $_SESSION['clear_cart'] = 1;

    // Write final success message
    fwrite($debug_log, "Order processing completed successfully\n");
    
    // Close debug log once at the end
    if (isset($debug_log)) {
        fclose($debug_log);
        $debug_log = null; // Ensure we can't use it again
    }

    // Redirect to order summary with a flag instructing the client to clear its cart
    header("Location: order_summary.php?id=$order_id&clear_cart=1");
    exit;
    
} catch (Exception $e) {
    // Rollback the transaction on any error
    $db->rollback();
    
    if (isset($debug_log)) {
        fwrite($debug_log, "Error occurred: " . $e->getMessage() . "\n");
        fwrite($debug_log, "Transaction rolled back\n");
        fclose($debug_log);
        $debug_log = null;
    }
    
    die("Error processing order: " . $e->getMessage());
}
