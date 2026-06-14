# Nginx Configuration for MoneyPuran

## Overview

This directory contains the Nginx configuration to serve the MoneyPuran Next.js application with SSL/TLS support.

## Files

- **nginx.conf** - Main Nginx configuration file
- **certs/** - Directory for SSL certificates (not in repo, must be created)

## SSL Certificate Setup

### Option 1: Using Let's Encrypt with Certbot (Recommended)

```bash
# Install certbot
apt-get install certbot python3-certbot-nginx

# Generate certificate (replace email and domain)
certbot certonly --standalone \
  -d moneypuran.com \
  -d www.moneypuran.com \
  --email your-email@example.com \
  --agree-tos

# Copy certificates to nginx/certs/
sudo cp /etc/letsencrypt/live/moneypuran.com/fullchain.pem nginx/certs/
sudo cp /etc/letsencrypt/live/moneypuran.com/privkey.pem nginx/certs/
sudo chown $USER:$USER nginx/certs/*
```

### Option 2: Using Self-Signed Certificate (Development Only)

```bash
# Create certs directory
mkdir -p nginx/certs

# Generate self-signed certificate (valid for 365 days)
openssl req -x509 -newkey rsa:4096 -keyout nginx/certs/privkey.pem \
  -out nginx/certs/fullchain.pem -days 365 -nodes \
  -subj "/C=IN/ST=State/L=City/O=MoneyPuran/CN=moneypuran.com"
```

### Option 3: Using Hostinger SSL Certificates

If using Hostinger hosting:

1. Go to Hostinger Control Panel → SSL/TLS
2. Install or generate an SSL certificate
3. Download the certificate files
4. Place them in `nginx/certs/` as:
   - `fullchain.pem` (certificate + CA bundle)
   - `privkey.pem` (private key)

## Docker Setup

The `docker-compose.yml` automatically mounts the nginx configuration:

```yaml
volumes:
  - ./nginx/nginx.conf:/etc/nginx/nginx.conf:ro
  - ./nginx/certs:/etc/nginx/certs:ro
```

## Deployment Commands

### Start the application

```bash
docker-compose up -d
```

### Check nginx status

```bash
docker exec moneypuran_nginx nginx -t
```

### View nginx logs

```bash
docker logs moneypuran_nginx
```

### Reload nginx configuration

```bash
docker exec moneypuran_nginx nginx -s reload
```

## Troubleshooting 403 Forbidden Error

### Common Causes and Solutions

1. **Missing SSL Certificates**
   - Ensure `nginx/certs/fullchain.pem` and `nginx/certs/privkey.pem` exist
   - If missing, generate using one of the options above

2. **Incorrect Permissions**
   ```bash
   chmod 644 nginx/certs/fullchain.pem
   chmod 600 nginx/certs/privkey.pem
   ```

3. **Next.js App Not Running**
   - Check if the app container is running: `docker ps`
   - Verify app logs: `docker logs moneypuran_app`
   - Ensure app is listening on port 3000

4. **Nginx Configuration Errors**
   ```bash
   # Test configuration
   docker exec moneypuran_nginx nginx -t
   
   # View error logs
   docker exec moneypuran_nginx tail -f /var/log/nginx/error.log
   ```

5. **Domain Not Resolving**
   - Verify DNS records point to server IP
   - Update `server_name` in nginx.conf if domain is different

6. **Port Access Issues**
   - Verify ports 80 and 443 are open: `sudo ufw allow 80 && sudo ufw allow 443`
   - Check firewall rules on hosting provider

## Security Features

✅ HTTPS/TLS encryption
✅ Security headers (X-Frame-Options, X-Content-Type-Options, etc.)
✅ Gzip compression
✅ Rate limiting for API and general traffic
✅ Denial of service protection
✅ Hidden dot files and temporary files

## Performance Optimization

✅ Caching for static assets (1 year)
✅ Caching for uploads (7 days)
✅ HTTP/2 support
✅ Connection keepalive
✅ Gzip compression level 6
✅ Worker process optimization

## Renewal of Let's Encrypt Certificates

To auto-renew Let's Encrypt certificates:

```bash
# Add to crontab
0 0 1 * * certbot renew --quiet
```

Or use automated renewal in Docker:

```bash
from docker-compose.yml, add a renewal service
```
