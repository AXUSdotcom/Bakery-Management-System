# Sweet Bakers — Ops Console → PHP + MySQL Rebuild Plan

> **Handover doc for Claude Code.** Source of truth is the uploaded prototype
> `sweet-bakers-console.html` — a single-file HTML/CSS/JS mock-up with an
> in-memory `DB` object simulating a bakery management system. Nothing in it
> persists (a page refresh wipes all data). The job is to rebuild it as a real
> multi-user web app: **HTML/CSS/vanilla JS frontend (reuse the existing
> design) + PHP backend (REST-ish JSON endpoints) + MySQL**, deployable on
> **Railway**. Every function currently implemented in JS against `DB` must be
> reimplemented against real tables with real persistence, real auth, and
> real concurrency safety.

---

## 0. Ground rules for whoever (Claude Code) executes this

1. **Read `sweet-bakers-console.html` fully first** (1314 lines). It is the
   spec. Every `RENDER.*`, every `function` that mutates `DB`, every modal,
   every filter/segment control is a required feature — not inspiration.
2. Keep the **visual design** (CSS variables, layout, colors, fonts, card/
   pill/badge/toast/modal components) — port the `<style>` block essentially
   as-is into the new frontend. Do not redesign.
3. Replace the in-memory `DB` object and all the pure-JS "services" (FEFO
   deduction, feasibility calc, auto-PO, stock status, audit) with **PHP
   business logic backed by MySQL**, exposed as JSON endpoints that the
   existing-style JS calls via `fetch()`.
4. Preserve business rules exactly (see §5) — they encode real bakery
   operations logic (FEFO stock deduction, 2× reorder point PO sizing,
   loyalty points at 1pt/Rs100, free delivery over Rs 2000, etc.).
5. Ask before deviating from the prototype's behavior. If something is
   ambiguous, prefer the prototype's exact numbers/thresholds.

---

## 1. Tech stack

| Layer | Choice |
|---|---|
| Frontend | Plain HTML + CSS (ported from prototype) + vanilla JS (`fetch` to backend, no build step) |
| Backend | PHP 8.2+ (no framework required; a tiny router is fine — see §3) |
| DB | MySQL 8 (Railway MySQL plugin) |
| Auth | PHP native sessions (`session_start()`), password hashing via `password_hash()`/`password_verify()` |
| Charts | Chart.js via CDN (already used in prototype — keep) |
| Hosting | Railway: one service for PHP (Dockerfile using `php:8.2-apache` or `richarvey/nginx-php-fpm`), one Railway-provided MySQL plugin |
| Local dev | `php -S localhost:8000` against a local/dockerized MySQL, or `docker-compose` with php+mysql for parity with Railway |

**Do not use Laravel/Symfony/etc.** — keep it dependency-light so it is easy
to reason about and cheap to run on Railway. Composer is fine for small
utility libs (e.g. `vlucas/phpdotenv`) but not required.

---

## 2. Repository structure

