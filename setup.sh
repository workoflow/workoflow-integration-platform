#!/bin/bash

# Workoflow Integration Platform Setup Script
# This script sets up the application on a production server

set -e

echo "========================================="
echo "Workoflow Integration Platform Setup"
echo "========================================="

# Check if running as root
if [[ $EUID -eq 0 ]]; then
   echo "This script should not be run as root for security reasons."
   echo "Please run as a regular user with sudo privileges."
   exit 1
fi

# Detect environment
if [ -z "$1" ]; then
    echo "Usage: ./setup.sh [dev|prod]"
    exit 1
fi

ENVIRONMENT=$1
echo "Setting up for environment: $ENVIRONMENT"

# Check dependencies
echo ""
echo "Checking dependencies..."
command -v docker >/dev/null 2>&1 || { echo "Docker is required but not installed. Aborting." >&2; exit 1; }
command -v docker-compose >/dev/null 2>&1 || { echo "Docker Compose is required but not installed. Aborting." >&2; exit 1; }

# Create .env file if not exists
if [ ! -f .env ]; then
    echo ""
    echo "Creating .env file from .env.dist..."
    cp .env.dist .env
    
    # Generate random keys
    APP_SECRET=$(openssl rand -hex 16)
    ENCRYPTION_KEY=$(openssl rand -hex 16)
    JWT_PASSPHRASE=$(openssl rand -hex 16)
    MYSQL_ROOT_PASSWORD=$(openssl rand -hex 16)
    MYSQL_PASSWORD=$(openssl rand -hex 16)
    MINIO_ROOT_PASSWORD=$(openssl rand -hex 16)
    
    # Update .env with generated values
    if [[ "$OSTYPE" == "darwin"* ]]; then
        # macOS
        sed -i '' "s/your_app_secret_here/$APP_SECRET/" .env
        sed -i '' "s/your_32_char_encryption_key_here/$ENCRYPTION_KEY/" .env
        sed -i '' "s/your_jwt_passphrase/$JWT_PASSPHRASE/" .env
        sed -i '' "s/your_root_password/$MYSQL_ROOT_PASSWORD/" .env
        sed -i '' "s/your_mysql_password/$MYSQL_PASSWORD/" .env
        sed -i '' "s/workoflow123/$MINIO_ROOT_PASSWORD/" .env
    else
        # Linux
        sed -i "s/your_app_secret_here/$APP_SECRET/" .env
        sed -i "s/your_32_char_encryption_key_here/$ENCRYPTION_KEY/" .env
        sed -i "s/your_jwt_passphrase/$JWT_PASSPHRASE/" .env
        sed -i "s/your_root_password/$MYSQL_ROOT_PASSWORD/" .env
        sed -i "s/your_mysql_password/$MYSQL_PASSWORD/" .env
        sed -i "s/workoflow123/$MINIO_ROOT_PASSWORD/" .env
    fi
    
    echo "Generated secure random keys in .env"
else
    echo ".env file already exists, skipping..."
fi

# Production specific setup
if [ "$ENVIRONMENT" == "prod" ]; then
    echo ""
    echo "Setting up production environment..."
    
    # Update .env for production
    sed -i "s/APP_ENV=dev/APP_ENV=prod/" .env
    sed -i "s/APP_DEBUG=true/APP_DEBUG=false/" .env
    
    # Create external volumes
    echo "Creating external Docker volumes..."
    docker volume create mariadb_data || true
    docker volume create redis_data || true
    docker volume create caddy_data || true
    docker volume create caddy_config || true
    docker volume create minio_data || true
    
    COMPOSE_FILE="docker-compose-prod.yml"
else
    COMPOSE_FILE="docker-compose.yml"
fi

# Generate JWT keys
echo ""
echo "Generating JWT keys..."
mkdir -p config/jwt
if [ ! -f config/jwt/private.pem ]; then
    openssl genpkey -out config/jwt/private.pem -aes256 -algorithm rsa -pkeyopt rsa_keygen_bits:4096 -pass pass:$(grep JWT_PASSPHRASE .env | cut -d '=' -f2)
    openssl pkey -in config/jwt/private.pem -out config/jwt/public.pem -pubout -passin pass:$(grep JWT_PASSPHRASE .env | cut -d '=' -f2)
    chmod 644 config/jwt/public.pem
    chmod 600 config/jwt/private.pem
    echo "JWT keys generated successfully"
else
    echo "JWT keys already exist, skipping..."
fi

# Build and start containers
echo ""
echo "Building and starting Docker containers..."
docker-compose -f $COMPOSE_FILE build
docker-compose -f $COMPOSE_FILE up -d

# Wait for services to be ready
echo ""
echo "Waiting for services to be ready..."
sleep 10

# Install dependencies
echo ""
echo "Installing PHP dependencies..."
docker-compose -f $COMPOSE_FILE exec -T frankenphp composer install --no-dev --optimize-autoloader

# Install npm dependencies and build assets
echo ""
echo "Installing npm dependencies..."
docker-compose -f $COMPOSE_FILE exec -T frankenphp npm install

echo ""
echo "Building frontend assets..."
if [ "$ENVIRONMENT" == "prod" ]; then
    docker-compose -f $COMPOSE_FILE exec -T frankenphp npm run build
else
    docker-compose -f $COMPOSE_FILE exec -T frankenphp npm run dev
fi

# Run database migrations
echo ""
echo "Running database migrations..."
docker-compose -f $COMPOSE_FILE exec -T frankenphp php bin/console doctrine:migrations:migrate --no-interaction

# Clear and warm up cache
echo ""
echo "Clearing and warming up cache..."
docker-compose -f $COMPOSE_FILE exec -T frankenphp php bin/console cache:clear --env=$ENVIRONMENT
docker-compose -f $COMPOSE_FILE exec -T frankenphp php bin/console cache:warmup --env=$ENVIRONMENT

# Create MinIO bucket
echo ""
echo "Setting up MinIO bucket..."
docker-compose -f $COMPOSE_FILE exec -T frankenphp php bin/console app:create-bucket || true

# Set proper permissions
echo ""
echo "Setting file permissions..."
docker-compose -f $COMPOSE_FILE exec -T frankenphp chown -R www-data:www-data var/
docker-compose -f $COMPOSE_FILE exec -T frankenphp chmod -R 775 var/

echo ""
echo "========================================="
echo "Setup completed successfully!"
echo ""
echo "Application is running at:"
echo "- HTTP: http://localhost:3979"
echo "- HTTPS: https://localhost"
echo ""
echo "Other services:"
echo "- MinIO Console: http://localhost:9001"
echo "- MailHog: http://localhost:8025"
echo ""
if [ "$ENVIRONMENT" == "dev" ]; then
    echo "Development credentials:"
    echo "- MCP Auth: workoflow / workoflow"
    echo ""
fi
echo "IMPORTANT: Please configure your Google OAuth credentials in .env"
echo "========================================="