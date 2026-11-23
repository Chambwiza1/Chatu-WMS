# Installing Chatu Warehouse

Chatu Warehouse, by Chatu Innovations, is a PHP application. It runs on an ordinary Linux server or on cPanel shared hosting, and needs no Composer, Node or command line on the server. Everything it needs is in the zip.

## What you need

| | Minimum | Notes |
|---|---|---|
| PHP | 8.1 (tested on 8.3) | Extensions: intl, mbstring, mysqli, json, openssl. Recommended: gd (photo resizing and thumbnails; without it photos are stored as uploaded) |
| Database | MariaDB 10.5+ or MySQL 8.0.16+ | An empty database, utf8mb4 |
| Web server | Apache 2.4 with mod_rewrite, or nginx with PHP-FPM | LiteSpeed (common on cPanel) reads the same .htaccess files |
| HTTPS | Required for live use | Browsers only install the app, keep the offline look-up and use the camera scanner over HTTPS (plain http works on localhost only) |
| Scheduled job | Every 5 minutes | Reminders, escalations, expiries, e-mail, new periods, nightly integrity check |

The installer checks PHP, the extensions and folder permissions before it does anything, and tells you exactly what to fix.

---

## Option A: cPanel hosting

### 1. Create the database
1. cPanel > **MySQL Databases**.
2. Create a database (for example `account_wms`).
3. Create a user with a strong password.
4. Add the user to the database with **All privileges**.

Note the full names: cPanel adds your account name as a prefix (`account_wms`, `account_user`).

### 2. Set PHP
cPanel > **Select PHP Version** (or **MultiPHP Manager**): choose PHP 8.1 or newer. Under **Extensions**, make sure `intl`, `mbstring`, `mysqli` and `openssl` are ticked.

### 3. Upload the files
Upload `chatu-warehouse-0.2.1.zip` with **File Manager** and choose **Extract**. Pick one of these layouts:

**Recommended: a subdomain pointing at `public/`**
1. Extract to your home folder, giving `/home/ACCOUNT/chatuwarehouse`.
2. cPanel > **Domains** > **Create a new domain** (e.g. `wms.example.com`). Untick "Share document root" and set the document root to `chatuwarehouse/public`.
3. The application code, settings and data are then outside the web root.

**Also works: inside `public_html`**
1. Extract into `public_html` (the app is then at `example.com/chatuwarehouse/`), or into `public_html` of a subdomain.
2. The `.htaccess` file in the application folder sends every request to `public/` and refuses everything else, so `.env`, `app/`, `system/` and `writable/` cannot be downloaded. This was tested with Apache: requests for `.env`, `app/Config/Database.php` and `writable/logs/` return 403.
3. You can rename the folder (for example to `wms`); the installer detects the address.

### 4. Turn on HTTPS
cPanel > **SSL/TLS Status** > **Run AutoSSL** for the domain or subdomain. Wait until it shows a valid certificate.

### 5. Run the installer
Open `https://wms.example.com/` (or `https://example.com/chatuwarehouse/`). You are sent to the installer. Fill in:
- **Database**: host `localhost`, the database name, user and password from step 1. Leave the table prefix as `cw_` unless the database is shared.
- **Company and first warehouse**: the company name, currency symbol (K), and the code and name of your first warehouse.
- **Administrator (you)**: manages users, settings and the warehouse layout. To keep duties apart, the administrator cannot move or approve stock.
- **Warehouse manager** (optional): a second account that runs the warehouses (items, suppliers, customers, purchasing, receiving, transfers, orders and dispatch, production, fleet, approvals). You can add it later.
- **Demo data** (optional): adds the demo warehouses LSK and KTW with people, items and history. Leave it off for a live system.

When it finishes, the installer writes `.env` and `writable/installed.lock`, and the installer page is locked.

### 6. Add the scheduled job
cPanel > **Cron Jobs** > Common settings **Once Per Five Minutes**. Command:

```
php /home/ACCOUNT/chatuwarehouse/spark wms:cron >/dev/null 2>&1
```

(Use `/home/ACCOUNT/public_html/chatuwarehouse/spark` if you extracted into `public_html`.) If `php` is the wrong version, cPanel shows the right path under **MultiPHP Manager**, typically `/usr/local/bin/ea-php83`.

If your host only offers web cron, use the address shown under **Administration > Settings**:
```
wget -q -O /dev/null "https://wms.example.com/cron/run?key=YOUR-KEY"
```
If you cannot schedule anything at all, set **Run scheduled jobs on page visits** to Yes in Settings. Jobs then run while people use the app, which is enough for a small office but less reliable.

### 7. E-mail
**Administration > Settings**: set **Send e-mails from** to an address on your domain (create it in cPanel > **Email Accounts**). Leave the SMTP server empty to use the host's PHP mail, or fill in SMTP details for better delivery.

---

## Option B: Linux server (Ubuntu/Debian example)

### 1. Packages
```bash
sudo apt install apache2 libapache2-mod-php8.3 php8.3-{intl,mbstring,mysql,gd} mariadb-server unzip
# or for nginx: sudo apt install nginx php8.3-fpm php8.3-{intl,mbstring,mysql,gd} mariadb-server unzip
sudo a2enmod rewrite headers    # Apache only
```

