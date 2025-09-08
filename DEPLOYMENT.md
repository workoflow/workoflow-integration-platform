# Deployment Guide

## Production Deployment

Deploy to production using external Docker volumes (for cloud/Kubernetes compatibility):

```bash
./deploy.sh prod
```

### Options

```bash
# Skip database backup
./deploy.sh prod --skip-backup

# Force rebuild Docker images (even if no code changes)
./deploy.sh prod --force-rebuild

# Both options
./deploy.sh prod --skip-backup --force-rebuild
```

## What the deployment does

1. **Creates Docker volumes** (if they don't exist)
   - workoflow_integration_mariadb_data
   - workoflow_integration_redis_data
   - workoflow_integration_caddy_data
   - workoflow_integration_caddy_config
   - workoflow_integration_app_var
   - workoflow_integration_app_uploads

2. **Backs up database** (unless --skip-backup)
   - Saves to `backups/db_backup_[timestamp].sql`
   - Keeps last 10 backups automatically

3. **Pulls latest code** from git

4. **Builds Docker images** (only if code changed or --force-rebuild)
   - Uses `Dockerfile.prod` with everything baked in
   - Includes composer dependencies and npm build

5. **Restarts containers** with new images

6. **Runs database migrations**

7. **Clears Symfony cache**

8. **Performs health check**

## Requirements

- Docker and docker-compose installed
- `.env` file configured (copy from `.env.dist`)
- `docker-compose-prod-volumes.yml` file
- `Dockerfile.prod` file

## Configuration

The deployment uses:
- **docker-compose-prod-volumes.yml** - External volumes only, no bind mounts
- **Dockerfile.prod** - Production image with all code and dependencies built in
- **Environment variables** from `.env` file

## Manual Commands

If you need to run specific operations:

```bash
# View logs
docker-compose -f docker-compose-prod-volumes.yml logs -f

# Check container status
docker-compose -f docker-compose-prod-volumes.yml ps

# Enter container
docker-compose -f docker-compose-prod-volumes.yml exec frankenphp bash

# Stop all containers
docker-compose -f docker-compose-prod-volumes.yml down

# Rebuild without cache
docker-compose -f docker-compose-prod-volumes.yml build --no-cache

# Run migrations manually
docker-compose -f docker-compose-prod-volumes.yml exec frankenphp php bin/console doctrine:migrations:migrate --env=prod

# Clear cache manually
docker-compose -f docker-compose-prod-volumes.yml exec frankenphp php bin/console cache:clear --env=prod
```

## Rollback

If deployment fails:

1. **Restore database from backup:**
```bash
docker-compose -f docker-compose-prod-volumes.yml exec mariadb mysql -u root -p[password] [database] < backups/db_backup_[timestamp].sql
```

2. **Revert code changes:**
```bash
git reset --hard HEAD~1
./deploy.sh prod --force-rebuild
```

## Troubleshooting

### Application not responding
```bash
# Check logs
docker-compose -f docker-compose-prod-volumes.yml logs frankenphp

# Check health endpoint
curl http://localhost:3979/health
```

### Permission issues
```bash
docker-compose -f docker-compose-prod-volumes.yml exec frankenphp chown -R www-data:www-data var/
```

### Database connection issues
```bash
# Check if database is running
docker-compose -f docker-compose-prod-volumes.yml ps mariadb

# Test connection
docker-compose -f docker-compose-prod-volumes.yml exec mariadb mysql -u root -p
```