# Render deployment

## Deployment analysis

This is a PHP 8.3 Apache MVC application. `public/` is the only web root, and Apache serves it through `docker/vhost.conf` with rewrite rules and upload execution disabled. The container entrypoint writes a runtime `.env` from environment variables, so no production secrets are baked into the image. PDO uses `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASS` (with `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` also accepted).

The local `docker-compose.yml` remains a development-only PHP + MySQL stack. Production uses the existing external FreeDB database; the Render Blueprint deliberately creates no MySQL service and the PHP web service must not receive `MYSQL_ROOT_PASSWORD`.

## Architecture

```text
GitHub (Development branch)
          |
          v
Render Web Service (Dockerfile)
          |
          v
PHP 8.3 + Apache (/public)
          |
          v
External FreeDB MySQL database (`freedb_xGoOT6yI`)
```

## Render configuration

Create the services through a **Blueprint** from `HajithMohamed/Footwear_Business_Management_System`:

| Setting | Value |
| --- | --- |
| Branch | `Development` |
| Runtime | `Docker` |
| Dockerfile path | `./Dockerfile` |
| Health check path | `/health.php` |
| Port | Do not set manually; the entrypoint listens on Render's `PORT` value. |

The included `render.yaml` creates only the web service. It uses the configured FreeDB host; set `DB_PASS` as a secret in the Render dashboard. Do not add any `MYSQL_*` variables to this service.

```dotenv
APP_NAME=Shoe Bank
APP_ENV=production
APP_DEBUG=false
APP_URL=https://footwear-business-management-system.onrender.com
APP_TIMEZONE=Asia/Colombo
DB_HOST=sql.freedb.tech
DB_PORT=3306
DB_NAME=freedb_xGoOT6yI
DB_USER=u_lZIrIf
DB_PASS=your-FreeDB-password-set-as-a-Render-secret
SESSION_NAME=footwear_erp_session
SESSION_LIFETIME=120
```

Use your actual Render URL for `APP_URL`; never commit it with credentials. Render terminates HTTPS before the container; the HTTPS `APP_URL` makes session cookies secure behind that proxy.

## Database initialization

The container applies `database/schema.sql` automatically at startup using
idempotent `CREATE TABLE IF NOT EXISTS` statements. For a fresh database, you
can also import the files manually using the provider's SQL tool. Select the
target database first, then import them in this exact order:

```bash
1. `database/schema.sql`
2. `database/seed.sql`
```

The files contain no `CREATE DATABASE`, `USE`, grants, or server-level commands, so no compatibility edit is required for an already-selected FreeDB database. `schema.sql` creates InnoDB/`utf8mb4` tables and foreign keys. An obsolete seed block for an earlier purchase-order schema was removed because those tables no longer exist in the current schema; the current purchase, arrival, and clearance tables remain intact. `seed.sql` includes `admin / admin123`; change that password immediately after first login. Do not rerun historical migrations blindly; apply only the migration required by a later release, after a database backup.

## Deploy and verify

1. Commit these hosting changes and push them to the `Development` branch.
2. In Render, create or update the Blueprint from the repository and branch.
3. Set the FreeDB `DB_HOST` and secret `DB_PASS` on the web service; confirm the database name/user values shown above.
4. Import `schema.sql`, then `seed.sql`, into the selected FreeDB database.
5. Deploy and wait for `/health.php` to return `ok`.
6. Open the Render URL and verify login/logout, protected pages, Products, Customers, Suppliers, and a representative sale workflow.

The service includes Tesseract OCR and Poppler utilities. `public/uploads`, `storage/logs`, and `storage/backups` are created writable at boot, but a normal Render filesystem is ephemeral: uploaded files and local logs/backups may be lost on restart or redeploy. Use object storage or a persistent disk for a future production hardening step; this deployment intentionally preserves the existing upload implementation.

The current overdue-notification script is an optional manual/cron task and is not run by the web service. Use a separate scheduled service only if you later enable real notification delivery.

## Troubleshooting

| Symptom | Check |
| --- | --- |
| 502 / deploy never becomes healthy | Check Render logs and confirm `/health.php` returns `ok`; do not override `PORT`. |
| Database connection failed | Verify host, port, credentials, database access allowlist, and whether the provider requires TLS. |
| 404s or missing CSS | Confirm Dockerfile path is `./Dockerfile`; Apache must retain `/var/www/html/public` as DocumentRoot. |
| Images/uploads fail | Ensure the upload is below PHP limits and remember Render's filesystem is not durable. |
| Login/session loops | Use the exact HTTPS Render/custom-domain URL as `APP_URL` and clear old browser cookies. |
| Docker build fails | Review the build log; the image requires Debian package access for GD, Tesseract, and Poppler. |

## Readiness

| Check | Status |
| --- | --- |
| Docker build | Verify locally / in Render build log |
| Apache and PHP | Ready; PHP 8.3 Apache image |
| MySQL connection | Requires supplied external credentials |
| Routing / `.htaccess` | Ready; existing rewrite configuration retained |
| Authentication / uploads / OCR | Preserved; verify against imported database |
| Production configuration | Ready; no credentials committed |
| Render readiness | Ready after external MySQL provisioning |
