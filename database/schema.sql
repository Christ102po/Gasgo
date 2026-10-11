-- GasGo schema for Railway MySQL
-- Import into your Railway MySQL database (empty database only).
-- It intentionally does NOT contain CREATE DATABASE or USE statements.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS managers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gas_stations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  manager_id INT UNSIGNED NULL,
  name VARCHAR(150) NOT NULL,
  address VARCHAR(255) NOT NULL,
  barangay VARCHAR(120) NOT NULL,
  description TEXT NULL,
  latitude DECIMAL(10,7) NOT NULL,
  longitude DECIMAL(10,7) NOT NULL,
  operating_hours VARCHAR(120) NULL,
  diesel_price DECIMAL(10,2) NULL,
  unleaded_price DECIMAL(10,2) NULL,
  premium_price DECIMAL(10,2) NULL,
  lpg_available TINYINT(1) NOT NULL DEFAULT 0,
  lpg_price DECIMAL(10,2) NULL,
  contact_number VARCHAR(40) NULL,
  image VARCHAR(255) NULL,
  status ENUM('Pending','Approved','Needs changes','Rejected') NOT NULL DEFAULT 'Approved',
  premium_available TINYINT(1) NOT NULL DEFAULT 1,
  regular_available TINYINT(1) NOT NULL DEFAULT 1,
  diesel_available TINYINT(1) NOT NULL DEFAULT 1,
  fuel_prices_updated_at DATETIME NULL,
  availability_updated_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_station_manager (manager_id),
  INDEX idx_station_status (status),
  INDEX idx_barangay (barangay),
  INDEX idx_location (latitude, longitude),
  CONSTRAINT fk_station_manager FOREIGN KEY (manager_id) REFERENCES managers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS station_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  manager_id INT UNSIGNED NOT NULL,
  station_name VARCHAR(150) NOT NULL,
  address VARCHAR(255) NOT NULL,
  barangay VARCHAR(120) NOT NULL,
  description TEXT NULL,
  latitude DECIMAL(10,7) NOT NULL,
  longitude DECIMAL(10,7) NOT NULL,
  operating_hours VARCHAR(120) NULL,
  diesel_price DECIMAL(10,2) NULL,
  unleaded_price DECIMAL(10,2) NULL,
  premium_price DECIMAL(10,2) NULL,
  lpg_available TINYINT(1) NOT NULL DEFAULT 0,
  lpg_price DECIMAL(10,2) NULL,
  contact_number VARCHAR(40) NULL,
  image VARCHAR(255) NULL,
  status ENUM('Pending','Approved','Needs changes','Rejected') NOT NULL DEFAULT 'Pending',
  admin_feedback TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_request_manager_status (manager_id, status),
  CONSTRAINT fk_request_manager FOREIGN KEY (manager_id) REFERENCES managers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS station_request_lpg_products (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id INT UNSIGNED NOT NULL,
  kg DECIMAL(8,2) NOT NULL,
  price DECIMAL(10,2) NOT NULL,
  available TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_request_lpg_size (request_id, kg),
  CONSTRAINT fk_request_lpg_request FOREIGN KEY (request_id) REFERENCES station_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS station_lpg_products (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  station_id INT UNSIGNED NOT NULL,
  kg DECIMAL(8,2) NOT NULL,
  price DECIMAL(10,2) NOT NULL,
  available TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_station_lpg_size (station_id, kg),
  CONSTRAINT fk_station_lpg_station FOREIGN KEY (station_id) REFERENCES gas_stations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reviews (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  station_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  rating TINYINT UNSIGNED NOT NULL,
  comment TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_review_station (station_id),
  CONSTRAINT fk_review_station FOREIGN KEY (station_id) REFERENCES gas_stations(id) ON DELETE CASCADE,
  CONSTRAINT chk_rating CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS faqs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  question VARCHAR(255) NOT NULL,
  answer TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
