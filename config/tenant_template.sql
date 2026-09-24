-- 1. Hospital Profile
CREATE TABLE IF NOT EXISTS `org_profile` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `org_name` VARCHAR(150) NOT NULL,
    `tagline` VARCHAR(255) NULL,
    `email` VARCHAR(100) NULL,
    `phone` VARCHAR(30) NULL,
    `address` TEXT NULL,
    `city` VARCHAR(100) NULL,
    `state` VARCHAR(100) NULL,
    `pincode` VARCHAR(20) NULL,
    `logo` VARCHAR(255) NULL,
    `status` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2. Master Centers
CREATE TABLE IF NOT EXISTS `master_centers` (
    `center_id` INT AUTO_INCREMENT PRIMARY KEY,
    `center_name` VARCHAR(150) NOT NULL,
    `center_code` VARCHAR(50) NOT NULL UNIQUE,
    `contact_person` VARCHAR(100) NULL,
    `contact_no` VARCHAR(30) NULL,
    `address` TEXT NULL,
    `status` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 3. Tenant Roles
CREATE TABLE IF NOT EXISTS `tenant_roles` (
    `role_id` INT AUTO_INCREMENT PRIMARY KEY,
    `role_name` VARCHAR(100) NOT NULL UNIQUE,
    `description` VARCHAR(255) NULL,
    `status` TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

-- 4. Tenant Users / Staff with Menu Access
CREATE TABLE IF NOT EXISTS `tenant_users` (
    `user_id` INT AUTO_INCREMENT PRIMARY KEY,
    `center_id` INT DEFAULT 1,
    `role_id` INT NOT NULL,
    `fullname` VARCHAR(120) NOT NULL,
    `username` VARCHAR(100) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `mobile` VARCHAR(20) NULL,
    `email` VARCHAR(100) NULL,
    `menu_access` TEXT NULL,
    `is_admin` TINYINT(1) DEFAULT 0,
    `status` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`role_id`) REFERENCES `tenant_roles`(`role_id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 5. OPD Patients (UHID Base)
CREATE TABLE IF NOT EXISTS `opd_patients` (
    `patient_id` INT AUTO_INCREMENT PRIMARY KEY,
    `uhid` VARCHAR(50) NOT NULL UNIQUE,
    `center_id` INT DEFAULT 1,
    `fullname` VARCHAR(150) NOT NULL,
    `gender` VARCHAR(20) NOT NULL,
    `age` INT NOT NULL,
    `age_unit` VARCHAR(20) DEFAULT 'Years',
    `mobile` VARCHAR(20) NOT NULL,
    `address` TEXT NULL,
    `status` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 6. OPD Visits / Queue
CREATE TABLE IF NOT EXISTS `opd_visits` (
    `visit_id` INT AUTO_INCREMENT PRIMARY KEY,
    `patient_id` INT NOT NULL,
    `center_id` INT DEFAULT 1,
    `doctor_id` INT DEFAULT 0,
    `token_no` INT NOT NULL,
    `visit_date` DATE NOT NULL,
    `consultation_fee` DECIMAL(10,2) DEFAULT 0.00,
    `payment_status` TINYINT(1) NOT NULL DEFAULT 1,
    `status` INT NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`patient_id`) REFERENCES `opd_patients`(`patient_id`) ON DELETE CASCADE
) ENGINE=InnoDB;