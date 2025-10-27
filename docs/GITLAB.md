# GitLab Integration Documentation

## Übersicht

Die GitLab Integration ermöglicht es AI-Agenten, auf self-hosted und GitLab.com Instanzen zuzugreifen und umfassende Operationen durchzuführen. Diese Integration ist Teil des Plugin-basierten Integrationssystems der Workoflow Platform und bietet 24 spezialisierte Tools für Repository-Management, Merge Requests, Issues, Packages und CI/CD Pipelines.

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
│              GitLabIntegration                                │
│   - Implements IntegrationInterface                         │
│   - Defines 24 GitLab tools                                 │
│   - Validates credentials                                   │
└────────────────────────┬────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────┐
│                GitLabService                                  │
│   - HTTP Client wrapper                                     │
│   - GitLab REST API v4 calls                                │
│   - URL validation & normalization                          │
│   - Project path encoding                                   │
└─────────────────────────────────────────────────────────────┘
```

### Dateistruktur

```
src/
├── Integration/
│   ├── IntegrationInterface.php           # Core contract für alle Integrationen
│   ├── UserIntegrations/
│   │   └── GitLabIntegration.php          # GitLab Integration Implementation
│   └── IntegrationRegistry.php            # Zentrale Registry für alle Integrationen
├── Service/
│   ├── Integration/
│   │   └── GitLabService.php              # GitLab API Client Service
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
Eingabe: GitLab URL, API Token
  ↓
GitLabIntegration::validateCredentials()
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
Return Available Tools (24 GitLab tools):
  - gitlab_get_project_{config_id}
  - gitlab_list_projects_{config_id}
  - gitlab_search_merge_requests_{config_id}
  - gitlab_create_merge_request_{config_id}
  - gitlab_search_packages_{config_id}
  - ... (19 weitere Tools)
```

### 3. Tool Execution (API)

```
AI Agent → POST /api/integrations/{org-uuid}/execute
  ↓
Request Body: {
  "tool_id": "gitlab_search_merge_requests_123",
  "parameters": {
    "project": "packages/php/spryker/keycloak",
    "state": "opened"
  },
  "workflow_user_id": "..."
}
  ↓
Parse tool_id → Extract config_id (123)
  ↓
Load IntegrationConfig from Database
  ↓
EncryptionService::decrypt() credentials
  ↓
GitLabIntegration::executeTool()
  ↓
GitLabService → GitLab REST API v4
  ↓
Return Response to AI Agent
```

## GitLab Service Details

### Entry Point: `src/Service/Integration/GitLabService.php`

Die GitLabService Klasse ist der zentrale HTTP Client für alle GitLab API v4 Aufrufe:

#### Hauptfunktionen:

1. **URL Validation & Normalization**
   - Validiert GitLab URLs (HTTPS required für gitlab.com)
   - Normalisiert URLs (entfernt trailing slashes)
   - Unterstützt self-hosted Instanzen (HTTP erlaubt)
   - Verwendet PSR-7 Uri für robuste URL-Verarbeitung

2. **Project Identifier Handling**
   - Unterstützt numerische Project IDs: `42`
   - Unterstützt URL-encoded Paths: `namespace%2Fproject`
   - Automatisches URL-encoding für Projekt-Pfade
   - Flexible API-kompatible Konvertierung

3. **Authentication**:
   - Personal Access Token (PAT)
   - Header: `PRIVATE-TOKEN: {token}`
   - Credentials verschlüsselt in DB gespeichert

#### API Endpunkte:

Die GitLabService implementiert umfassende GitLab API v4 Unterstützung:

```php
// Connection & Auth
testConnection()               # GET /api/v4/user

// Repository & Metadata
getProject()                   # GET /api/v4/projects/{id}
listProjects()                 # GET /api/v4/projects
getFileContent()              # GET /api/v4/projects/{id}/repository/files/{path}
listRepositoryTree()          # GET /api/v4/projects/{id}/repository/tree

// Version Control
listTags()                    # GET /api/v4/projects/{id}/repository/tags
listBranches()                # GET /api/v4/projects/{id}/repository/branches
getCommit()                   # GET /api/v4/projects/{id}/repository/commits/{sha}
listCommits()                 # GET /api/v4/projects/{id}/repository/commits

