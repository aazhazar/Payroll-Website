<?php


session_start();

// Turn on error reporting (remove in production)
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Set default timezone
date_default_timezone_set('Asia/Colombo');

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'YOUR_DATABASE_NAME');
define('DB_USER', 'YOUR_DATABASE_USER');
define('DB_PASS', 'YOUR_DATABASE_PASSWORD');

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO ::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: index.php");
    exit;
}

// Initialize database with correct payouts table
function init_db($pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS payouts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT,
            amount DECIMAL(10,2) NOT NULL,
            payout_date DATETIME DEFAULT CURRENT_TIMESTAMP,
            status ENUM('pending', 'completed') DEFAULT 'pending',
            FOREIGN KEY (employee_id) REFERENCES employees(id)
        )");
    } catch(PDOException $e) {
        die("Payouts table creation failed: " . $e->getMessage());
    }
}

// Fetch current ETF and EPF rates
try {
    $stmt = $pdo->query("SELECT setting_name, setting_value FROM settings WHERE setting_name IN ('etf_rate', 'epf_employee_rate', 'epf_employer_rate')");
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $etf_rate = $settings['etf_rate'] ?? 3.00;
    $epf_employee_rate = $settings['epf_employee_rate'] ?? 8.00;
    $epf_employer_rate = $settings['epf_employer_rate'] ?? 12.00;
} catch(PDOException $e) {
    die("Error fetching settings: " . $e->getMessage());
}

// Update ETF and EPF rates
if (isset($_POST['update_rates'])) {
    $new_etf_rate = floatval($_POST['etf_rate']);
    $new_epf_employee_rate = floatval($_POST['epf_employee_rate']);
    $new_epf_employer_rate = floatval($_POST['epf_employer_rate']);
    
    try {
        $stmt = $pdo->prepare("INSERT INTO settings (setting_name, setting_value) VALUES ('etf_rate', ?) 
            ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$new_etf_rate, $new_etf_rate]);
        
        $stmt = $pdo->prepare("INSERT INTO settings (setting_name, setting_value) VALUES ('epf_employee_rate', ?) 
            ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$new_epf_employee_rate, $new_epf_employee_rate]);
        
        $stmt = $pdo->prepare("INSERT INTO settings (setting_name, setting_value) VALUES ('epf_employer_rate', ?) 
            ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$new_epf_employer_rate, $new_epf_employer_rate]);
        
        $success = "ETF and EPF rates updated successfully";
        $etf_rate = $new_etf_rate;
        $epf_employee_rate = $new_epf_employee_rate;
        $epf_employer_rate = $new_epf_employer_rate;
    } catch(PDOException $e) {
        $error = "Error updating rates: " . $e->getMessage();
    }
}

// Add employee
if (isset($_POST['add_employee'])) {
    $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_SPECIAL_CHARS);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $name = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_SPECIAL_CHARS);
    $hourly_rate = floatval($_POST['hourly_rate']);
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];
    $ot_rate = floatval($_POST['ot_rate']);
    $holiday_rate = floatval($_POST['holiday_rate']);
    $tax_rate = floatval($_POST['tax_rate']);
    
    try {
        $stmt = $pdo->prepare("INSERT INTO employees (username, password, name, role, hourly_rate, start_time, end_time, ot_rate, holiday_rate, tax_rate) 
            VALUES (?, ?, ?, 'employee', ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$username, $password, $name, $hourly_rate, $start_time, $end_time, $ot_rate, $holiday_rate, $tax_rate]);
        $success = "Employee added successfully";
    } catch(PDOException $e) {
        $error = "Error adding employee: " . $e->getMessage();
    }
}

// Edit employee (including password change)
if (isset($_POST['edit_time'])) {
    $employee_id = $_POST['employee_id'];
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];
    $ot_rate = floatval($_POST['ot_rate']);
    $holiday_rate = floatval($_POST['holiday_rate']);
    $tax_rate = floatval($_POST['tax_rate']);
    $new_password = $_POST['new_password'] ?? ''; // Optional password field
    
    try {
        $query = "UPDATE employees SET start_time = ?, end_time = ?, ot_rate = ?, holiday_rate = ?, tax_rate = ?";
        $params = [$start_time, $end_time, $ot_rate, $holiday_rate, $tax_rate];
        
        // If a new password is provided, include it in the update
        if (!empty($new_password)) {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $query .= ", password = ?";
            $params[] = $hashed_password;
        }
        
        $query .= " WHERE id = ? AND role = 'employee'";
        $params[] = $employee_id;
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        
        $success = "Employee updated successfully" . (!empty($new_password) ? " (password changed)" : "");
    } catch(PDOException $e) {
        $error = "Error updating employee: " . $e->getMessage();
    }
}

