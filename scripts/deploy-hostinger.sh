#!/bin/bash

# ====================================================================
# MoneyPuran - Deployment Script (Hostinger MySQL, No Redis)
# ====================================================================
# This script will:
# 1. Pull latest code from GitHub
# 2. Generate SSL certificates (Let's Encrypt)
# 3. Create .env with Hostinger MySQL credentials
# 4. Update docker-compose.yml to skip Redis
# 5. Build Docker images
# 6. Start services (App + Nginx only)
# 7. Verify everything works
# ====================================================================

set -e

# Color codes
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# ====================================================================
# Configuration - UPDATE THESE WITH YOUR HOSTINGER CREDENTIALS
# ====================================================================

DEPLOY_DIR="/root/moneypuran"
DOMAIN="moneypuran.com"
CERT_EMAIL="admin@moneypuran.com"  # Change to your email

# Hostinger MySQL Credentials
MYSQL_HOST="localhost"  # Or your Hostinger DB server
MYSQL_PORT="3306"
MYSQL_USER="u286969112_moneypuran_db"
MYSQL_PASSWORD="Moneypuran145@#\$&#!\$%"
MYSQL_DATABASE="u286969112_moneypuran_db"

# ====================================================================
# Helper Functions
# ====================================================================

log_info() { echo -e "${BLUE}ℹ️  $1${NC}"; }
log_success() { echo -e "${GREEN}✅ $1${NC}"; }
log_warning() { echo -e "${YELLOW}⚠️  $1${NC}"; }
log_error() { echo -e "${RED}❌ $1${NC}"; }

# ====================================================================
# Step 1: Checkout Code
# ====================================================================

log_info "Step 1: Setting up repository..."

if [ ! -d "$DEPLOY_DIR" ]; then
    mkdir -p "$DEPLOY_DIR"
    cd "$DEPLOY_DIR"
    git init
    git remote add origin https://github.com/rahulkrdaman/moneypuran.git
else
    cd "$DEPLOY_DIR"
fi

git fetch origin main
git checkout -f origin/main

log_success "Repository ready at $DEPLOY_DIR"

# ====================================================================
# Step 2: Create SSL Certificates
# ====================================================================

log_info "Step 2: Setting up SSL certificates..."

mkdir -p nginx/certs

if [ -f "nginx/certs/fullchain.pem" ] && [ -f "nginx/certs/privkey.pem" ]; then
    log_success "SSL certificates already exist"
else
    log_warning "SSL certificates not found, generating..."
    
    if ! command -v certbot &> /dev/null; then
        log_info "Installing certbot..."
        apt-get update
        apt-get install -y certbot
    fi
    
    # Stop docker temporarily
    docker-compose down 2>/dev/null || true
    sleep 5
    
    log_info "Generating Let's Encrypt certificate for $DOMAIN..."
    certbot certonly --standalone \
        --non-interactive \
        --agree-tos \
        --email "$CERT_EMAIL" \
        -d "$DOMAIN" \
        -d "www.$DOMAIN" 2>&1 || {
        log_warning "Certificate generation failed, using self-signed..."
    }
    
    # Copy certificates
    if [ -f "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" ]; then
        cp /etc/letsencrypt/live/$DOMAIN/fullchain.pem nginx/certs/
        cp /etc/letsencrypt/live/$DOMAIN/privkey.pem nginx/certs/
        chmod 644 nginx/certs/fullchain.pem
        chmod 600 nginx/certs/privkey.pem
        log_success "SSL certificates installed from Let's Encrypt"
    else
        log_warning "Using self-signed certificate..."
        openssl req -x509 -newkey rsa:4096 \
            -keyout nginx/certs/privkey.pem \
            -out nginx/certs/fullchain.pem \
            -days 365 -nodes \
            -subj "/C=IN/ST=State/L=City/O=MoneyPuran/CN=$DOMAIN"
        log_success "Self-signed certificate created"
    fi
fi

# ====================================================================
# Step 3: Create .env Configuration (Hostinger MySQL)
# ====================================================================

log_info "Step 3: Creating .env configuration with Hostinger MySQL..."

cat > .env << ENVEOF
# ═══════════════════════════════════════════════════════════════════
# MoneyPuran - Hostinger MySQL Configuration (No Redis)
# ═══════════════════════════════════════════════════════════════════

# ── DATABASE (Hostinger MySQL) ─────────────────────────────────────
# Format: mysql://username:password@host:port/database
DATABASE_URL="mysql://${MYSQL_USER}:${MYSQL_PASSWORD}@${MYSQL_HOST}:${MYSQL_PORT}/${MYSQL_DATABASE}"

