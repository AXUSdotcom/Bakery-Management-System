# Sweet Bakers — Ops Console

Multi-user rebuild of the `sweet-bakers-console.html` prototype: same visual
design, real persistence. **HTML/CSS/vanilla JS frontend + PHP 8.2 backend +
MySQL 8**, deployable on Railway. No frontend build step, no framework —
`fetch()` calls a small JSON API behind a single front controller.

## Tech stack

| Layer | Choice |
|---|---|
| Frontend | Plain HTML + CSS (ported 1:1 from the prototype) + vanilla JS |
| Backend | PHP 8.2, no framework, one router (`public/api/index.php`) |
| DB | MySQL 8 |
| Auth | PHP sessions + `password_hash()`/`password_verify()` |
| Charts | Chart.js via CDN |

## Repository layout

```
Dockerfile, docker-compose.yml, railway.json   deployment
database/schema.sql, seed.sql, migrate.php     schema + seed data + one-shot setup runner
src/Config/Database.php                        PDO singleton
src/Support/                                   Auth (session+RBAC), Audit, Notify, IdSequence, Request, Response
src/Domain/                                     business logic (Inventory, Production, Purchasing, Orders, Wastage, Catalogue, Supplier, Users, Dashboard)
src/Controllers/                                one per API module, called from public/api/index.php
public/index.html, assets/                      frontend shell, ported CSS, per-view JS modules
public/api/index.php                            front controller / router
public/.htaccess                                rewrites /api/* to the front controller
```

## Local development

### Option A — Docker Compose (closest to Railway)

```
docker compose up --build
```

Then open http://localhost:8080. The container's entrypoint waits for MySQL
and runs `database/migrate.php` automatically on first boot.

### Option B — PHP's built-in server + your own MySQL

```
php -S localhost:8000 -t public
```

Point `DB_HOST`/`DB_PORT`/`DB_NAME`/`DB_USER`/`DB_PASS` env vars at a MySQL 8
instance (see `.env.example`), then run once:

```
php database/migrate.php
```

This creates the schema (`database/schema.sql`), seeds the 4 staff accounts +
1 demo customer with real hashed passwords, then loads `database/seed.sql`
(suppliers, ingredients, batches, products, one open PO, wastage, one
production run, orders, a 7-day sales rollup, starter notifications). It's a
no-op if the `users` table already exists — drop the database to reseed from
scratch.

## Demo logins

Every account — staff included — signs in with a real email + password
(see "Deviations from the prototype" below for why).

| Role | Email | Password |
|---|---|---|
| Admin | admin@sweetbakers.lk | admin123 |
| Manager | manager@sweetbakers.lk | manager123 |
| Baker | baker@sweetbakers.lk | baker123 |
| Storekeeper | store@sweetbakers.lk | store123 |
| Customer (demo) | amaya@gmail.com | customer123 |

Change these before any real deployment — `database/migrate.php` only runs
once, so update passwords via the Users & roles screen (admin) or directly in
MySQL afterwards.

## Railway deployment

1. Create a new Railway project with **two services**: this repo (Dockerfile
   build) and Railway's **MySQL** plugin.
2. On the web service's **Variables**, map Railway's MySQL plugin variables
   to what `src/Config/Database.php` expects (Railway supports cross-service
   variable references):
   ```
   DB_HOST=${{MySQL.MYSQLHOST}}
   DB_PORT=${{MySQL.MYSQLPORT}}
   DB_NAME=${{MySQL.MYSQLDATABASE}}
   DB_USER=${{MySQL.MYSQLUSER}}
   DB_PASS=${{MySQL.MYSQLPASSWORD}}
   APP_ENV=production
   ```
3. Deploy. The Docker entrypoint (`docker/entrypoint.sh`) waits for the
   database to accept connections, then runs `database/migrate.php`
   automatically — schema + seed data are created on first boot, and it's a
   no-op on every boot after that.
4. `APP_ENV=production` turns on `session.cookie_secure`, since Railway
   serves over HTTPS by default.
5. Smoke-test all 5 roles once it's live (see the acceptance checklist in
   `BAKERY_APP_BUILD_PLAN.md`).

## Deviations from the prototype (intentional, per the build plan's §10 call-outs)

These were flagged in the handover doc as decisions to make explicitly rather
than assume — resolved as follows:

1. **Real passwords for every role, including staff.** The prototype's
   "pick a role, no password" login was a single-user demo convenience. Since
   this is now a real deployed multi-user system, every account (staff and
   customer) authenticates with email + password, hashed via
   `password_hash()`. No role-picker login was kept.

2. **Shelf stock decrements at order placement, not just at production.**
   The prototype only added to `shelf_stock` during production and never
   subtracted it when an online order was placed — fine for a single
   in-memory session, but it would let two concurrent customers oversell the
   same units in a real multi-user system. `OrdersService::checkout()` now
   locks and decrements `products.shelf_stock` inside the same transaction
   that creates the order, and cancelling a `Pending` order (staff or
   customer) restores that stock.

3. **`used_last_7d` isn't tracked as a live rolling metric — see below.**
   Rather than maintain a rolling counter that needs a scheduled job to stay
   correct, days-of-cover math reads `ingredients.used_last_7d`, which starts
   from the seed data. Sales/waste dashboard figures (today's sales, the
   7-day sales chart, the 6-week waste trend, waste-by-reason, and top
   sellers) are all derived live from real `orders`/`order_lines`/`wastage`
   rows — nothing is hardcoded after the initial seed, per the plan's
   recommendation to derive these from actual activity from day one.

## Other notes

- **Supplier CRUD** got its own `SupplierService`/`SupplierController` — the
  build plan's file list didn't spell one out explicitly, but §3.6 of the API
  surface calls for full supplier CRUD, so it's broken out the same way every
  other module is.
- **Customer shop listing** is served by a small `ShopController` at
  `GET /api/shop/products` rather than reusing `GET /api/products`, because
  the `products` module (with recipe cost/margin data) is staff-only in the
  RBAC map and customers only need name/price/emoji/stock/description.
- **Admin-created users** need a starting password at creation time (the
  prototype could skip this since it never actually verified passwords) —
  the "New user" form takes one, meant to be shared with that person
  out-of-band.
- Loyalty points are **display-only** (`floor(total_spent / 100)`), matching
  the prototype — there's no redemption flow to build against yet.
