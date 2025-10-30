<?php


session_start();

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

define('AUDIT_PASSWORD', 'admin');

// Handle password submission
if (isset($_POST['audit_password'])) {
    if ($_POST['audit_password'] === AUDIT_PASSWORD) {
        $_SESSION['audit_access'] = true;
    } else {
        $error = "Incorrect password";
    }
}

if (!isset($_SESSION['audit_access']) || $_SESSION['audit_access'] !== true) {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Audit Log - Access</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body { background: #f8f9fa; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
            .card { max-width: 400px; width: 100%; padding: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.15); border-radius: 15px; }
            .btn-primary { background: #007bff; border: none; width: 100%; }
            .btn-primary:hover { background: #0056b3; }
        </style>
    </head>
    <body>
        <div class="card">
            <h2 class="text-center mb-4">Audit Log Password</h2>
            <?php if (isset($error)) echo "<div class='alert alert-danger'>$error</div>"; ?>
            <form method="post">
                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <input type="password" name="audit_password" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-primary">Submit</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Fetch audit logs
try {
    $stmt = $pdo->query("SELECT * FROM audit_logs ORDER BY timestamp DESC");
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $error = "Error fetching logs: " . $e->getMessage();
}

// Logout
if (isset($_GET['logout'])) {
    unset($_SESSION['audit_access']);
    header("Location: auditlog.php");
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Audit Log</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; padding: 20px; }
        .navbar { background: #343a40; }
        .card { box-shadow: 0 4px 20px rgba(0,0,0,0.15); border-radius: 15px; padding: 20px; margin-top: 20px; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="#">Audit Log</a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="auditlog.php?logout=1">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="card">
            <h2>Audit Logs</h2>
            <?php if (isset($error)) echo "<div class='alert alert-danger'>$error</div>"; ?>
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>IP Address</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($log['timestamp']); ?></td>
                            <td><?php echo htmlspecialchars($log['username']); ?></td>
                            <td><?php echo htmlspecialchars($log['role']); ?></td>
                            <td><?php echo htmlspecialchars($log['status']); ?></td>
                            <td><?php echo htmlspecialchars($log['ip_address']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>