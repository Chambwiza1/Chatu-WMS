-- Chatu Warehouse schema. {P} is replaced by the table prefix.
-- Platform tables (from Chatu Assets) first, then master data, stock, documents, fleet.

CREATE TABLE IF NOT EXISTS {P}settings (
  name VARCHAR(100) NOT NULL PRIMARY KEY,
  value TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {P}users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(60) NOT NULL UNIQUE,
  email VARCHAR(190) NULL,
  phone VARCHAR(40) NULL,
  name VARCHAR(150) NOT NULL,
  position VARCHAR(100) NULL,
  password_hash VARCHAR(255) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  failed_logins INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {P}roles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  description VARCHAR(255) NULL,
  rank_level TINYINT UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {P}permissions (
  code VARCHAR(60) NOT NULL PRIMARY KEY,
  description VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {P}role_permissions (
  role_id INT UNSIGNED NOT NULL,
  permission_code VARCHAR(60) NOT NULL,
  PRIMARY KEY (role_id, permission_code),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES {P}roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY (permission_code) REFERENCES {P}permissions(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A role is held for the whole company (system) or for one warehouse.
CREATE TABLE IF NOT EXISTS {P}user_roles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  role_id INT UNSIGNED NOT NULL,
  scope_type ENUM('system','warehouse') NOT NULL DEFAULT 'system',
  scope_id INT UNSIGNED NULL,
  scope_key INT UNSIGNED AS (COALESCE(scope_id, 0)) STORED,
  UNIQUE KEY uq_user_role (user_id, role_id, scope_type, scope_key),
  CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES {P}users(id),
  CONSTRAINT fk_ur_role FOREIGN KEY (role_id) REFERENCES {P}roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {P}sod_rules (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_a_id INT UNSIGNED NOT NULL,
  role_b_id INT UNSIGNED NOT NULL,
  description VARCHAR(255) NOT NULL,
  CONSTRAINT fk_sod_a FOREIGN KEY (role_a_id) REFERENCES {P}roles(id),
  CONSTRAINT fk_sod_b FOREIGN KEY (role_b_id) REFERENCES {P}roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {P}notifications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  type VARCHAR(40) NOT NULL,
  title VARCHAR(255) NOT NULL,
  body VARCHAR(1000) NULL,
  link VARCHAR(255) NULL,
  read_at DATETIME NULL,
  emailed_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  KEY idx_notif_user (user_id, read_at),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES {P}users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {P}op_tokens (
  token CHAR(36) NOT NULL PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  result TEXT NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {P}audit_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  at DATETIME(3) NOT NULL,
  effective_date DATE NULL,
  user_id INT UNSIGNED NULL,
  username VARCHAR(60) NULL,
  on_behalf_of INT UNSIGNED NULL,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  app_version VARCHAR(20) NULL,
  device_id VARCHAR(64) NULL,
  action VARCHAR(80) NOT NULL,
  object_type VARCHAR(40) NULL,
  object_id VARCHAR(40) NULL,
  request_id INT UNSIGNED NULL,
  before_json LONGTEXT NULL,
  after_json LONGTEXT NULL,
  outcome ENUM('success','denied','failed') NOT NULL DEFAULT 'success',
  detail VARCHAR(500) NULL,
  prev_hash CHAR(64) NOT NULL,
  hash CHAR(64) NOT NULL,
  KEY idx_audit_obj (object_type, object_id),
  KEY idx_audit_user (user_id),
  KEY idx_audit_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {P}jobs_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job VARCHAR(60) NOT NULL,
  started_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  outcome VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {P}attachments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_type VARCHAR(30) NOT NULL,
  owner_id INT UNSIGNED NOT NULL,
  filename VARCHAR(255) NOT NULL,
  stored_name VARCHAR(100) NOT NULL,
  mime VARCHAR(100) NOT NULL,
  size INT UNSIGNED NOT NULL,
  uploaded_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  KEY idx_att_owner (owner_type, owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Document numbers: PO-2026-00001 and so on.
CREATE TABLE IF NOT EXISTS {P}doc_counters (
  prefix VARCHAR(10) NOT NULL,
  year SMALLINT UNSIGNED NOT NULL,
  next_no INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (prefix, year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== Master data =====

CREATE TABLE IF NOT EXISTS {P}item_categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {P}items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sku VARCHAR(40) NOT NULL UNIQUE,
  barcode VARCHAR(60) NULL,
  name VARCHAR(150) NOT NULL,
  description VARCHAR(500) NULL,
  category_id INT UNSIGNED NULL,
  kind ENUM('goods','raw_material','finished_good','packaging','consumable') NOT NULL DEFAULT 'goods',
  uom VARCHAR(12) NOT NULL DEFAULT 'each',
  decimals TINYINT UNSIGNED NOT NULL DEFAULT 0,
  tracking ENUM('none','batch','serial','batch_serial') NOT NULL DEFAULT 'none',
  expiry_required TINYINT(1) NOT NULL DEFAULT 0,
  shelf_life_days INT UNSIGNED NULL,
  min_shelf_days INT UNSIGNED NOT NULL DEFAULT 0,
  pick_policy ENUM('fifo','fefo') NULL,
  weight_kg DECIMAL(12,3) NULL,
  volume_m3 DECIMAL(12,4) NULL,
  reorder_level DECIMAL(18,3) NOT NULL DEFAULT 0,
  reorder_qty DECIMAL(18,3) NOT NULL DEFAULT 0,
  last_cost DECIMAL(18,4) NOT NULL DEFAULT 0,
  sell_price DECIMAL(18,2) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  KEY idx_item_barcode (barcode),
  CONSTRAINT fk_item_cat FOREIGN KEY (category_id) REFERENCES {P}item_categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {P}suppliers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(30) NOT NULL UNIQUE,
  name VARCHAR(150) NOT NULL,
  contact VARCHAR(100) NULL,
  phone VARCHAR(40) NULL,
  email VARCHAR(190) NULL,
  address VARCHAR(255) NULL,
  tax_no VARCHAR(40) NULL,
  lead_days INT UNSIGNED NOT NULL DEFAULT 0,
  notes VARCHAR(500) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {P}customers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(30) NOT NULL UNIQUE,
  name VARCHAR(150) NOT NULL,
  contact VARCHAR(100) NULL,
  phone VARCHAR(40) NULL,
  email VARCHAR(190) NULL,
  address VARCHAR(255) NULL,
  town VARCHAR(100) NULL,
  tax_no VARCHAR(40) NULL,
  min_shelf_days INT UNSIGNED NOT NULL DEFAULT 0,
  notes VARCHAR(500) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {P}reasons (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(30) NOT NULL,
  label VARCHAR(100) NOT NULL,
  use_for ENUM('adjust','supplier_return','failed_delivery','customer_return','hold') NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_reason (use_for, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== Warehouses =====

CREATE TABLE IF NOT EXISTS {P}warehouses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NOT NULL UNIQUE,
  name VARCHAR(150) NOT NULL,
  kind ENUM('warehouse','depot','factory','store') NOT NULL DEFAULT 'warehouse',
  address VARCHAR(255) NULL,
  town VARCHAR(100) NULL,
  pick_policy ENUM('fifo','fefo') NOT NULL DEFAULT 'fefo',
  receiving_bin_id INT UNSIGNED NULL,
  stock_bin_id INT UNSIGNED NULL,
  dispatch_bin_id INT UNSIGNED NULL,
  quarantine_bin_id INT UNSIGNED NULL,
  damaged_bin_id INT UNSIGNED NULL,
  production_bin_id INT UNSIGNED NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {P}zones (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  warehouse_id INT UNSIGNED NOT NULL,
  code VARCHAR(20) NOT NULL,
  name VARCHAR(100) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_zone (warehouse_id, code),
  CONSTRAINT fk_zone_wh FOREIGN KEY (warehouse_id) REFERENCES {P}warehouses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {P}bins (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  warehouse_id INT UNSIGNED NOT NULL,
  zone_id INT UNSIGNED NULL,
  code VARCHAR(30) NOT NULL,
  type ENUM('receiving','storage','picking','dispatch','quarantine','damaged','production') NOT NULL DEFAULT 'storage',
  status ENUM('active','blocked','inactive') NOT NULL DEFAULT 'active',
  pickable TINYINT(1) NOT NULL DEFAULT 1,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  sort INT NOT NULL DEFAULT 0,
  note VARCHAR(255) NULL,
  UNIQUE KEY uq_bin (warehouse_id, code),
  CONSTRAINT fk_bin_wh FOREIGN KEY (warehouse_id) REFERENCES {P}warehouses(id),
  CONSTRAINT fk_bin_zone FOREIGN KEY (zone_id) REFERENCES {P}zones(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== Lots, serials, stock =====

CREATE TABLE IF NOT EXISTS {P}lots (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id INT UNSIGNED NOT NULL,
  lot_no VARCHAR(60) NOT NULL,
  supplier_lot VARCHAR(60) NULL,
  mfg_date DATE NULL,
  expiry_date DATE NULL,
  source ENUM('receipt','production','opening','adjustment','count') NOT NULL,
  source_id INT UNSIGNED NULL,
  supplier_id INT UNSIGNED NULL,
  status ENUM('active','on_hold','recalled') NOT NULL DEFAULT 'active',
  hold_reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_lot (item_id, lot_no),
  KEY idx_lot_expiry (expiry_date),
  CONSTRAINT fk_lot_item FOREIGN KEY (item_id) REFERENCES {P}items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per item and serial number, for all time.
CREATE TABLE IF NOT EXISTS {P}serials (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id INT UNSIGNED NOT NULL,
  serial_no VARCHAR(80) NOT NULL,
  lot_id INT UNSIGNED NULL,
  status ENUM('in_stock','in_transit','dispatched','returned_supplier','scrapped','consumed','lost') NOT NULL DEFAULT 'in_stock',
  warehouse_id INT UNSIGNED NULL,
  customer_id INT UNSIGNED NULL,
  last_doc VARCHAR(40) NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_serial (item_id, serial_no),
  CONSTRAINT fk_serial_item FOREIGN KEY (item_id) REFERENCES {P}items(id),
  CONSTRAINT fk_serial_lot FOREIGN KEY (lot_id) REFERENCES {P}lots(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every unit of stock sits in exactly one row: warehouse, bin, item, lot, serial, status.
CREATE TABLE IF NOT EXISTS {P}stock (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  warehouse_id INT UNSIGNED NOT NULL,
  bin_id INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  lot_id INT UNSIGNED NULL,
  serial_id INT UNSIGNED NULL,
  status ENUM('available','quarantine','damaged','hold') NOT NULL DEFAULT 'available',
  qty DECIMAL(18,3) NOT NULL DEFAULT 0,
  qty_allocated DECIMAL(18,3) NOT NULL DEFAULT 0,
  lot_key INT UNSIGNED AS (COALESCE(lot_id, 0)) STORED,
  serial_key INT UNSIGNED AS (COALESCE(serial_id, 0)) STORED,
  serial_live INT UNSIGNED AS (IF(serial_id IS NOT NULL AND qty > 0, serial_id, NULL)) STORED,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_stock (bin_id, item_id, lot_key, serial_key, status),
  UNIQUE KEY uq_stock_serial (serial_live),
  KEY idx_stock_item (warehouse_id, item_id),
  KEY idx_stock_lot (lot_id),
  CONSTRAINT chk_stock_qty CHECK (qty >= 0 AND qty_allocated >= 0 AND qty_allocated <= qty),
  CONSTRAINT chk_stock_serial CHECK (serial_id IS NULL OR qty <= 1),
  CONSTRAINT fk_stock_wh FOREIGN KEY (warehouse_id) REFERENCES {P}warehouses(id),
  CONSTRAINT fk_stock_bin FOREIGN KEY (bin_id) REFERENCES {P}bins(id),
  CONSTRAINT fk_stock_item FOREIGN KEY (item_id) REFERENCES {P}items(id),
  CONSTRAINT fk_stock_lot FOREIGN KEY (lot_id) REFERENCES {P}lots(id),
  CONSTRAINT fk_stock_serial FOREIGN KEY (serial_id) REFERENCES {P}serials(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per warehouse and item, locked by every operation that changes that item's stock there.
CREATE TABLE IF NOT EXISTS {P}stock_locks (
  warehouse_id INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (warehouse_id, item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only ledger. Inflow: to_bin only. Outflow: from_bin only. Move: both.
CREATE TABLE IF NOT EXISTS {P}movements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  at DATETIME NOT NULL,
  warehouse_id INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  lot_id INT UNSIGNED NULL,
  serial_id INT UNSIGNED NULL,
  qty DECIMAL(18,3) NOT NULL,
  from_bin_id INT UNSIGNED NULL,
  to_bin_id INT UNSIGNED NULL,
  from_status VARCHAR(12) NULL,
  to_status VARCHAR(12) NULL,
  type VARCHAR(30) NOT NULL,
  doc_type VARCHAR(30) NULL,
  doc_id INT UNSIGNED NULL,
  doc_line_id INT UNSIGNED NULL,
  value DECIMAL(18,2) NULL,
  reason_code VARCHAR(30) NULL,
  note VARCHAR(255) NULL,
  user_id INT UNSIGNED NULL,
  KEY idx_mv_item (warehouse_id, item_id, at),
  KEY idx_mv_doc (doc_type, doc_id),
  KEY idx_mv_lot (lot_id),
  KEY idx_mv_serial (serial_id),
  CONSTRAINT chk_mv_qty CHECK (qty > 0),
  CONSTRAINT chk_mv_bins CHECK (from_bin_id IS NOT NULL OR to_bin_id IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FIFO cost layers per item and warehouse. Quantity left always equals the stock on hand.
CREATE TABLE IF NOT EXISTS {P}cost_layers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  warehouse_id INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  lot_id INT UNSIGNED NULL,
  received_at DATETIME NOT NULL,
  source_type VARCHAR(30) NOT NULL,
  source_id INT UNSIGNED NULL,
  qty_in DECIMAL(18,3) NOT NULL,
  qty_left DECIMAL(18,3) NOT NULL,
  unit_cost DECIMAL(18,4) NOT NULL,
  KEY idx_layer_open (warehouse_id, item_id, qty_left),
  CONSTRAINT chk_layer CHECK (qty_left >= 0 AND qty_left <= qty_in AND unit_cost >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What each outflow took from which layer.
CREATE TABLE IF NOT EXISTS {P}cost_entries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  at DATETIME NOT NULL,
  layer_id INT UNSIGNED NOT NULL,
  warehouse_id INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  qty DECIMAL(18,3) NOT NULL,
  unit_cost DECIMAL(18,4) NOT NULL,
  movement_id BIGINT UNSIGNED NULL,
  doc_type VARCHAR(30) NOT NULL,
  doc_id INT UNSIGNED NULL,
  KEY idx_ce_doc (doc_type, doc_id),
  KEY idx_ce_layer (layer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reservations of stock rows for documents (sales orders, transfers, production).
CREATE TABLE IF NOT EXISTS {P}allocations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stock_id INT UNSIGNED NOT NULL,
  doc_type VARCHAR(30) NOT NULL,
  doc_id INT UNSIGNED NOT NULL,
  doc_line_id INT UNSIGNED NOT NULL,
  qty DECIMAL(18,3) NOT NULL,
  stage ENUM('reserved','picked') NOT NULL DEFAULT 'reserved',
  status ENUM('active','released','consumed') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL,
  KEY idx_alloc_doc (doc_type, doc_id, status),
  KEY idx_alloc_stock (stock_id, status),
  CONSTRAINT chk_alloc CHECK (qty > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== Purchasing and receiving =====

CREATE TABLE IF NOT EXISTS {P}purchase_orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(20) NOT NULL UNIQUE,
  supplier_id INT UNSIGNED NOT NULL,
  warehouse_id INT UNSIGNED NOT NULL,
  status ENUM('draft','open','partly_received','received','closed','cancelled') NOT NULL DEFAULT 'draft',
  order_date DATE NOT NULL,
  expected_date DATE NULL,
  supplier_ref VARCHAR(60) NULL,
  notes VARCHAR(500) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  issued_by INT UNSIGNED NULL,
  issued_at DATETIME NULL,
  CONSTRAINT fk_po_sup FOREIGN KEY (supplier_id) REFERENCES {P}suppliers(id),
  CONSTRAINT fk_po_wh FOREIGN KEY (warehouse_id) REFERENCES {P}warehouses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {P}po_lines (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  po_id INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  qty_ordered DECIMAL(18,3) NOT NULL,
  qty_received DECIMAL(18,3) NOT NULL DEFAULT 0,
  unit_cost DECIMAL(18,4) NOT NULL DEFAULT 0,
  CONSTRAINT fk_pol_po FOREIGN KEY (po_id) REFERENCES {P}purchase_orders(id),
  CONSTRAINT fk_pol_item FOREIGN KEY (item_id) REFERENCES {P}items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {P}receipts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(20) NOT NULL UNIQUE,
  warehouse_id INT UNSIGNED NOT NULL,
  supplier_id INT UNSIGNED NULL,
  po_id INT UNSIGNED NULL,
  kind ENUM('purchase','opening') NOT NULL DEFAULT 'purchase',
  received_on DATE NOT NULL,
  delivery_note VARCHAR(60) NULL,
  carrier VARCHAR(100) NULL,
  notes VARCHAR(500) NULL,
  value DECIMAL(18,2) NOT NULL DEFAULT 0,
  posted_by INT UNSIGNED NULL,
  posted_at DATETIME NOT NULL,
  CONSTRAINT fk_rcp_wh FOREIGN KEY (warehouse_id) REFERENCES {P}warehouses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {P}receipt_lines (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  receipt_id INT UNSIGNED NOT NULL,
  po_line_id INT UNSIGNED NULL,
  item_id INT UNSIGNED NOT NULL,
  bin_id INT UNSIGNED NOT NULL,
  lot_id INT UNSIGNED NULL,
  qty_accepted DECIMAL(18,3) NOT NULL DEFAULT 0,
  qty_quarantine DECIMAL(18,3) NOT NULL DEFAULT 0,
  qty_rejected DECIMAL(18,3) NOT NULL DEFAULT 0,
  unit_cost DECIMAL(18,4) NOT NULL DEFAULT 0,
  serials TEXT NULL,
  reject_reason VARCHAR(255) NULL,
  CONSTRAINT fk_rcl_rcp FOREIGN KEY (receipt_id) REFERENCES {P}receipts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {P}supplier_returns (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(20) NOT NULL UNIQUE,
  warehouse_id INT UNSIGNED NOT NULL,
  supplier_id INT UNSIGNED NOT NULL,
  receipt_id INT UNSIGNED NULL,
  reason_code VARCHAR(30) NOT NULL,
  notes VARCHAR(500) NULL,
  value DECIMAL(18,2) NOT NULL DEFAULT 0,
  posted_by INT UNSIGNED NULL,
  posted_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stock moves inside a warehouse: put-away, bin to bin, status changes, holds and releases.
CREATE TABLE IF NOT EXISTS {P}moves (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(20) NOT NULL UNIQUE,
  warehouse_id INT UNSIGNED NOT NULL,
  kind ENUM('putaway','move','status') NOT NULL DEFAULT 'move',
  note VARCHAR(255) NULL,
  user_id INT UNSIGNED NULL,
  at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== Transfers between warehouses =====

CREATE TABLE IF NOT EXISTS {P}transfers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(20) NOT NULL UNIQUE,
  from_warehouse_id INT UNSIGNED NOT NULL,
  to_warehouse_id INT UNSIGNED NOT NULL,
  status ENUM('draft','allocated','in_transit','partly_received','received','cancelled') NOT NULL DEFAULT 'draft',
  needed_by DATE NULL,
  trip_id INT UNSIGNED NULL,
  notes VARCHAR(500) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  dispatched_by INT UNSIGNED NULL,
  dispatched_at DATETIME NULL,
  closed_at DATETIME NULL,
  CONSTRAINT fk_tr_from FOREIGN KEY (from_warehouse_id) REFERENCES {P}warehouses(id),
  CONSTRAINT fk_tr_to FOREIGN KEY (to_warehouse_id) REFERENCES {P}warehouses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {P}transfer_lines (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transfer_id INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  qty DECIMAL(18,3) NOT NULL,
  qty_dispatched DECIMAL(18,3) NOT NULL DEFAULT 0,
  qty_received DECIMAL(18,3) NOT NULL DEFAULT 0,
  qty_damaged DECIMAL(18,3) NOT NULL DEFAULT 0,
  qty_rejected DECIMAL(18,3) NOT NULL DEFAULT 0,
  qty_missing DECIMAL(18,3) NOT NULL DEFAULT 0,
  value_sent DECIMAL(18,2) NOT NULL DEFAULT 0,
  value_missing DECIMAL(18,2) NOT NULL DEFAULT 0,
  CONSTRAINT fk_trl_tr FOREIGN KEY (transfer_id) REFERENCES {P}transfers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Goods in transit: what left, per lot and serial, and how much of it is still on the way.
CREATE TABLE IF NOT EXISTS {P}transit (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transfer_id INT UNSIGNED NOT NULL,
  transfer_line_id INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  lot_id INT UNSIGNED NULL,
  serial_id INT UNSIGNED NULL,
  qty DECIMAL(18,3) NOT NULL,
  qty_open DECIMAL(18,3) NOT NULL,
  KEY idx_transit_tr (transfer_id),
  CONSTRAINT chk_transit CHECK (qty_open >= 0 AND qty_open <= qty)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The cost of what is in transit, in the FIFO order it left the sending warehouse.
CREATE TABLE IF NOT EXISTS {P}transit_costs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transfer_id INT UNSIGNED NOT NULL,
  transfer_line_id INT UNSIGNED NOT NULL,
  received_at DATETIME NOT NULL,
  qty DECIMAL(18,3) NOT NULL,
  qty_open DECIMAL(18,3) NOT NULL,
  unit_cost DECIMAL(18,4) NOT NULL,
  KEY idx_tc_line (transfer_line_id),
  CONSTRAINT chk_tc CHECK (qty_open >= 0 AND qty_open <= qty)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {P}transfer_receipts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transfer_id INT UNSIGNED NOT NULL,
  is_final TINYINT(1) NOT NULL DEFAULT 0,
  notes VARCHAR(500) NULL,
  received_by INT UNSIGNED NULL,
  received_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== Sales and distribution =====

CREATE TABLE IF NOT EXISTS {P}sales_orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(20) NOT NULL UNIQUE,
  customer_id INT UNSIGNED NOT NULL,
  warehouse_id INT UNSIGNED NOT NULL,
  status ENUM('draft','confirmed','in_progress','dispatched','delivered','closed','cancelled') NOT NULL DEFAULT 'draft',
  order_date DATE NOT NULL,
  required_date DATE NULL,
  customer_ref VARCHAR(60) NULL,
  delivery_address VARCHAR(255) NULL,
  notes VARCHAR(500) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_so_cust FOREIGN KEY (customer_id) REFERENCES {P}customers(id),
  CONSTRAINT fk_so_wh FOREIGN KEY (warehouse_id) REFERENCES {P}warehouses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {P}so_lines (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  so_id INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  qty DECIMAL(18,3) NOT NULL,
  qty_shipped DECIMAL(18,3) NOT NULL DEFAULT 0,
  qty_delivered DECIMAL(18,3) NOT NULL DEFAULT 0,
  qty_returned DECIMAL(18,3) NOT NULL DEFAULT 0,
  unit_price DECIMAL(18,2) NOT NULL DEFAULT 0,
  CONSTRAINT fk_sol_so FOREIGN KEY (so_id) REFERENCES {P}sales_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {P}shipments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(20) NOT NULL UNIQUE,
  so_id INT UNSIGNED NOT NULL,
  warehouse_id INT UNSIGNED NOT NULL,
  status ENUM('allocated','picked','packed','dispatched','delivered','failed','cancelled') NOT NULL DEFAULT 'allocated',
  packages INT UNSIGNED NOT NULL DEFAULT 0,
  weight_kg DECIMAL(12,3) NULL,
  trip_id INT UNSIGNED NULL,
  value DECIMAL(18,2) NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  picked_by INT UNSIGNED NULL,
  picked_at DATETIME NULL,
  packed_at DATETIME NULL,
  dispatched_by INT UNSIGNED NULL,
  dispatched_at DATETIME NULL,
  delivered_at DATETIME NULL,
  pod_name VARCHAR(100) NULL,
  pod_note VARCHAR(500) NULL,
  failed_reason VARCHAR(255) NULL,
  KEY idx_ship_so (so_id),
  CONSTRAINT fk_ship_so FOREIGN KEY (so_id) REFERENCES {P}sales_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {P}shipment_lines (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shipment_id INT UNSIGNED NOT NULL,
  so_line_id INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  qty DECIMAL(18,3) NOT NULL,
  qty_picked DECIMAL(18,3) NOT NULL DEFAULT 0,
  qty_returned DECIMAL(18,3) NOT NULL DEFAULT 0,
  CONSTRAINT fk_shl_ship FOREIGN KEY (shipment_id) REFERENCES {P}shipments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What physically left on a shipment, per lot and serial, at what cost (traceability and returns).
CREATE TABLE IF NOT EXISTS {P}shipment_units (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shipment_id INT UNSIGNED NOT NULL,
  shipment_line_id INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  lot_id INT UNSIGNED NULL,
  serial_id INT UNSIGNED NULL,
  qty DECIMAL(18,3) NOT NULL,
  qty_returned DECIMAL(18,3) NOT NULL DEFAULT 0,
  value DECIMAL(18,2) NOT NULL DEFAULT 0,
  KEY idx_shu_ship (shipment_id),
  KEY idx_shu_lot (lot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {P}customer_returns (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(20) NOT NULL UNIQUE,
  shipment_id INT UNSIGNED NOT NULL,
  warehouse_id INT UNSIGNED NOT NULL,
  kind ENUM('failed_delivery','customer_return') NOT NULL,
  reason_code VARCHAR(30) NOT NULL,
  notes VARCHAR(500) NULL,
  value DECIMAL(18,2) NOT NULL DEFAULT 0,
  posted_by INT UNSIGNED NULL,
  posted_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== Production =====

CREATE TABLE IF NOT EXISTS {P}recipes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(30) NOT NULL UNIQUE,
  name VARCHAR(150) NOT NULL,
  output_item_id INT UNSIGNED NOT NULL,
  output_qty DECIMAL(18,3) NOT NULL,
  other_cost DECIMAL(18,2) NOT NULL DEFAULT 0,
  notes VARCHAR(500) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_rec_item FOREIGN KEY (output_item_id) REFERENCES {P}items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {P}recipe_lines (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  recipe_id INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  qty DECIMAL(18,3) NOT NULL,
  CONSTRAINT fk_recl_rec FOREIGN KEY (recipe_id) REFERENCES {P}recipes(id) ON DELETE CASCADE,
  CONSTRAINT fk_recl_item FOREIGN KEY (item_id) REFERENCES {P}items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {P}production_runs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(20) NOT NULL UNIQUE,
  recipe_id INT UNSIGNED NOT NULL,
  warehouse_id INT UNSIGNED NOT NULL,
  status ENUM('posted','voided') NOT NULL DEFAULT 'posted',
  batches DECIMAL(18,3) NOT NULL,
  expected_qty DECIMAL(18,3) NOT NULL,
  actual_qty DECIMAL(18,3) NOT NULL,
  output_item_id INT UNSIGNED NOT NULL,
  output_bin_id INT UNSIGNED NOT NULL,
  output_status ENUM('available','quarantine') NOT NULL DEFAULT 'available',
  lot_id INT UNSIGNED NULL,
  material_cost DECIMAL(18,2) NOT NULL DEFAULT 0,
  other_cost DECIMAL(18,2) NOT NULL DEFAULT 0,
  unit_cost DECIMAL(18,4) NOT NULL DEFAULT 0,
  notes VARCHAR(500) NULL,
  produced_on DATE NOT NULL,
  posted_by INT UNSIGNED NULL,
  posted_at DATETIME NOT NULL,
  voided_by INT UNSIGNED NULL,
  voided_at DATETIME NULL,
  void_reason VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {P}production_inputs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  run_id INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  planned_qty DECIMAL(18,3) NOT NULL,
  bin_id INT UNSIGNED NOT NULL,
  lot_id INT UNSIGNED NULL,
  serial_id INT UNSIGNED NULL,
  qty DECIMAL(18,3) NOT NULL,
  value DECIMAL(18,2) NOT NULL DEFAULT 0,
  KEY idx_pi_run (run_id),
  KEY idx_pi_lot (lot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== Adjustments and counts =====

CREATE TABLE IF NOT EXISTS {P}adjustments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(20) NOT NULL UNIQUE,
  warehouse_id INT UNSIGNED NOT NULL,
  status ENUM('pending','posted','rejected') NOT NULL DEFAULT 'pending',
  reason_code VARCHAR(30) NOT NULL,
  notes VARCHAR(500) NULL,
  value DECIMAL(18,2) NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  decided_by INT UNSIGNED NULL,
  decided_at DATETIME NULL,
  decision_note VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {P}adjustment_lines (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  adjustment_id INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  bin_id INT UNSIGNED NOT NULL,
  lot_id INT UNSIGNED NULL,
  lot_no VARCHAR(60) NULL,
  expiry_date DATE NULL,
  serial_no VARCHAR(80) NULL,
  status ENUM('available','quarantine','damaged','hold') NOT NULL DEFAULT 'available',
  qty DECIMAL(18,3) NOT NULL,
  unit_cost DECIMAL(18,4) NULL,
  value DECIMAL(18,2) NOT NULL DEFAULT 0,
  CONSTRAINT fk_adjl_adj FOREIGN KEY (adjustment_id) REFERENCES {P}adjustments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {P}counts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(20) NOT NULL UNIQUE,
  warehouse_id INT UNSIGNED NOT NULL,
  zone_id INT UNSIGNED NULL,
  item_id INT UNSIGNED NULL,
  blind TINYINT(1) NOT NULL DEFAULT 1,
  status ENUM('open','submitted','posted','cancelled') NOT NULL DEFAULT 'open',
  notes VARCHAR(500) NULL,
  value DECIMAL(18,2) NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  submitted_by INT UNSIGNED NULL,
  submitted_at DATETIME NULL,
  posted_by INT UNSIGNED NULL,
  posted_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {P}count_lines (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  count_id INT UNSIGNED NOT NULL,
  bin_id INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  lot_id INT UNSIGNED NULL,
  serial_id INT UNSIGNED NULL,
  status ENUM('available','quarantine','damaged','hold') NOT NULL DEFAULT 'available',
  expected_qty DECIMAL(18,3) NOT NULL DEFAULT 0,
  counted_qty DECIMAL(18,3) NULL,
  posted_qty DECIMAL(18,3) NULL,
  is_extra TINYINT(1) NOT NULL DEFAULT 0,
  counted_by INT UNSIGNED NULL,
  KEY idx_cl_count (count_id),
  CONSTRAINT fk_cl_count FOREIGN KEY (count_id) REFERENCES {P}counts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== Fleet =====

CREATE TABLE IF NOT EXISTS {P}vehicles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reg_no VARCHAR(20) NOT NULL UNIQUE,
  make VARCHAR(60) NULL,
  model VARCHAR(60) NULL,
  body ENUM('truck','van','pickup','car','motorbike','trailer','other') NOT NULL DEFAULT 'truck',
  ownership ENUM('own','hired','contractor') NOT NULL DEFAULT 'own',
  home_warehouse_id INT UNSIGNED NULL,
  capacity_kg DECIMAL(12,1) NULL,
  capacity_m3 DECIMAL(12,2) NULL,
  odometer_km INT UNSIGNED NOT NULL DEFAULT 0,
  insurance_expiry DATE NULL,
  fitness_expiry DATE NULL,
  road_tax_expiry DATE NULL,
  next_service_date DATE NULL,
  next_service_km INT UNSIGNED NULL,
  status ENUM('available','on_trip','in_service','retired') NOT NULL DEFAULT 'available',
  notes VARCHAR(500) NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {P}drivers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  phone VARCHAR(40) NULL,
  licence_no VARCHAR(40) NULL,
  licence_class VARCHAR(20) NULL,
  licence_expiry DATE NULL,
  user_id INT UNSIGNED NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  notes VARCHAR(500) NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {P}vehicle_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vehicle_id INT UNSIGNED NOT NULL,
  kind ENUM('fuel','service','repair','inspection','incident') NOT NULL,
  on_date DATE NOT NULL,
  odometer_km INT UNSIGNED NULL,
  litres DECIMAL(10,2) NULL,
  cost DECIMAL(18,2) NOT NULL DEFAULT 0,
  trip_id INT UNSIGNED NULL,
  note VARCHAR(500) NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  KEY idx_vlog (vehicle_id, on_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {P}trips (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(20) NOT NULL UNIQUE,
  warehouse_id INT UNSIGNED NOT NULL,
  vehicle_id INT UNSIGNED NOT NULL,
  driver_id INT UNSIGNED NOT NULL,
  status ENUM('planned','on_road','completed','cancelled') NOT NULL DEFAULT 'planned',
  planned_date DATE NOT NULL,
  route_note VARCHAR(255) NULL,
  odometer_out INT UNSIGNED NULL,
  odometer_in INT UNSIGNED NULL,
  departed_at DATETIME NULL,
  returned_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_trip_veh FOREIGN KEY (vehicle_id) REFERENCES {P}vehicles(id),
  CONSTRAINT fk_trip_drv FOREIGN KEY (driver_id) REFERENCES {P}drivers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {P}trip_stops (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  trip_id INT UNSIGNED NOT NULL,
  seq INT UNSIGNED NOT NULL DEFAULT 1,
  kind ENUM('shipment','transfer') NOT NULL,
  doc_id INT UNSIGNED NOT NULL,
  status ENUM('pending','done','failed') NOT NULL DEFAULT 'pending',
  done_at DATETIME NULL,
  UNIQUE KEY uq_stop_doc (kind, doc_id, trip_id),
  CONSTRAINT fk_stop_trip FOREIGN KEY (trip_id) REFERENCES {P}trips(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
