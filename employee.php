<?php


session_start();

// Turn on error reporting (remove this in production)
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Set default timezone (Sri Lanka)
date_default_timezone_set('Asia/Colombo');

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'YOUR_DATABASE_NAME');
define('DB_USER', 'YOUR_DATABASE_USER');
define('DB_PASS', 'YOUR_DATABASE_PASSWORD');

// Workplace coordinates for Colombo, Sri Lanka
define('WORKPLACE_LAT', 28.532057998123193); // Latitude (North)
define('WORKPLACE_LNG', 77.26398598135316); // Longitude (East)
define('ALLOWED_RADIUS', 0.04); // 40 meters in kilometers

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'employee') {
    header("Location: index.php");
    exit;
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

// Get employee details
try {
    $stmt = $pdo->prepare("SELECT name, hourly_rate, start_time, end_time, ot_rate, holiday_rate, tax_rate FROM employees WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$employee) {
        die("Employee not found.");
    }
} catch(PDOException $e) {
    die("Error fetching employee details: " . $e->getMessage());
}

// Function to calculate distance between two coordinates (Haversine formula)
function calculateDistance($lat1, $lng1, $lat2, $lng2) {
    $earth_radius = 6371; // Earth's radius in kilometers
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) * sin($dLat / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) * sin($dLng / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earth_radius * $c; // Distance in kilometers
}

// Mark attendance with geolocation check
if (isset($_POST['attendance'])) {
    $action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_SPECIAL_CHARS);
    $latitude = floatval($_POST['latitude'] ?? 0);
    $longitude = floatval($_POST['longitude'] ?? 0);

    // Debug: Log received coordinates
    error_log("Received coordinates: Lat=$latitude, Lng=$longitude");

    if (!$action || !in_array($action, ['in', 'out'])) {
        $error = "Invalid attendance action";
    } elseif ($latitude == 0 || $longitude == 0) {
        $error = "Unable to get your location. Please enable location services and try again.";
    } else {
        // Check if employee is within workplace radius
        $distance = calculateDistance($latitude, $longitude, WORKPLACE_LAT, WORKPLACE_LNG);
        error_log("Calculated distance: $distance km from Lat=$latitude, Lng=$longitude to Lat=" . WORKPLACE_LAT . ", Lng=" . WORKPLACE_LNG);

        if ($distance > ALLOWED_RADIUS) {
            $error = "You must be at the workplace to clock $action. Distance: " . number_format($distance * 1000, 2) . " meters.";
        } else {
            $current_time = new DateTime('now', new DateTimeZone('Asia/Colombo'));
            $is_sunday = (int)($current_time->format('N') == 7);
            $overtime_hours = 0;
            $holiday_hours = 0;

            try {
                $stmt = $pdo->prepare("SELECT action FROM attendance WHERE employee_id = ? ORDER BY timestamp DESC LIMIT 1");
                $stmt->execute([$_SESSION['user_id']]);
                $last_action = $stmt->fetchColumn();

                if ($action == 'in' && $last_action == 'in') {
                    $error = "You are already clocked in. Please clock out first.";
                } elseif ($action == 'out' && ($last_action == 'out' || !$last_action)) {
                    $error = "You need to clock in before clocking out.";
                } else {
                    $is_holiday = (int)($pdo->query("SELECT COUNT(*) FROM holidays WHERE holiday_date = CURDATE()")->fetchColumn() > 0) || $is_sunday ? 1 : 0;

                    if ($action == 'out') {
                        $stmt = $pdo->prepare("SELECT timestamp FROM attendance WHERE employee_id = ? AND action = 'in' ORDER BY timestamp DESC LIMIT 1");
                        $stmt->execute([$_SESSION['user_id']]);
                        $last_in = $stmt->fetchColumn();
                        
                        if ($last_in) {
                            $clock_in = new DateTime($last_in, new DateTimeZone('Asia/Colombo'));
                            $diff = $current_time->diff($clock_in);
                            $total_hours = $diff->h + ($diff->i / 60) + ($diff->s / 3600);

                            $start_time = DateTime::createFromFormat('H:i:s', $employee['start_time'], new DateTimeZone('Asia/Colombo'));
                            $start_time->setDate((int)$clock_in->format('Y'), (int)$clock_in->format('m'), (int)$clock_in->format('d'));
                            $end_time = DateTime::createFromFormat('H:i:s', $employee['end_time'], new DateTimeZone('Asia/Colombo'));
                            $end_time->setDate((int)$clock_in->format('Y'), (int)$clock_in->format('m'), (int)$clock_in->format('d'));

                            if ($is_holiday) {
                                $holiday_hours = $total_hours;
                            } else {
                                if ($current_time <= $end_time) {
                                    $overtime_hours = 0;
                                } else {
                                    $regular_diff = $end_time->diff($clock_in);
                                    $regular_hours = $regular_diff->h + ($regular_diff->i / 60) + ($regular_diff->s / 3600);
                                    $overtime_hours = max(0, $total_hours - $regular_hours);
                                }
                            }
                        } else {
                            $error = "No clock-in found for this clock-out.";
                        }
                    }
                    if (!isset($error)) {
                        $stmt = $pdo->prepare("INSERT INTO attendance (employee_id, timestamp, action, overtime_hours, holiday_hours, is_holiday, is_sunday, latitude, longitude) 
                            VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$_SESSION['user_id'], $action, $overtime_hours, $holiday_hours, $is_holiday, $is_sunday, $latitude, $longitude]);
                        $success = "Attendance recorded successfully at location: Lat $latitude, Lng $longitude";
                    }
                }
            } catch(PDOException $e) {
                $error = "Error recording attendance: " . $e->getMessage();
            }
        }
    }
}