```
/ (repo root)
├── Dockerfile
├── railway.json                 # or railway.toml — build/start config
├── .env.example                 # DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS, APP_KEY
├── composer.json                 # optional, only if using phpdotenv
├── public/                       # Apache/nginx document root
│   ├── index.html                # shell: login + app scaffold (from prototype)
│   ├── assets/
│   │   ├── css/app.css           # ported <style> block
│   │   └── js/
│   │       ├── app.js            # boot, nav, session bootstrap, router (was CURRENT/go())
│   │       ├── api.js            # fetch() wrapper, error/toast handling
│   │       ├── modal.js          # modal()/closeModal() (ported)
│   │       ├── toast.js          # toast() (ported)
│   │       ├── notifications.js
│   │       ├── dashboard.js      # RENDER.dashboard + AFTER.dashboard (Chart.js)
│   │       ├── inventory.js
│   │       ├── production.js
│   │       ├── orders.js
│   │       ├── purchasing.js
│   │       ├── suppliers.js
│   │       ├── products.js
│   │       ├── wastage.js
│   │       ├── users.js
│   │       ├── audit.js
│   │       ├── shop.js           # customer-facing catalogue + cart + checkout
│   │       └── account.js
│   └── api/
│       └── index.php             # single front controller, see §3
├── src/                          # PHP application code (not web-accessible directly)
│   ├── Config/Database.php       # PDO connection singleton, reads env vars
│   ├── Support/Auth.php          # session helpers, role guard, RBAC (CAN map)
│   ├── Support/Audit.php         # audit() equivalent — inserts into audit_log
│   ├── Support/Notify.php        # notify() equivalent — inserts into notifications
│   ├── Support/Money.php         # formatting helpers (money/fmt equivalents, PHP-side is optional; frontend can format)
│   ├── Domain/InventoryService.php   # stockOf, activeBatches, lowItems, daysCover, FEFO deduction, invValue, stockStatus, suggestQty
│   ├── Domain/ProductionService.php  # maxBakeable, planNeeds, feasibility check, confirmBake (FEFO deduct + stock increment, transaction)
│   ├── Domain/PurchasingService.php  # autoPO, autoPOAll, createPOs, sendPO, cancelPO, receivePO
│   ├── Domain/OrdersService.php      # order lifecycle, staff actions, customer actions, dispatch
│   ├── Domain/WastageService.php     # manual waste, batch waste, runExpiryJob
│   ├── Domain/CatalogueService.php   # products/recipes CRUD, margin/recipeCost
│   ├── Domain/UsersService.php       # user CRUD, role changes, enable/disable
│   ├── Domain/DashboardService.php   # KPI aggregation, chart datasets
│   └── Controllers/*.php             # one per module, called from api/index.php router
├── database/
│   ├── schema.sql                 # full CREATE TABLE statements (see §4)
│   ├── seed.sql                   # port of the prototype's seed() data (§4.9)
│   └── migrations/                # optional if you want versioned migrations instead of one schema.sql
└── README.md                      # setup + Railway deploy steps
```

Routing approach: a single `public/api/index.php` front controller reading
`$_GET['module']` / `$_GET['action']` (or use `PATH_INFO` for clean URLs like
`/api/inventory/add-stock`), dispatching to the matching Controller method.
Keep it simple — no need for a full router library.

---

## 3. API surface

Design as JSON endpoints returning `{ok:true, data:...}` or
`{ok:false, error:"..."}`. Every mutating endpoint must: (a) check
`Auth::role()` against the permission map in §5.2, (b) wrap DB writes in a
transaction where multiple tables are touched, (c) write an `audit_log` row,
(d) return the updated resource so the frontend can re-render without a
second round trip.

### 3.1 Auth
- `POST /api/auth/login` — body: `{role}` (prototype logs in by *role*
  picking the first active user of that role — keep this "demo login by
  role" behavior AND also support real email+password login for registered
  customers, since the prototype has both a role-select login and a
  customer register/login flow). See §5.1 for the dual-mode nuance.
- `POST /api/auth/register` — customer self-registration (`doRegister`)
- `POST /api/auth/logout`
- `GET /api/auth/me` — current session user + role + permissions

### 3.2 Dashboard
- `GET /api/dashboard` — all KPI numbers + chart datasets in one payload
  (waste 30d + prev30d, inventory value, low-stock count, sales today, open
  orders, top sellers, stock health counts, expiring-in-7-days batches,
  waste-by-reason breakdown, 6-week waste trend, 7-day sales series)

### 3.3 Inventory
- `GET /api/inventory` — ingredients list w/ computed stock, status, cover,
  value (supports `?search=&filter=all|low|ok`)
- `GET /api/inventory/batches` — active batches, FEFO order
- `POST /api/inventory/ingredients` — new ingredient (`mNewIngredient`/`saveNewIngredient`)
- `POST /api/inventory/receive` — receive stock → new batch (`saveAddStock`)
- `POST /api/inventory/waste` — waste against an ingredient, FEFO-deducted (`saveWasteIng`)
- `POST /api/inventory/waste-batch` — waste against a specific batch (`saveWasteBatch`)
- `POST /api/inventory/run-expiry-job` — auto-waste all expired batches (`runExpiryJob`)

### 3.4 Production
- `GET /api/production` — products with on-shelf/bakeable, production history
- `POST /api/production/suggest` — 7-day-avg-minus-shelf suggestion (`suggestPlan`)
- `POST /api/production/feasibility` — given a plan `{productId: qty}`, return
  per-ingredient need/have/shortage (`renderFeas`/`planNeeds`)