// Merge Requests
searchMergeRequests()         # GET /api/v4/projects/{id}/merge_requests
getMergeRequest()             # GET /api/v4/projects/{id}/merge_requests/{iid}
getMergeRequestChanges()      # GET /api/v4/projects/{id}/merge_requests/{iid}/changes
getMergeRequestDiscussions() # GET /api/v4/projects/{id}/merge_requests/{iid}/discussions
getMergeRequestCommits()      # GET /api/v4/projects/{id}/merge_requests/{iid}/commits
createMergeRequest()          # POST /api/v4/projects/{id}/merge_requests
updateMergeRequest()          # PUT /api/v4/projects/{id}/merge_requests/{iid}
addMergeRequestNote()         # POST /api/v4/projects/{id}/merge_requests/{iid}/notes

// Issues
searchIssues()                # GET /api/v4/projects/{id}/issues
getIssue()                    # GET /api/v4/projects/{id}/issues/{iid}
createIssue()                 # POST /api/v4/projects/{id}/issues
updateIssue()                 # PUT /api/v4/projects/{id}/issues/{iid}

// Packages
searchPackages()              # GET /api/v4/projects/{id}/packages
getPackage()                  # GET /api/v4/projects/{id}/packages/{package_id}

// CI/CD
listPipelines()               # GET /api/v4/projects/{id}/pipelines
getPipeline()                 # GET /api/v4/projects/{id}/pipelines/{id}
```

## Verfügbare Tools (24 Tools)

### Repository & Metadata (4 Tools)

#### 1. gitlab_get_project
**Beschreibung**: Ruft detaillierte Informationen über ein GitLab-Projekt ab

**Parameters**:
- `project` (string, required): Projekt-ID oder Pfad (z.B. "packages/php/spryker/keycloak")

**API Call**: `GET /api/v4/projects/{id}`

**Return**: Projekt-Details (Name, Beschreibung, URL, Statistiken, etc.)

---

#### 2. gitlab_list_projects
**Beschreibung**: Listet alle zugänglichen GitLab-Projekte auf

**Parameters**:
- `search` (string, optional): Suchbegriff für Projektnamen
- `visibility` (string, optional): Filter nach Sichtbarkeit (public/private/internal)
- `owned` (boolean, optional): Nur eigene Projekte
- `starred` (boolean, optional): Nur favorisierte Projekte
- `limit` (integer, optional): Maximale Anzahl Ergebnisse

**API Call**: `GET /api/v4/projects`

**Return**: Liste von Projekten mit Metadaten

---

#### 3. gitlab_get_file_content
**Beschreibung**: Liest den Inhalt einer Datei aus einem Repository

**Parameters**:
- `project` (string, required): Projekt-ID oder Pfad
- `file_path` (string, required): Pfad zur Datei im Repository
- `ref` (string, optional): Branch, Tag oder Commit SHA (default: main/master)

**API Call**: `GET /api/v4/projects/{id}/repository/files/{path}`

**Return**: Dateiinhalt (base64 encoded) und Metadaten

---

#### 4. gitlab_list_repository_tree
**Beschreibung**: Listet Dateien und Verzeichnisse im Repository auf

**Parameters**:
- `project` (string, required): Projekt-ID oder Pfad
- `path` (string, optional): Pfad im Repository (default: root)
- `ref` (string, optional): Branch, Tag oder Commit SHA
- `recursive` (boolean, optional): Rekursive Auflistung

**API Call**: `GET /api/v4/projects/{id}/repository/tree`

**Return**: Baum-Struktur mit Dateien und Verzeichnissen

---

### Tags, Branches & Commits (4 Tools)

#### 5. gitlab_list_tags
**Beschreibung**: Listet alle Tags eines Repositories auf

**Parameters**:
- `project` (string, required): Projekt-ID oder Pfad
- `search` (string, optional): Suchbegriff für Tag-Namen
- `order_by` (string, optional): Sortierung (name/updated)

**API Call**: `GET /api/v4/projects/{id}/repository/tags`

**Return**: Liste von Tags mit Commit-Informationen

---

#### 6. gitlab_list_branches
**Beschreibung**: Listet alle Branches eines Repositories auf

**Parameters**:
- `project` (string, required): Projekt-ID oder Pfad
- `search` (string, optional): Suchbegriff für Branch-Namen

**API Call**: `GET /api/v4/projects/{id}/repository/branches`

**Return**: Liste von Branches mit Metadaten

---

#### 7. gitlab_get_commit
**Beschreibung**: Ruft Details zu einem spezifischen Commit ab

**Parameters**:
- `project` (string, required): Projekt-ID oder Pfad
- `commit_sha` (string, required): Commit SHA

**API Call**: `GET /api/v4/projects/{id}/repository/commits/{sha}`

**Return**: Commit-Details und Diff

---

#### 8. gitlab_list_commits
**Beschreibung**: Listet Commit-History mit Filtern auf

**Parameters**:
- `project` (string, required): Projekt-ID oder Pfad
- `ref_name` (string, optional): Branch oder Tag Name
- `since` (string, optional): Commits seit diesem Datum
- `until` (string, optional): Commits bis zu diesem Datum
- `author` (string, optional): Filter nach Autor
- `path` (string, optional): Filter nach Datei-Pfad

**API Call**: `GET /api/v4/projects/{id}/repository/commits`

**Return**: Liste von Commits mit Details

---

### Merge Requests - Read Operations (5 Tools)

#### 9. gitlab_search_merge_requests
**Beschreibung**: Sucht und filtert Merge Requests

**Parameters**:
- `project` (string, required): Projekt-ID oder Pfad
- `state` (string, optional): opened/closed/merged/locked
- `author` (string, optional): Autor-Username
- `assignee` (string, optional): Assignee-Username
- `labels` (string, optional): Komma-separierte Labels
- `created_after` (string, optional): Erstellt nach diesem Datum
- `created_before` (string, optional): Erstellt vor diesem Datum
- `target_branch` (string, optional): Ziel-Branch
- `source_branch` (string, optional): Quell-Branch

**API Call**: `GET /api/v4/projects/{id}/merge_requests`

**Return**: Liste von gefilterten Merge Requests

---

#### 10. gitlab_get_merge_request
**Beschreibung**: Ruft Details zu einem spezifischen Merge Request ab

**Parameters**:
- `project` (string, required): Projekt-ID oder Pfad
- `merge_request_iid` (integer, required): Merge Request IID (nicht ID!)

**API Call**: `GET /api/v4/projects/{id}/merge_requests/{iid}`

**Return**: Vollständige MR-Details (Titel, Beschreibung, Status, Approvals, etc.)

---

#### 11. gitlab_get_merge_request_changes
**Beschreibung**: Ruft Diff/Changes eines Merge Requests ab

**Parameters**:
- `project` (string, required): Projekt-ID oder Pfad
- `merge_request_iid` (integer, required): Merge Request IID

**API Call**: `GET /api/v4/projects/{id}/merge_requests/{iid}/changes`

**Return**: Datei-Änderungen und Diffs

---

#### 12. gitlab_get_merge_request_discussions
**Beschreibung**: Ruft Kommentare und Diskussionen eines MR ab

**Parameters**:
- `project` (string, required): Projekt-ID oder Pfad
- `merge_request_iid` (integer, required): Merge Request IID

**API Call**: `GET /api/v4/projects/{id}/merge_requests/{iid}/discussions`

**Return**: Diskussions-Threads mit Kommentaren

---

#### 13. gitlab_get_merge_request_commits
**Beschreibung**: Ruft alle Commits eines Merge Requests ab

**Parameters**:
- `project` (string, required): Projekt-ID oder Pfad
- `merge_request_iid` (integer, required): Merge Request IID

**API Call**: `GET /api/v4/projects/{id}/merge_requests/{iid}/commits`

**Return**: Liste von Commits im MR

---

### Merge Requests - Write Operations (3 Tools)

#### 14. gitlab_create_merge_request
**Beschreibung**: Erstellt einen neuen Merge Request

**Parameters**:
- `project` (string, required): Projekt-ID oder Pfad
- `source_branch` (string, required): Quell-Branch
- `target_branch` (string, required): Ziel-Branch
- `title` (string, required): MR Titel
- `description` (string, optional): MR Beschreibung
- `assignee_ids` (array, optional): Array von User IDs
- `labels` (string, optional): Komma-separierte Labels

**API Call**: `POST /api/v4/projects/{id}/merge_requests`

**Return**: Erstellter Merge Request mit Details

---

#### 15. gitlab_update_merge_request
**Beschreibung**: Aktualisiert einen existierenden Merge Request

**Parameters**:
- `project` (string, required): Projekt-ID oder Pfad
- `merge_request_iid` (integer, required): Merge Request IID
- `title` (string, optional): Neuer Titel
- `description` (string, optional): Neue Beschreibung
- `assignee_ids` (array, optional): Array von User IDs
- `labels` (string, optional): Komma-separierte Labels
- `state_event` (string, optional): close/reopen

**API Call**: `PUT /api/v4/projects/{id}/merge_requests/{iid}`

**Return**: Aktualisierter Merge Request

---

#### 16. gitlab_add_merge_request_note
**Beschreibung**: Fügt einen Kommentar zu einem Merge Request hinzu

**Parameters**:
- `project` (string, required): Projekt-ID oder Pfad
- `merge_request_iid` (integer, required): Merge Request IID
- `body` (string, required): Kommentar-Text

**API Call**: `POST /api/v4/projects/{id}/merge_requests/{iid}/notes`

**Return**: Erstellter Kommentar

---

### Issues (4 Tools)

#### 17. gitlab_search_issues
**Beschreibung**: Sucht und filtert GitLab Issues

**Parameters**:
- `project` (string, required): Projekt-ID oder Pfad
- `state` (string, optional): opened/closed
- `labels` (string, optional): Komma-separierte Labels
- `assignee` (string, optional): Assignee-Username
- `author` (string, optional): Autor-Username
- `created_after` (string, optional): Erstellt nach diesem Datum
- `created_before` (string, optional): Erstellt vor diesem Datum

**API Call**: `GET /api/v4/projects/{id}/issues`

**Return**: Liste von gefilterten Issues

---

#### 18. gitlab_get_issue
**Beschreibung**: Ruft Details zu einem spezifischen Issue ab

**Parameters**:
- `project` (string, required): Projekt-ID oder Pfad
- `issue_iid` (integer, required): Issue IID (nicht ID!)

**API Call**: `GET /api/v4/projects/{id}/issues/{iid}`

**Return**: Vollständige Issue-Details

---

#### 19. gitlab_create_issue
**Beschreibung**: Erstellt ein neues GitLab Issue

**Parameters**:
- `project` (string, required): Projekt-ID oder Pfad
- `title` (string, required): Issue Titel
- `description` (string, optional): Issue Beschreibung
- `assignee_ids` (array, optional): Array von User IDs
- `labels` (string, optional): Komma-separierte Labels

**API Call**: `POST /api/v4/projects/{id}/issues`

**Return**: Erstelltes Issue mit Details

---

#### 20. gitlab_update_issue
**Beschreibung**: Aktualisiert ein existierendes Issue

**Parameters**:
- `project` (string, required): Projekt-ID oder Pfad
- `issue_iid` (integer, required): Issue IID
- `title` (string, optional): Neuer Titel
- `description` (string, optional): Neue Beschreibung
- `assignee_ids` (array, optional): Array von User IDs
- `labels` (string, optional): Komma-separierte Labels
- `state_event` (string, optional): close/reopen

**API Call**: `PUT /api/v4/projects/{id}/issues/{iid}`

**Return**: Aktualisiertes Issue

---

### Package Registry (2 Tools)

#### 21. gitlab_search_packages
**Beschreibung**: Durchsucht das GitLab Package Registry

**Parameters**:
- `project` (string, optional): Projekt-ID oder Pfad für projekt-spezifische Suche
- `package_name` (string, optional): Paket-Name Suchbegriff
- `package_type` (string, optional): composer/npm/maven/etc.

**API Call**: `GET /api/v4/projects/{id}/packages` oder `GET /api/v4/groups/{id}/packages`

**Return**: Liste von Packages mit Metadaten

**Use Case**: AI Agent kann nach Spryker Keycloak Package suchen: `package_name: "keycloak"` in `project: "packages/php/spryker"`

---

#### 22. gitlab_get_package
**Beschreibung**: Ruft Details zu einem spezifischen Package ab

**Parameters**:
- `project` (string, required): Projekt-ID oder Pfad
- `package_id` (integer, required): Package ID

**API Call**: `GET /api/v4/projects/{id}/packages/{package_id}`

**Return**: Package-Details (Version, Download-URL, Dependencies)

---

### CI/CD Pipelines (2 Tools)

#### 23. gitlab_list_pipelines
**Beschreibung**: Listet CI/CD Pipelines eines Projekts auf

**Parameters**:
- `project` (string, required): Projekt-ID oder Pfad
- `ref` (string, optional): Branch oder Tag Name
- `status` (string, optional): running/pending/success/failed/canceled/skipped
- `updated_after` (string, optional): Aktualisiert nach diesem Datum

**API Call**: `GET /api/v4/projects/{id}/pipelines`

**Return**: Liste von Pipelines mit Status

---

#### 24. gitlab_get_pipeline
**Beschreibung**: Ruft Details zu einer spezifischen Pipeline ab

**Parameters**:
- `project` (string, required): Projekt-ID oder Pfad
- `pipeline_id` (integer, required): Pipeline ID
- `include_jobs` (boolean, optional): Jobs mit einschließen

**API Call**: `GET /api/v4/projects/{id}/pipelines/{id}` + `/api/v4/projects/{id}/pipelines/{id}/jobs`

**Return**: Pipeline-Details, Status, Jobs, Logs

---

## Authentifizierung & Sicherheit

### Credential Storage

1. **Eingabe**: User gibt GitLab Credentials ein:
   - URL: `https://gitlab.nxs360.com` (self-hosted) oder `https://gitlab.com`
   - Personal Access Token: `glpat-xxxxxxxxxxxxx`