// Request advance payment
if (isset($_POST['request_advance'])) {
    $amount = floatval($_POST['advance_amount']);
    if ($amount <= 0) {
        $error = "Invalid advance amount.";
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO advance_payments (employee_id, amount, request_date) VALUES (?, ?, CURDATE())");
            $stmt->execute([$_SESSION['user_id'], $amount]);
            $success = "Advance payment request submitted.";
        } catch(PDOException $e) {
            $error = "Error requesting advance: " . $e->getMessage();
        }
    }
}

// Request leave
if (isset($_POST['request_leave'])) {
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $type = $_POST['leave_type'];
    try {
        $stmt = $pdo->prepare("INSERT INTO leaves (employee_id, start_date, end_date, type) VALUES (?, ?, ?, ?)");
        $stmt->execute([$_SESSION['user_id'], $start_date, $end_date, $type]);
        $success = "Leave request submitted";
    } catch(PDOException $e) {
        $error = "Error requesting leave: " . $e->getMessage();
    }
}

// Generate Personal Report
if (isset($_POST['generate_personal_report'])) {
    $start_date = $_POST['report_start_date'];
    $end_date = $_POST['report_end_date'];

    try {
        $stmt = $pdo->prepare("
            SELECT 
                e.name, e.hourly_rate, e.start_time, e.end_time, e.ot_rate, e.holiday_rate, e.tax_rate,
                a.timestamp, a.action, a.overtime_hours, a.holiday_hours, a.is_holiday, a.is_sunday,
                (SELECT SUM(amount) FROM advance_payments ap WHERE ap.employee_id = e.id AND ap.status = 'approved' AND ap.request_date BETWEEN ? AND ?) as total_advance
            FROM employees e
            LEFT JOIN attendance a ON e.id = a.employee_id AND a.timestamp BETWEEN ? AND ?
            WHERE e.id = ?
            ORDER BY a.timestamp
        ");
        $stmt->execute([$start_date, $end_date, $start_date, $end_date, $_SESSION['user_id']]);
        $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $filename = "personal_report_{$start_date}_to_{$end_date}.csv";
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, [
            'Name', 'Hourly Rate', 'Start Time', 'End Time', 'OT Rate', 'Holiday Rate', 'Tax Rate (%)',
            'Clock In/Out Time', 'Action', 'OT Hours', 'Holiday Hours', 'Is Holiday', 'Is Sunday',
            'Total Advance', 'Gross Earnings', 'EPF Employee', 'EPF Employer', 'ETF Employer', 'Tax', 'Net Earnings'
        ]);

        $regular_hours = 0;
        $total_ot = 0;
        $total_holiday = 0;
        $last_in = null;

        foreach ($report_data as $row) {
            if ($row['action'] == 'in') {
                $last_in = $row['timestamp'];
            } elseif ($row['action'] == 'out' && $last_in) {
                $diff = (strtotime($row['timestamp']) - strtotime($last_in)) / 3600;
                if (!$row['is_holiday'] && !$row['is_sunday']) {
                    $regular_hours += $diff;
                }
                $last_in = null;
            }
            $total_ot += $row['overtime_hours'] ?? 0;
            $total_holiday += $row['holiday_hours'] ?? 0;

            $gross = ($regular_hours * $row['hourly_rate']) + 
                     ($total_ot * $row['hourly_rate'] * $row['ot_rate']) + 
                     ($total_holiday * $row['hourly_rate'] * $row['holiday_rate']);
            $epf_employee = $gross * ($epf_employee_rate / 100);
            $epf_employer = $gross * ($epf_employer_rate / 100);
            $etf_employer = $gross * ($etf_rate / 100);
            $tax = $gross * ($row['tax_rate'] / 100);
            $advance = $row['total_advance'] ?? 0;
            $net = $gross - $epf_employee - $tax - $advance;

            fputcsv($output, [
                $row['name'],
                number_format($row['hourly_rate'], 2),
                $row['start_time'],
                $row['end_time'],
                $row['ot_rate'],
                $row['holiday_rate'],
                $row['tax_rate'],
                $row['timestamp'] ?? '-',
                $row['action'] ?? '-',
                number_format($row['overtime_hours'] ?? 0, 2),
                number_format($row['holiday_hours'] ?? 0, 2),
                $row['is_holiday'] ? 'Yes' : 'No',
                $row['is_sunday'] ? 'Yes' : 'No',
                number_format($advance, 2),
                number_format($gross, 2),
                number_format($epf_employee, 2),
                number_format($epf_employer, 2),
                number_format($etf_employer, 2),
                number_format($tax, 2),
                number_format($net, 2)
            ]);
        }
        
        fclose($output);
        exit;
    } catch(PDOException $e) {
        $error = "Error generating report: " . $e->getMessage();
    }
}