# ── JWT Authentication ─────────────────────────────────────────────
JWT_SECRET="moneypuran-prod-jwt-secret-\$(openssl rand -hex 24)"
JWT_REFRESH_SECRET="moneypuran-refresh-secret-\$(openssl rand -hex 24)"
JWT_EXPIRES_IN="15m"
JWT_REFRESH_EXPIRES_IN="7d"

# ── Application Configuration ──────────────────────────────────────
NEXT_PUBLIC_APP_URL="https://${DOMAIN}"
NODE_ENV="production"
PORT="3000"
HOSTNAME="0.0.0.0"

# ── OpenAI Configuration (Optional) ────────────────────────────────
OPENAI_API_KEY="sk-your-openai-api-key-here"
OPENAI_MODEL="gpt-4o-mini"

# ── Google Analytics & AdSense (Optional) ──────────────────────────
NEXT_PUBLIC_GA_ID=""
NEXT_PUBLIC_ADSENSE_ID=""

# ── Admin Account (First Deploy Only) ──────────────────────────────
ADMIN_EMAIL="admin@moneypuran.com"
ADMIN_PASSWORD="Admin@123"
ENVEOF

log_success ".env file created with Hostinger MySQL credentials"

# ====================================================================
# Step 4: Update docker-compose.yml (Remove Redis, Postgres)
# ====================================================================

log_info "Step 4: Creating docker-compose.yml without Redis/Postgres..."

cat > docker-compose.yml << 'DOCKEREOF'
version: "3.9"

services:
  app:
    build:
      context: .
      dockerfile: Dockerfile
    container_name: moneypuran_app
    restart: unless-stopped
    environment:
      NODE_ENV: production
      DATABASE_URL: mysql://u286969112_moneypuran_db:Moneypuran145@#$&#!$%@localhost:3306/u286969112_moneypuran_db
    ports:
      - "3000:3000"
    volumes:
      - ./public/uploads:/app/public/uploads
    networks:
      - moneypuran_network
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:3000"]
      interval: 30s
      timeout: 10s
      retries: 3

  nginx:
    image: nginx:alpine
    container_name: moneypuran_nginx
    restart: unless-stopped
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./nginx/nginx.conf:/etc/nginx/nginx.conf:ro
      - ./nginx/certs:/etc/nginx/certs:ro
      - ./public/uploads:/var/www/uploads:ro
    depends_on:
      - app
    networks:
      - moneypuran_network

networks:
  moneypuran_network:
    driver: bridge

volumes: {}
DOCKEREOF

log_success "docker-compose.yml updated (Redis & PostgreSQL removed)"

# ====================================================================
# Step 5: Install Docker & Docker Compose
# ====================================================================

log_info "Step 5: Checking Docker installation..."

if ! command -v docker &> /dev/null; then
    log_warning "Docker not found, installing..."
    curl -fsSL https://get.docker.com -o get-docker.sh
    sh get-docker.sh
    usermod -aG docker root
    log_success "Docker installed"
else
    log_success "Docker is already installed"
fi

if ! command -v docker-compose &> /dev/null; then
    log_warning "Docker Compose not found, installing..."
    apt-get install -y docker-compose || pip install docker-compose
    log_success "Docker Compose installed"
else
    log_success "Docker Compose is already installed"
fi

# ====================================================================
# Step 6: Build Docker Images
# ====================================================================

log_info "Step 6: Building Docker images..."

# Build with cache initially
docker-compose build 2>&1 | grep -E '(Building|Successfully|ERROR)' || docker-compose build

if [ ${PIPESTATUS[0]} -eq 0 ]; then
    log_success "Docker images built successfully"
else
    log_error "Docker build failed"
    exit 1
fi

# ====================================================================
# Step 7: Start Services
# ====================================================================

log_info "Step 7: Starting Docker services..."

docker-compose down 2>/dev/null || true
sleep 5

docker-compose up -d

log_success "Services started"
log_info "Waiting 20 seconds for services to initialize..."
sleep 20

# ====================================================================
# Step 8: Health Checks
# ====================================================================

log_info "Step 8: Running health checks..."

# Check Nginx
if docker exec moneypuran_nginx nginx -t &>/dev/null; then
    log_success "Nginx configuration is valid"
else
    log_error "Nginx configuration error"
    docker logs moneypuran_nginx | tail -20
    exit 1
fi

# Check App Container
if docker ps | grep -q moneypuran_app; then
    log_success "App container is running"