### 2. Database
```bash
sudo mysql -e "CREATE DATABASE chatuwms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER 'chatuwms'@'localhost' IDENTIFIED BY 'choose-a-strong-password';
  GRANT ALL ON chatuwms.* TO 'chatuwms'@'localhost';"
```

### 3. Files
```bash
cd /var/www && sudo unzip /path/to/chatu-warehouse-0.2.1.zip
sudo chown -R www-data:www-data /var/www/chatuwarehouse/writable
sudo touch /var/www/chatuwarehouse/.env && sudo chown www-data:www-data /var/www/chatuwarehouse/.env
```

### 4. Web server
Use the examples in `deploy/`; both point the document root at `/var/www/chatuwarehouse/public`:
- Apache: `deploy/apache-chatuwarehouse.conf` to `/etc/apache2/sites-available/`, then `sudo a2ensite apache-chatuwarehouse && sudo systemctl reload apache2`
- nginx: `deploy/nginx-chatuwarehouse.conf` to `/etc/nginx/sites-available/`, link it into `sites-enabled`, check the PHP-FPM socket path, `sudo nginx -t && sudo systemctl reload nginx`

Get a certificate with `sudo certbot --apache` or `sudo certbot --nginx`.

### 5. Install
Either open the site in a browser and use the installer (as in cPanel step 5), or from the command line:

```bash
cd /var/www/chatuwarehouse
sudo -u www-data php spark wms:install                 # asks questions
# or unattended:
sudo -u www-data php spark wms:install --non-interactive \
  --base-url https://wms.example.com/ --db-name chatuwms --db-user chatuwms --db-pass '...' \
  --company "Acme Ltd" --wh-code MAIN --wh-name "Main warehouse" --admin-username admin --admin-name "Mary Banda" --admin-password '...' \
  --manager-username john --manager-name "John Phiri" --manager-password '...'
  # add --demo for demo warehouses with history
```

### 6. Scheduled job
```bash
sudo crontab -u www-data -e
*/5 * * * * php /var/www/chatuwarehouse/spark wms:cron >/dev/null 2>&1
```

---

## After installing

1. **Sign in as the administrator.** Under **Warehouses and bins** add your other warehouses, then zones and racks of bins (a rack at a time: prefix, aisles, bays, levels). Print barcode bin labels from the warehouse page (A4 label sheets of 3 × 8, or a label printer).
2. **Administration > Users and roles**: add each person and give them their roles, for the whole company or for one warehouse. The administrator cannot give roles to themselves, and cannot move or approve stock.
3. **Settings**: company name, address and TPIN, your logo (printed on delivery notes and other documents), currency symbol and code (ZMW; purchase orders can be in other currencies), the automatic limits for adjustments and counts (0 = always a second person), expiry and fleet warning periods, e-mail.
4. **The warehouse manager or buyer** adds items (with their tracking: batch, serial, expiry), suppliers and customers.
5. **Opening stock**: Goods received > Opening stock. Count each bin first; give batch numbers, expiry dates and serial numbers where items are tracked, and the cost. It needs the "enter opening stock" duty (warehouse manager).
6. **Check** Administration > Integrity check shows everything agrees.

## Updating from an earlier version (0.1 to 0.2)

Your data stays where it is; only the program files are replaced and the database gets the new columns and tables.

1. **Back up** the database (cPanel > phpMyAdmin > Export, or `mysqldump`) and the `writable/uploads` folder.
2. **Copy the new files over the old ones.** Extract the new zip on top of the existing folder. The zip contains no `.env`, no `writable/` data and no `installed.lock`, so your settings, uploads and installation are kept.
3. **Update the database**, either way:
   - on a server with a terminal: `php spark migrate --all` in the application folder;
   - on cPanel without a terminal: sign in as the administrator and open **Administration**. A yellow panel says a database update is needed; press **Update the database**.
4. Open **Administration > Integrity check** and run it: it should say everything agrees.

The update can be run more than once safely: it only adds what is missing. Users may need to reload the page once (or close and reopen the installed app) to get the new look and the offline look-up.

## Testing an installation

`php spark wms:test --db <a database whose name contains "test">` runs the automated acceptance tests (receiving, transfers, sales and distribution, production and traceability, fleet, adjustments and counts, packs, foreign-currency costs, wave picking, barcodes and the Chatu Assets vehicle import). Every test starts from a fresh demo company and ends with the integrity check. Never point it at the live database.

## Demo accounts

If you ticked demo data, the warehouses LSK (Lusaka) and KTW (Kitwe) exist with history (including packs on rice and milk, an open purchase order in US dollars, a pick wave of two orders and vehicles with Chatu Assets numbers), and these users, all with the password `Demo-pass-2026`: wendy (warehouse manager LSK), kalo (manager KTW), steve (storekeeper LSK), kim (storekeeper KTW), bella (buyer), sally (sales), paul (production), tom (transport), anna (accountant), dora (director), olive (auditor), sam (administrator). Switch them off before live use.
