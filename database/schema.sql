-- Sweet Bakers — Ops Console schema
-- MySQL 8. Run once against a fresh database (see README for order).

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------
-- users & auth
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(160) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','manager','baker','store','customer') NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  phone VARCHAR(30),
  address TEXT,
  payment_method VARCHAR(60),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- suppliers
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS suppliers (
  id VARCHAR(10) PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  contact VARCHAR(60),
  email VARCHAR(160),
  lead_days INT NOT NULL DEFAULT 2,
  supplies_summary VARCHAR(255)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- ingredients
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ingredients (
  id VARCHAR(10) PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  uom ENUM('kg','L','pc') NOT NULL,
  unit_cost DECIMAL(10,2) NOT NULL,
  reorder_level DECIMAL(10,3) NOT NULL,
  supplier_id VARCHAR(10) NULL,
  used_last_7d DECIMAL(10,3) NOT NULL DEFAULT 0,
  low_stock_notified TINYINT(1) NOT NULL DEFAULT 0,
  CONSTRAINT fk_ing_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- inventory batches (FEFO source of truth)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS batches (
  id VARCHAR(10) PRIMARY KEY,
  ingredient_id VARCHAR(10) NOT NULL,
  supplier_id VARCHAR(10) NULL,
  received_qty DECIMAL(10,3) NOT NULL,
  qty_on_hand DECIMAL(10,3) NOT NULL,
  unit_cost DECIMAL(10,2) NOT NULL,
  expiry_date DATE NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_batch_ing FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE,
  CONSTRAINT fk_batch_sup FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
  INDEX idx_batch_fefo (ingredient_id, expiry_date)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- products
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS products (
  id VARCHAR(10) PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  emoji VARCHAR(10),
  price DECIMAL(10,2) NOT NULL,
  shelf_stock INT NOT NULL DEFAULT 0,
  description VARCHAR(255),
  avg_weekly_sales DECIMAL(10,2) NOT NULL DEFAULT 10
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- recipe lines (junction: product -> ingredient qty-per-unit)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS recipe_lines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id VARCHAR(10) NOT NULL,
  ingredient_id VARCHAR(10) NOT NULL,
  qty_per_unit DECIMAL(10,4) NOT NULL,
  CONSTRAINT fk_recipe_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_recipe_ing FOREIGN KEY (ingredient_id) REFERENCES ingredients(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- purchase orders + lines
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS purchase_orders (
  id VARCHAR(10) PRIMARY KEY,
  supplier_id VARCHAR(10) NOT NULL,
  status ENUM('Draft','Sent','Received','Cancelled') NOT NULL DEFAULT 'Draft',
  is_auto TINYINT(1) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  eta_days INT,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  sent_at TIMESTAMP NULL,
  received_at TIMESTAMP NULL,
  CONSTRAINT fk_po_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
  CONSTRAINT fk_po_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS purchase_order_lines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  po_id VARCHAR(10) NOT NULL,
  ingredient_id VARCHAR(10) NOT NULL,
  qty DECIMAL(10,3) NOT NULL,
  unit_cost DECIMAL(10,2) NOT NULL,
  CONSTRAINT fk_poline_po FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_poline_ing FOREIGN KEY (ingredient_id) REFERENCES ingredients(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- wastage log
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS wastage (
  id VARCHAR(10) PRIMARY KEY,
  ingredient_id VARCHAR(10) NOT NULL,
  batch_id VARCHAR(10) NULL,
  qty DECIMAL(10,3) NOT NULL,
  reason ENUM('Expired','Damaged/Spoiled','Over-Production','Prep-Loss/Spillage','Customer-Return') NOT NULL,
  cost DECIMAL(10,2) NOT NULL,
  is_auto TINYINT(1) NOT NULL DEFAULT 0,
  logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_waste_ing FOREIGN KEY (ingredient_id) REFERENCES ingredients(id),
  CONSTRAINT fk_waste_batch FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- production runs
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS production_runs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  run_by INT NULL,
  run_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  status VARCHAR(30) DEFAULT 'Completed',
  CONSTRAINT fk_run_user FOREIGN KEY (run_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS production_run_lines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  run_id INT NOT NULL,
  product_id VARCHAR(10) NOT NULL,
  qty INT NOT NULL,
  CONSTRAINT fk_runline_run FOREIGN KEY (run_id) REFERENCES production_runs(id) ON DELETE CASCADE,
  CONSTRAINT fk_runline_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- orders (customer/POS) + lines + timeline
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
  id VARCHAR(12) PRIMARY KEY,
  customer_id INT NULL,
  customer_name VARCHAR(160) NOT NULL,
  phone VARCHAR(30),
  total DECIMAL(12,2) NOT NULL,
  status ENUM('Pending','Preparing','Ready','Out for delivery','Delivered','Cancelled') NOT NULL DEFAULT 'Pending',
  order_type ENUM('Online','POS') NOT NULL DEFAULT 'Online',
  mode ENUM('Delivery','Pickup') NOT NULL DEFAULT 'Delivery',
  address VARCHAR(255),
  payment_method VARCHAR(60),
  note VARCHAR(255),
  driver_name VARCHAR(120), vehicle_type VARCHAR(60), vehicle_no VARCHAR(60),
  driver_phone VARCHAR(30), eta VARCHAR(60), delivered_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_order_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_lines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id VARCHAR(12) NOT NULL,
  product_id VARCHAR(10) NOT NULL,
  qty INT NOT NULL,
  unit_price DECIMAL(10,2) NOT NULL,
  CONSTRAINT fk_orderline_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_orderline_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_timeline (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id VARCHAR(12) NOT NULL,
  event VARCHAR(255) NOT NULL,
  happened_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_timeline_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- daily sales rollup (feeds the 7-day sales chart)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sales_daily (
  sale_date DATE PRIMARY KEY,
  total DECIMAL(12,2) NOT NULL DEFAULT 0
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- notifications
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  type ENUM('bad','warn','info','good') NOT NULL,
  icon VARCHAR(10),
  title VARCHAR(160) NOT NULL,
  message VARCHAR(255),
  category ENUM('inventory','orders','purchasing','production','catalogue') NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- audit log
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  user_name VARCHAR(160),
  action VARCHAR(60) NOT NULL,
  detail VARCHAR(255),
  happened_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- id sequence counters (PO/ORD/batch/waste/generic ids get a human-readable prefix+number)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS id_sequences (
  name VARCHAR(20) PRIMARY KEY,
  next_value INT NOT NULL
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;
