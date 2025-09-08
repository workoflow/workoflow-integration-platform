#!/bin/bash

# Workoflow Integration Platform - Production Deployment Script
# Usage: ./deploy.sh prod [options]
#
# Options:
#   --skip-backup      Skip database backup
#   --force-rebuild    Force rebuild Docker images

set -e  # Exit on error

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Check first argument
if [ "$1" != "prod" ]; then
    echo -e "${RED}Usage: $0 prod [options]${NC}"
    echo "Options:"
    echo "  --skip-backup     Skip database backup"
    echo "  --force-rebuild   Force rebuild Docker images"
    exit 1
fi
shift # Remove 'prod' from arguments

# Parse options
SKIP_BACKUP=false
FORCE_REBUILD=false

for arg in "$@"; do
    case $arg in
        --skip-backup)
            SKIP_BACKUP=true
            ;;
        --force-rebuild)
            FORCE_REBUILD=true
            ;;
    esac
done

# Configuration
COMPOSE_FILE="docker-compose-prod-volumes.yml"
DOCKERFILE="Dockerfile.prod"
BACKUP_DIR="./backups"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)

echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}Production Deployment${NC}"
echo -e "${GREEN}========================================${NC}"

# Check requirements
if [ ! -f "$COMPOSE_FILE" ]; then
    echo -e "${RED}Error: $COMPOSE_FILE not found!${NC}"
    exit 1
fi

if [ ! -f "$DOCKERFILE" ]; then
    echo -e "${RED}Error: $DOCKERFILE not found!${NC}"
    exit 1
fi

if [ ! -f ".env" ]; then
    echo -e "${RED}Error: .env file not found!${NC}"
    echo "Create it from template: cp .env.dist .env"
    exit 1
fi

# Create Docker volumes (silently succeeds if they exist)
echo -e "\n${YELLOW}Ensuring Docker volumes...${NC}"
docker volume create workoflow_integration_mariadb_data >/dev/null 2>&1 || true
docker volume create workoflow_integration_redis_data >/dev/null 2>&1 || true
docker volume create workoflow_integration_caddy_data >/dev/null 2>&1 || true
docker volume create workoflow_integration_caddy_config >/dev/null 2>&1 || true
docker volume create workoflow_integration_app_var >/dev/null 2>&1 || true
docker volume create workoflow_integration_app_uploads >/dev/null 2>&1 || true
echo -e "${GREEN}✓ Volumes ready${NC}"

# Backup database
if [ "$SKIP_BACKUP" = false ]; then
    echo -e "\n${YELLOW}Backing up database...${NC}"
    mkdir -p "$BACKUP_DIR"
    if docker-compose -f $COMPOSE_FILE ps | grep -q "mariadb.*Up"; then
        source .env
        if docker-compose -f $COMPOSE_FILE exec -T mariadb mysqldump -u root -p${MYSQL_ROOT_PASSWORD:-rootpassword} ${MYSQL_DATABASE:-workoflow_db} > "$BACKUP_DIR/db_backup_$TIMESTAMP.sql" 2>/dev/null; then
            echo -e "${GREEN}✓ Database backed up${NC}"
        else
            echo -e "${YELLOW}⚠ Could not backup database${NC}"
        fi
    else
        echo -e "${YELLOW}⚠ Database not running, skipping backup${NC}"
    fi
fi

# Pull latest code
echo -e "\n${YELLOW}Pulling latest code...${NC}"
CURRENT_COMMIT=$(git rev-parse HEAD 2>/dev/null || echo "none")
git pull origin main
NEW_COMMIT=$(git rev-parse HEAD 2>/dev/null || echo "none")

# Build if needed
if [ "$CURRENT_COMMIT" != "$NEW_COMMIT" ] || [ "$FORCE_REBUILD" = true ]; then
    echo -e "\n${YELLOW}Building Docker images...${NC}"
    docker-compose -f $COMPOSE_FILE build --no-cache
    echo -e "${GREEN}✓ Images built${NC}"
    
    echo -e "\n${YELLOW}Restarting containers...${NC}"
    docker-compose -f $COMPOSE_FILE down
    docker-compose -f $COMPOSE_FILE up -d
    echo -e "${GREEN}✓ Containers started${NC}"
else
    echo -e "\n${YELLOW}No changes detected, ensuring containers are running...${NC}"
    docker-compose -f $COMPOSE_FILE up -d
    echo -e "${GREEN}✓ Containers running${NC}"
fi

# Wait for services
echo -e "\n${YELLOW}Waiting for services...${NC}"
sleep 10
echo -e "${GREEN}✓ Services ready${NC}"

# Run migrations
echo -e "\n${YELLOW}Running migrations...${NC}"
docker-compose -f $COMPOSE_FILE exec -T frankenphp php bin/console doctrine:migrations:migrate --no-interaction --env=prod || true
echo -e "${GREEN}✓ Migrations done${NC}"

# Clear cache
echo -e "\n${YELLOW}Clearing cache...${NC}"
docker-compose -f $COMPOSE_FILE exec -T frankenphp php bin/console cache:clear --env=prod --no-debug
echo -e "${GREEN}✓ Cache cleared${NC}"

# Health check
echo -e "\n${YELLOW}Health check...${NC}"
if curl -f -s http://localhost:3979/health >/dev/null 2>&1; then
    echo -e "${GREEN}✓ Application healthy${NC}"
else
    echo -e "${YELLOW}⚠ Health check failed (may still be starting)${NC}"
fi

# Clean old backups (keep last 10)
if [ -d "$BACKUP_DIR" ]; then
    ls -t "$BACKUP_DIR"/db_backup_*.sql 2>/dev/null | tail -n +11 | xargs -r rm
fi

echo -e "\n${GREEN}========================================${NC}"
echo -e "${GREEN}Deployment complete!${NC}"
echo -e "${GREEN}========================================${NC}"

echo -e "\nCommands:"
echo "  Logs:    docker-compose -f $COMPOSE_FILE logs -f"
echo "  Status:  docker-compose -f $COMPOSE_FILE ps"
echo "  Stop:    docker-compose -f $COMPOSE_FILE down"