// Delete employee
if (isset($_POST['delete_employee'])) {
    $employee_id = $_POST['employee_id'];
    try {
        $pdo->prepare("DELETE FROM attendance WHERE employee_id = ?")->execute([$employee_id]);
        $pdo->prepare("DELETE FROM leaves WHERE employee_id = ?")->execute([$employee_id]);
        $pdo->prepare("DELETE FROM advance_payments WHERE employee_id = ?")->execute([$employee_id]);
        $pdo->prepare("DELETE FROM payouts WHERE employee_id = ?")->execute([$employee_id]);
        $pdo->prepare("DELETE FROM employees WHERE id = ? AND role = 'employee'")->execute([$employee_id]);
        $success = "Employee deleted successfully";
    } catch(PDOException $e) {
        $error = "Error deleting employee: " . $e->getMessage();
    }
}

// Add holiday
if (isset($_POST['add_holiday'])) {
    $holiday_date = $_POST['holiday_date'];
    $description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_SPECIAL_CHARS);
    try {
        $stmt = $pdo->prepare("INSERT INTO holidays (holiday_date, description) VALUES (?, ?)");
        $stmt->execute([$holiday_date, $description]);
        $success = "Holiday added successfully";
    } catch(PDOException $e) {
        $error = "Error adding holiday: " . $e->getMessage();
    }
}

// Approve/Reject leave
if (isset($_POST['update_leave'])) {
    $leave_id = $_POST['leave_id'];
    $status = $_POST['status'];
    try {
        $stmt = $pdo->prepare("UPDATE leaves SET status = ? WHERE id = ?");
        $stmt->execute([$status, $leave_id]);
        $success = "Leave request updated";
    } catch(PDOException $e) {
        $error = "Error updating leave: " . $e->getMessage();
    }
}

// Approve/Reject advance payment
if (isset($_POST['update_advance'])) {
    $advance_id = $_POST['advance_id'];
    $status = $_POST['advance_status'];
    try {
        $stmt = $pdo->prepare("UPDATE advance_payments SET status = ? WHERE id = ?");
        $stmt->execute([$status, $advance_id]);
        $success = "Advance request updated";
    } catch(PDOException $e) {
        $error = "Error updating advance: " . $e->getMessage();
    }
}

// Process Payout
if (isset($_POST['process_payout'])) {
    $employee_id = $_POST['employee_id'];
    $amount = floatval($_POST['payout_amount']);
    
    if ($amount <= 0) {
        $error = "Invalid payout amount.";
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT 
                    e.hourly_rate, e.ot_rate, e.holiday_rate, e.tax_rate,
                    SUM(a.overtime_hours) as total_ot, 
                    SUM(a.holiday_hours) as total_holiday,
                    SUM(CASE 
                        WHEN a.action = 'out' AND a.is_holiday = 0 AND a.is_sunday = 0 THEN 
                            TIMESTAMPDIFF(MICROSECOND, 
                                (SELECT timestamp FROM attendance a2 
                                 WHERE a2.employee_id = a.employee_id 
                                 AND a2.action = 'in' 
                                 AND a2.timestamp < a.timestamp 
                                 ORDER BY a2.timestamp DESC LIMIT 1), 
                                a.timestamp) / 3600000000.0 
                        ELSE 0 
                    END) as regular_hours,
                    (SELECT SUM(amount) FROM advance_payments ap WHERE ap.employee_id = e.id AND ap.status = 'approved') as total_advance
                FROM employees e 
                LEFT JOIN attendance a ON e.id = a.employee_id AND a.timestamp >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)
                WHERE e.id = ? AND e.role = 'employee'
                GROUP BY e.id, e.hourly_rate, e.ot_rate, e.holiday_rate, e.tax_rate
            ");
            $stmt->execute([$employee_id]);
            $emp_data = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($emp_data) {
                $regular_hours = $emp_data['regular_hours'] ?? 0;
                $gross = ($regular_hours * $emp_data['hourly_rate']) + 
                         (($emp_data['total_ot'] ?? 0) * $emp_data['hourly_rate'] * $emp_data['ot_rate']) + 
                         (($emp_data['total_holiday'] ?? 0) * $emp_data['hourly_rate'] * $emp_data['holiday_rate']);
                $epf_employee = $gross * ($epf_employee_rate / 100);
                $tax = $gross * ($emp_data['tax_rate'] / 100);
                $advance = $emp_data['total_advance'] ?? 0;
                $net_earnings = $gross - $epf_employee - $tax - $advance;

                if ($amount > $net_earnings) {
                    $error = "Payout amount exceeds employee's net earnings (LKR " . number_format($net_earnings, 2) . ").";
                } else {
                    $stmt = $pdo->prepare("INSERT INTO payouts (employee_id, amount) VALUES (?, ?)");
                    $stmt->execute([$employee_id, $amount]);
                    $success = "Payout of LKR " . number_format($amount, 2) . " processed for employee ID $employee_id (pending confirmation).";
                }
            } else {
                $error = "Employee data not found.";
            }
        } catch(PDOException $e) {
            $error = "Error processing payout: " . $e->getMessage();
        }
    }
}

