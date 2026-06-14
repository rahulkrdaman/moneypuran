# Fix 403 Forbidden Error - MoneyPuran Deployment

## Problem

You're receiving a **403 Forbidden** error when accessing https://moneypuran.com

## Root Cause Analysis

The 403 error typically indicates one of these issues:

1. **Missing Nginx Configuration** - The nginx directory and configuration were empty
2. **Missing SSL Certificates** - SSL certificates required for HTTPS are not present
3. **Misconfigured Proxy** - Nginx is not properly proxying requests to the Next.js app
4. **Application Not Running** - The Next.js backend is not accessible

## Solution Implemented

### ✅ What Was Fixed

1. **Updated nginx.conf** with:
   - Proper HTTP to HTTPS redirect
   - SSL/TLS configuration with security headers
   - Correct proxy settings to Next.js backend on port 3000
   - Rate limiting for API protection
   - Static file caching
   - ACME challenge support for Let's Encrypt

2. **Added nginx/README.md** with:
   - SSL certificate setup instructions
   - Docker deployment commands
   - Troubleshooting guide

3. **Key Configuration Features**:
   - HTTPS enforcement (HTTP redirects to HTTPS)
   - Security headers (HSTS, X-Frame-Options, CSP)
   - Gzip compression
   - WebSocket support for real-time features
   - Rate limiting to prevent abuse

## Quick Start - Complete the Setup

### Step 1: Set Up SSL Certificates

Choose ONE of these options:

#### Option A: Let's Encrypt (FREE - Recommended)

```bash
# On your server, install certbot
cd /root/moneypuran  # or your repo directory
mkdir -p nginx/certs

# If using Ubuntu/Debian
sudo apt-get update
sudo apt-get install certbot -y

# Generate certificate
sudo certbot certonly --standalone \
  -d moneypuran.com \
  -d www.moneypuran.com \
  --email your-email@example.com \
  --agree-tos

# Copy to nginx directory
sudo cp /etc/letsencrypt/live/moneypuran.com/fullchain.pem nginx/certs/
sudo cp /etc/letsencrypt/live/moneypuran.com/privkey.pem nginx/certs/
sudo chown $USER:$USER nginx/certs/*
```

#### Option B: Self-Signed Certificate (Development/Testing)

```bash
mkdir -p nginx/certs

# Generate self-signed cert (valid 365 days)
openssl req -x509 -newkey rsa:4096 \
  -keyout nginx/certs/privkey.pem \
  -out nginx/certs/fullchain.pem \
  -days 365 -nodes \
  -subj "/C=IN/ST=State/L=City/O=MoneyPuran/CN=moneypuran.com"
```

#### Option C: Hostinger SSL (If Using Hostinger)

1. Log in to Hostinger Control Panel
2. Go to **Websites** → **SSL/TLS Certificates**
3. Install Free AutoSSL or purchase premium SSL
4. Download certificate files
5. Place in `nginx/certs/` directory:
   - Rename to `fullchain.pem` and `privkey.pem`

### Step 2: Verify Certificates Are in Place

```bash
ls -la nginx/certs/
```

You should see:
```
-rw-r--r-- fullchain.pem
-rw------- privkey.pem
```

### Step 3: Test Nginx Configuration

```bash
# Build and start containers
docker-compose down  # stop if running
docker-compose build
docker-compose up -d

# Test nginx configuration
docker exec moneypuran_nginx nginx -t
```

Expected output:
```
nginx: the configuration file /etc/nginx/nginx.conf syntax is ok
nginx: configuration file /etc/nginx/nginx.conf test is successful
```

### Step 4: Verify Application Is Running

```bash
# Check all containers
docker ps

# You should see:
# - moneypuran_db (PostgreSQL)
# - moneypuran_redis (Redis)
# - moneypuran_app (Next.js)
# - moneypuran_worker (Background jobs)
# - moneypuran_nginx (Nginx)

# Check app logs
docker logs moneypuran_app

# Check nginx logs
docker logs moneypuran_nginx
```

### Step 5: Test the Website

```bash
# From your browser or curl
curl -I https://moneypuran.com

# Should return 200 OK (not 403)
```

## Troubleshooting

### Issue: Still Getting 403 Forbidden

**Check 1: Are SSL certificates present?**
```bash
ls -la nginx/certs/
# Must show both fullchain.pem and privkey.pem
```

**Check 2: Is the app container running?**
```bash
docker ps | grep moneypuran_app
# Must show running status

# If not running, check logs
docker logs moneypuran_app
```

**Check 3: Can nginx reach the app?**
```bash
docker exec moneypuran_app curl http://localhost:3000
# Should return HTML, not 403
```

**Check 4: Nginx configuration errors**
```bash
docker exec moneypuran_nginx nginx -t
docker exec moneypuran_nginx tail -50 /var/log/nginx/error.log
```

**Check 5: Firewall/Port issues**
```bash
# Verify ports are accessible
sudo netstat -tlnp | grep -E ':80|:443'

# If using firewall, allow ports
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw reload
```

### Issue: SSL Certificate Errors

**Error: "No such file or directory"**
```bash
# Certificate files missing
mkdir -p nginx/certs
# Generate or copy certificates (see Step 1)
```

**Error: "Permission denied"**
```bash
# Fix permissions
chmod 644 nginx/certs/fullchain.pem
chmod 600 nginx/certs/privkey.pem
```

**Error: "SSL certificate chain is invalid"**
```bash
# Verify certificate
openssl x509 -in nginx/certs/fullchain.pem -text -noout

# Verify key matches cert
openssl x509 -noout -modulus -in nginx/certs/fullchain.pem | openssl md5
openssl rsa -noout -modulus -in nginx/certs/privkey.pem | openssl md5
# Both should output same hash
```

### Issue: Website Loads But Shows Errors

**Check Environment Variables**
```bash
docker exec moneypuran_app cat .env.production | grep NEXT_PUBLIC_APP_URL
# Should show: NEXT_PUBLIC_APP_URL=https://moneypuran.com
```

**Restart Services**
```bash
docker-compose restart
wait 30 seconds
# Test again
```

## Maintenance

### Renew Let's Encrypt Certificate

```bash
# Manual renewal
sudo certbot renew

# After renewal, copy to nginx/certs/
sudo cp /etc/letsencrypt/live/moneypuran.com/fullchain.pem nginx/certs/
sudo cp /etc/letsencrypt/live/moneypuran.com/privkey.pem nginx/certs/

# Reload nginx
docker exec moneypuran_nginx nginx -s reload
```

### Monitor Nginx

```bash
# View access logs
docker logs -f moneypuran_nginx

# Monitor connections
docker exec moneypuran_nginx netstat -an | grep ESTABLISHED | wc -l
```

## Success Indicators

✅ Website loads without 403 error
✅ Green/valid SSL certificate in browser
✅ `nginx -t` shows "successful"
✅ All containers running: `docker ps`
✅ No permission denied errors in logs
✅ Can curl the site: `curl -I https://moneypuran.com` returns 200

## Need More Help?

- Check nginx error logs: `docker logs moneypuran_nginx`
- Check app logs: `docker logs moneypuran_app`
- Test connectivity: `docker exec moneypuran_nginx curl http://app:3000`
- Review nginx config: `docker exec moneypuran_nginx cat /etc/nginx/nginx.conf`
