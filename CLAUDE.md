# Workoflow Integration Platform

## Übersicht
Die Workoflow Integration Platform ist eine production-ready Symfony 7.2 Anwendung, die es Benutzern ermöglicht, verschiedene Integrationen (Jira, Confluence) zu verwalten und über einen MCP (Model Context Protocol) Server für AI Agenten bereitzustellen.

### Hauptfunktionen
- OAuth2 Google Login
- Multi-Tenant Organisation Management
- Integration Management (Jira, Confluence)
- MCP Server für AI Agent Zugriff
- File Management mit MinIO S3
- Audit Logging
- Mehrsprachigkeit (DE/EN)

## Architektur

### Tech Stack
- **Backend**: PHP 8.4, Symfony 7.2, FrankenPHP
- **Database**: MariaDB 11.2
- **Cache**: Redis 7
- **Storage**: MinIO (S3-kompatibel)
- **Mail**: MailHog (Development)
- **Container**: Docker & Docker Compose

### Verzeichnisstruktur
```
workoflow-promopage-v2/
├── config/           # Symfony Konfiguration
├── migrations/       # Doctrine Migrations
├── public/          # Web Root
├── src/
│   ├── Command/     # Console Commands
│   ├── Controller/  # HTTP Controllers
│   ├── Entity/      # Doctrine Entities
│   ├── Repository/  # Entity Repositories
│   ├── Security/    # Auth & Security
│   └── Service/     # Business Logic
├── templates/       # Twig Templates
├── translations/    # i18n Files
├── docker/         # Docker Configs
└── tests/          # Test Suite
```

## Setup

### Entwicklungsumgebung
```bash
# 1. Repository klonen
git clone <repository-url>
cd workoflow-promopage-v2

# 2. Setup ausführen
./setup.sh dev

# 3. Google OAuth konfigurieren in .env
GOOGLE_CLIENT_ID=your_client_id
GOOGLE_CLIENT_SECRET=your_client_secret
```

### Produktionsumgebung
```bash
# 1. Als non-root User mit Docker-Zugriff
./setup.sh prod

# 2. SSL-Zertifikate konfigurieren
# 3. Domain in .env anpassen
# 4. Google OAuth Redirect URI aktualisieren
```

### Zugriff
- **Application**: http://localhost:3979
- **MinIO Console**: http://localhost:9001 (admin/workoflow123)
- **MailHog**: http://localhost:8025

## API Referenz

### MCP Server Endpoint
```
GET /api/mcp/{org-uuid}/sse?id={workflow-user-id}
Authorization: Basic d29ya29mbG93Ondvcmtvd2xvdw==
```

Der MCP Server implementiert das Anthropic Model Context Protocol und stellt dynamisch Tools basierend auf den aktivierten User-Integrationen bereit.

### Verfügbare Tools

#### Jira Tools
- `jira_search_{integration_id}` - JQL Suche
- `jira_get_issue_{integration_id}` - Issue Details abrufen
- `jira_get_sprints_from_board_{integration_id}` - Board Sprints
- `jira_get_sprint_issues_{integration_id}` - Sprint Issues

#### Confluence Tools
- `confluence_search_{integration_id}` - CQL Suche
- `confluence_get_page_{integration_id}` - Seite abrufen
- `confluence_get_comments_{integration_id}` - Kommentare abrufen

## Entities & Datenmodell

### User
- Authentifizierung via Google OAuth2
- Rollen: ROLE_ADMIN, ROLE_MEMBER
- 1:1 Beziehung zu Organisation
- 1:N Beziehung zu Integrations

### Organisation
- UUID für MCP Server URL
- 1:N Beziehung zu Users
- Audit Logging auf Org-Ebene

### Integration
- Typen: jira, confluence
- Verschlüsselte Credentials (Sodium)
- Workflow User ID für Filterung
- 1:N Beziehung zu IntegrationFunctions

### IntegrationFunction
- Definiert verfügbare API-Funktionen
- Kann aktiviert/deaktiviert werden
- Enthält Tool-Beschreibungen für AI

## Sicherheit

### Authentifizierung
- Google OAuth2 für User Login
- Basic Auth für MCP Server
- JWT Tokens für API
- X-Test-Auth-Email GET Parameter für Tests

### Verschlüsselung
- Credentials mit Sodium verschlüsselt
- 32-Zeichen Encryption Key
- JWT mit RSA 4096-bit Keys

### Audit Logging
- Alle kritischen Aktionen geloggt
- IP-Adresse und User Agent
- Monolog mit verschiedenen Channels

## Testing

### Test User Setup
```php
// GET Parameter für Test-Authentifizierung
?X-Test-Auth-Email=test@example.com

// Vorkonfigurierte Test-User
puppeteer.test1@example.com (Admin)
puppeteer.test2@example.com (Member)
```

### MCP Server Tests
- Puppeteer für UI Tests
- MariaDB für Datenbank-Tests
- Integration Tests für APIs

## Deployment

### Environment Variables
Alle kritischen Konfigurationen über .env:
- Database Credentials
- OAuth2 Settings
- S3/MinIO Config
- Encryption Keys

### Migration Strategy
- Development: Single Migration überschreibbar
- Production: Neue Migration Files

### Monitoring
- Audit Logs in `/var/log/audit.log`
- MCP Access Logs
- Error Tracking via Monolog

## Wartung

### Backup
- MariaDB Datenbank
- MinIO S3 Bucket
- Environment Files

### Updates
```bash
# Dependencies aktualisieren
docker-compose exec frankenphp composer update

# Migrations ausführen
docker-compose exec frankenphp php bin/console doctrine:migrations:migrate

# Cache leeren
docker-compose exec frankenphp php bin/console cache:clear

# rebuild assets
docker-compose exec frankenphp npm run build
```

## Changelog

### Version 1.0.0 (02.07.2025)
- Initial Release
- OAuth2 Google Login
- Organisation Management
- Jira/Confluence Integrations
- MCP Server Implementation
- File Management
- Multi-Language Support (DE/EN)