// Confirm Payout
if (isset($_POST['confirm_payout'])) {
    $payout_id = $_POST['payout_id'];
    try {
        $stmt = $pdo->prepare("UPDATE payouts SET status = 'completed' WHERE id = ? AND status = 'pending'");
        $stmt->execute([$payout_id]);
        $success = "Payout confirmed successfully.";
    } catch(PDOException $e) {
        $error = "Error confirming payout: " . $e->getMessage();
    }
}

// View Employee Attendance
$attendance_records = [];
$selected_employee_name = '';
if (isset($_POST['view_attendance'])) {
    $employee_id = $_POST['employee_id'];
    $start_date = $_POST['attendance_start_date'] ?? '';
    $end_date = $_POST['attendance_end_date'] ?? '';
    
    try {
        $query = "SELECT a.*, e.name 
                  FROM attendance a 
                  JOIN employees e ON a.employee_id = e.id 
                  WHERE a.employee_id = ?";
        $params = [$employee_id];
        
        if ($start_date && $end_date) {
            $query .= " AND a.timestamp BETWEEN ? AND ?";
            $params[] = "$start_date 00:00:00";
            $params[] = "$end_date 23:59:59";
        }
        
        $query .= " ORDER BY a.timestamp DESC";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $attendance_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($attendance_records)) {
            $selected_employee_name = $attendance_records[0]['name'];
        } else {
            $error = "No attendance records found for this employee.";
        }
    } catch(PDOException $e) {
        $error = "Error fetching attendance: " . $e->getMessage();
    }
}

// Export payroll as CSV
if (isset($_POST['export_payroll'])) {
    $filename = "payroll_" . date('Y-m-d') . ".csv";
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Name', 'Hourly Rate', 'Regular Hours', 'OT Hours', 'Holiday Hours', 'Gross Earnings', 'EPF Employee', 'EPF Employer', 'ETF Employer', 'Tax', 'Advance', 'Payout', 'Net Earnings']);
    
    foreach ($employees as $emp) {
        $regular_hours = $emp['regular_hours'] ?? 0;
        $gross = ($regular_hours * $emp['hourly_rate']) + 
                 (($emp['total_ot'] ?? 0) * $emp['hourly_rate'] * $emp['ot_rate']) + 
                 (($emp['total_holiday'] ?? 0) * $emp['hourly_rate'] * $emp['holiday_rate']);
        $epf_employee = $gross * ($epf_employee_rate / 100);
        $epf_employer = $gross * ($epf_employer_rate / 100);
        $etf_employer = $gross * ($etf_rate / 100);
        $tax = $gross * ($emp['tax_rate'] / 100);
        $advance = $emp['total_advance'] ?? 0;
        $payout = $emp['total_payout'] ?? 0;
        $net = $gross - $epf_employee - $tax - $advance - $payout;
        fputcsv($output, [
            $emp['name'], 
            number_format($emp['hourly_rate'], 2), 
            number_format($regular_hours, 2), 
            number_format($emp['total_ot'] ?? 0, 2), 
            number_format($emp['total_holiday'] ?? 0, 2), 
            number_format($gross, 2), 
            number_format($epf_employee, 2), 
            number_format($epf_employer, 2), 
            number_format($etf_employer, 2), 
            number_format($tax, 2), 
            number_format($advance, 2), 
            number_format($payout, 2),
            number_format($net, 2)
        ]);
    }
    
    fclose($output);
    exit;
}

