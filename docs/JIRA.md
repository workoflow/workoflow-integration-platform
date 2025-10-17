# JIRA Integration Documentation

## Übersicht

Die JIRA Integration ermöglicht es AI-Agenten, auf JIRA-Instanzen zuzugreifen und verschiedene Operationen durchzuführen. Diese Integration ist Teil des Plugin-basierten Integrationssystems der Workoflow Platform.

## Architektur

### Komponenten-Übersicht

```
┌─────────────────────────────────────────────────────────────┐
│                      REST API Endpoint                        │
│              GET /api/integrations/{org-uuid}                │
│              POST /api/integrations/{org-uuid}/execute       │
└────────────────────────┬────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────┐
│              IntegrationApiController                         │
│   - Basic Auth Validation                                    │
│   - Tool Discovery & Execution                              │
│   - Credential Decryption                                   │
└────────────────────────┬────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────┐
│               IntegrationRegistry                             │
│   - Central DI-managed registry                             │
│   - Auto-discovery via tags                                 │
└────────────────────────┬────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────┐
│              JiraIntegration                                  │
│   - Implements IntegrationInterface                         │
│   - Defines 4 JIRA tools                                    │
│   - Validates credentials                                   │
└────────────────────────┬────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────┐
│                JiraService                                    │
│   - HTTP Client wrapper                                     │
│   - JIRA REST API v3 calls                                  │
│   - URL validation & normalization                          │
└─────────────────────────────────────────────────────────────┘
```

### Dateistruktur

```
src/
├── Integration/
│   ├── IntegrationInterface.php           # Core contract für alle Integrationen
│   ├── UserIntegrations/
│   │   └── JiraIntegration.php            # JIRA Integration Implementation
│   └── IntegrationRegistry.php            # Zentrale Registry für alle Integrationen
├── Service/
│   ├── Integration/
│   │   └── JiraService.php                # JIRA API Client Service
│   └── EncryptionService.php              # Credential Encryption (Sodium)
├── Controller/
│   ├── IntegrationApiController.php       # REST API für AI Agenten
│   └── IntegrationController.php          # Web UI Management
└── Entity/
    └── IntegrationConfig.php              # Database Entity für Integration Configs
```

## Datenfluss

### 1. Integration Setup (Web UI)

```
User → IntegrationController::setup()
  ↓
Eingabe: JIRA URL, Email, API Token
  ↓
JiraIntegration::validateCredentials()
  ↓
EncryptionService::encrypt() [Sodium Encryption]
  ↓
IntegrationConfig Entity → Database
```

### 2. Tool Discovery (API)

```
AI Agent → GET /api/integrations/{org-uuid}?workflow_user_id={id}
  ↓
Basic Auth Validation
  ↓
IntegrationRegistry::getAllIntegrations()
  ↓
Filter by Organisation & WorkflowUserId
  ↓
Return Available Tools:
  - jira_search_{config_id}
  - jira_get_issue_{config_id}
  - jira_get_sprints_from_board_{config_id}
  - jira_get_sprint_issues_{config_id}
  - jira_add_comment_{config_id}
```

### 3. Tool Execution (API)

```
AI Agent → POST /api/integrations/{org-uuid}/execute
  ↓
Request Body: {
  "tool_id": "jira_search_123",
  "parameters": {...},
  "workflow_user_id": "..."
}
  ↓
Parse tool_id → Extract config_id (123)
  ↓
Load IntegrationConfig from Database
  ↓
EncryptionService::decrypt() credentials
  ↓
JiraIntegration::executeTool()
  ↓
JiraService → JIRA REST API v3
  ↓
Return Response to AI Agent
```

## JIRA Service Details

### Entry Point: `src/Service/Integration/JiraService.php`

Die JiraService Klasse ist der zentrale HTTP Client für alle JIRA API Aufrufe:

#### Hauptfunktionen:

1. **URL Validation & Normalization**
   - Validiert JIRA URLs (HTTPS required)
   - Normalisiert URLs (entfernt trailing slashes, default ports)
   - Verwendet PSR-7 Uri für robuste URL-Verarbeitung

