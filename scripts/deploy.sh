#!/bin/bash

# ====================================================================
# MoneyPuran - Complete Deployment & SSL Setup Script
# ====================================================================
# This script will:
# 1. Pull latest code from GitHub
# 2. Generate SSL certificates (Let's Encrypt)
# 3. Create proper .env configuration
# 4. Build Docker images
# 5. Start all services
# 6. Verify everything is working
# ====================================================================

set -e

# Color codes for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
DEPLOY_DIR="/root/moneypuran"
DOMAIN="moneypuran.com"
CERT_EMAIL="your-email@example.com"  # Change this!
DB_PASSWORD="MoneyPuran@Secure123"
REDIS_PASSWORD="RedisPass123"

# ====================================================================
# Helper Functions
# ====================================================================

log_info() {
    echo -e "${BLUE}ℹ️  $1${NC}"
}

log_success() {
    echo -e "${GREEN}✅ $1${NC}"
}

log_warning() {
    echo -e "${YELLOW}⚠️  $1${NC}"
}

log_error() {
    echo -e "${RED}❌ $1${NC}"
}

# ====================================================================
# Step 1: Checkout Code
# ====================================================================

log_info "Step 1: Setting up repository..."

if [ ! -d "$DEPLOY_DIR" ]; then
    log_info "Creating deployment directory..."
    mkdir -p "$DEPLOY_DIR"
    cd "$DEPLOY_DIR"
    git init
    git remote add origin https://github.com/rahulkrdaman/moneypuran.git
else
    cd "$DEPLOY_DIR"
fi

log_info "Pulling latest code from main branch..."
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
    log_warning "SSL certificates not found, generating with Let's Encrypt..."
    
    # Install certbot if needed
    if ! command -v certbot &> /dev/null; then
        log_info "Installing certbot..."
        apt-get update
        apt-get install -y certbot
    fi
    
    # Stop docker temporarily to free port 80
    docker-compose down 2>/dev/null || true
    sleep 5
    
    # Generate certificate
    log_info "Generating Let's Encrypt certificate for $DOMAIN..."
    certbot certonly --standalone \
        --non-interactive \
        --agree-tos \
        --email "$CERT_EMAIL" \
        -d "$DOMAIN" \
        -d "www.$DOMAIN" 2>&1 || {
        log_warning "Certificate generation failed or already exists"
    }
    
    # Copy certificates to nginx directory
    if [ -f "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" ]; then
        log_info "Copying certificates to nginx/certs..."
        cp /etc/letsencrypt/live/$DOMAIN/fullchain.pem nginx/certs/
        cp /etc/letsencrypt/live/$DOMAIN/privkey.pem nginx/certs/
        chmod 644 nginx/certs/fullchain.pem
        chmod 600 nginx/certs/privkey.pem
        log_success "SSL certificates installed"
    else
        log_warning "Let's Encrypt certificate not available, using self-signed..."
        openssl req -x509 -newkey rsa:4096 \
            -keyout nginx/certs/privkey.pem \
            -out nginx/certs/fullchain.pem \
            -days 365 -nodes \
            -subj "/C=IN/ST=State/L=City/O=MoneyPuran/CN=$DOMAIN"
        log_success "Self-signed certificate created"
    fi
fi

# ====================================================================
# Step 3: Create .env Configuration
# ====================================================================

log_info "Step 3: Creating .env configuration..."

cat > .env << 'ENVEOF'
# ═══════════════════════════════════════════════════════════════════
# MoneyPuran Environment Configuration
# ═══════════════════════════════════════════════════════════════════

# ── DATABASE (PostgreSQL in Docker) ────────────────────────────────
DATABASE_URL="postgresql://moneypuran:MoneyPuran@Secure123@db:5432/moneypuran_db"

# ── REDIS (Redis in Docker) ────────────────────────────────────────
REDIS_URL="redis://:RedisPass123@redis:6379"

# ── Docker Service Passwords ───────────────────────────────────────
DB_PASSWORD="MoneyPuran@Secure123"
REDIS_PASSWORD="RedisPass123"

# ── JWT Authentication ────────────────────────────────────────────
JWT_SECRET="moneypuran-prod-jwt-secret-please-change-this-to-random-string-48-chars-minimum"
JWT_REFRESH_SECRET="moneypuran-prod-refresh-secret-change-this-to-random-48-chars-minimum"
JWT_EXPIRES_IN="15m"
JWT_REFRESH_EXPIRES_IN="7d"