// Get attendance, leaves, advances, and payroll
try {
    $stmt = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ? ORDER BY timestamp DESC LIMIT 10");
    $stmt->execute([$_SESSION['user_id']]);
    $attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM leaves WHERE employee_id = ? ORDER BY start_date DESC LIMIT 5");
    $stmt->execute([$_SESSION['user_id']]);
    $leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM advance_payments WHERE employee_id = ? ORDER BY request_date DESC LIMIT 5");
    $stmt->execute([$_SESSION['user_id']]);
    $advances = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $start_of_month = date('Y-m-01');
    $stmt = $pdo->prepare("
        SELECT 
            SUM(overtime_hours) as total_ot, 
            SUM(holiday_hours) as total_holiday,
            SUM(CASE 
                WHEN action = 'out' AND is_holiday = 0 AND is_sunday = 0 THEN 
                    TIMESTAMPDIFF(MICROSECOND, 
                        (SELECT timestamp FROM attendance a2 
                         WHERE a2.employee_id = a.employee_id 
                         AND a2.action = 'in' 
                         AND a2.timestamp < a.timestamp 
                         ORDER BY a2.timestamp DESC LIMIT 1), 
                        a.timestamp) / 3600000000.0 
                ELSE 0 
            END) as regular_hours
        FROM attendance a 
        WHERE employee_id = ? AND timestamp >= ?
    ");
    $stmt->execute([$_SESSION['user_id'], $start_of_month]);
    $payroll = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $regular_hours = $payroll['regular_hours'] ?? 0;
    $gross_earnings = ($regular_hours * $employee['hourly_rate']) + 
                     (($payroll['total_ot'] ?? 0) * $employee['hourly_rate'] * $employee['ot_rate']) + 
                     (($payroll['total_holiday'] ?? 0) * $employee['hourly_rate'] * $employee['holiday_rate']);
    
    $epf_employee = $gross_earnings * ($epf_employee_rate / 100);
    $epf_employer = $gross_earnings * ($epf_employer_rate / 100);
    $etf_employer = $gross_earnings * ($etf_rate / 100);
    
    $tax_deduction = $gross_earnings * ($employee['tax_rate'] / 100);
    $total_deductions = $epf_employee + $tax_deduction;
    
    $stmt = $pdo->prepare("SELECT SUM(amount) as total_advance FROM advance_payments WHERE employee_id = ? AND status = 'approved'");
    $stmt->execute([$_SESSION['user_id']]);
    $total_advance = $stmt->fetchColumn() ?? 0;
    $net_earnings = $gross_earnings - $total_deductions - $total_advance;

    if ($total_advance > 0 && $net_earnings >= $total_advance) {
        $pdo->prepare("UPDATE advance_payments SET status = 'deducted' WHERE employee_id = ? AND status = 'approved'")->execute([$_SESSION['user_id']]);
    }
} catch(PDOException $e) {
    die("Error fetching data: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Employee Dashboard</title>
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
        .btn-success, .btn-primary {
            background: linear-gradient(45deg, #28a745, #34d058);
            border: none;
            border-radius: 10px;
            padding: 10px 20px;
            transition: all 0.3s ease;
        }
        .btn-success:hover, .btn-primary:hover {
            background: linear-gradient(45deg, #218838, #2eb84c);
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
        .progress {
            height: 25px;
            border-radius: 10px;
            background: #e9ecef;
            box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        .progress-bar {
            background: linear-gradient(45deg, #28a745, #34d058);
            transition: width 0.6s ease;
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
        #submitAttendance { display: none; }
        @media (max-width: 576px) {
            h2, h3 { font-size: 1.2rem; }
            .table { font-size: 0.9rem; }
            .form-control { font-size: 0.9rem; }
            .progress { height: 20px; }
            #liveClock { font-size: 0.9rem; padding: 3px 10px; }
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="#"><i class="fas fa-briefcase me-2"></i>Payroll</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <div class="navbar-nav ms-auto">
                    <span id="liveClock" class="me-3"></span>
                    <a class="nav-link" href="index.php?logout=1"><i class="fas fa-sign-out-alt me-2"></i>Logout</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="card">
            <h2 class="mb-4"><i class="fas fa-user dashboard-icon"></i>Welcome, <?php echo htmlspecialchars($employee['name']); ?></h2>
            <?php 
            if (isset($success)) echo "<div class='alert alert-success'><i class='fas fa-check-circle me-2'></i>$success</div>";
            if (isset($error)) echo "<div class='alert alert-danger'><i class='fas fa-exclamation-triangle me-2'></i>$error</div>"; 
            ?>

            <!-- Attendance -->
            <h3><i class="fas fa-clock dashboard-icon"></i>Attendance (Last 10 Records)</h3>
            <form method="post" id="attendanceForm" class="mb-4">
                <div class="mb-3">
                    <div class="form-check form-check-inline">
                        <input type="radio" name="action" value="in" class="form-check-input" required>
                        <label class="form-check-label">Clock In</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input type="radio" name="action" value="out" class="form-check-input" required>
                        <label class="form-check-label">Clock Out</label>
                    </div>
                </div>
                <input type="hidden" name="latitude" id="latitude">
                <input type="hidden" name="longitude" id="longitude">
                <button type="button" id="getLocation" class="btn btn-success"><i class="fas fa-clock me-2"></i>Submit</button>
                <button type="submit" name="attendance" id="submitAttendance" class="btn btn-success"><i class="fas fa-clock me-2"></i>Submit</button>
            </form>
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead class="table-dark">
                        <tr>
                            <th>Date/Time</th>
                            <th>Action</th>
                            <th>OT (hrs)</th>
                            <th>Holiday (hrs)</th>
                            <th>Sunday</th>
                            <th>Location</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($attendance as $record): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($record['timestamp']); ?></td>
                                <td><span class="badge <?php echo $record['action'] == 'in' ? 'bg-success' : 'bg-danger'; ?>"><?php echo htmlspecialchars($record['action']); ?></span></td>
                                <td><?php echo number_format($record['overtime_hours'], 2); ?></td>
                                <td><?php echo number_format($record['holiday_hours'], 2); ?></td>
                                <td><?php echo $record['is_sunday'] ? '<span class="badge bg-warning">Yes</span>' : 'No'; ?></td>
                                <td><?php echo $record['latitude'] && $record['longitude'] ? "Lat: " . number_format($record['latitude'], 6) . ", Lng: " . number_format($record['longitude'], 6) : '-'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Advance Payment Request -->
            <h3><i class="fas fa-money-check-alt dashboard-icon"></i>Request Advance Payment (Last 5 Requests)</h3>
            <form method="post" class="mb-4">
                <div class="mb-3">
                    <label class="form-label">Amount (LKR)</label>
                    <input type="number" name="advance_amount" class="form-control" step="0.01" min="1" required>
                </div>
                <button type="submit" name="request_advance" class="btn btn-primary"><i class="fas fa-hand-holding-usd me-2"></i>Request</button>
            </form>
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead class="table-dark">
                        <tr>
                            <th>Request Date</th>
                            <th>Amount (LKR)</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($advances as $advance): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($advance['request_date']); ?></td>
                                <td><?php echo number_format($advance['amount'], 2); ?></td>
                                <td><span class="badge <?php echo $advance['status'] == 'approved' ? 'bg-success' : ($advance['status'] == 'pending' ? 'bg-warning' : 'bg-info'); ?>"><?php echo htmlspecialchars($advance['status']); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Leave Request -->
            <h3><i class="fas fa-calendar-times dashboard-icon"></i>Request Leave (Last 5 Requests)</h3>
            <form method="post" class="mb-4">
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Start Date</label>
                        <input type="date" name="start_date" class="form-control" required>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">End Date</label>
                        <input type="date" name="end_date" class="form-control" required>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Type</label>
                        <select name="leave_type" class="form-control" required>
                            <option value="paid">Paid</option>
                            <option value="unpaid">Unpaid</option>
                        </select>
                    </div>
                </div>
                <button type="submit" name="request_leave" class="btn btn-primary"><i class="fas fa-paper-plane me-2"></i>Submit Request</button>
            </form>
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead class="table-dark">
                        <tr>
                            <th>Start Date</th>
                            <th>End Date</th>
                            <th>Type</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($leaves as $leave): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($leave['start_date']); ?></td>
                                <td><?php echo htmlspecialchars($leave['end_date']); ?></td>
                                <td><?php echo htmlspecialchars($leave['type']); ?></td>
                                <td><span class="badge <?php echo $leave['status'] == 'approved' ? 'bg-success' : ($leave['status'] == 'pending' ? 'bg-warning' : 'bg-danger'); ?>"><?php echo htmlspecialchars($leave['status']); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Payroll -->
            <h3><i class="fas fa-money-bill dashboard-icon"></i>Payroll (This Month)</h3>
            <div class="progress mb-3">
                <div class="progress-bar" role="progressbar" style="width: <?php echo min(100, ($regular_hours + ($payroll['total_ot'] ?? 0) + ($payroll['total_holiday'] ?? 0)) / 160 * 100); ?>%;" aria-valuenow="<?php echo $regular_hours + ($payroll['total_ot'] ?? 0) + ($payroll['total_holiday'] ?? 0); ?>" aria-valuemin="0" aria-valuemax="160">Hours Worked</div>
            </div>
            <div class="alert alert-info">
                <p><i class="fas fa-clock me-2"></i>Regular: <?php echo number_format($regular_hours, 2); ?> hrs</p>
                <p><i class="fas fa-plus-circle me-2"></i>OT: <?php echo number_format($payroll['total_ot'] ?? 0, 2); ?> hrs (<?php echo $employee['ot_rate']; ?>x)</p>
                <p><i class="fas fa-calendar-day me-2"></i>Holiday: <?php echo number_format($payroll['total_holiday'] ?? 0, 2); ?> hrs (<?php echo $employee['holiday_rate']; ?>x)</p>
                <p><i class="fas fa-coins me-2"></i>Gross: LKR <?php echo number_format($gross_earnings, 2); ?></p>
                <p><i class="fas fa-hand-holding-usd me-2"></i>EPF (Employee <?php echo number_format($epf_employee_rate, 2); ?>%): LKR <?php echo number_format($epf_employee, 2); ?></p>
                <p><i class="fas fa-building me-2"></i>EPF (Employer <?php echo number_format($epf_employer_rate, 2); ?>%): LKR <?php echo number_format($epf_employer, 2); ?></p>
                <p><i class="fas fa-handshake me-2"></i>ETF (Employer <?php echo number_format($etf_rate, 2); ?>%): LKR <?php echo number_format($etf_employer, 2); ?></p>
                <p><i class="fas fa-percentage me-2"></i>Tax (<?php echo $employee['tax_rate']; ?>%): LKR <?php echo number_format($tax_deduction, 2); ?></p>
                <p><i class="fas fa-minus-circle me-2"></i>Advance Deduction: LKR <?php echo number_format($total_advance, 2); ?></p>
                <p><i class="fas fa-wallet me-2"></i>Net: LKR <strong><?php echo number_format($net_earnings, 2); ?></strong></p>
            </div>

            <!-- Generate Personal Report -->
            <h3><i class="fas fa-file-alt dashboard-icon"></i>Generate Personal Report</h3>
            <form method="post" class="mb-4">
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Start Date</label>
                        <input type="date" name="report_start_date" class="form-control" required>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">End Date</label>
                        <input type="date" name="report_end_date" class="form-control" required>
                    </div>
                    <div class="col-md-4 mb-3 d-flex align-items-end">
                        <button type="submit" name="generate_personal_report" class="btn btn-primary w-100"><i class="fas fa-download me-2"></i>Generate Report</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Live Clock for Colombo, Sri Lanka
        function updateClock() {
            const now = new Date().toLocaleString('en-US', { timeZone: 'Asia/Colombo' });
            document.getElementById('liveClock').textContent = now.split(', ')[1] + ' (Colombo)';
        }
        setInterval(updateClock, 1000);
        updateClock();

        // Geolocation for Attendance
        document.getElementById('getLocation').addEventListener('click', function() {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    function(position) {
                        const lat = position.coords.latitude;
                        const lng = position.coords.longitude;
                        console.log('Captured Location: Latitude=' + lat + ', Longitude=' + lng);
                        document.getElementById('latitude').value = lat;
                        document.getElementById('longitude').value = lng;
                        document.getElementById('submitAttendance').click();
                    },
                    function(error) {
                        alert('Error getting location: ' + error.message + '. Please enable location services.');
                    },
                    { enableHighAccuracy: true, timeout: 5000, maximumAge: 0 }
                );
            } else {
                alert('Geolocation is not supported by your browser.');
            }
        });
    </script>
</body>
</html>