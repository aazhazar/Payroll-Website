<?php
session_start();

// Turn on error reporting (remove this in production)
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'YOUR_DATABASE_NAME');
define('DB_USER', 'YOUR_DATABASE_USER');
define('DB_PASS', 'YOUR_DATABASE_PASSWORD');

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Initialize and update database tables
function init_db($pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS employees (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) UNIQUE,
            password VARCHAR(255),
            name VARCHAR(100),
            role ENUM('admin', 'employee') DEFAULT 'employee',
            hourly_rate DECIMAL(10,2),
            start_time TIME DEFAULT '09:00:00',
            end_time TIME DEFAULT '18:00:00',
            ot_rate DECIMAL(10,2) DEFAULT 1.5,
            holiday_rate DECIMAL(10,2) DEFAULT 2.0,
            tax_rate DECIMAL(5,2) DEFAULT 0
        )");

        $pdo->exec("ALTER TABLE employees 
            ADD COLUMN IF NOT EXISTS start_time TIME DEFAULT '09:00:00',
            ADD COLUMN IF NOT EXISTS end_time TIME DEFAULT '18:00:00',
            ADD COLUMN IF NOT EXISTS ot_rate DECIMAL(10,2) DEFAULT 1.5,
            ADD COLUMN IF NOT EXISTS holiday_rate DECIMAL(10,2) DEFAULT 2.0,
            ADD COLUMN IF NOT EXISTS tax_rate DECIMAL(5,2) DEFAULT 0");

        $pdo->exec("CREATE TABLE IF NOT EXISTS attendance (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT,
            timestamp DATETIME,
            action ENUM('in', 'out'),
            overtime_hours DECIMAL(5,2) DEFAULT 0,
            holiday_hours DECIMAL(5,2) DEFAULT 0,
            is_holiday TINYINT(1) DEFAULT 0,
            is_sunday TINYINT(1) DEFAULT 0,
            FOREIGN KEY (employee_id) REFERENCES employees(id)
        )");

        $pdo->exec("ALTER TABLE attendance 
            ADD COLUMN IF NOT EXISTS holiday_hours DECIMAL(5,2) DEFAULT 0,
            ADD COLUMN IF NOT EXISTS is_holiday TINYINT(1) DEFAULT 0,
            ADD COLUMN IF NOT EXISTS is_sunday TINYINT(1) DEFAULT 0");

        $pdo->exec("CREATE TABLE IF NOT EXISTS holidays (
            id INT AUTO_INCREMENT PRIMARY KEY,
            holiday_date DATE UNIQUE,
            description VARCHAR(100)
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS leaves (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT,
            start_date DATE,
            end_date DATE,
            type ENUM('paid', 'unpaid') DEFAULT 'paid',
            status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
            FOREIGN KEY (employee_id) REFERENCES employees(id)
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS advance_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT,
            amount DECIMAL(10,2) NOT NULL,
            request_date DATE NOT NULL,
            status ENUM('pending', 'approved', 'deducted') DEFAULT 'pending',
            FOREIGN KEY (employee_id) REFERENCES employees(id)
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50),
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            role ENUM('admin', 'employee', 'unknown'),
            status ENUM('success', 'failure'),
            ip_address VARCHAR(45)
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_name VARCHAR(50) UNIQUE,
            setting_value DECIMAL(5,2) NOT NULL
        )");

        $pdo->exec("INSERT INTO settings (setting_name, setting_value) VALUES ('etf_rate', 3.00) ON DUPLICATE KEY UPDATE setting_value = 3.00");
        $pdo->exec("INSERT INTO settings (setting_name, setting_value) VALUES ('epf_employee_rate', 8.00) ON DUPLICATE KEY UPDATE setting_value = 8.00");
        $pdo->exec("INSERT INTO settings (setting_name, setting_value) VALUES ('epf_employer_rate', 12.00) ON DUPLICATE KEY UPDATE setting_value = 12.00");

        $stmt = $pdo->query("SELECT * FROM employees WHERE username='admin'");
        if (!$stmt->fetch()) {
            $password = password_hash('admin123', PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO employees (username, password, name, role, hourly_rate) 
                VALUES (?, ?, ?, ?, ?)")->execute(['admin', $password, 'Administrator', 'admin', 0]);
        }
    } catch(PDOException $e) {
        die("Database setup failed: " . $e->getMessage());
    }
}

// Handle login
if (isset($_POST['login'])) {
    $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_SPECIAL_CHARS);
    $password = $_POST['password'];
    $remember = isset($_POST['remember']); // Check if "Remember Me" is selected
    $ip_address = $_SERVER['REMOTE_ADDR']; // Capture IP address
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM employees WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($password, $user['password'])) {
            // Successful login
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['name'] = $user['name'];
            
            // Log success
            $stmt = $pdo->prepare("INSERT INTO audit_logs (username, role, status, ip_address) VALUES (?, ?, 'success', ?)");
            $stmt->execute([$username, $user['role'], $ip_address]);
            
            // Store "Remember Me" intent and credentials in session for the target page
            if ($remember) {
                $_SESSION['remember_me'] = true;
                $_SESSION['remembered_username'] = $username;
                $_SESSION['remembered_password'] = $password;
            } else {
                $_SESSION['remember_me'] = false;
            }
            
            header("Location: " . ($user['role'] == 'admin' ? 'admin.php' : 'employee.php'));
            exit;
        } else {
            // Failed login
            $role = $user ? $user['role'] : 'unknown';
            $stmt = $pdo->prepare("INSERT INTO audit_logs (username, role, status, ip_address) VALUES (?, ?, 'failure', ?)");
            $stmt->execute([$username, $role, $ip_address]);
            
            $error = "Invalid credentials";
        }
    } catch(PDOException $e) {
        $error = "Login error: " . $e->getMessage();
    }
}

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