2. **Verschlüsselung**:
   - Verwendet **Sodium (libsodium)** Encryption
   - 32-character ENCRYPTION_KEY aus `.env`
   - Implementiert in `EncryptionService`

3. **Speicherung**:
   - Verschlüsselte Credentials in `IntegrationConfig::encryptedCredentials`
   - Base64-encoded: `nonce + ciphertext`

### Personal Access Token (PAT)

**Erforderliche Scopes**:
- `api` - Vollständiger API-Zugriff (read+write)
- `read_api` - Read-only API-Zugriff
- `read_repository` - Repository lesen (Clone, Dateien lesen)
- `write_repository` - Repository schreiben (für zukünftige Datei-Operationen)

**Token erstellen**:
1. GitLab → User Settings → Access Tokens
2. Name: "Workoflow Integration"
3. Scopes auswählen (siehe oben)
4. Expiration Date setzen (empfohlen: 1 Jahr)
5. Token kopieren (nur einmal sichtbar!)

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
- Jeder User kann nur seine eigenen GitLab-Integrationen sehen/bearbeiten
- Integrationen sind an `User` + `Organisation` gebunden
- `workflow_user_id` ermöglicht weitere Segmentierung

### Organisation Isolation
- Integrations sind Organisation-spezifisch
- API Access nur mit korrekter Organisation UUID
- Audit Logging auf Organisation-Ebene