- `POST /api/production/fit` — auto-fit plan to available stock (`fitPlan`)
- `POST /api/production/po-for-shortages` — raise POs from a plan's shortages (`poForShortages`)
- `POST /api/production/confirm` — confirm bake: FEFO-deduct ingredients,
  increment product stock, log a production run, must be atomic (`confirmBake`)

### 3.5 Purchase Orders
- `GET /api/purchase` — POs (supports `?status=`)
- `POST /api/purchase/auto` — draft PO for one ingredient (`autoPO`)
- `POST /api/purchase/auto-all` — draft POs for all low-stock items, grouped
  by supplier (`autoPOAll`/`createPOs`)
- `GET /api/purchase/{id}/preview` — line items + totals (`previewPO`)
- `POST /api/purchase/{id}/send` — Draft → Sent (`sendPO`)
- `POST /api/purchase/{id}/cancel` — → Cancelled, from Draft or Sent (`cancelPO`/`doCancelPO`)
- `POST /api/purchase/{id}/receive` — Sent → Received, creates new batches
  per line item, updates stock (`receivePO`)

### 3.6 Suppliers
- `GET /api/suppliers`
- `POST /api/suppliers` — create/update (`saveSupplier`)
- `POST /api/suppliers/{id}/remove` — block if ingredients still linked (`removeSupplier`)

### 3.7 Products & Recipes
- `GET /api/products` — with computed maxBakeable, margin
- `POST /api/products` — create/update incl. recipe lines (`saveProduct`)
- `POST /api/products/{id}/remove` (`doRemoveProduct`)

### 3.8 Orders (staff + customer)
- `GET /api/orders` — staff view, all orders (`?status=`)
- `GET /api/orders/mine` — customer's own orders
- `GET /api/orders/{id}` — detail + timeline
- `POST /api/orders/{id}/advance` — Pending→Preparing→Ready→(Out for
  delivery)→Delivered (`ordNext`, `mDispatch`, `markDelivered`)
- `POST /api/orders/{id}/staff-cancel`
- `POST /api/orders/{id}/customer-cancel` — only allowed while Pending (`custCancel`)
- `POST /api/orders/{id}/reorder` — copy items back into cart (can be pure
  frontend logic against `/api/products`)
- `POST /api/orders/checkout` — customer places an order: builds items from
  cart, computes delivery fee (free ≥ Rs 2000, else Rs 250), snapshot
  address/payment, optionally save address to profile (`placeOrder`)

### 3.9 Wastage
- `GET /api/wastage` — log + 7d/30d totals + auto-logged count

### 3.10 Users & Roles (admin only)
- `GET /api/users`
- `POST /api/users` — create (`saveNewUser`)
- `POST /api/users/{id}/toggle` — enable/disable (`uToggle`)
- `POST /api/users/{id}/role` — change role (`saveRole`)