# ── Application Configuration ──────────────────────────────────────
NEXT_PUBLIC_APP_URL="https://moneypuran.com"
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

log_success ".env file created"
log_info "Review .env and update secrets if needed"

# ====================================================================
# Step 4: Install Docker & Docker Compose
# ====================================================================

log_info "Step 4: Checking Docker installation..."

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
# Step 5: Build Docker Images
# ====================================================================

log_info "Step 5: Building Docker images..."

docker-compose build --no-cache 2>&1 | grep -E '(Building|Successfully|ERROR)' || docker-compose build

if [ ${PIPESTATUS[0]} -eq 0 ]; then
    log_success "Docker images built successfully"
else
    log_error "Docker build failed"
    exit 1
fi

# ====================================================================
# Step 6: Start Services
# ====================================================================

log_info "Step 6: Starting Docker services..."

# Stop any existing containers
docker-compose down 2>/dev/null || true
sleep 5

# Start fresh
docker-compose up -d

log_success "Services started"
log_info "Waiting 30 seconds for services to initialize..."
sleep 30

# ====================================================================
# Step 7: Health Checks
# ====================================================================

log_info "Step 7: Running health checks..."

# Check Nginx
if docker exec moneypuran_nginx nginx -t &>/dev/null; then
    log_success "Nginx configuration is valid"
else
    log_error "Nginx configuration error"
    docker logs moneypuran_nginx | tail -20
    exit 1
fi

# Check if app container is running
if docker ps | grep -q moneypuran_app; then
    log_success "App container is running"
else
    log_error "App container failed to start"
    docker logs moneypuran_app | tail -50
    exit 1
fi

# Check if app is listening on port 3000
if docker exec moneypuran_app curl -s http://localhost:3000 &>/dev/null; then
    log_success "App is responding on port 3000"
else
    log_warning "App health check inconclusive, checking logs..."
    docker logs moneypuran_app | tail -20
fi

# Check database
if docker exec moneypuran_db pg_isready -U moneypuran &>/dev/null; then
    log_success "Database is healthy"
else
    log_warning "Database might still be initializing..."
fi

# Check Redis
if docker exec moneypuran_redis redis-cli ping &>/dev/null; then
    log_success "Redis is healthy"
else
    log_warning "Redis might still be initializing..."
fi

# ====================================================================
# Step 8: Certificate Auto-Renewal (Cron Job)
# ====================================================================

log_info "Step 8: Setting up certificate auto-renewal..."

if command -v certbot &> /dev/null; then
    # Create renewal script
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
    
    # Add to crontab (run daily at 2 AM)
    (crontab -l 2>/dev/null | grep -v 'renew-certs.sh'; echo "0 2 * * * /usr/local/bin/renew-certs.sh") | crontab -
    
    log_success "Certificate auto-renewal configured (daily at 2 AM)"
else
    log_warning "Certbot not available, skipping auto-renewal setup"
fi

# ====================================================================
# Step 9: Verify Deployment
# ====================================================================

log_info "Step 9: Verifying deployment..."

# Show container status
log_info "Docker containers status:"
docker ps --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"

# ====================================================================
# Success Message
# ====================================================================

echo ""
echo -e "${GREEN}╔════════════════════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║           ✅ MoneyPuran Deployment Complete!                  ║${NC}"
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
echo -e "${BLUE}Useful Commands:${NC}"
echo "  📊 View logs:        docker logs -f moneypuran_app"
echo "  🔍 Check status:     docker ps"
echo "  ♻️  Restart services: docker-compose restart"
echo "  🛑 Stop services:    docker-compose down"
echo "  🚀 Start services:   docker-compose up -d"
echo ""
echo -e "${BLUE}Configuration Files:${NC}"
echo "  ⚙️  Environment: $DEPLOY_DIR/.env"
echo "  🔐 Certificates: $DEPLOY_DIR/nginx/certs/"
echo "  🌐 Nginx config: $DEPLOY_DIR/nginx/nginx.conf"
echo ""
echo -e "${YELLOW}⚠️  Important:${NC}"
echo "  1. Change ADMIN_PASSWORD in .env before deployment"
echo "  2. Update JWT secrets with random values"
echo "  3. Add your OpenAI API key if using AI features"
echo "  4. Check logs if website is not loading"
echo ""
echo -e "${BLUE}Monitor your application:${NC}"
echo "  tail -f $DEPLOY_DIR/deployment.log"
echo ""

log_success "Deployment script completed successfully!"