### Multiple GitLab Instances
- User können mehrere GitLab Instanzen konfigurieren (z.B. gitlab.nxs360.com, gitlab.com)
- Jede Instanz bekommt eigene `config_id`
- Tools werden mit Suffix exposiert: `gitlab_search_merge_requests_123`

## Tool Management

### Aktivierung/Deaktivierung

1. **Integration Level**:
   - Gesamte Integration kann aktiviert/deaktiviert werden
   - `IntegrationConfig::active` Flag

2. **Tool Level**:
   - Einzelne Tools können deaktiviert werden
   - `IntegrationConfig::disabledTools` Array
   - Flexible Kontrolle über verfügbare Funktionen
   - Beispiel: Nur Read-Operations erlauben, Write-Operations deaktivieren

### Project Access Control

**Flexible Zugriffskontrolle**:
- PAT bestimmt, welche Projekte zugänglich sind
- AI Agent kann jedes zugängliche Projekt via Parameter abfragen
- Keine Einschränkung auf konfigurierte Projekt-Liste
- Security durch GitLab-Permissions und PAT-Scopes

## Integration Registry

### Auto-Discovery

```yaml
# config/services/integrations.yaml
App\Integration\UserIntegrations\GitLabIntegration:
    autowire: true
    tags: ['app.integration']
```