### 3.11 Notifications
- `GET /api/notifications`
- `POST /api/notifications/{id}/read`
- `POST /api/notifications/read-all`
- (Low-stock notifications are generated server-side whenever stock crosses
  the reorder threshold — see §5.6 — not purely client-triggered like the
  prototype's `checkLowStock()`.)

### 3.12 Audit (admin only)
- `GET /api/audit`

### 3.13 Account (customer)
- `GET /api/account`
- `POST /api/account` — update profile/address/payment (`saveAccount`)

---

## 4. Database schema

Translate the prototype's `DB` object 1:1 into normalized tables. Key
departure from the prototype: **recipes and PO line items become junction
tables** instead of embedded arrays, and **money/quantity precision** must
use `DECIMAL`, not floats.

```sql
-- users & auth
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(160) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NULL,        -- NULL for the 4 staff "demo" seed accounts if you keep role-picker login; required for real customers
  role ENUM('admin','manager','baker','store','customer') NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  phone VARCHAR(30),
  address TEXT,
  payment_method VARCHAR(60),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- suppliers
CREATE TABLE suppliers (
  id VARCHAR(10) PRIMARY KEY,             -- e.g. S01 (or switch to AUTO_INCREMENT INT if you prefer)
  name VARCHAR(160) NOT NULL,
  contact VARCHAR(60),
  email VARCHAR(160),
  lead_days INT NOT NULL DEFAULT 2,
  supplies_summary VARCHAR(255)           -- free-text "Flour, Yeast" (or normalize to a supplier_ingredient table — optional upgrade)
);

-- ingredients
CREATE TABLE ingredients (
  id VARCHAR(10) PRIMARY KEY,             -- e.g. IG01
  name VARCHAR(160) NOT NULL,
  uom ENUM('kg','L','pc') NOT NULL,
  unit_cost DECIMAL(10,2) NOT NULL,       -- current/default cost (batches carry their own cost too)
  reorder_level DECIMAL(10,3) NOT NULL,
  supplier_id VARCHAR(10) NULL REFERENCES suppliers(id),
  used_last_7d DECIMAL(10,3) NOT NULL DEFAULT 0,  -- rolling usage, recompute nightly or on write (see §5.7)
  low_stock_notified TINYINT(1) NOT NULL DEFAULT 0
);

-- inventory batches (FEFO source of truth)
CREATE TABLE batches (
  id VARCHAR(10) PRIMARY KEY,             -- e.g. B1001
  ingredient_id VARCHAR(10) NOT NULL REFERENCES ingredients(id),
  supplier_id VARCHAR(10) NULL REFERENCES suppliers(id),
  received_qty DECIMAL(10,3) NOT NULL,
  qty_on_hand DECIMAL(10,3) NOT NULL,
  unit_cost DECIMAL(10,2) NOT NULL,
  expiry_date DATE NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_batch_fefo (ingredient_id, expiry_date)
);

-- products
CREATE TABLE products (
  id VARCHAR(10) PRIMARY KEY,             -- e.g. P01
  name VARCHAR(160) NOT NULL,
  emoji VARCHAR(10),
  price DECIMAL(10,2) NOT NULL,
  shelf_stock INT NOT NULL DEFAULT 0,
  description VARCHAR(255),
  avg_weekly_sales DECIMAL(10,2) NOT NULL DEFAULT 10
);

-- recipe lines (junction: product -> ingredient qty-per-unit)
CREATE TABLE recipe_lines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id VARCHAR(10) NOT NULL REFERENCES products(id) ON DELETE CASCADE,
  ingredient_id VARCHAR(10) NOT NULL REFERENCES ingredients(id),
  qty_per_unit DECIMAL(10,4) NOT NULL
);

-- purchase orders + lines
CREATE TABLE purchase_orders (
  id VARCHAR(10) PRIMARY KEY,             -- e.g. PO119
  supplier_id VARCHAR(10) NOT NULL REFERENCES suppliers(id),
  status ENUM('Draft','Sent','Received','Cancelled') NOT NULL DEFAULT 'Draft',
  is_auto TINYINT(1) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  eta_days INT,
  created_by INT REFERENCES users(id),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  sent_at TIMESTAMP NULL,
  received_at TIMESTAMP NULL
);
CREATE TABLE purchase_order_lines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  po_id VARCHAR(10) NOT NULL REFERENCES purchase_orders(id) ON DELETE CASCADE,
  ingredient_id VARCHAR(10) NOT NULL REFERENCES ingredients(id),
  qty DECIMAL(10,3) NOT NULL,
  unit_cost DECIMAL(10,2) NOT NULL
);

-- wastage log
CREATE TABLE wastage (
  id VARCHAR(10) PRIMARY KEY,             -- e.g. W001
  ingredient_id VARCHAR(10) NOT NULL REFERENCES ingredients(id),
  batch_id VARCHAR(10) NULL REFERENCES batches(id),  -- nullable = FEFO-spread waste not tied to one batch
  qty DECIMAL(10,3) NOT NULL,
  reason ENUM('Expired','Damaged/Spoiled','Over-Production','Prep-Loss/Spillage','Customer-Return') NOT NULL,
  cost DECIMAL(10,2) NOT NULL,
  is_auto TINYINT(1) NOT NULL DEFAULT 0,
  logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- production runs
CREATE TABLE production_runs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  run_by INT REFERENCES users(id),
  run_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  status VARCHAR(30) DEFAULT 'Completed'
);
CREATE TABLE production_run_lines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  run_id INT NOT NULL REFERENCES production_runs(id) ON DELETE CASCADE,
  product_id VARCHAR(10) NOT NULL REFERENCES products(id),
  qty INT NOT NULL
);

-- orders (customer/POS) + lines + timeline
CREATE TABLE orders (
  id VARCHAR(12) PRIMARY KEY,             -- e.g. ORD-5012
  customer_id INT NULL REFERENCES users(id),   -- NULL for walk-in/POS
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
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE order_lines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id VARCHAR(12) NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
  product_id VARCHAR(10) NOT NULL REFERENCES products(id),
  qty INT NOT NULL,
  unit_price DECIMAL(10,2) NOT NULL
);
CREATE TABLE order_timeline (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id VARCHAR(12) NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
  event VARCHAR(255) NOT NULL,
  happened_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- daily sales rollup (feeds the 7-day sales chart; recompute via trigger or nightly job — see §5.7)
CREATE TABLE sales_daily (
  sale_date DATE PRIMARY KEY,
  total DECIMAL(12,2) NOT NULL DEFAULT 0
);

-- notifications
CREATE TABLE notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  type ENUM('bad','warn','info','good') NOT NULL,
  icon VARCHAR(10),
  title VARCHAR(160) NOT NULL,
  message VARCHAR(255),
  category ENUM('inventory','orders','purchasing','production','catalogue') NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- audit log
CREATE TABLE audit_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL REFERENCES users(id),
  user_name VARCHAR(160),
  action VARCHAR(60) NOT NULL,     -- e.g. RECEIVE, WASTE, PO_SENT, PRODUCTION, ORDER_PLACED...
  detail VARCHAR(255),
  happened_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- id sequence counters (mirrors DB.seq — or just use MAX()+1 / AUTO_INCREMENT with prefix logic in PHP)
CREATE TABLE id_sequences (
  name VARCHAR(20) PRIMARY KEY,   -- 'po','ord','batch','waste','gid'
  next_value INT NOT NULL
);
```

**Seed data**: port every row from `seed()` (users, suppliers, ingredients,
batches, products+recipes, orders, one open PO, wastage entries, one
production run, 7-day sales series → `sales_daily`, starter notifications)
into `database/seed.sql`. Keep the same IDs (`IG01`, `P01`, `S01`, `B1001`,
etc.) so the dataset is recognizable to whoever reviews the migration.

---

## 5. Business logic — must match the prototype exactly

### 5.1 Auth model
The prototype has **two parallel login styles**:
- Staff/demo: a dropdown picks a role, logs in as the first active seeded
  user of that role, no password (`doLogin`).
- Customers: real registration with name/phone/email/address/password
  (`doRegister`), and implicitly log straight in after registering.

For the real app: give **all users real passwords**. Seed the 4 staff demo
accounts with known passwords (documented in README, e.g.
`admin@sweetbakers.lk` / a seeded password) and implement a normal
email+password login for everyone, staff included. Keep a "quick demo login"
convenience only if explicitly wanted — otherwise this is the natural
security upgrade from a stateless mock to a real multi-user system. Passwords
must be hashed with `password_hash()`; verify with `password_verify()`.
Sessions via PHP `$_SESSION`, `httponly` + `secure` cookies in production.

### 5.2 RBAC — port `CAN` and `HOME` maps verbatim

```php
const CAN = [
  'admin'    => ['dashboard','inventory','production','orders','purchase','suppliers','products','wastage','users','notifications','audit'],
  'manager'  => ['dashboard','inventory','production','orders','purchase','suppliers','products','wastage','notifications'],
  'baker'    => ['production','inventory','products','orders','notifications'],
  'store'    => ['inventory','purchase','suppliers','wastage','notifications'],
  'customer' => ['shop','myorders','account','notifications'],
];
const HOME = ['admin'=>'dashboard','manager'=>'dashboard','baker'=>'production','store'=>'inventory','customer'=>'shop'];
```
Every controller method must re-check role server-side (never trust the
frontend nav) — mirror the prototype's finer-grained "canEdit"/"canAct"
checks too, e.g.:
- Inventory edit actions: `admin, manager, store`
- Production confirm: `admin, manager, baker`
- Products/recipes edit: `admin, manager`
- Suppliers/PO actions: `admin, manager, store`
- Users & roles, audit log: `admin` only
- Wastage log entries: `admin, manager, store`

### 5.3 FEFO (first-expire-first-out) deduction
`fefoDeduct(ingredientId, qty)`: pull from `batches` where
`qty_on_hand > 0 AND expiry_date >= today`, ordered by `expiry_date ASC`,
decrementing each batch until `qty` is satisfied. Must run inside a DB
transaction with row locking (`SELECT ... FOR UPDATE`) to be safe under
concurrent requests (two bakers confirming production simultaneously).

### 5.4 Stock calculations (recompute server-side, don't cache stale values)
- `stockOf(ingredientId)` = SUM(qty_on_hand) over active, non-expired batches
- `daysCover(i)` = stockOf / (used_last_7d / 7), capped display at "30+"
- `stockStatus(i)`: `≤0` → Out of stock; `< reorder` → Low/reorder;
  `< reorder*1.4` → Getting low; else Healthy
- `invValue()` = SUM(qty_on_hand * unit_cost) over active batches
- `maxBakeable(product)` = floor(min over recipe lines of stockOf(ingredient)/qty_per_unit)
- `suggestQty(i)` = max(1, ceil(reorder*2 - stockOf(i))) — PO sizing target is 2× reorder level
- `recipeCost(p)` = SUM(qty_per_unit * ingredient.unit_cost); `margin(p) = (price-cost)/price*100`

### 5.5 Auto-PO engine
- `autoPO(ingredientId)`: draft a PO to that ingredient's supplier for
  `suggestQty()` units.
- `autoPOAll()`: group all low-stock ingredients by supplier, one draft PO
  per supplier with all their line items (`createPOs`).
- PO lifecycle: **Draft → Sent → Received**, or **cancelled** from Draft or
  Sent. Only `Received` mutates inventory (creates new batches per line,
  expiry = `+14 days` if uom is `pc`, else `+20 days` — matches prototype's
  `receivePO`). Cancelling a Sent PO is allowed (simulating notifying the
  supplier) up until it's received.
- ETA on send = `+{supplier.lead_days} days` from send date.

### 5.6 Notifications & low-stock alerting
Whenever stock changes (receive, waste, PO receive, production confirm),
recompute `stockOf` for affected ingredients and:
- If it just crossed **below** reorder level and wasn't already flagged →
  insert a `bad`/⚑ notification ("X below reorder level") and set
  `low_stock_notified = 1`.
- If it recovers to ≥ reorder level → clear the flag so it can re-fire later.

Also emit notifications for: new online order placed, wastage recorded,
expiry job results, PO drafted/sent/cancelled/received, new product added —
matching the categories/icons used throughout the prototype
(`inventory`,`orders`,`purchasing`,`production`,`catalogue`).

### 5.7 Rolling "used last 7 days" and sales chart data
The prototype hardcodes `used7` per ingredient and a static `sales7` array.
In the real app these must be **derived from actual activity**:
- `ingredients.used_last_7d`: sum of ingredient quantity consumed via FEFO
  deduction (production confirmations) over the trailing 7 days. Recompute
  either on-the-fly (query) or via a small scheduled job — either is fine,
  but must reflect real order/production history, not a static seed number
  forever.
- `sales_daily`: increment on every order placed (or on POS sale / delivery
  completion — pick "order placed" to match the prototype's dashboard
  framing of "sales today"). Dashboard's 7-day chart reads the last 7 rows.
- Waste trend chart (6 weeks) and waste-by-reason donut: derive with
  `GROUP BY` queries over `wastage.logged_at`, no hardcoding.

### 5.8 Production confirm — must be one atomic transaction
`confirmBake(plan)`:
1. Compute total ingredient needs from `plan` × `recipe_lines`.
2. Validate every ingredient has `stockOf >= need` (reject with the same
   shortage detail the prototype shows if not — don't silently partial-fill).
3. FEFO-deduct every ingredient.
4. Increment `products.shelf_stock` per line.
5. Insert `production_runs` + `production_run_lines`.
6. Insert audit log + notification.
All in one DB transaction; roll back entirely on any failure.

### 5.9 Checkout / order placement
- Delivery fee: **free if cart subtotal ≥ Rs 2000, else Rs 250** — only
  applies in Delivery mode, not Pickup.
- Cart quantities capped at each product's `shelf_stock`.
- On placing an order: decrement `products.shelf_stock` by ordered qty
  (prototype does NOT do this explicitly for online orders — check: looking
  at `placeOrder`, it does *not* deduct shelf stock at order time, only
  production adds to it. **Decide and document**: recommend decrementing
  shelf stock at order placement to avoid overselling in a real multi-user
  system — call this out as an intentional improvement over the prototype
  in the README, since two customers could otherwise order more units than
  exist).
- Optionally save the delivery address back to the customer's profile if
  they check "save this address."
- Loyalty points shown on Account page: `floor(total_spent / 100)`, 1 point
  per Rs 100 spent (display-only — no redemption flow in the prototype,
  keep it display-only unless asked to build redemption).
- Customer order cancellation only allowed while `status = Pending`.

### 5.10 Wastage & expiry job
- Manual waste against an ingredient: FEFO-deduct the wasted qty across
  batches, log one `wastage` row costed at the blended cost of the batches
  drawn from (prototype uses the ingredient's flat unit cost for
  ingredient-level waste, and the specific batch's cost for batch-level
  waste — preserve that distinction).
- "Run expiry job": find all batches with `qty_on_hand > 0 AND expiry_date <
  today`, zero them out, log one `wastage` row per batch with
  `reason='Expired', is_auto=1`.

---

## 6. Frontend rebuild approach

Reuse the prototype's exact visual language:
1. Copy the `<style>` block into `public/assets/css/app.css` unmodified
   (CSS variables, components — `.card`, `.pill`, `.badge`, `.btn`, `.kpi`,
   `.modal`, `.toast`, `.notif-drawer`, `.side` nav, etc.).
2. Rebuild `login`, `register`, and `app` shell markup in `index.html`.
3. Port each `RENDER.xxx` function into its own JS module that:
   - Calls the matching `GET /api/xxx` endpoint(s) instead of reading `DB`.
   - Renders the same HTML string/template.
   - Wires the same button `onclick` handlers, but those handlers now call
     `POST /api/xxx/...` via `fetch()` and re-render on success, showing
     `toast()` messages using the server's response message where possible.
4. Keep `modal()`, `toast()`, `go()`/nav switching, notification drawer,
   cart FAB behavior identical to the prototype — these are pure UI state
   and don't need the server.
5. Charts: same Chart.js calls, fed by `/api/dashboard`'s JSON instead of
   the in-memory `DB.sales7` etc.
6. No client framework needed — this is a deliberate choice to match the
   prototype's vanilla-JS style and keep the Railway deployment simple
   (static files + PHP, no Node build step).

---

## 7. Railway deployment

1. **Two Railway services** in one project:
   - `web` — this PHP app (Dockerfile below)
   - `mysql` — Railway's MySQL plugin (managed, gives you `MYSQLHOST`,
     `MYSQLPORT`, `MYSQLDATABASE`, `MYSQLUSER`, `MYSQLPASSWORD` env vars)
2. **Dockerfile** (example, adjust as needed):
   ```dockerfile
   FROM php:8.2-apache
   RUN docker-php-ext-install pdo pdo_mysql mysqli
   COPY . /var/www/html/
   COPY public/ /var/www/html/public/
   RUN a2enmod rewrite
   ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
   RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
   RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf
   EXPOSE 80
   ```
3. **Env vars on Railway** (`web` service → Variables): map Railway's
   auto-provided MySQL vars into whatever names `src/Config/Database.php`
   expects (`DB_HOST=${{MySQL.MYSQLHOST}}`, etc. — Railway supports variable
   references between services).
4. **Startup**: on first deploy, run `database/schema.sql` then
   `database/seed.sql` against the Railway MySQL instance — either via a
   one-off Railway `Run Command` (`php database/migrate.php`) or a small PHP
   migration runner triggered on boot that checks "if tables don't exist,
   create them" (simplest for a first deployment).
5. **HTTPS/session cookies**: set `session.cookie_secure=1` in production
   since Railway serves over HTTPS by default.
6. Document exact steps (env var names, run order) in `README.md` so a
   fresh Railway project can be stood up from scratch.

---

## 8. Build order (suggested milestones for Claude Code)

1. **Schema + seed** — `database/schema.sql`, `database/seed.sql`, verify
   locally with a MySQL container.
2. **Auth + RBAC skeleton** — login/register/logout/me, session guard, role
   map, protect routes.
3. **Read-only modules first** (fastest to validate against prototype):
   dashboard, inventory list, suppliers, products, purchase list, orders
   list, wastage list, users list, audit list, notifications list.
4. **Inventory writes**: receive stock, waste (ingredient + batch), expiry
   job, new ingredient. Verify FEFO math against hand-computed examples.
5. **Purchasing writes**: auto-PO / auto-PO-all, send, cancel, receive.
6. **Production**: feasibility calc, suggest, fit-to-stock, PO-for-shortage,
   confirm-bake (the highest-stakes transaction — test concurrency).
7. **Products/recipes CRUD**, **suppliers CRUD**, **users/roles CRUD**.
8. **Customer-facing shop**: catalogue, cart (client-side), checkout,
   my-orders, reorder, account.
9. **Staff order management**: status advancement, dispatch (driver
   details), cancel.
10. **Dashboard charts wiring** + notification bell/drawer + audit log.
11. **Polish pass**: match every toast message, empty-state, and disabled
    state from the prototype; confirm RBAC hides/shows exactly the same nav
    items and action buttons per role.
12. **Railway deploy** — Dockerfile, env vars, run schema+seed, smoke test
    all 5 roles end to end.

---

## 9. Acceptance checklist (functional parity with prototype)

- [ ] All 5 roles (admin, manager, baker, store, customer) can log in and
      see exactly the nav items `CAN` allows them
- [ ] Dashboard KPIs and all 4 charts render from real data, not seed
      constants, after some transactions have occurred
- [ ] Inventory: search, filter (all/low/ok), receive stock, waste
      (ingredient-level and batch-level), run expiry job, add ingredient —
      all persist and recompute stock/status correctly
- [ ] Production: quantities update live feasibility, auto-suggest,
      auto-fit, raise-POs-for-shortage, and confirm production all work and
      confirm is atomic + FEFO-correct
- [ ] Purchase orders: auto-draft (single + all), preview, send, cancel
      (from draft and sent), receive (creates batches, updates stock) —
      full lifecycle persists
- [ ] Suppliers CRUD, block delete when ingredients linked
- [ ] Products/recipes CRUD with dynamic recipe-line editor, margin/max
      bakeable recompute
- [ ] Staff order queue: filter by status, confirm/prepare/ready/
      dispatch/deliver/cancel actions matching allowed transitions
- [ ] Wastage log with 7d/30d totals and auto/manual source tagging
- [ ] Users & roles: create user, toggle active, change role — admin only
- [ ] Notifications: bell badge count, drawer, mark read/mark all read,
      auto-generated on stock/order/PO/waste events
- [ ] Audit log: every mutating action recorded, admin-only, read-only view
- [ ] Customer shop: browse, cart with stock cap, checkout with delivery
      fee rule, address save-to-profile, payment method selection
- [ ] My orders: status badges, live rider info while "Out for delivery",
      cancel while Pending, reorder
- [ ] Account: profile/address/payment edit, spend/loyalty-points summary
- [ ] All role-based edit/action buttons are hidden AND server-enforced for
      users without permission (test by hitting the API directly as a
      lower-privileged role)
- [ ] App deployed on Railway with MySQL plugin, schema+seed applied,
      reachable over HTTPS

---

## 10. Explicit call-outs / things to decide with the user, not silently assume

- Whether to keep "login by role" for staff demo convenience, or require
  real passwords for everyone (§5.1) — recommend real passwords for staff
  too, since this is going from a prototype to a real deployed app.
- Whether online orders should decrement `products.shelf_stock` at
  placement time (recommended) vs. only at production time as in the
  prototype (§5.9) — prevents overselling once this is a real multi-user
  system instead of a single in-memory demo.
- Whether `used_last_7d` and the sales chart should be purely derived from
  real transactions from day one (recommended) or seeded with prototype
  constants until enough real data accumulates.
