# Payroll-Website

## Overview
This is a web-based payroll management system developed by **Aaz Hazar**. It provides functionalities for employee attendance tracking, payroll calculation, leave management, advance payment requests, and administrative controls.  
The system is built using **PHP, MySQL, Bootstrap, and JavaScript**, with **geolocation features** for attendance tracking.

---

## 🚀 Live Demo
You can try the live demo version of the system here:  
🔗 **Demo Website:** [https://aazhazar.com/payroll/](https://aazhazar.com/payroll/)

### 🔑 Demo Login Credentials

**Admin Login**
- Username: `demo`
- Password: `demo`

**Employee Login**
- Username: `demo2`
- Password: `demo2`

---

## 📋 Prerequisites
- PHP **7.4** or higher  
- MySQL **5.7** or higher  
- Web server (**Apache** or **Nginx**)  
- Composer (optional, for dependency management)  
- A modern web browser with JavaScript and geolocation support enabled  

---

## ⚙️ Installation

### 1️⃣ Clone or Download the Repository
Copy all files to your web server's root directory:  
👉 [https://source.aazhazar.com/payroll/payroll.zip](https://source.aazhazar.com/payroll/payroll.zip)

### 2️⃣ Database Setup
1. Create a MySQL database and user with the following details (replace placeholders with your own values):
   - Database Name: `[YOUR_DATABASE_NAME]`
   - Username: `[YOUR_DATABASE_USERNAME]`
   - Password: `[YOUR_DATABASE_PASSWORD]`
   - Host: `localhost` (or your database host)
2. Update the database configuration in the following files:
   - `index.php`
   - `employee.php`
   - `admin.php`
   - `auditlog.php`

   Replace the lines with your actual database credentials.

3. Import the SQL commands provided below into your MySQL database to create the necessary tables.

### 3️⃣ File Configuration
- Ensure your web server has **write permissions** for session handling and file generation (e.g., CSV exports).  
- Update the **workplace coordinates** in `employee.php` if required.

### 4️⃣ Run the Application
Access the system via your web browser:  
➡️ `https://yourdomain/payroll/index.php`

**Default Admin Credentials**
- Username: `admin`
- Password: `admin123`

**Audit Log Password**
- Default: `admin` (change this in production)

---

## 💼 Features
- 🧭 **Employee Dashboard:** Clock in/out using geolocation, request leaves and advances, view payroll, and generate personal reports.  
- 🧑‍💼 **Admin Dashboard:** Manage employees, holidays, leaves, advances, payouts, and export payroll data.  
- 🧾 **Audit Log:** Tracks login attempts with IP addresses (password-protected).  
- 🔒 **Security:** Password hashing, secure sessions, and input sanitization.

---

## 🧠 Usage
1. **Login** with admin or employee credentials.  
2. **Attendance:** Employees must be within the allowed radius to clock in/out.  
3. **Payroll:** Automatically calculates regular, overtime, and holiday pay, including EPF, ETF, and tax deductions.  
4. **Reports:** Export payroll data as CSV or generate individual employee reports.

---

## ⚠️ Notes
- Remove `ini_set('display_errors', 1);` and `error_reporting(E_ALL);` in production for security.  
- Enable **HTTPS** in production to secure geolocation and session data.  
- Test geolocation functionality on a device with **location services enabled**.

---

## 💬 Support
For any issues or inquiries, please contact:  
📧 **Email:** [aaz@aazhazar.com](mailto:aaz@aazhazar.com)  
🌐 **Website:** [https://aazhazar.com/](https://aazhazar.com/)

---

**Developed by [Aaz Hazar](https://aazhazar.com/)** © 2025