### Registry Loading
- Symfony DI Container injiziert alle getaggten Services
- `IntegrationRegistry` sammelt alle Integrationen
- Zentrale Verwaltung aller Integration Types

## Error Handling

### URL Validation
- HTTPS mandatory für gitlab.com (Sicherheit)
- HTTP erlaubt für self-hosted Instanzen
- Detaillierte Fehlermeldungen bei invaliden URLs
- PSR-7 Uri für robuste URL-Verarbeitung

### API Errors

**HTTP Status Codes**:
- **401 Unauthorized**: Token ungültig oder abgelaufen
- **403 Forbidden**: Keine Berechtigung für diese Ressource
- **404 Not Found**: Projekt/MR/Issue existiert nicht
- **429 Too Many Requests**: Rate Limit erreicht (Retry mit Backoff)

**Error Handling Strategy**:
- Detaillierte Fehlermeldungen mit Kontext
- Logging via Monolog
- Audit Trail für alle API Zugriffe
- Vorschläge zur Fehlerbehebung in Responses

### Project Identifier Handling

**Flexible Project Parameter**:
```php
// Numerische ID
"project": "42"

// Namespace/Projekt Pfad
"project": "packages/php/spryker/keycloak"

// Automatisches URL-Encoding
"packages/php/spryker/keycloak" → "packages%2Fphp%2Fspryker%2Fkeycloak"
```

## Testing & Debugging

### Test Connection

```php
// IntegrationController::testConnection()
- Decrypt credentials
- Call GitLabService::testConnection()
- GET /api/v4/user
- Verify 200 OK response
- Return user info (username, email, is_admin)
```