// Get data with updated payroll query
try {
    $stmt = $pdo->prepare("
        SELECT e.id, e.name, e.hourly_rate, e.start_time, e.end_time, e.ot_rate, e.holiday_rate, e.tax_rate,
            SUM(a.overtime_hours) as total_ot, 
            SUM(a.holiday_hours) as total_holiday,
            SUM(CASE 
                WHEN a.action = 'out' AND a.is_holiday = 0 AND a.is_sunday = 0 THEN 
                    TIMESTAMPDIFF(MICROSECOND, 
                        (SELECT timestamp FROM attendance a2 
                         WHERE a2.employee_id = a.employee_id 
                         AND a2.action = 'in' 
                         AND a2.timestamp < a.timestamp 
                         ORDER BY a2.timestamp DESC LIMIT 1), 
                        a.timestamp) / 3600000000.0 
                ELSE 0 
            END) as regular_hours,
            MAX(CASE WHEN a.action = 'in' THEN a.timestamp END) as last_clock_in,
            MAX(CASE WHEN a.action = 'out' THEN a.timestamp END) as last_clock_out,
            (SELECT SUM(amount) FROM advance_payments ap WHERE ap.employee_id = e.id AND ap.status = 'approved') as total_advance,
            (SELECT SUM(amount) FROM payouts p WHERE p.employee_id = e.id AND p.status = 'completed') as total_payout
        FROM employees e 
        LEFT JOIN attendance a ON e.id = a.employee_id AND a.timestamp >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)
        WHERE e.role = 'employee'
        GROUP BY e.id, e.name, e.hourly_rate, e.start_time, e.end_time, e.ot_rate, e.holiday_rate, e.tax_rate
    ");
    $stmt->execute();
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $holidays = $pdo->query("SELECT * FROM holidays ORDER BY holiday_date DESC")->fetchAll(PDO::FETCH_ASSOC);
    $leaves = $pdo->query("SELECT l.*, e.name FROM leaves l JOIN employees e ON l.employee_id = e.id ORDER BY l.start_date DESC")->fetchAll(PDO::FETCH_ASSOC);
    $advances = $pdo->query("SELECT ap.*, e.name FROM advance_payments ap JOIN employees e ON ap.employee_id = e.id ORDER BY ap.request_date DESC")->fetchAll(PDO::FETCH_ASSOC);
    $payouts = $pdo->query("SELECT p.*, e.name FROM payouts p JOIN employees e ON p.employee_id = e.id ORDER BY p.payout_date DESC")->fetchAll(PDO::FETCH_ASSOC);

    // Total Payroll Summary
    $stmt = $pdo->query("SELECT COUNT(*) as total_employees FROM employees WHERE role = 'employee'");
    $total_employees = $stmt->fetchColumn();
    $stmt = $pdo->query("SELECT SUM(amount) as total_advances FROM advance_payments WHERE status = 'approved'");
    $total_advances = $stmt->fetchColumn() ?? 0;
    $stmt = $pdo->query("SELECT SUM(amount) as total_payouts FROM payouts WHERE status = 'completed'");
    $total_payouts = $stmt->fetchColumn() ?? 0;
} catch(PDOException $e) {
    die("Error fetching data: " . $e->getMessage());
}

init_db($pdo);