else
    log_error "App container failed to start"
    docker logs moneypuran_app | tail -50
    exit 1
fi

# Check if App is Responsive
if docker exec moneypuran_app curl -s http://localhost:3000 &>/dev/null; then
    log_success "App is responding on port 3000"
else
    log_warning "App health check inconclusive, checking container..."
    docker logs moneypuran_app | tail -30
fi

# ====================================================================
# Step 9: Certificate Auto-Renewal (Cron Job)
# ====================================================================

log_info "Step 9: Setting up certificate auto-renewal..."

if command -v certbot &> /dev/null; then
    cat > /usr/local/bin/renew-certs.sh << 'SCRIPTEOF'
#!/bin/bash
DEPLOY_DIR="/root/moneypuran"
DOMAIN="moneypuran.com"

certbot renew --quiet || true

if [ -f "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" ]; then
    cp /etc/letsencrypt/live/$DOMAIN/fullchain.pem $DEPLOY_DIR/nginx/certs/
    cp /etc/letsencrypt/live/$DOMAIN/privkey.pem $DEPLOY_DIR/nginx/certs/
    chmod 644 $DEPLOY_DIR/nginx/certs/fullchain.pem
    chmod 600 $DEPLOY_DIR/nginx/certs/privkey.pem
    docker exec moneypuran_nginx nginx -s reload 2>/dev/null || true
fi
SCRIPTEOF

    chmod +x /usr/local/bin/renew-certs.sh
    
    # Add to crontab
    (crontab -l 2>/dev/null | grep -v 'renew-certs.sh'; echo "0 2 * * * /usr/local/bin/renew-certs.sh") | crontab -
    
    log_success "Certificate auto-renewal configured (daily at 2 AM)"
else
    log_warning "Certbot not available, skipping auto-renewal"
fi

# ====================================================================
# Step 10: Display Results
# ====================================================================

log_info "Step 10: Verifying deployment..."

echo ""
echo -e "${GREEN}╔════════════════════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║     ✅ MoneyPuran Deployment Complete (Hostinger MySQL)      ║${NC}"
echo -e "${GREEN}╚════════════════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "${BLUE}Website URLs:${NC}"
echo "  🌐 HTTP:  http://$DOMAIN"
echo "  🔒 HTTPS: https://$DOMAIN"
echo ""
echo -e "${BLUE}Admin Panel:${NC}"
echo "  📊 Dashboard: https://$DOMAIN/admin"
echo "  👤 Username: admin@moneypuran.com"
echo "  🔑 Password: Admin@123"
echo ""
echo -e "${BLUE}Database Configuration:${NC}"
echo "  🗄️  Host: $MYSQL_HOST"
echo "  👤 User: $MYSQL_USER"
echo "  💾 Database: $MYSQL_DATABASE"
echo ""
echo -e "${BLUE}Docker Containers:${NC}"
docker ps --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"
echo ""
echo -e "${BLUE}Useful Commands:${NC}"
echo "  📊 View app logs:     docker logs -f moneypuran_app"
echo "  🔍 Check status:      docker ps"
echo "  ♻️  Restart app:       docker-compose restart app"
echo "  🛑 Stop services:     docker-compose down"
echo "  🚀 Start services:    docker-compose up -d"
echo "  🔧 Rebuild images:    docker-compose build --no-cache"
echo ""
echo -e "${BLUE}Configuration Files:${NC}"
echo "  ⚙️  Environment: $DEPLOY_DIR/.env"
echo "  🔐 Certificates: $DEPLOY_DIR/nginx/certs/"
echo "  🌐 Nginx config: $DEPLOY_DIR/nginx/nginx.conf"
echo "  🐳 Docker config: $DEPLOY_DIR/docker-compose.yml"
echo ""
echo -e "${YELLOW}⚠️  Important:${NC}"
echo "  1. Test database connection: mysql -h$MYSQL_HOST -u$MYSQL_USER -p"
echo "  2. Change ADMIN_PASSWORD in .env before sharing"
echo "  3. Update JWT secrets with random values in .env"
echo "  4. Test your website: https://$DOMAIN"
echo ""
echo -e "${BLUE}Troubleshooting:${NC}"
echo "  If you see 403 Forbidden:"
echo "  - Check app logs: docker logs moneypuran_app"
echo "  - Verify database connection: docker exec moneypuran_app mysql -h$MYSQL_HOST -u$MYSQL_USER -p$MYSQL_PASSWORD"
echo "  - Check nginx config: docker exec moneypuran_nginx nginx -t"
echo ""

log_success "Deployment script completed successfully!"