2. **API Methoden**:
   ```php
   testConnection()      # GET /rest/api/3/myself
   search()             # GET /rest/api/3/search/jql
   getIssue()           # GET /rest/api/3/issue/{issueKey}
   getSprintsFromBoard() # GET /rest/agile/1.0/board/{boardId}/sprint
   getSprintIssues()    # GET /rest/agile/1.0/sprint/{sprintId}/issue
   addComment()         # POST /rest/api/3/issue/{issueKey}/comment
   ```

3. **Authentication**:
   - Basic Auth mit Email + API Token
   - Credentials werden verschlüsselt in DB gespeichert

## Verfügbare Tools

### 1. jira_search
**Beschreibung**: Suche nach JIRA Issues mit JQL (JIRA Query Language)

**Parameters**:
- `jql` (string, required): JQL Query String
- `maxResults` (integer, optional): Maximum Anzahl Ergebnisse (default: 50)

**API Call**: `GET /rest/api/3/search/jql`

### 2. jira_get_issue
**Beschreibung**: Detaillierte Informationen zu einem JIRA Issue

**Parameters**:
- `issueKey` (string, required): Issue Key (z.B. PROJ-123)

**API Call**: `GET /rest/api/3/issue/{issueKey}`

### 3. jira_get_sprints_from_board
**Beschreibung**: Alle Sprints eines JIRA Boards abrufen

**Parameters**:
- `boardId` (integer, required): Board ID

**API Call**: `GET /rest/agile/1.0/board/{boardId}/sprint`

### 4. jira_get_sprint_issues
**Beschreibung**: Alle Issues in einem Sprint abrufen

**Parameters**:
- `sprintId` (integer, required): Sprint ID
- `maxResults` (integer, optional): Maximum Anzahl Ergebnisse (default: 50)

**API Call**: `GET /rest/agile/1.0/sprint/{sprintId}/issue`

### 5. jira_add_comment
**Beschreibung**: Kommentar zu einem JIRA Issue hinzufügen

**Parameters**:
- `issueKey` (string, required): Issue Key (z.B. PROJ-123)
- `comment` (string, required): Der Kommentar-Text, der hinzugefügt werden soll

**API Call**: `POST /rest/api/3/issue/{issueKey}/comment`

## Authentifizierung & Sicherheit

### Credential Storage

1. **Eingabe**: User gibt JIRA Credentials ein:
   - URL: `https://your-domain.atlassian.net`
   - Email: `your-email@example.com`
   - API Token: `your-jira-api-token`

2. **Verschlüsselung**:
   - Verwendet **Sodium (libsodium)** Encryption
   - 32-character ENCRYPTION_KEY aus `.env`
   - Implementiert in `EncryptionService`

3. **Speicherung**:
   - Verschlüsselte Credentials in `IntegrationConfig::encryptedCredentials`
   - Base64-encoded: `nonce + ciphertext`

### API Authentifizierung

1. **Basic Auth** für REST API Zugriff:
   - Username/Password aus `.env`
   - Header: `Authorization: Basic base64(user:pass)`

2. **Organisation Isolation**:
   - Jede Organisation hat eigene UUID
   - Integrations sind Organisation-spezifisch
   - Workflow User ID für weitere Filterung

## Multi-Tenancy & Isolation

### User Isolation
- Jeder User kann nur seine eigenen Integrationen sehen/bearbeiten
- Integrationen sind an `User` + `Organisation` gebunden
- `workflow_user_id` ermöglicht weitere Segmentierung

### Organisation Isolation
- Integrations sind Organisation-spezifisch
- API Access nur mit korrekter Organisation UUID
- Audit Logging auf Organisation-Ebene

## Tool Management

### Aktivierung/Deaktivierung

1. **Integration Level**:
   - Gesamte Integration kann aktiviert/deaktiviert werden
   - `IntegrationConfig::active` Flag

2. **Tool Level**:
   - Einzelne Tools können deaktiviert werden
   - `IntegrationConfig::disabledTools` Array
   - Flexible Kontrolle über verfügbare Funktionen

### Multiple Instances
- User können mehrere JIRA Instanzen konfigurieren
- Jede Instanz bekommt eigene `config_id`
- Tools werden mit Suffix exposiert: `jira_search_123`

## Integration Registry

### Auto-Discovery
```yaml
# config/services/integrations.yaml
App\Integration\UserIntegrations\JiraIntegration:
    autowire: true
    tags: ['app.integration']
```

