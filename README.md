# Warehouse Inventory App

A mobile-first physical inventory app with three roles — **Data Entry**,
**Control**, and **Admin** — built to the spec: blind counting, address-based
control, and a full audit trail. Plain PHP 8 + MySQL, no build step, no
Composer — upload and run on almost any shared host (e.g. Hostinger).

## What's included

- **Data Entry**: scan an Address (camera or type), scan HUs (camera or
  type), auto-filled Part Number/Unit from imported stock, "HU NOT
  AVAILABLE" flow, decimal-vs-whole-number quantity validation by unit,
  and a blind match/difference signal — **never** the expected quantity.
- **Control**: address-based queue of everything that needs review, a full
  expected-vs-physical comparison table, and actions to correct a line, add
  missing physical stock, remove a bad entry (soft-delete, never destroyed),
  or confirm a difference is real, then validate the address.
- **Admin**: dashboard, Excel/CSV stock import (parsed in the browser with
  SheetJS, re-validated on the server), user management, full audit trail,
  and CSV exports.
- Every correction is logged with before/after values, the actor, and a
  timestamp — nothing is ever hard-deleted.

## Requirements

- PHP 8.1+ with the `pdo_mysql` extension (standard on any PHP host).
- MySQL 5.7+ / MariaDB 10.3+.
- HTTPS (required for camera access in mobile browsers).

## Deploy on shared hosting (Hostinger or similar)

1. **Create a MySQL database** in your hosting control panel and note the
   database name, username, password, and host (often `localhost`).
2. **Upload the files.** In hPanel/cPanel's File Manager (or via FTP),
   upload the *entire contents of this zip* to your hosting account.
   - **Best option:** if your host lets you set the document root, point
     it at the `public/` folder, and upload the other folders
     (`app/`, `config/`, `database/`, `storage/`) one level above it.
   - **Simplest option (works everywhere):** upload everything as-is into
     `public_html/` (so you get `public_html/public/`,
     `public_html/app/`, etc.), then set your domain's document root to
     `public_html/public`. If you cannot change the document root at all,
     the top-level `.htaccess` in this package blocks direct access to
     everything outside `public/` as a fallback — but pointing the
     document root at `public/` is the correct setup.
3. **Make `storage/` writable** by the web server (usually already the
   case; if not, `chmod 755 storage storage/logs`).
4. **Run the setup wizard**: open `https://yourdomain.com/install/` in a
   browser. Enter your MySQL credentials and create the first Admin
   account. This writes `config/config.php` and creates all tables.
5. **Delete the `/install` folder** once setup succeeds (the wizard reminds
   you, and it also refuses to run again once `config/config.php` exists).
6. **Log in** at your domain's root and create the Control and Data Entry
   users from Admin → Users.
7. **Import your stock file** from Admin → Import Stock. Download the
   template link there for the exact column layout (Address, HU, Part
   Number, Unit, Quantity).

That's it — the app is ready for the floor. Data Entry users should open
the site on their phones (camera access requires HTTPS, which most hosts
provide by default via Let's Encrypt).

## Notes on how it works

- **Blind counting** is enforced server-side: the Data Entry API endpoints
  (`/api/entry/*`) never include expected/imported quantities in their
  responses, regardless of what the client asks for. Only Control and
  Admin endpoints (`/api/control/*`, `/api/admin/*`) return that detail,
  and both are gated by the server-side session role — never by anything
  the browser sends.
- **Re-importing stock** deactivates the previous stock snapshot and makes
  the new one authoritative; addresses and any physical counts already
  entered are kept. Use **Admin → Start a new counting cycle** to reset all
  physical counts and address statuses for a fresh count (imported stock is
  kept).
- **Units**: KG, Meter/M, and L/Liter accept decimals; PCS and Rolls must be
  whole numbers. Unrecognized units default to whole-number validation —
  ask an admin to extend the list in `app/Services/UnitService.php` if you
  use other units.
- **Local testing**: the setup wizard also offers a SQLite option for quick
  local testing without a MySQL server. Use MySQL for production.

## Support

Everything here is plain, documented PHP — no framework magic. The
reconciliation logic lives in `app/Services/ComparisonService.php` if you
ever need to adjust the matching rules.
