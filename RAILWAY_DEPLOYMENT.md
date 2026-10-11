# GasGo — Full Railway Deployment Guide

The existing PHP site, frontend chatbot UI, manager/admin pages, live gas-station information, and MySQL schema remain in this package. Railway runs the PHP site and MySQL as **two services in one Railway project**. No separate chatbot server is required.

## 0. Mandatory security step

The original uploaded archive contained an OpenAI API key and a database password directly in PHP code. **Revoke/rotate BOTH now**, as well as any other credentials reused elsewhere. The revised source uses environment variables instead. Do not publish the original archive or import its historical credentials into GitHub.

## 1. Put the project into a private GitHub repository

Unzip `GasGo_Railway_Ready.zip`. Make the folder's contents the repository root: `Dockerfile`, `index.php`, `config/`, `public/`, etc. Do not commit any real `.env` file. This package contains historical station images for continuity (check that you have the right to store/share them). You may remove photos you do not need before pushing.

Railway can deploy a GitHub repository using the included Dockerfile. The PHP application will listen on Railway's `PORT` environment variable. Keep the service root directory set to the repository root.

## 2. Add MySQL service on Railway

1. Sign in at https://railway.com and create a **New Project**.
2. Add a **MySQL** database service (Railway's MySQL template).
3. Record its service name (the example below assumes it is exactly `MySQL`).
4. The database is private by default; the web app connects over Railway's internal network.

## 3. Add and configure GasGo web service

1. In the same Railway project, add a service via **GitHub Repo** and select your GasGo repo.
2. Railway detects the root `Dockerfile` and builds PHP 8.3 + Apache with `pdo_mysql`, `mysqli`, and `mbstring`.
3. Open **GasGo Web service → Variables**. Add these *variable references* (not the literal password values):

```text
DB_HOST=${{MySQL.MYSQLHOST}}
DB_PORT=${{MySQL.MYSQLPORT}}
DB_DATABASE=${{MySQL.MYSQLDATABASE}}
DB_USERNAME=${{MySQL.MYSQLUSER}}
DB_PASSWORD=${{MySQL.MYSQLPASSWORD}}
OPENAI_API_KEY=YOUR_NEW_OPENAI_API_KEY
OPENAI_MODEL=gpt-4.1-mini
```

Replace `MySQL` in `${{MySQL...}}` if the actual database service is named differently. Prefer Railway's variable reference picker. Keep `OPENAI_API_KEY` secret. An OpenAI API key requires an API account with billing/credits; a ChatGPT subscription does not provide API usage by itself.

4. Deploy changes and generate a public domain in **GasGo service → Settings → Networking → Public Networking**. The example domain `gasgo.up.railway.app` is **not guaranteed**; use the URL Railway actually assigns.
5. Open `https://YOUR-RAILWAY-DOMAIN/health.php`. Expected: `{"status":"ok","service":"gasgo"}`.

**Important:** The homepage needs MySQL data. It will not work fully until the database has been imported.

## 4. Import your MySQL data (critical)

The included `database/schema.sql` and `database/seed.sql` are for initializing a *new* database. If you already have production managers, stations, reviews, LPG, prices, and requests in your original InfinityFree MySQL database, **export that live database** from phpMyAdmin as a SQL file (schema + data) and import the export into Railway instead. Do **not** run `seed.sql` over live data. A clean initial installation can import `schema.sql` first and `seed.sql` if desired; this will not recover historic records.

To import into Railway:

1. Open the Railway MySQL service → **Connect** (or Variables) to obtain its external connection details. Turn on TCP public access temporarily if using a local MySQL client. This may have egress charges.
2. Use MySQL Workbench, DBeaver, or `mysql` CLI locally to import the export into the Railway database.
3. Example (replace placeholders with values from Railway's **public** TCP connection, not the internal service address):

```bash
mysql --host=PUBLIC_MYSQL_HOST --port=PUBLIC_MYSQL_PORT --user=MYSQL_USER --password MYSQL_DATABASE < your-export.sql
```

4. Disable public access once migration is complete if not required.
5. Confirm that `gas_stations`, `reviews`, `faqs`, `station_lpg_products`, and manager tables/rows exist.

Never put your SQL production export (it contains potentially sensitive records) into a public GitHub repository.

## 5. Preserve uploaded station photos across deployments

Railway deployments have ephemeral container filesystems. This project currently stores uploaded station photos under `public/uploads/stations/` and may also resolve older image paths under `uploads/stations/`.

**Recommended:** Add a **Railway Volume** to the GasGo web service at mount path:

```text
/var/www/html/public/uploads/stations
```

The container startup script seeds the original station images bundled in the project into the volume on first launch, without overwriting matching existing filenames. When uploading new photos, the application writes into the volume. Do not repeatedly delete/recreate the volume if you want to retain new uploads. This protects the primary station-photo location used by the app.

If you still have image records that resolve only via `uploads/stations/`, migrate those images to `public/uploads/stations/` and update their stored paths to the expected basenames, or attach an additional storage solution. Additional application file paths should be reviewed before using them for production uploads.

Railway volumes and MySQL may incur usage costs. Back up the database and photos separately. Volume mounting may require service redeployment.

## 6. Test the OpenAI chatbot

1. Visit the website homepage after importing the database.
2. Open the existing chatbot widget and ask `Which approved station has the cheapest diesel?`.
3. Your browser continues calling the same relative endpoint: `public/api/ai_chat.php`.
4. That PHP API loads station rows from Railway MySQL, attaches live station context, and sends a HTTPS POST to OpenAI's Responses API.
5. If chat fails, open Railway web-service logs. Check `OPENAI_API_KEY`, API billing, model permission, DNS/network access, and presence of required database tables. Missing keys return HTTP 503; upstream/API errors return HTTP 502.

## 7. Checklist

- [ ] Rotate the exposed old OpenAI key and database password
- [ ] Deploy from a **private** repository, or review all included files before publishing
- [ ] Add Railway MySQL service
- [ ] Set all six web-service environment variables in Railway
- [ ] Import live database export rather than overwriting data with demo seed
- [ ] Mount a Railway volume for `public/uploads/stations`
- [ ] Confirm website loads and images appear
- [ ] Confirm admin and station-manager pages work
- [ ] Confirm chatbot gets fresh station data and returns an answer
- [ ] Create ongoing MySQL and photo backups

## Troubleshooting

- **Database is not configured:** missing MySQL variable references on *web* service.
- **Database connection failed:** database not ready, wrong service name/reference, or wrong access details.
- **Chatbot says not configured:** set a new `OPENAI_API_KEY` on the web service.
- **OpenAI API error:** check Railway logs, model selection, API key validity, OpenAI usage limits, and API credits.
- **Station image disappeared after deploy:** add/check the volume or restore the photo files from backup. Check that the DB stores image basenames consistent with current upload paths.
- **Railway domain shows 502:** check web service deploy logs and exposed port; `docker/railway-entrypoint.sh` uses `$PORT` (default 8080).

Documentation: https://docs.railway.com/builds/dockerfiles | https://docs.railway.com/databases/mysql | https://docs.railway.com/variables