### Registry Loading
- Symfony DI Container injiziert alle getaggten Services
- `IntegrationRegistry` sammelt alle Integrationen
- Zentrale Verwaltung aller Integration Types

## Error Handling

### URL Validation
- HTTPS ist mandatory für Sicherheit
- Detaillierte Fehlermeldungen bei invaliden URLs
- PSR-7 Uri für robuste URL-Verarbeitung

### API Errors
- HTTP Status Codes werden weitergereicht
- Logging via Monolog
- Audit Trail für alle API Zugriffe

### Credential Errors
- Verschlüsselungsfehler werden geloggt
- Validation vor Speicherung
- Test Connection Funktion verfügbar

## Testing & Debugging

### Test Connection
```php
// IntegrationController::testConnection()
- Decrypt credentials
- Call JiraService::testConnection()
- GET /rest/api/3/myself
- Verify 200 OK response
```

### Command Line Testing
```bash
# Get Integration Command verfügbar
php bin/console app:get-integration {org-uuid} {workflow-user-id}
```

### API Testing
```bash
# Tool Discovery
curl -X GET "http://localhost:3979/api/integrations/{org-uuid}?workflow_user_id={id}" \
  -H "Authorization: Basic base64(user:pass)"

# Tool Execution
curl -X POST "http://localhost:3979/api/integrations/{org-uuid}/execute" \
  -H "Authorization: Basic base64(user:pass)" \
  -H "Content-Type: application/json" \
  -d '{
    "tool_id": "jira_search_123",
    "parameters": {
      "jql": "project = TEST",
      "maxResults": 10
    },
    "workflow_user_id": "user-123"
  }'
```

## Konfiguration

### Environment Variables
```env
# API Authentication
API_AUTH_USER=api_user
API_AUTH_PASSWORD=secure_password

# Encryption Key (32 characters)
ENCRYPTION_KEY=your-32-character-encryption-key

# JIRA nicht direkt konfiguriert - User-spezifisch
```

### Database Schema
```sql
-- integration_config table
id                    INT PRIMARY KEY
organisation_id       INT NOT NULL
user_id              INT NULL
integration_type      VARCHAR(100)  -- 'jira'
name                 VARCHAR(255)  -- User-defined name
encrypted_credentials TEXT         -- Encrypted JSON
disabled_tools       JSON         -- Array of disabled tool names
active               BOOLEAN      -- Integration active flag
last_accessed_at     DATETIME
created_at           DATETIME
updated_at           DATETIME
```

## Best Practices

### Security
1. **Immer HTTPS** für JIRA URLs verwenden
2. **API Tokens** statt Passwörter nutzen
3. **Encryption Key** sicher aufbewahren
4. **Audit Logging** für Compliance

### Performance
1. **Caching** von Tool Definitions
2. **Connection Pooling** via HttpClient
3. **Lazy Loading** von Credentials
4. **Batch Operations** wo möglich

### Maintenance
1. **Regular Token Rotation**
2. **Monitor API Usage**
3. **Update JIRA API Version** bei Bedarf
4. **Test nach JIRA Updates**

## Troubleshooting

### Häufige Probleme

1. **401 Unauthorized**
   - API Token abgelaufen oder ungültig
   - Email/Token Kombination falsch
   - JIRA Permissions fehlen

2. **404 Not Found**
   - JIRA URL falsch
   - API Endpoint geändert
   - Issue/Board/Sprint existiert nicht

3. **Connection Failed**
   - JIRA Server nicht erreichbar
   - Firewall blockiert Zugriff
   - SSL/TLS Probleme

### Debug Logging
```php
// Enable debug logging in .env
APP_ENV=dev
APP_DEBUG=true

// Check logs
tail -f var/log/integration_api.log
tail -f var/log/audit.log
```

## Erweiterungsmöglichkeiten

### Neue JIRA Tools hinzufügen

1. Tool Definition in `JiraIntegration::getTools()` ergänzen
2. Execution Logic in `JiraIntegration::executeTool()` implementieren
3. API Call in `JiraService` hinzufügen
4. Tests schreiben

### Webhook Integration
- JIRA Webhooks für Real-time Updates
- Event-driven Architecture
- Async Processing mit Messenger Component

### Caching Strategy
- Redis für häufige Queries
- TTL basierend auf Datentyp
- Cache Invalidation bei Updates