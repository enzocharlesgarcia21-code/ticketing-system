CREATE DATABASE IF NOT EXISTS ticketing_system;
USE ticketing_system;

-- -------------------------
-- TABLE: users
-- -------------------------
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(100) NOT NULL UNIQUE,
  company VARCHAR(255) DEFAULT NULL,
  department VARCHAR(100) NOT NULL,
  password VARCHAR(255) NOT NULL,
  role ENUM('admin','employee') DEFAULT 'employee',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  otp_code VARCHAR(10) DEFAULT NULL,
  is_verified TINYINT(1) DEFAULT 0,
  reset_otp VARCHAR(10) DEFAULT NULL,
  reset_otp_expiry DATETIME DEFAULT NULL
);

-- -------------------------
-- TABLE: employee_tickets
-- -------------------------
CREATE TABLE employee_tickets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  company VARCHAR(255) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  category VARCHAR(100) NOT NULL,
  sub_category VARCHAR(255) DEFAULT NULL,
  priority ENUM('Low','Medium','High','Critical') NOT NULL,
  department ENUM('IT','HR','Marketing','Admin','Technical','Accounting','Supply Chain','MPDC','E-Comm') DEFAULT NULL,
  assigned_company VARCHAR(255) DEFAULT NULL,
  assigned_department ENUM('IT','HR','Marketing','Admin','Technical','Accounting','Supply Chain','MPDC','E-Comm') NOT NULL,
  description TEXT,
  admin_note TEXT,
  attachment VARCHAR(255) DEFAULT NULL,
  status ENUM('Open','In Progress','Resolved','Closed') DEFAULT 'Open',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT NULL,
  is_read TINYINT(1) DEFAULT 0,
  started_at DATETIME DEFAULT NULL,
  resolved_at DATETIME DEFAULT NULL,
  employee_update_unread TINYINT(1) DEFAULT 0
);

-- -------------------------
-- TABLE: knowledge_base
-- -------------------------
CREATE TABLE knowledge_base (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  content TEXT NOT NULL,
  image_path VARCHAR(255) DEFAULT NULL,
  category VARCHAR(100) NOT NULL,
  created_by INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  views INT DEFAULT 0,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- -------------------------
-- TABLE: notifications
-- -------------------------
CREATE TABLE notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  ticket_id INT NOT NULL,
  message TEXT NOT NULL,
  type VARCHAR(50) NOT NULL,
  is_read TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- -------------------------
-- TABLE: ticket_messages
-- -------------------------
CREATE TABLE ticket_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT NOT NULL,
  sender_id INT NOT NULL,
  message TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (ticket_id) REFERENCES employee_tickets(id) ON DELETE CASCADE,
  FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
);