**Test Connection Feedback**:
- ✅ Success: "Verbindung erfolgreich! Eingeloggt als: username"
- ❌ Error 401: "Token ungültig oder abgelaufen. Bitte neues Token erstellen."
- ❌ Error 403: "Keine Berechtigung. Token benötigt 'api' oder 'read_api' Scope."
- ❌ Connection Error: "GitLab Server nicht erreichbar. URL prüfen."

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

# Example: Search Merge Requests
curl -X POST "http://localhost:3979/api/integrations/{org-uuid}/execute" \
  -H "Authorization: Basic base64(user:pass)" \
  -H "Content-Type: application/json" \
  -d '{
    "tool_id": "gitlab_search_merge_requests_123",
    "parameters": {
      "project": "packages/php/spryker/keycloak",
      "state": "opened",
      "labels": "backend,review-needed"
    },
    "workflow_user_id": "user-123"
  }'

# Example: Search Packages
curl -X POST "http://localhost:3979/api/integrations/{org-uuid}/execute" \
  -H "Authorization: Basic base64(user:pass)" \
  -H "Content-Type: application/json" \
  -d '{
    "tool_id": "gitlab_search_packages_123",
    "parameters": {
      "project": "packages/php/spryker",
      "package_name": "keycloak",
      "package_type": "composer"
    },
    "workflow_user_id": "user-123"
  }'

