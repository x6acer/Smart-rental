<?php
/*
SmartRental - Installation Script
-------------------------------

Setup Instructions:
1. Make sure XAMPP is installed and running (Apache & MySQL)
2. Create a database named 'smart_rental' in phpMyAdmin
3. Update db_connect.php with your database credentials if needed
4. Run this script by visiting: http://localhost/smart_rental/install.php
5. Delete this file after successful installation

Default Admin Credentials:
Email: admin@smartrental.com
Password: admin123
*/

// Run this script once to create tables and seed sample data
require_once __DIR__ . '/db_connect.php';

try {
    // Use the PDO connection for installation tasks (install.php uses PDO methods)
    $conn = pdo_get_conn();
    
    // Array to store all our create table queries
    $queries = [];

    // Categories table
    $queries[] = "CREATE TABLE IF NOT EXISTS categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    // Admins table
    $queries[] = "CREATE TABLE IF NOT EXISTS admins (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(255) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    // Rentals table with improved schema
    $queries[] = "CREATE TABLE IF NOT EXISTS rentals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        category_id INT,
        name VARCHAR(255) NOT NULL,
        description TEXT,
        daily_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        image VARCHAR(255),
        status ENUM('available','maintenance','rented','deleted') DEFAULT 'available',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    // Cart table
    $queries[] = "CREATE TABLE IF NOT EXISTS cart (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL,
        rental_id INT NOT NULL,
        quantity INT NOT NULL DEFAULT 1,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
        FOREIGN KEY (rental_id) REFERENCES rentals(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    // Customers table
    $queries[] = "CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    phone VARCHAR(20),
    address TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// Orders table
$queries[] = "CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status ENUM('pending','confirmed','active','completed','cancelled') DEFAULT 'pending',
    payment_status ENUM('unpaid','paid','refunded') DEFAULT 'unpaid',
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// Order items
$queries[] = "CREATE TABLE IF NOT EXISTS order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    rental_id INT NOT NULL,
    days INT NOT NULL DEFAULT 1,
    daily_rate DECIMAL(10,2) NOT NULL,
    total_amount DECIMAL(12,2) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (rental_id) REFERENCES rentals(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    // Execute all table creation queries
    foreach($queries as $query) {
        $conn->exec($query);
    }

    // Execute each alteration separately to ensure proper sequencing
    $alterSequence = [
        // Step 1: Check existing columns
        "SELECT GROUP_CONCAT(COLUMN_NAME) as columns 
         FROM INFORMATION_SCHEMA.COLUMNS 
         WHERE TABLE_SCHEMA = DATABASE() 
         AND TABLE_NAME = 'rentals'",

        // Step 2: Add basic columns to rentals
        "ALTER TABLE rentals 
         ADD COLUMN IF NOT EXISTS is_deleted TINYINT(1) NOT NULL DEFAULT 0,
         ADD COLUMN IF NOT EXISTS price_per_day DECIMAL(10,2) NOT NULL DEFAULT 0.00,
         ADD COLUMN IF NOT EXISTS seats INT NULL,
         ADD COLUMN IF NOT EXISTS transmission VARCHAR(50) NULL,
         ADD COLUMN IF NOT EXISTS fuel VARCHAR(50) NULL",

        // Step 3: Create the cart table
        "CREATE TABLE IF NOT EXISTS cart (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id INT NOT NULL,
            rental_id INT NOT NULL,
            quantity INT NOT NULL DEFAULT 1,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
            FOREIGN KEY (rental_id) REFERENCES rentals(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Step 4: Add columns to orders
        "ALTER TABLE orders 
         ADD COLUMN IF NOT EXISTS payment_status ENUM('unpaid','paid','refunded') DEFAULT 'unpaid',
         ADD COLUMN IF NOT EXISTS start_date DATE NULL,
         ADD COLUMN IF NOT EXISTS end_date DATE NULL,
         MODIFY COLUMN status ENUM('pending','confirmed','active','completed','cancelled') DEFAULT 'pending'",

        // Step 5: Add columns to order_items
        "ALTER TABLE order_items 
         ADD COLUMN IF NOT EXISTS start_date DATE NULL,
         ADD COLUMN IF NOT EXISTS end_date DATE NULL",

        // Step 6: Create indexes after all columns exist
        "CREATE INDEX IF NOT EXISTS idx_rental_status ON rentals(status, is_deleted)",
        "CREATE INDEX IF NOT EXISTS idx_order_dates ON orders(start_date, end_date, status)",
        "CREATE INDEX IF NOT EXISTS idx_cart_customer ON cart(customer_id, rental_id)",
        "CREATE INDEX IF NOT EXISTS idx_orderitems_dates ON order_items(rental_id, start_date, end_date)"
    ];

    $alterQueries = $alterSequence;

    // Create triggers for data consistency
    $triggerQueries = [
        // Drop existing triggers if any
        "DROP TRIGGER IF EXISTS before_rental_insert",
        "DROP TRIGGER IF EXISTS before_rental_update",
        
        // Trigger for new insertions
        "CREATE TRIGGER before_rental_insert BEFORE INSERT ON rentals
         FOR EACH ROW
         BEGIN
             IF NEW.price_per_day = 0 AND NEW.daily_rate > 0 THEN
                 SET NEW.price_per_day = NEW.daily_rate;
             ELSEIF NEW.daily_rate = 0 AND NEW.price_per_day > 0 THEN
                 SET NEW.daily_rate = NEW.price_per_day;
             ELSEIF NEW.price_per_day = 0 AND NEW.daily_rate = 0 THEN
                 SET NEW.price_per_day = 0, NEW.daily_rate = 0;
             END IF;
         END;",

        // Trigger for updates
        "CREATE TRIGGER before_rental_update BEFORE UPDATE ON rentals
         FOR EACH ROW
         BEGIN
             IF NEW.price_per_day != OLD.price_per_day THEN
                 SET NEW.daily_rate = NEW.price_per_day;
             ELSEIF NEW.daily_rate != OLD.daily_rate THEN
                 SET NEW.price_per_day = NEW.daily_rate;
             END IF;
         END;"
    ];

    // Execute alter queries - ignore errors since some might already exist
    // Execute alter queries for columns and indexes
    $hasColumns = [];
    
    foreach($alterQueries as $query) {
        try {
            echo "Executing: " . substr($query, 0, 50) . "...<br>";
            
            // Special handling for column check query
            if (strpos($query, 'INFORMATION_SCHEMA.COLUMNS') !== false) {
                $stmt = $conn->query($query);
                if ($stmt) {
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($row && $row['columns']) {
                        $columns = explode(',', $row['columns']);
                        $hasColumns = array_flip($columns); // Create associative array for easy checking
                        echo "Found existing columns: " . $row['columns'] . "<br>";
                    }
                }
                continue;
            }
            
            $conn->exec($query);
            
            // After adding columns, check if we need to sync prices
            if (strpos($query, 'ADD COLUMN') !== false && strpos($query, 'price_per_day') !== false) {
                if (isset($hasColumns['daily_rate'])) {
                    echo "Syncing prices from daily_rate to price_per_day...<br>";
                    $conn->exec("UPDATE rentals SET price_per_day = daily_rate WHERE price_per_day = 0 AND daily_rate > 0");
                }
            }
            
        } catch(PDOException $e) {
            $errorMessage = $e->getMessage();
            
            // List of errors we expect and can safely ignore
            $ignorableErrors = [
                'Duplicate', 
                'already exists',
                'Data truncated',
                'Duplicate column name',
                'Incorrect table definition' // For ENUM changes
            ];
            
            // Check if this is an ignorable error
            $canIgnore = false;
            foreach($ignorableErrors as $errorType) {
                if(strpos($errorMessage, $errorType) !== false) {
                    $canIgnore = true;
                    break;
                }
            }
            
            if($canIgnore) {
                echo "Notice: " . $errorMessage . " (continuing...)<br>";
                continue;
            }
            
            // If we get here, it's an error we need to handle
            echo "Error executing: " . $query . "<br>";
            echo "Error message: " . $errorMessage . "<br>";
            throw $e;
        }
    }

    // Only create triggers if we need to handle both price fields
    if (isset($hasColumns['daily_rate'])) {
        // Enable TRIGGERS
        $conn->exec("SET SQL_MODE='STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

        // Create triggers with DELIMITER handling
        foreach($triggerQueries as $query) {
            try {
                if (trim($query)) {  // Skip empty queries
                    echo "Creating trigger: " . substr($query, 0, 50) . "...<br>";
                    $conn->exec($query);
                }
            } catch(PDOException $e) {
                $errorMessage = $e->getMessage();
                // Only throw if it's not a "trigger already exists" error
                if(!strpos($errorMessage, 'Duplicate') && !strpos($errorMessage, 'already exists')) {
                    echo "Error creating trigger: " . $errorMessage . "<br>";
                    throw $e;
                }
                echo "Notice: " . $errorMessage . " (continuing...)<br>";
            }
        }
    }

    // Seed sample data if tables are empty
    $stmt = $conn->query("SELECT COUNT(*) as count FROM categories");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if($row['count'] == 0) {
        // Insert sample categories
        $categories = [
            ['Cars', 'Modern vehicles for daily use'],
            ['Equipment', 'Professional tools and equipment'],
            ['Electronics', 'Latest gadgets and devices'],
            ['Furniture', 'Home and office furniture'],
            ['Sports', 'Sports and outdoor equipment']
        ];
        
        $stmt = $conn->prepare("INSERT INTO categories (name, description) VALUES (?, ?)");
        foreach($categories as $cat) {
            $stmt->execute($cat);
        }
        
        // Insert sample rentals
        $rentals = [
            [1, 'Toyota Camry', 'Reliable mid-size sedan perfect for family trips', 75.00],
            [1, 'Honda CR-V', 'Compact SUV with great fuel efficiency', 85.00],
            [2, 'Professional Camera', 'High-end DSLR camera with multiple lenses', 45.00],
            [3, 'MacBook Pro', 'Latest model with M1 chip', 65.00],
            [4, 'Conference Table', 'Large table perfect for meetings', 35.00]
        ];
        
        $stmt = $conn->prepare("INSERT INTO rentals (category_id, name, description, daily_rate) VALUES (?, ?, ?, ?)");
        foreach($rentals as $rental) {
            $stmt->execute($rental);
        }

        // Create default admin account
        $admin_password = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO admins (name, email, password) VALUES (?, ?, ?)");
        $stmt->execute(['Administrator', 'admin@smartrental.com', $admin_password]);
    }

    echo "<div style='font-family: Arial, sans-serif; max-width: 800px; margin: 40px auto; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);'>";
    echo "<h1 style='color: #2c5282;'>SmartRental Installation Complete! 🎉</h1>";
    echo "<div style='background-color: #f0fff4; border: 1px solid #68d391; padding: 15px; border-radius: 4px; margin: 20px 0;'>";
    echo "<h3 style='color: #2f855a; margin-top: 0;'>Success!</h3>";
    echo "<p>✅ Database tables created successfully<br>";
    echo "✅ Sample data inserted<br>";
    echo "✅ Admin account created</p>";
    echo "</div>";
    
    echo "<div style='background-color: #ebf8ff; border: 1px solid #4299e1; padding: 15px; border-radius: 4px; margin: 20px 0;'>";
    echo "<h3 style='color: #2b6cb0; margin-top: 0;'>Next Steps:</h3>";
    echo "<ol style='margin: 0; padding-left: 20px;'>";
    echo "<li>Access admin panel: <a href='/smart_rental/admin/login.php' style='color: #4299e1;'>/smart_rental/admin/login.php</a></li>";
    echo "<li>Use these admin credentials:";
    echo "<ul style='margin: 5px 0;'>";
    echo "<li>Email: admin@smartrental.com</li>";
    echo "<li>Password: admin123</li>";
    echo "</ul></li>";
    echo "<li>Important: Change the admin password after first login</li>";
    echo "<li>Delete this install.php file</li>";
    echo "</ol>";
    echo "</div>";
    
    echo "<div style='background-color: #fff5f5; border: 1px solid #fc8181; padding: 15px; border-radius: 4px; margin: 20px 0;'>";
    echo "<h3 style='color: #c53030; margin-top: 0;'>Security Warning!</h3>";
    echo "<p>For security reasons, please:</p>";
    echo "<ul style='margin: 0; padding-left: 20px;'>";
    echo "<li>Delete this install.php file immediately</li>";
    echo "<li>Change the default admin password</li>";
    echo "<li>Secure your database credentials</li>";
    echo "</ul>";
    echo "</div>";
    echo "</div>";

} catch(PDOException $e) {
    die("Installation failed: " . $e->getMessage());
}
