-- Employees Table
CREATE TABLE IF NOT EXISTS employees (
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
);

-- Attendance Table
CREATE TABLE IF NOT EXISTS attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT,
    timestamp DATETIME,
    action ENUM('in', 'out'),
    overtime_hours DECIMAL(5,2) DEFAULT 0,
    holiday_hours DECIMAL(5,2) DEFAULT 0,
    is_holiday TINYINT(1) DEFAULT 0,
    is_sunday TINYINT(1) DEFAULT 0,
    latitude DECIMAL(10,6),  -- Added for geolocation
    longitude DECIMAL(10,6), -- Added for geolocation
    FOREIGN KEY (employee_id) REFERENCES employees(id)
);

-- Holidays Table
CREATE TABLE IF NOT EXISTS holidays (
    id INT AUTO_INCREMENT PRIMARY KEY,
    holiday_date DATE UNIQUE,
    description VARCHAR(100)
);

-- Leaves Table
CREATE TABLE IF NOT EXISTS leaves (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT,
    start_date DATE,
    end_date DATE,
    type ENUM('paid', 'unpaid') DEFAULT 'paid',
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    FOREIGN KEY (employee_id) REFERENCES employees(id)
);

-- Advance Payments Table
CREATE TABLE IF NOT EXISTS advance_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT,
    amount DECIMAL(10,2) NOT NULL,
    request_date DATE NOT NULL,
    status ENUM('pending', 'approved', 'deducted') DEFAULT 'pending',
    FOREIGN KEY (employee_id) REFERENCES employees(id)
);

-- Audit Logs Table
CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50),
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    role ENUM('admin', 'employee', 'unknown'),
    status ENUM('success', 'failure'),
    ip_address VARCHAR(45)
);

-- Settings Table
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_name VARCHAR(50) UNIQUE,
    setting_value DECIMAL(5,2) NOT NULL
);

-- Payouts Table
CREATE TABLE IF NOT EXISTS payouts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT,
    amount DECIMAL(10,2) NOT NULL,
    payout_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    status ENUM('pending', 'completed') DEFAULT 'pending',
    FOREIGN KEY (employee_id) REFERENCES employees(id)
);

-- Insert Default Settings
INSERT INTO settings (setting_name, setting_value) VALUES ('etf_rate', 3.00) ON DUPLICATE KEY UPDATE setting_value = 3.00;
INSERT INTO settings (setting_name, setting_value) VALUES ('epf_employee_rate', 8.00) ON DUPLICATE KEY UPDATE setting_value = 8.00;
INSERT INTO settings (setting_name, setting_value) VALUES ('epf_employer_rate', 12.00) ON DUPLICATE KEY UPDATE setting_value = 12.00;

-- Insert Default Admin User
INSERT INTO employees (username, password, name, role, hourly_rate)
SELECT 'admin', '$2y$10$XUR.9Z19s7TPtzH4/5QSee5hR8eXz5X5X5X5X5X5X5X5X5X5X5X5X', 'Administrator', 'admin', 0
WHERE NOT EXISTS (SELECT 1 FROM employees WHERE username = 'admin');