# Example: Create Merge Request
curl -X POST "http://localhost:3979/api/integrations/{org-uuid}/execute" \
  -H "Authorization: Basic base64(user:pass)" \
  -H "Content-Type: application/json" \
  -d '{
    "tool_id": "gitlab_create_merge_request_123",
    "parameters": {
      "project": "packages/php/spryker/keycloak",
      "source_branch": "feature/new-auth",
      "target_branch": "main",
      "title": "Add Keycloak SSO support",
      "description": "Implements Single Sign-On via Keycloak",
      "labels": "enhancement,security"
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

# GitLab nicht direkt konfiguriert - User-spezifisch via Web UI
```

### Database Schema

```sql
-- integration_config table
id                    INT PRIMARY KEY
organisation_id       INT NOT NULL
user_id              INT NULL
integration_type      VARCHAR(100)  -- 'gitlab'
name                 VARCHAR(255)  -- User-defined name (z.B. "Production GitLab")
encrypted_credentials TEXT         -- Encrypted JSON: {gitlab_url, api_token}
disabled_tools       JSON         -- Array of disabled tool names
active               BOOLEAN      -- Integration active flag
last_accessed_at     DATETIME
created_at           DATETIME
updated_at           DATETIME
```

## Use Cases & Beispiele

### Use Case 1: Package Discovery für Spryker

**Szenario**: AI Agent soll Keycloak Package im Spryker Namespace finden

```json
{
  "tool_id": "gitlab_search_packages_123",
  "parameters": {
    "project": "packages/php/spryker",
    "package_name": "keycloak",
    "package_type": "composer"
  }
}
```

**Response**:
```json
{
  "success": true,
  "data": [
    {
      "id": 456,
      "name": "spryker/keycloak",
      "version": "1.2.3",
      "package_type": "composer",
      "_links": {
        "web_path": "/packages/php/spryker/keycloak"
      }
    }
  ]
}
```

### Use Case 2: MR Review Workflow

**Szenario**: AI Agent prüft offene Merge Requests und deren Änderungen

```json
// Step 1: Find open MRs
{
  "tool_id": "gitlab_search_merge_requests_123",
  "parameters": {
    "project": "packages/php/spryker/keycloak",
    "state": "opened",
    "labels": "needs-review"
  }
}

// Step 2: Get MR changes
{
  "tool_id": "gitlab_get_merge_request_changes_123",
  "parameters": {
    "project": "packages/php/spryker/keycloak",
    "merge_request_iid": 42
  }
}

// Step 3: Add review comment
{
  "tool_id": "gitlab_add_merge_request_note_123",
  "parameters": {
    "project": "packages/php/spryker/keycloak",
    "merge_request_iid": 42,
    "body": "LGTM! Code sieht gut aus. ✅"
  }
}
```

### Use Case 3: CI/CD Monitoring

**Szenario**: AI Agent überwacht Pipeline-Status

```json
// Check pipeline status
{
  "tool_id": "gitlab_list_pipelines_123",
  "parameters": {
    "project": "packages/php/spryker/keycloak",
    "ref": "main",
    "status": "failed"
  }
}

// Get pipeline details and logs
{
  "tool_id": "gitlab_get_pipeline_123",
  "parameters": {
    "project": "packages/php/spryker/keycloak",
    "pipeline_id": 789,
    "include_jobs": true
  }
}
```

### Use Case 4: Automated Issue Creation

**Szenario**: AI Agent erstellt Issue basierend auf Error Log

```json
{
  "tool_id": "gitlab_create_issue_123",
  "parameters": {
    "project": "packages/php/spryker/keycloak",
    "title": "🐛 Authentication fails with Keycloak 19.0",
    "description": "## Problem\nUser authentication fails after Keycloak upgrade...\n\n## Stack Trace\n...",
    "labels": "bug,authentication,high-priority"
  }
}
```

## Best Practices

### Security

1. **Personal Access Token Management**
   - Regelmäßige Token-Rotation (z.B. alle 6-12 Monate)
   - Minimale erforderliche Scopes verwenden
   - Separate Tokens für verschiedene Umgebungen
   - Token in `encrypted_credentials` niemals im Klartext loggen

2. **URL Security**
   - HTTPS für gitlab.com verpflichtend
   - Self-hosted: SSL/TLS Zertifikate validieren
   - Keine URLs mit Credentials (username:password@gitlab.com)

3. **Permission Management**
   - Tool-Level Deaktivierung für sensible Operationen
   - GitLab Project-Permissions als zusätzliche Sicherheitsebene
   - Audit Logging für Compliance aktiviert

### Performance

1. **API Rate Limiting**
   - GitLab.com: 2000 requests/minute (authenticated)
   - Self-hosted: Konfigurierbar
   - Implementierung: HttpClient Retry Middleware

2. **Caching**
   - Project-Metadaten cachen (TTL: 5 Minuten)
   - Tag/Branch Listen cachen
   - User-Informationen cachen

3. **Batch Operations**
   - Bei vielen MRs: Pagination nutzen
   - Parallele Requests mit Guzzle Promises
   - Connection Pooling via HttpClient

### Maintenance

1. **Token Lifecycle**
   - Expiration Monitoring
   - Automatische Benachrichtigungen vor Ablauf
   - Token-Rotation Workflow

2. **API Version Monitoring**
   - GitLab API v4 ist stable
   - Deprecation Notices beachten
   - Breaking Changes testen

3. **Health Checks**
   - Regelmäßige `testConnection()` Calls
   - Pipeline-Status Monitoring
   - Error Rate Tracking

## Troubleshooting

### Häufige Probleme

#### 1. 401 Unauthorized
**Ursachen**:
- Token abgelaufen oder ungültig
- Token hat nicht die erforderlichen Scopes
- GitLab User Account deaktiviert

**Lösung**:
```bash
# Test Connection durchführen
curl -H "PRIVATE-TOKEN: glpat-xxx" https://gitlab.nxs360.com/api/v4/user

# Neues Token erstellen mit korrekten Scopes
# GitLab → User Settings → Access Tokens
```

#### 2. 403 Forbidden
**Ursachen**:
- Keine Berechtigung für dieses Projekt
- Token-Scopes zu restriktiv
- Projekt ist privat und User kein Member

**Lösung**:
- Project Access Level prüfen
- Token-Scopes erweitern (`api` statt nur `read_api`)
- User zum Projekt hinzufügen

#### 3. 404 Not Found
**Ursachen**:
- Projekt-Pfad falsch geschrieben
- Projekt existiert nicht
- Issue/MR IID (nicht ID!) falsch

**Lösung**:
```bash
# Projekt-Pfad prüfen
gitlab_list_projects → Korrekte Pfade anzeigen

# IID vs ID beachten
# ❌ Falsch: "merge_request_iid": 123456 (das ist die ID)
# ✅ Richtig: "merge_request_iid": 42 (das ist die IID)
```

#### 4. 429 Too Many Requests
**Ursachen**:
- GitLab Rate Limit überschritten
- Zu viele parallele Requests

**Lösung**:
- Retry-After Header beachten
- Request-Frequenz reduzieren
- Caching implementieren

#### 5. Project Path Encoding
**Problem**: `packages/php/spryker/keycloak` wird nicht gefunden

**Lösung**:
```php
// GitLabService encodiert automatisch
"packages/php/spryker/keycloak" → "packages%2Fphp%2Fspryker%2Fkeycloak"

// Alternativ: Numerische Project ID verwenden
"project": "123" statt "project": "packages/php/spryker/keycloak"
```

### Debug Logging

```php
// Enable debug logging in .env
APP_ENV=dev
APP_DEBUG=true

// Check logs
tail -f var/log/integration_api.log
tail -f var/log/audit.log

// GitLab API Response debugging
# Response enthält Headers mit Rate Limit Info:
# RateLimit-Limit: 2000
# RateLimit-Remaining: 1999
# RateLimit-Reset: 1234567890
```

### Network Debugging

```bash
# Test GitLab API direkt
curl -H "PRIVATE-TOKEN: glpat-xxx" \
  https://gitlab.nxs360.com/api/v4/projects/packages%2Fphp%2Fspryker%2Fkeycloak

# Mit Verbose Output
curl -v -H "PRIVATE-TOKEN: glpat-xxx" \
  https://gitlab.nxs360.com/api/v4/user

# SSL/TLS Zertifikat prüfen
openssl s_client -connect gitlab.nxs360.com:443 -showcerts
```

## Erweiterungsmöglichkeiten

### Neue GitLab Tools hinzufügen

1. **Tool Definition** in `GitLabIntegration::getTools()` ergänzen:
```php
new ToolDefinition(
    name: 'gitlab_new_feature',
    description: 'Beschreibung des neuen Tools',
    parameters: ['param1' => 'description', ...]
)
```

2. **Execution Logic** in `GitLabIntegration::executeTool()` implementieren:
```php
case 'gitlab_new_feature':
    return $this->gitlabService->newFeature(
        $credentials,
        $params['param1']
    );
```

3. **API Call** in `GitLabService` hinzufügen:
```php
public function newFeature(array $credentials, string $param): array
{
    return $this->request(
        credentials: $credentials,
        method: 'GET',
        endpoint: '/api/v4/projects/{id}/new-endpoint',
        queryParams: ['param' => $param]
    );
}
```

4. **Translations** ergänzen in `translations/messages.*.yaml`

### Webhook Integration

**Future Enhancement**: GitLab Webhooks für Real-time Updates

```yaml
# Mögliche Events
- push
- merge_request
- issue
- pipeline
- deployment
```

**Implementierung**:
- Webhook Endpoint in Controller
- Event-driven Architecture mit Symfony Messenger
- Async Processing für Performance

### Advanced Features

1. **File Operations**
   - Repository File Upload/Update
   - Commit API für Datei-Änderungen
   - Branch-Creation via API

2. **Wiki Integration**
   - Wiki-Seiten lesen/schreiben
   - Dokumentation Management

3. **Deployment Integration**
   - Deployment API
   - Environment Management
   - Release Tracking

4. **GraphQL API Support**
   - Effizientere Queries
   - Weniger Requests für komplexe Daten
   - Performance-Optimierung

## Performance Metrics

### Typische Response Times

```
testConnection()              ~200ms
getProject()                  ~150ms
listProjects()                ~300ms
searchMergeRequests()         ~400ms
getMergeRequestChanges()      ~500ms (abhängig von Diff-Größe)
searchPackages()              ~350ms
listPipelines()               ~250ms
```

### Rate Limits

**GitLab.com**:
- Authenticated: 2000 requests/minute
- Unauthenticated: 500 requests/minute

**Self-hosted (default)**:
- Konfigurierbar in GitLab Admin
- Empfohlen: 300-600 requests/minute pro User

## Anhang

### GitLab API v4 Dokumentation

- Official Docs: https://docs.gitlab.com/ee/api/api_resources.html
- Authentication: https://docs.gitlab.com/ee/api/index.html#authentication
- Rate Limits: https://docs.gitlab.com/ee/user/admin_area/settings/user_and_ip_rate_limits.html

### Personal Access Token Creation

1. GitLab → Avatar (top right) → Preferences
2. Left sidebar → Access Tokens
3. Name: "Workoflow Integration"
4. Expiration date: 1 Jahr
5. Scopes: `api`, `read_api`, `read_repository`, `write_repository`
6. Create personal access token
7. Copy token (nur einmal sichtbar!)

### Project Path vs Project ID

**Project Path**: `namespace/subgroup/project-name`
- Human-readable
- Kann sich ändern bei Rename
- URL-Encoding erforderlich

**Project ID**: Numerische ID (z.B. `123`)
- Unveränderlich
- Kein Encoding erforderlich
- Performance-Vorteil

**Empfehlung**: Beide Formate unterstützen, Project ID bevorzugen für Stabilität

### IID vs ID

**IID (Internal ID)**: Pro-Projekt Nummerierung (z.B. MR !42, Issue #15)
- Sichtbar in GitLab UI
- Verwendet in URLs: `/merge_requests/42`
- **Verwenden für API Calls!**

**ID**: Globale Datenbank-ID (z.B. 123456)
- Nicht sichtbar in UI
- Nur in API Responses

**Wichtig**: Alle `_iid` Parameter in Tools verwenden IID, nicht ID!