// Handle Edit Employee Form Submission
$edit_employee = null;
if (isset($_POST['edit_employee'])) {
    $employee_id = $_POST['employee_id'];
    $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ? AND role = 'employee'");
    $stmt->execute([$employee_id]);
    $edit_employee = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            padding-bottom: 20px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .navbar {
            background: linear-gradient(90deg, #1e3c72 0%, #2a5298 100%);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }
        .navbar-brand {
            font-weight: 600;
            letter-spacing: 1px;
        }
        #liveClock {
            color: #fff;
            font-weight: 500;
            font-size: 1.1rem;
            background: rgba(255, 255, 255, 0.2);
            padding: 5px 15px;
            border-radius: 20px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        .card {
            background: #fff;
            box-shadow: 0 6px 25px rgba(0, 0, 0, 0.1);
            border-radius: 20px;
            padding: 20px;
            margin: 20px 0;
            transition: transform 0.3s ease;
        }
        .card:hover {
            transform: translateY(-5px);
        }
        h2, h3 {
            color: #1e3c72;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .btn-primary {
            background: linear-gradient(45deg, #007bff, #00b4db);
            border: none;
            border-radius: 10px;
            padding: 10px 20px;
            transition: all 0.3s ease;
        }
        .btn-primary:hover {
            background: linear-gradient(45deg, #0056b3, #0083b0);
            transform: scale(1.05);
        }
        .btn-warning, .btn-danger, .btn-success {
            border-radius: 10px;
            transition: all 0.3s ease;
        }
        .btn-warning:hover, .btn-danger:hover, .btn-success:hover {
            transform: scale(1.05);
        }
        .table-dark {
            background: #2a5298;
            color: #fff;
        }
        .table-striped tbody tr:nth-child(odd) {
            background-color: rgba(255, 255, 255, 0.9);
        }
        .table-responsive {
            border-radius: 15px;
            overflow: hidden;
        }
        .form-control {
            border-radius: 10px;
            box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        .form-control:focus {
            border-color: #1e3c72;
            box-shadow: 0 0 5px rgba(30, 60, 114, 0.5);
        }
        .alert {
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        .dashboard-icon {
            font-size: 1.5rem;
            margin-right: 10px;
            color: #1e3c72;
        }
        .summary-box {
            background: #fff;
            border-radius: 15px;
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }
        @media (max-width: 768px) {
            h2, h3 { font-size: 1.2rem; }
            .table { font-size: 0.9rem; }
            .btn-sm { padding: 0.15rem 0.3rem; }
            #liveClock { font-size: 0.9rem; padding: 3px 10px; }
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="#"><i class="fas fa-briefcase me-2"></i>Payroll</a>
            <div class="navbar-nav ms-auto">
                <span id="liveClock" class="me-3"></span>
                <a class="nav-link" href="index.php?logout=1"><i class="fas fa-sign-out-alt me-2"></i>Logout</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="card">
            <h2 class="mb-4"><i class="fas fa-user-shield dashboard-icon"></i>Admin Dashboard</h2>
            <?php 
            if (isset($success)) echo "<div class='alert alert-success'><i class='fas fa-check-circle me-2'></i>$success</div>";
            if (isset($error)) echo "<div class='alert alert-danger'><i class='fas fa-exclamation-triangle me-2'></i>$error</div>"; 
            ?>

            <!-- Edit Employee Form (appears when Edit button is clicked) -->
            <?php if ($edit_employee): ?>
                <h3><i class="fas fa-user-edit dashboard-icon"></i>Edit Employee: <?php echo htmlspecialchars($edit_employee['name']); ?></h3>
                <form method="post" class="mb-4">
                    <input type="hidden" name="employee_id" value="<?php echo $edit_employee['id']; ?>">
                    <div class="row">
                        <div class="col-md-3 col-6 mb-3">
                            <label class="form-label">Start Time</label>
                            <input type="time" name="start_time" class="form-control" value="<?php echo substr($edit_employee['start_time'], 0, 5); ?>" required>
                        </div>
                        <div class="col-md-3 col-6 mb-3">
                            <label class="form-label">End Time</label>
                            <input type="time" name="end_time" class="form-control" value="<?php echo substr($edit_employee['end_time'], 0, 5); ?>" required>
                        </div>
                        <div class="col-md-2 col-6 mb-3">
                            <label class="form-label">OT Rate</label>
                            <input type="number" name="ot_rate" step="0.1" class="form-control" value="<?php echo $edit_employee['ot_rate']; ?>" required>
                        </div>
                        <div class="col-md-2 col-6 mb-3">
                            <label class="form-label">Holiday Rate</label>
                            <input type="number" name="holiday_rate" step="0.1" class="form-control" value="<?php echo $edit_employee['holiday_rate']; ?>" required>
                        </div>
                        <div class="col-md-2 col-6 mb-3">
                            <label class="form-label">Tax Rate (%)</label>
                            <input type="number" name="tax_rate" step="0.1" class="form-control" value="<?php echo $edit_employee['tax_rate']; ?>" required>
                        </div>
                        <div class="col-md-3 col-6 mb-3">
                            <label class="form-label">New Password (optional)</label>
                            <input type="password" name="new_password" class="form-control" placeholder="Leave blank to keep current">
                        </div>
                    </div>
                    <button type="submit" name="edit_time" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save Changes</button>
                </form>
            <?php endif; ?>

            <!-- Payroll Summary -->
            <h3><i class="fas fa-chart-bar dashboard-icon"></i>Payroll Summary</h3>
            <div class="row">
                <div class="col-md-4 col-12">
                    <div class="summary-box">
                        <h5>Total Employees</h5>
                        <p class="fs-3"><?php echo $total_employees; ?></p>
                    </div>
                </div>
                <div class="col-md-4 col-12">
                    <div class="summary-box">
                        <h5>Total Advances (LKR)</h5>
                        <p class="fs-3"><?php echo number_format($total_advances, 2); ?></p>
                    </div>
                </div>
                <div class="col-md-4 col-12">
                    <div class="summary-box">
                        <h5>Total Payouts (LKR)</h5>
                        <p class="fs-3"><?php echo number_format($total_payouts, 2); ?></p>
                    </div>
                </div>
            </div>

            <!-- View Employee Attendance -->
            <h3><i class="fas fa-clock dashboard-icon"></i>View Employee Attendance</h3>
            <form method="post" class="mb-4">
                <div class="row">
                    <div class="col-md-4 col-12 mb-3">
                        <label class="form-label">Select Employee</label>
                        <select name="employee_id" class="form-control" required>
                            <option value="">-- Select Employee --</option>
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?php echo $emp['id']; ?>"><?php echo htmlspecialchars($emp['name']); ?> (ID: <?php echo $emp['id']; ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 col-6 mb-3">
                        <label class="form-label">Start Date (Optional)</label>
                        <input type="date" name="attendance_start_date" class="form-control">
                    </div>
                    <div class="col-md-3 col-6 mb-3">
                        <label class="form-label">End Date (Optional)</label>
                        <input type="date" name="attendance_end_date" class="form-control">
                    </div>
                    <div class="col-md-2 col-12 mb-3 d-flex align-items-end">
                        <button type="submit" name="view_attendance" class="btn btn-primary w-100"><i class="fas fa-eye me-2"></i>View</button>
                    </div>
                </div>
            </form>
            <?php if (!empty($attendance_records)): ?>
                <h4>Attendance for <?php echo htmlspecialchars($selected_employee_name); ?></h4>
                <div class="table-responsive mb-4">
                    <table class="table table-striped">
                        <thead class="table-dark">
                            <tr>
                                <th>Date/Time</th>
                                <th>Action</th>
                                <th>OT (hrs)</th>
                                <th>Holiday (hrs)</th>
                                <th>Holiday</th>
                                <th>Sunday</th>
                                <th>Location</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($attendance_records as $record): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($record['timestamp']); ?></td>
                                    <td><span class="badge <?php echo $record['action'] == 'in' ? 'bg-success' : 'bg-danger'; ?>"><?php echo htmlspecialchars($record['action']); ?></span></td>
                                    <td><?php echo number_format($record['overtime_hours'] ?? 0, 2); ?></td>
                                    <td><?php echo number_format($record['holiday_hours'] ?? 0, 2); ?></td>
                                    <td><?php echo $record['is_holiday'] ? '<span class="badge bg-info">Yes</span>' : 'No'; ?></td>
                                    <td><?php echo $record['is_sunday'] ? '<span class="badge bg-warning">Yes</span>' : 'No'; ?></td>
                                    <td><?php echo ($record['latitude'] && $record['longitude']) ? "Lat: " . number_format($record['latitude'], 6) . ", Lng: " . number_format($record['longitude'], 6) : '-'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php elseif (isset($_POST['view_attendance'])): ?>
                <div class="alert alert-warning">No attendance records found for the selected employee and date range.</div>
            <?php endif; ?>

            <!-- Process Payout -->
            <h3><i class="fas fa-money-bill-wave dashboard-icon"></i>Process Payout</h3>
            <form method="post" class="mb-4">
                <div class="row">
                    <div class="col-md-6 col-12 mb-3">
                        <label class="form-label">Select Employee</label>
                        <select name="employee_id" class="form-control" required>
                            <option value="">-- Select Employee --</option>
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?php echo $emp['id']; ?>"><?php echo htmlspecialchars($emp['name']); ?> (ID: <?php echo $emp['id']; ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 col-12 mb-3">
                        <label class="form-label">Payout Amount (LKR)</label>
                        <input type="number" name="payout_amount" class="form-control" step="0.01" min="1" required>
                    </div>
                    <div class="col-md-2 col-12 mb-3 d-flex align-items-end">
                        <button type="submit" name="process_payout" class="btn btn-primary w-100"><i class="fas fa-money-check-alt me-2"></i>Payout</button>
                    </div>
                </div>
            </form>

            <!-- Payout History -->
            <h3><i class="fas fa-history dashboard-icon"></i>Payout History</h3>
            <div class="table-responsive mb-4">
                <table class="table table-striped">
                    <thead class="table-dark">
                        <tr>
                            <th>Employee</th>
                            <th>Amount (LKR)</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payouts as $payout): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($payout['name']); ?></td>
                                <td><?php echo number_format($payout['amount'], 2); ?></td>
                                <td><?php echo htmlspecialchars($payout['payout_date']); ?></td>
                                <td><span class="badge <?php echo $payout['status'] == 'completed' ? 'bg-success' : 'bg-warning'; ?>"><?php echo htmlspecialchars($payout['status']); ?></span></td>
                                <td>
                                    <?php if ($payout['status'] == 'pending'): ?>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="payout_id" value="<?php echo $payout['id']; ?>">
                                            <button type="submit" name="confirm_payout" class="btn btn-sm btn-success" onclick="return confirm('Confirm payout of LKR <?php echo number_format($payout['amount'], 2); ?>?');"><i class="fas fa-check"></i> Confirm</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Customize ETF and EPF Rates -->
            <h3><i class="fas fa-cog dashboard-icon"></i>Customize ETF & EPF Rates</h3>
            <form method="post" class="mb-4">
                <div class="row">
                    <div class="col-md-4 col-12 mb-3">
                        <label class="form-label">ETF Rate (%)</label>
                        <input type="number" name="etf_rate" class="form-control" step="0.01" min="0" value="<?php echo htmlspecialchars($etf_rate); ?>" required>
                    </div>
                    <div class="col-md-4 col-12 mb-3">
                        <label class="form-label">EPF Employee Rate (%)</label>
                        <input type="number" name="epf_employee_rate" class="form-control" step="0.01" min="0" value="<?php echo htmlspecialchars($epf_employee_rate); ?>" required>
                    </div>
                    <div class="col-md-4 col-12 mb-3">
                        <label class="form-label">EPF Employer Rate (%)</label>
                        <input type="number" name="epf_employer_rate" class="form-control" step="0.01" min="0" value="<?php echo htmlspecialchars($epf_employer_rate); ?>" required>
                    </div>
                </div>
                <button type="submit" name="update_rates" class="btn btn-primary"><i class="fas fa-save me-2"></i>Update Rates</button>
            </form>

            <!-- Add Employee -->
            <h3><i class="fas fa-user-plus dashboard-icon"></i>Add Employee</h3>
            <form method="post" class="mb-4">
                <div class="row">
                    <div class="col-md-3 col-6 mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" name="username" class="form-control" required>
                    </div>
                    <div class="col-md-3 col-6 mb-3">
                        <label class="form-label">Password</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    <div class="col-md-3 col-6 mb-3">
                        <label class="form-label">Name</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="col-md-3 col-6 mb-3">
                        <label class="form-label">Hourly Rate</label>
                        <input type="number" name="hourly_rate" step="0.01" class="form-control" required>
                    </div>
                    <div class="col-md-3 col-6 mb-3">
                        <label class="form-label">Start Time</label>
                        <input type="time" name="start_time" class="form-control" value="09:00" required>
                    </div>
                    <div class="col-md-3 col-6 mb-3">
                        <label class="form-label">End Time</label>
                        <input type="time" name="end_time" class="form-control" value="18:00" required>
                    </div>
                    <div class="col-md-2 col-6 mb-3">
                        <label class="form-label">OT Rate</label>
                        <input type="number" name="ot_rate" step="0.1" class="form-control" value="1.5" required>
                    </div>
                    <div class="col-md-2 col-6 mb-3">
                        <label class="form-label">Holiday Rate</label>
                        <input type="number" name="holiday_rate" step="0.1" class="form-control" value="2.0" required>
                    </div>
                    <div class="col-md-2 col-6 mb-3">
                        <label class="form-label">Tax Rate (%)</label>
                        <input type="number" name="tax_rate" step="0.1" class="form-control" value="0" required>
                    </div>
                </div>
                <button type="submit" name="add_employee" class="btn btn-primary"><i class="fas fa-plus me-2"></i>Add Employee</button>
            </form>

            <!-- Manage Holidays -->
            <h3><i class="fas fa-calendar-alt dashboard-icon"></i>Manage Holidays</h3>
            <form method="post" class="mb-4">
                <div class="row">
                    <div class="col-md-4 col-12 mb-3">
                        <label class="form-label">Holiday Date</label>
                        <input type="date" name="holiday_date" class="form-control" required>
                    </div>
                    <div class="col-md-8 col-12 mb-3">
                        <label class="form-label">Description</label>
                        <input type="text" name="description" class="form-control" required>
                    </div>
                </div>
                <button type="submit" name="add_holiday" class="btn btn-primary"><i class="fas fa-calendar-plus me-2"></i>Add Holiday</button>
            </form>
            <div class="table-responsive mb-4">
                <table class="table table-striped">
                    <thead class="table-dark">
                        <tr>
                            <th>Date</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($holidays as $holiday): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($holiday['holiday_date']); ?></td>
                                <td><?php echo htmlspecialchars($holiday['description']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Manage Leaves -->
            <h3><i class="fas fa-calendar-times dashboard-icon"></i>Manage Leaves</h3>
            <div class="table-responsive mb-4">
                <table class="table table-striped">
                    <thead class="table-dark">
                        <tr>
                            <th>Employee</th>
                            <th>Start Date</th>
                            <th>End Date</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($leaves as $leave): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($leave['name']); ?></td>
                                <td><?php echo htmlspecialchars($leave['start_date']); ?></td>
                                <td><?php echo htmlspecialchars($leave['end_date']); ?></td>
                                <td><?php echo htmlspecialchars($leave['type']); ?></td>
                                <td><span class="badge <?php echo $leave['status'] == 'approved' ? 'bg-success' : ($leave['status'] == 'pending' ? 'bg-warning' : 'bg-danger'); ?>"><?php echo htmlspecialchars($leave['status']); ?></span></td>
                                <td>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="leave_id" value="<?php echo $leave['id']; ?>">
                                        <select name="status" class="form-control form-control-sm d-inline w-auto">
                                            <option value="pending" <?php echo $leave['status'] == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                            <option value="approved" <?php echo $leave['status'] == 'approved' ? 'selected' : ''; ?>>Approved</option>
                                            <option value="rejected" <?php echo $leave['status'] == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                        </select>
                                        <button type="submit" name="update_leave" class="btn btn-sm btn-success"><i class="fas fa-check"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Manage Advance Payments -->
            <h3><i class="fas fa-money-check-alt dashboard-icon"></i>Manage Advance Payments</h3>
            <div class="table-responsive mb-4">
                <table class="table table-striped">
                    <thead class="table-dark">
                        <tr>
                            <th>Employee</th>
                            <th>Request Date</th>
                            <th>Amount (LKR)</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($advances as $advance): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($advance['name']); ?></td>
                                <td><?php echo htmlspecialchars($advance['request_date']); ?></td>
                                <td><?php echo number_format($advance['amount'], 2); ?></td>
                                <td><span class="badge <?php echo $advance['status'] == 'approved' ? 'bg-success' : ($advance['status'] == 'pending' ? 'bg-warning' : 'bg-info'); ?>"><?php echo htmlspecialchars($advance['status']); ?></span></td>
                                <td>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="advance_id" value="<?php echo $advance['id']; ?>">
                                        <select name="advance_status" class="form-control form-control-sm d-inline w-auto">
                                            <option value="pending" <?php echo $advance['status'] == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                            <option value="approved" <?php echo $advance['status'] == 'approved' ? 'selected' : ''; ?>>Approved</option>
                                            <option value="deducted" <?php echo $advance['status'] == 'deducted' ? 'selected' : ''; ?>>Deducted</option>
                                        </select>
                                        <button type="submit" name="update_advance" class="btn btn-sm btn-success"><i class="fas fa-check"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Employees and Payroll -->
            <h3><i class="fas fa-users dashboard-icon"></i>Employees (This Month)</h3>
            <form method="post" class="mb-3">
                <button type="submit" name="export_payroll" class="btn btn-primary"><i class="fas fa-download me-2"></i>Export Payroll</button>
            </form>
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead class="table-dark">
                        <tr>
                            <th>Name</th>
                            <th>Rate</th>
                            <th>Start</th>
                            <th>End</th>
                            <th>OT (x)</th>
                            <th>Holiday (x)</th>
                            <th>Tax (%)</th>
                            <th>Reg Hrs</th>
                            <th>OT Hrs</th>
                            <th>Holiday Hrs</th>
                            <th>Last In</th>
                            <th>Last Out</th>
                            <th>Advance</th>
                            <th>Payout</th>
                            <th>Net (LKR)</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($employees)): ?>
                            <tr>
                                <td colspan="16" class="text-center">No employees found. Add an employee above.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($employees as $emp): 
                                $regular_hours = $emp['regular_hours'] ?? 0;
                                $total_ot = $emp['total_ot'] ?? 0;
                                $total_holiday = $emp['total_holiday'] ?? 0;
                                $gross = ($regular_hours * $emp['hourly_rate']) + 
                                         ($total_ot * $emp['hourly_rate'] * $emp['ot_rate']) + 
                                         ($total_holiday * $emp['hourly_rate'] * $emp['holiday_rate']);
                                $epf_employee = $gross * ($epf_employee_rate / 100);
                                $tax = $gross * ($emp['tax_rate'] / 100);
                                $advance = $emp['total_advance'] ?? 0;
                                $payout = $emp['total_payout'] ?? 0;
                                $net = $gross - $epf_employee - $tax - $advance - $payout;
                            ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($emp['name']); ?></td>
                                    <td><?php echo number_format($emp['hourly_rate'], 2); ?></td>
                                    <td><?php echo substr($emp['start_time'], 0, 5); ?></td>
                                    <td><?php echo substr($emp['end_time'], 0, 5); ?></td>
                                    <td><?php echo number_format($emp['ot_rate'], 1); ?></td>
                                    <td><?php echo number_format($emp['holiday_rate'], 1); ?></td>
                                    <td><?php echo number_format($emp['tax_rate'], 1); ?></td>
                                    <td><?php echo number_format($regular_hours, 2); ?></td>
                                    <td><?php echo number_format($total_ot, 2); ?></td>
                                    <td><?php echo number_format($total_holiday, 2); ?></td>
                                    <td><?php echo $emp['last_clock_in'] ? htmlspecialchars($emp['last_clock_in']) : '-'; ?></td>
                                    <td><?php echo $emp['last_clock_out'] ? htmlspecialchars($emp['last_clock_out']) : '-'; ?></td>
                                    <td><?php echo number_format($advance, 2); ?></td>
                                    <td><?php echo number_format($payout, 2); ?></td>
                                    <td><strong><?php echo number_format($net, 2); ?></strong></td>
                                    <td>
                                        <!-- Edit Button -->
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="employee_id" value="<?php echo $emp['id']; ?>">
                                            <button type="submit" name="edit_employee" class="btn btn-warning btn-sm me-2">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                        </form>
                                        <!-- Delete Button -->
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="employee_id" value="<?php echo $emp['id']; ?>">
                                            <button type="submit" name="delete_employee" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete <?php echo htmlspecialchars($emp['name']); ?>?');">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function updateClock() {
            const now = new Date().toLocaleString('en-US', { timeZone: 'Asia/Colombo' });
            document.getElementById('liveClock').textContent = now.split(', ')[1] + ' (Colombo)';
        }
        setInterval(updateClock, 1000);
        updateClock();
    </script>
</body>
</html>