init_db($pdo);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Payroll System - Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            background: url('https://images.unsplash.com/photo-1504280390367-5d8a73e6d08b?ixlib=rb-4.0.3&auto=format&fit=crop&w=1350&q=80') no-repeat center center fixed;
            background-size: cover;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            padding: 15px;
        }
        .login-container { 
            max-width: 450px; 
            width: 100%; 
            margin: auto; 
        }
        .card { 
            background: rgba(255, 255, 255, 0.9); 
            border: 2px solid transparent; 
            border-radius: 15px; 
            padding: 25px; 
            box-shadow: 0 8px 30px rgba(0,0,0,0.2); 
            background: linear-gradient(135deg, #ffffff, #e9ecef); 
        }
        .btn-primary { 
            background: #28a745; 
            border: none; 
            width: 100%; 
            padding: 10px; 
            transition: all 0.3s ease; 
        }
        .btn-primary:hover { 
            background: #218838; 
            transform: translateY(-2px); 
            box-shadow: 0 4px 15px rgba(0,0,0,0.2); 
        }
        .form-control { 
            border-radius: 8px; 
            padding-left: 40px; 
            transition: all 0.3s ease; 
        }
        .form-control:focus { 
            border-color: #28a745; 
            box-shadow: 0 0 10px rgba(40, 167, 69, 0.5); 
        }
        .input-group { position: relative; }
        .input-group i { 
            position: absolute; 
            left: 12px; 
            top: 50%; 
            transform: translateY(-50%); 
            color: #6c757d; 
        }
        .logo-text { 
            text-align: center; 
            margin-bottom: 20px; 
            font-family: 'Arial', sans-serif; 
        }
        .logo-text .payroll { 
            font-size: 2.5rem; 
            font-weight: bold; 
            color: #28a745; 
            letter-spacing: 2px; 
        }
        .logo-text .byline { 
            font-size: 1rem; 
            color: #343a40; 
        }
        .logo-text .tent { 
            color: #007bff; 
            font-weight: bold; 
        }
        .footer { 
            text-align: center; 
            margin-top: 20px; 
            color: #fff; 
            text-shadow: 1px 1px 3px rgba(0,0,0,0.5); 
        }
        .footer a { 
            color: #28a745; 
            text-decoration: none; 
            font-weight: bold; 
        }
        .footer a:hover { 
            text-decoration: underline; 
            color: #218838; 
        }
        @media (max-width: 576px) { 
            .card { padding: 20px; } 
            h2 { font-size: 1.5rem; } 
            .logo-text .payroll { font-size: 2rem; } 
            .logo-text .byline { font-size: 0.9rem; } 
            .footer { font-size: 0.9rem; } 
        }
    </style>
</head>
<body>
    <div class="container login-container">
        <div class="card">
            <!-- Text Logo -->
            <div class="logo-text">
                <div class="payroll">PAYROLL</div>
                <div class="byline">by CanopyHut (Pvt) Ltd</div>
            </div>
            <h2 class="text-center mb-4"><i class="fas fa-lock me-2"></i>Login</h2>
            <?php if (isset($error)) echo "<div class='alert alert-danger'>$error</div>"; ?>
            <form method="post">
                <div class="mb-3 input-group">
                    <i class="fas fa-user"></i>
                    <input type="text" name="username" id="username" class="form-control" placeholder="Username" required>
                </div>
                <div class="mb-3 input-group">
                    <i class="fas fa-key"></i>
                    <input type="password" name="password" id="password" class="form-control" placeholder="Password" required>
                </div>
                <div class="mb-3 form-check">
                    <input type="checkbox" class="form-check-input" id="remember" name="remember">
                    <label class="form-check-label" for="remember">Remember Me</label>
                </div>
                <button type="submit" name="login" class="btn btn-primary">
                    <i class="fas fa-sign-in-alt me-2"></i>Login
                </button>
            </form>
        </div>
    </div>
    <footer class="footer">
        <a href="https://tent.lk/" target="_blank">Canopy Hut (Pvt) Ltd</a>
    </footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-fill form with stored credentials on page load
        window.onload = function() {
            const rememberedUsername = localStorage.getItem('rememberedUsername');
            const rememberedPassword = localStorage.getItem('rememberedPassword');
            
            if (rememberedUsername && rememberedPassword) {
                document.getElementById('username').value = rememberedUsername;
                document.getElementById('password').value = rememberedPassword;
                document.getElementById('remember').checked = true;
            }
        };
    </script>
</body>
</html>