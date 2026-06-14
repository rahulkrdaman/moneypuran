# MoneyPuran — Local Setup Guide (MySQL)

## What you need installed

| Tool | Version | Download |
|------|---------|----------|
| Node.js | 20 or higher | https://nodejs.org |
| MySQL | 8.0+ | https://dev.mysql.com/downloads/mysql |
| Redis | 7+ | https://redis.io/download |
| Git | any | https://git-scm.com |

> **Windows users:** Install MySQL via the MySQL Installer (includes MySQL Workbench GUI).  
> **macOS users:** `brew install mysql redis` is the easiest path.

---

## Step-by-step setup

### Step 1 — Extract the project

```bash
unzip moneypuran.zip
cd moneypuran
```

---

### Step 2 — Install dependencies

```bash
npm install
```

---

### Step 3 — Create the MySQL database

**Option A — MySQL command line:**
```bash
# Log in to MySQL (use your root password)
mysql -u root -p

# Inside MySQL shell:
CREATE DATABASE moneypuran_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'moneypuran'@'localhost' IDENTIFIED BY 'your_password';
GRANT ALL PRIVILEGES ON moneypuran_dev.* TO 'moneypuran'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

**Option B — MySQL Workbench GUI:**
1. Open MySQL Workbench
2. Right-click "Schemas" → Create Schema
3. Name: `moneypuran_dev`, Charset: `utf8mb4`, Collation: `utf8mb4_unicode_ci`

---

### Step 4 — Configure your .env

Open `.env` and update the DATABASE_URL with your MySQL credentials:

```env
DATABASE_URL="mysql://root:YOUR_PASSWORD@localhost:3306/moneypuran_dev"
```

> Replace `root` with your MySQL username and `YOUR_PASSWORD` with your password.  
> If you created a separate user in Step 3: `mysql://moneypuran:your_password@localhost:3306/moneypuran_dev`

---

### Step 5 — Run database migration

```bash
npx prisma migrate dev --name init
```

This creates all tables in your MySQL database. You'll see:
```
✔  Generated Prisma Client
✔  Applied migration `init`
```

---

### Step 6 — Seed the database

```bash
npm run db:seed
```

Creates:
- ✅ Admin user: `admin@moneypuran.com` / `Admin@123`
- ✅ 10 categories (Markets, Economy, Stocks, Crypto…)
- ✅ SEO & AI settings
- ✅ 5 RSS sources
- ✅ Sample ads

---

### Step 7 — Start the dev server

```bash
npm run dev
```

Open **http://localhost:3000**

| URL | Description |
|-----|-------------|
| http://localhost:3000 | Public news website |
| http://localhost:3000/admin/login | Admin login |
| http://localhost:3000/admin | Dashboard (after login) |

**Admin login:** `admin@moneypuran.com` / `Admin@123`

---

### Step 8 — (Optional) Start the AI worker

Open a **second terminal**:
```bash
npm run worker:start
```
Fetches RSS feeds and rewrites articles every 30 min. Needs a real `OPENAI_API_KEY`.

---

## Hostinger MySQL setup

On Hostinger:
1. Go to **Hosting → Databases → MySQL**
2. Create a new database (e.g., `u123456_moneypuran`)
3. Create a database user and note the password
4. Your connection string will be:
   ```
   mysql://u123456_dbuser:PASSWORD@localhost:3306/u123456_moneypuran
   ```
5. Set this as `DATABASE_URL` in your environment variables dashboard

> Hostinger uses `localhost` as the MySQL host for apps on the same server.

---

## Common errors & fixes

### ❌ `Can't connect to MySQL server on '127.0.0.1'`
MySQL isn't running:
```bash
# macOS
brew services start mysql

# Ubuntu/Debian
sudo systemctl start mysql

# Windows — open Services → start "MySQL80"
```

### ❌ `Access denied for user 'root'@'localhost'`
Wrong password in DATABASE_URL. Reset MySQL root password:
```bash
mysql -u root -p
ALTER USER 'root'@'localhost' IDENTIFIED BY 'new_password';
FLUSH PRIVILEGES;
```

### ❌ `Unknown database 'moneypuran_dev'`
Database not created yet. Run Step 3 again.

### ❌ `connect ECONNREFUSED 127.0.0.1:6379` (Redis)
Redis isn't running. If you don't need background queues yet, the app still works — Redis is optional for local dev:
```bash
# macOS
brew services start redis

# Ubuntu
sudo systemctl start redis

# No Redis at all? The app starts fine — queues just won't process.
```

### ❌ `PrismaClientInitializationError`
Run `npx prisma generate` to rebuild the Prisma client:
```bash
npx prisma generate
npx prisma migrate dev
```

### ❌ Port 3000 already in use
```bash
npm run dev -- -p 3001
# Then visit http://localhost:3001
```

---

## Useful commands

```bash
npm run dev          # Start dev server (hot reload)
npm run build        # Production build
npm start            # Start production server
npm run db:seed      # Seed database
npm run db:studio    # Open Prisma Studio (visual DB browser)
npm run worker:start # Start AI content worker
npx prisma migrate dev    # Run new migrations
npx prisma migrate reset  # Reset DB and re-run all migrations (⚠️ deletes data)
```

---

## Environment variables reference

| Variable | Required | Example |
|----------|----------|---------|
| `DATABASE_URL` | ✅ | `mysql://root:pass@localhost:3306/moneypuran_dev` |
| `REDIS_URL` | ⚠️ Optional | `redis://localhost:6379` |
| `JWT_SECRET` | ✅ | any 32+ char string |
| `JWT_REFRESH_SECRET` | ✅ | any 32+ char string |
| `OPENAI_API_KEY` | ⚠️ Optional | `sk-...` |
| `NEXT_PUBLIC_APP_URL` | ✅ | `http://localhost:3000` |
| `NEXT_PUBLIC_GA_ID` | ⚪ Optional | `G-XXXXXXXXXX` |
