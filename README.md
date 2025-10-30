# Payroll-Website

## Overview
This is a web-based payroll management system developed by Aaz Hazar. It provides functionalities for employee attendance tracking, payroll calculation, leave management, advance payment requests, and administrative controls. The system is built using PHP, MySQL, Bootstrap, and JavaScript, with geolocation features for attendance tracking.

## Prerequisites
- PHP 7.4 or higher
- MySQL 5.7 or higher
- Web server (e.g., Apache, Nginx)
- Composer (optional, for dependency management)
- A modern web browser with JavaScript and geolocation support enabled

## Installation

### 1. Clone or Download the Repository
- Copy all files to your web server's root directory (https://source.aazhazar.com/payroll/payroll.zip).

### 2. Database Setup
- Create a MySQL database and user with the following details (replace placeholders with your own values):
  - Database Name: [YOUR_DATABASE_NAME]
  - Username: [YOUR_DATABASE_USERNAME]
  - Password: [YOUR_DATABASE_PASSWORD]
  - Host: localhost (or your database host)

- Update the database configuration in the following files:
  - `index.php`
  - `employee.php`
  - `admin.php`
  - `auditlog.php`

  Replace the following lines with your credentials:




- Import the SQL commands provided below into your MySQL database to create the necessary tables.

### 3. File Configuration
- Ensure the web server has write permissions for session handling and file generation (e.g., CSV exports).
- Update the workplace coordinates in `employee.php` if needed:




### 4. Run the Application
- Access the system via your web browser: `https://yourdomain/payroll/index.php`
- Default admin credentials:
- Username: admin
- Password: admin123
- Audit log password (in `auditlog.php`):
- Password: admin (change this in production)

### Features
- **Employee Dashboard**: Clock in/out with geolocation, request leaves/advances, view payroll, generate personal reports.
- **Admin Dashboard**: Manage employees, holidays, leaves, advances, payouts, and export payroll data.
- **Audit Log**: Track login attempts with IP addresses (password-protected).
- **Security**: Password hashing, session management, and input sanitization.

### Usage
1. **Login**: Use the default admin credentials or create employee accounts via the admin dashboard.
2. **Attendance**: Employees must be within the allowed radius to clock in/out.
3. **Payroll**: Automatically calculates regular, overtime, and holiday hours with EPF, ETF, and tax deductions.
4. **Reports**: Export payroll data as CSV or generate personal reports.

### Notes
- Remove `ini_set('display_errors', 1);` and `error_reporting(E_ALL);` in production for security.
- Ensure HTTPS is enabled in production to secure geolocation and session data.
- Test geolocation functionality on a device with location services enabled.

### Support
For issues or inquiries, contact:
- Email: aaz@aazhazar.com
- Website: https://aazhazar.com/

---
Developed by Aaz hazar © 2025
