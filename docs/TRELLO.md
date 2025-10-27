# Trello Integration Documentation

## Übersicht

Die Trello Integration ermöglicht es AI-Agenten, auf Trello Boards, Lists und Cards zuzugreifen und umfassende Operationen durchzuführen. Diese Integration ist Teil des Plugin-basierten Integrationssystems der Workoflow Platform und bietet 12 spezialisierte Tools für Board-Management, Card-Operationen, Checklists und Comments.

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
│              TrelloIntegration                                │
│   - Implements IntegrationInterface                         │
│   - Defines 12 Trello tools                                 │
│   - Validates credentials                                   │
└────────────────────────┬────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────┐
│                TrelloService                                  │
│   - HTTP Client wrapper                                     │
│   - Trello REST API v1 calls                                │
│   - Key + Token authentication                              │
└─────────────────────────────────────────────────────────────┘
```

### Dateistruktur

```
src/
├── Integration/
│   ├── IntegrationInterface.php           # Core contract für alle Integrationen
│   ├── UserIntegrations/
│   │   └── TrelloIntegration.php          # Trello Integration Implementation
│   └── IntegrationRegistry.php            # Zentrale Registry für alle Integrationen
├── Service/
│   ├── Integration/
│   │   └── TrelloService.php              # Trello API Client Service
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
Eingabe: Trello API Key, API Token
  ↓
TrelloIntegration::validateCredentials()
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
Return Available Tools (12 Trello tools):
  - trello_search_{config_id}
  - trello_get_boards_{config_id}
  - trello_get_board_{config_id}
  - trello_get_board_lists_{config_id}
  - trello_get_board_cards_{config_id}
  - trello_get_list_cards_{config_id}
  - trello_get_card_{config_id}
  - trello_get_card_comments_{config_id}
  - trello_get_card_checklists_{config_id}
  - trello_create_card_{config_id}
  - trello_update_card_{config_id}
  - trello_add_comment_{config_id}
```

### 3. Tool Execution (API)

```
AI Agent → POST /api/integrations/{org-uuid}/execute
  ↓
Request Body: {
  "tool_id": "trello_search_123",
  "parameters": {
    "query": "bug",
    "maxResults": 50
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
TrelloIntegration::executeTool()
  ↓
TrelloService → Trello REST API v1
  ↓
Return Response to AI Agent
```

## Trello Service Details

### Entry Point: `src/Service/Integration/TrelloService.php`

Die TrelloService Klasse ist der zentrale HTTP Client für alle Trello API v1 Aufrufe:

#### Hauptfunktionen:

1. **Authentication**
   - API Key + API Token (Query Parameters)
   - Beide Credentials erforderlich für jeden Request
   - Credentials verschlüsselt in DB gespeichert

2. **API Base URL**
   - `https://api.trello.com/1`
   - Alle Endpoints relativ zu dieser Base URL

3. **Request Builder**
   - `buildAuthQuery()` - Erstellt Query Parameter mit Key + Token
   - Automatisches Hinzufügen von Auth zu allen Requests

#### API Endpunkte:

Die TrelloService implementiert umfassende Trello API v1 Unterstützung:

```php
// Connection & Auth
testConnection()               # GET /1/members/me

// Search
search()                       # GET /1/search

// Boards
getBoards()                    # GET /1/members/me/boards
getBoard()                     # GET /1/boards/{boardId}
getBoardLists()                # GET /1/boards/{boardId}/lists
getBoardCards()                # GET /1/boards/{boardId}/cards

// Lists & Cards
getListCards()                 # GET /1/lists/{listId}/cards
getCard()                      # GET /1/cards/{cardId}
getCardComments()              # GET /1/cards/{cardId}/actions
getCardChecklists()            # GET /1/cards/{cardId}/checklists

// Write Operations
createCard()                   # POST /1/cards
updateCard()                   # PUT /1/cards/{cardId}
addComment()                   # POST /1/cards/{cardId}/actions/comments
```

## Verfügbare Tools (12 Tools)

### Search & Discovery (1 Tool)

#### 1. trello_search
**Beschreibung**: Durchsucht alle zugänglichen Trello Boards, Cards und Lists mit einer Suchquery

**Parameters**:
- `query` (string, required): Suchbegriff für Boards, Cards oder Lists
- `maxResults` (integer, optional): Maximale Anzahl Card-Ergebnisse (default: 50)

**API Call**: `GET /1/search`

**Query Parameters**:
- `modelTypes`: "cards,boards,lists"
- `cards_limit`: maxResults
- `card_fields`: "all"
- `boards_limit`: 25
- `board_fields`: "all"

**Return**: Objekt mit drei Arrays:
- `boards`: Gefundene Boards
- `cards`: Gefundene Cards
- `lists`: Gefundene Lists

**Use Case**: "Finde alle Cards und Boards mit 'bug' im Titel"

---

### Boards - Read Operations (3 Tools)

#### 2. trello_get_boards
**Beschreibung**: Listet alle Trello Boards auf, auf die der authentifizierte User Zugriff hat

**Parameters**: Keine

**API Call**: `GET /1/members/me/boards`

**Query Parameters**:
- `fields`: "all"

**Return**: Array von Boards mit folgenden Daten:
- `id`: Board ID
- `name`: Board Name
- `desc`: Board Beschreibung
- `url`: Board URL
- `closed`: Ob Board archiviert ist
- `prefs`: Board Präferenzen und Einstellungen

**Use Case**: "Zeige mir alle meine verfügbaren Trello Boards"

---

#### 3. trello_get_board
**Beschreibung**: Ruft detaillierte Informationen zu einem spezifischen Board ab

**Parameters**:
- `boardId` (string, required): Board ID

**API Call**: `GET /1/boards/{boardId}`

**Query Parameters**:
- `fields`: "all"

**Return**: Board-Details:
- `id`: Board ID
- `name`: Board Name
- `desc`: Board Beschreibung
- `url`: Board URL
- `shortUrl`: Kurze Board URL
- `closed`: Archiviert Status
- `starred`: Favoriten Status
- `prefs`: Einstellungen (Permissions, Voting, etc.)
- `memberships`: Board-Mitglieder

**Use Case**: "Hole Details zum 'Development Sprint' Board"

---

#### 4. trello_get_board_lists
**Beschreibung**: Ruft alle Lists (Spalten) eines Boards ab

**Parameters**:
- `boardId` (string, required): Board ID

**API Call**: `GET /1/boards/{boardId}/lists`

**Query Parameters**:
- `fields`: "all"

**Return**: Array von Lists mit:
- `id`: List ID
- `name`: List Name (z.B. "To Do", "In Progress", "Done")
- `closed`: Ob List archiviert ist
- `pos`: Position auf dem Board
- `subscribed`: Ob User List folgt

**Use Case**: "Zeige alle Spalten des Sprint Boards"

---

#### 5. trello_get_board_cards
**Beschreibung**: Ruft alle Cards eines Boards über alle Lists hinweg ab

**Parameters**:
- `boardId` (string, required): Board ID

**API Call**: `GET /1/boards/{boardId}/cards`

**Query Parameters**:
- `fields`: "all"

**Return**: Array aller Cards auf dem Board mit vollständigen Details

**Use Case**: "Analysiere alle Tasks auf dem Board"

---

### Cards - Read Operations (4 Tools)

#### 6. trello_get_list_cards
**Beschreibung**: Ruft alle Cards in einer spezifischen List ab

**Parameters**:
- `listId` (string, required): List ID

**API Call**: `GET /1/lists/{listId}/cards`

**Query Parameters**:
- `fields`: "all"

**Return**: Array von Cards in der List mit:
- `id`: Card ID
- `name`: Card Titel
- `desc`: Card Beschreibung
- `url`: Card URL
- `shortUrl`: Kurze Card URL
- `due`: Fälligkeitsdatum
- `dueComplete`: Ob Fälligkeit abgehakt ist
- `labels`: Array von Labels
- `idMembers`: Array von zugewiesenen Member IDs
- `idChecklists`: Array von Checklist IDs

**Use Case**: "Zeige alle Cards in der 'In Progress' List"

---

#### 7. trello_get_card
**Beschreibung**: Ruft detaillierte Informationen zu einer spezifischen Card ab

**Parameters**:
- `cardId` (string, required): Card ID

**API Call**: `GET /1/cards/{cardId}`

**Query Parameters**:
- `fields`: "all"
- `members`: "true"
- `member_fields`: "all"
- `labels`: "all"
- `attachments`: "true"
- `checklists`: "all"

**Return**: Vollständige Card-Details:
- Grunddaten (name, desc, url)
- `members`: Array von zugewiesenen Mitgliedern mit Details
- `labels`: Array von Labels mit Farben
- `attachments`: Array von Anhängen (Dateien, Links)
- `checklists`: Array von Checklists mit Items
- `badges`: Card-Statistiken (Comments, Attachments, Checklist Items)
- `due`: Fälligkeitsdatum
- `dueComplete`: Completion Status

**Use Case**: "Zeige vollständige Details der Card inkl. Mitglieder und Checklists"

---

#### 8. trello_get_card_comments
**Beschreibung**: Ruft alle Kommentare einer Card ab

**Parameters**:
- `cardId` (string, required): Card ID

**API Call**: `GET /1/cards/{cardId}/actions`

**Query Parameters**:
- `filter`: "commentCard"

**Return**: Array von Kommentaren:
- `id`: Action ID
- `type`: "commentCard"
- `date`: Kommentar-Zeitstempel
- `memberCreator`: Autor-Informationen (id, fullName, username)
- `data.text`: Kommentar-Text
- `data.card`: Card-Referenz

**Use Case**: "Zeige Diskussionsverlauf einer Card"

---

#### 9. trello_get_card_checklists
**Beschreibung**: Ruft alle Checklists und deren Items einer Card ab

**Parameters**:
- `cardId` (string, required): Card ID

**API Call**: `GET /1/cards/{cardId}/checklists`

**Query Parameters**:
- `checkItems`: "all"
- `checkItem_fields`: "all"

**Return**: Array von Checklists:
- `id`: Checklist ID
- `name`: Checklist Name
- `pos`: Position
- `checkItems`: Array von Todo-Items:
  - `id`: Item ID
  - `name`: Item Text
  - `state`: "complete" oder "incomplete"
  - `pos`: Position in Checklist
  - `due`: Fälligkeitsdatum (optional)

**Use Case**: "Prüfe Todo-Status und verbleibende Aufgaben"

---

### Cards - Write Operations (3 Tools)

#### 10. trello_create_card
**Beschreibung**: Erstellt eine neue Card in einer List

**Parameters**:
- `idList` (string, required): List ID, in der die Card erstellt werden soll
- `name` (string, required): Card Titel
- `desc` (string, optional): Card Beschreibung
- `pos` (string, optional): Position ("top", "bottom", oder Zahl)
- `due` (string, optional): Fälligkeitsdatum (ISO format: 2024-12-31T23:59:59.000Z)
- `idMembers` (string, optional): Komma-separierte Member IDs
- `idLabels` (string, optional): Komma-separierte Label IDs

**API Call**: `POST /1/cards`

**Query Parameters**: Alle Parameter als Query String

**Return**: Erstellte Card mit allen Details

**Use Case**: "Erstelle neue Bug-Card in 'To Do' mit Fälligkeit morgen"

**Beispiel**:
```json
{
  "tool_id": "trello_create_card_123",
  "parameters": {
    "idList": "5f8a1b2c3d4e5f6a7b8c9d0e",
    "name": "Fix login bug",
    "desc": "Users cannot login with special characters in password",
    "pos": "top",
    "due": "2024-12-31T23:59:59.000Z",
    "idLabels": "5f8a1b2c3d4e5f6a7b8c9d0f,5f8a1b2c3d4e5f6a7b8c9d10"
  }
}
```

---

#### 11. trello_update_card
**Beschreibung**: Aktualisiert eine existierende Card

**Parameters**:
- `cardId` (string, required): Card ID
- `name` (string, optional): Neuer Titel
- `desc` (string, optional): Neue Beschreibung
- `closed` (boolean, optional): Card archivieren/wiederherstellen
- `idList` (string, optional): Verschieben in andere List
- `pos` (string, optional): Neue Position ("top", "bottom", oder Zahl)
- `due` (string, optional): Neues Fälligkeitsdatum (ISO format)
- `dueComplete` (boolean, optional): Fälligkeit als erledigt markieren
- `idMembers` (string, optional): Komma-separierte Member IDs (ersetzt bestehende)
- `idLabels` (string, optional): Komma-separierte Label IDs (ersetzt bestehende)

**API Call**: `PUT /1/cards/{cardId}`

**Query Parameters**: Alle angegebenen Parameter

**Return**: Aktualisierte Card mit neuen Daten

**Use Case**: "Verschiebe Card zu 'Done' und markiere als abgeschlossen"

**Beispiel - Card verschieben und schließen**:
```json
{
  "tool_id": "trello_update_card_123",
  "parameters": {
    "cardId": "5f8a1b2c3d4e5f6a7b8c9d0e",
    "idList": "5f8a1b2c3d4e5f6a7b8c9d11",
    "dueComplete": true,
    "closed": false
  }
}
```

**Beispiel - Card archivieren**:
```json
{
  "tool_id": "trello_update_card_123",
  "parameters": {
    "cardId": "5f8a1b2c3d4e5f6a7b8c9d0e",
    "closed": true
  }
}
```

---

#### 12. trello_add_comment
**Beschreibung**: Fügt einen Kommentar zu einer Card hinzu

**Parameters**:
- `cardId` (string, required): Card ID
- `text` (string, required): Kommentar-Text

**API Call**: `POST /1/cards/{cardId}/actions/comments`

**Query Parameters**:
- `text`: Kommentar-Text

**Return**: Erstellter Kommentar (Action) mit:
- `id`: Action ID
- `type`: "commentCard"
- `date`: Zeitstempel
- `memberCreator`: Autor-Details
- `data.text`: Kommentar-Text

**Use Case**: "Füge Status-Update als Kommentar hinzu"

**Beispiel**:
```json
{
  "tool_id": "trello_add_comment_123",
  "parameters": {
    "cardId": "5f8a1b2c3d4e5f6a7b8c9d0e",
    "text": "Bug wurde gefixt und deployed. Bitte testen."
  }
}
```

---

## Authentifizierung & Sicherheit

### Credential Storage

1. **Eingabe**: User gibt Trello Credentials ein:
   - API Key: Von https://trello.com/app-key
   - API Token: Über Link auf der API Key Seite generieren

2. **API Key Beschaffung**:
   - Navigiere zu https://trello.com/app-key
   - API Key wird direkt angezeigt
   - Wichtig: API Key ist öffentlich sichtbar (wie Username)

3. **API Token Beschaffung**:
   - Auf der API Key Seite: Link "Token" klicken
   - Trello Authorization Dialog erscheint
   - "Allow" klicken für Permission Grant
   - Token wird angezeigt (nur einmal!)
   - Token kopieren und in Workoflow eingeben

4. **Verschlüsselung**:
   - Verwendet **Sodium (libsodium)** Encryption
   - 32-character ENCRYPTION_KEY aus `.env`
   - Implementiert in `EncryptionService`

5. **Speicherung**:
   - Verschlüsselte Credentials in `IntegrationConfig::encryptedCredentials`
   - Base64-encoded: `nonce + ciphertext`
   - JSON Format: `{"api_key": "xxx", "api_token": "yyy"}`

### API Authentication

**Query String Authentication**:
- Jeder Request benötigt: `?key={api_key}&token={api_token}`
- Automatisch von `TrelloService::buildAuthQuery()` hinzugefügt
- Keine Header-basierte Authentication

**Permissions**:
- API Token bestimmt Zugriff auf Boards
- User kann nur auf eigene und geteilte Boards zugreifen
- Read/Write Permissions werden durch Token-Scope definiert

### API Authentifizierung (Platform)

1. **Basic Auth** für REST API Zugriff:
   - Username/Password aus `.env`
   - Header: `Authorization: Basic base64(user:pass)`

2. **Organisation Isolation**:
   - Jede Organisation hat eigene UUID
   - Integrations sind Organisation-spezifisch
   - Workflow User ID für weitere Filterung

## Multi-Tenancy & Isolation

### User Isolation
- Jeder User kann nur seine eigenen Trello-Integrationen sehen/bearbeiten
- Integrationen sind an `User` + `Organisation` gebunden
- `workflow_user_id` ermöglicht weitere Segmentierung

### Organisation Isolation
- Integrations sind Organisation-spezifisch
- API Access nur mit korrekter Organisation UUID
- Audit Logging auf Organisation-Ebene

### Multiple Trello Accounts
- User können mehrere Trello Accounts konfigurieren
- Jeder Account bekommt eigene `config_id`
- Tools werden mit Suffix exposiert: `trello_search_123`

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

### Board Access Control

**Flexible Zugriffskontrolle**:
- API Token bestimmt, welche Boards zugänglich sind
- AI Agent kann jedes zugängliche Board abfragen
- Keine Einschränkung auf konfigurierte Board-Liste
- Security durch Trello-Permissions und Token-Scopes

## Integration Registry

### Auto-Discovery

```yaml
# config/services/integrations.yaml
App\Integration\UserIntegrations\TrelloIntegration:
    autowire: true
    tags: ['app.integration']
```

### Registry Loading
- Symfony DI Container injiziert alle getaggten Services
- `IntegrationRegistry` sammelt alle Integrationen
- Zentrale Verwaltung aller Integration Types

## Error Handling

### API Errors

**HTTP Status Codes**:
- **401 Unauthorized**: API Key oder Token ungültig
- **403 Forbidden**: Keine Berechtigung für diese Ressource
- **404 Not Found**: Board/List/Card existiert nicht
- **429 Too Many Requests**: Rate Limit erreicht

**Error Handling Strategy**:
- Detaillierte Fehlermeldungen mit Kontext
- Logging via Monolog
- Audit Trail für alle API Zugriffe
- Vorschläge zur Fehlerbehebung in Responses

### Connection Errors
- Timeout nach 10 Sekunden (testConnection)
- Retry-Logik im HttpClient
- Network Error Handling

## Testing & Debugging

### Test Connection

```php
// IntegrationController::testConnection()
- Decrypt credentials
- Call TrelloService::testConnection()
- GET /1/members/me
- Verify 200 OK response
- Return user info (username, fullName, email)
```

**Test Connection Feedback**:
- ✅ Success: "Verbindung erfolgreich! Eingeloggt als: {fullName}"
- ❌ Error 401: "API Key oder Token ungültig. Bitte Credentials prüfen."
- ❌ Error 404: "Trello API nicht erreichbar."
- ❌ Connection Error: "Trello Server nicht erreichbar."

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

# Example: Search for Cards
curl -X POST "http://localhost:3979/api/integrations/{org-uuid}/execute" \
  -H "Authorization: Basic base64(user:pass)" \
  -H "Content-Type: application/json" \
  -d '{
    "tool_id": "trello_search_123",
    "parameters": {
      "query": "bug",
      "maxResults": 25
    },
    "workflow_user_id": "user-123"
  }'

# Example: Create Card
curl -X POST "http://localhost:3979/api/integrations/{org-uuid}/execute" \
  -H "Authorization: Basic base64(user:pass)" \
  -H "Content-Type: application/json" \
  -d '{
    "tool_id": "trello_create_card_123",
    "parameters": {
      "idList": "5f8a1b2c3d4e5f6a7b8c9d0e",
      "name": "Fix critical bug",
      "desc": "Login fails with special characters",
      "pos": "top",
      "due": "2024-12-31T23:59:59.000Z"
    },
    "workflow_user_id": "user-123"
  }'

# Example: Update Card (move to Done)
curl -X POST "http://localhost:3979/api/integrations/{org-uuid}/execute" \
  -H "Authorization: Basic base64(user:pass)" \
  -H "Content-Type: application/json" \
  -d '{
    "tool_id": "trello_update_card_123",
    "parameters": {
      "cardId": "5f8a1b2c3d4e5f6a7b8c9d0e",
      "idList": "5f8a1b2c3d4e5f6a7b8c9d11",
      "dueComplete": true
    },
    "workflow_user_id": "user-123"
  }'
```

## Konfiguration

### Environment Variables

```env
# API Authentication (Platform)
API_AUTH_USER=api_user
API_AUTH_PASSWORD=secure_password

# Encryption Key (32 characters)
ENCRYPTION_KEY=your-32-character-encryption-key

# Trello nicht direkt konfiguriert - User-spezifisch via Web UI
```

### Database Schema

```sql
-- integration_config table
id                    INT PRIMARY KEY
organisation_id       INT NOT NULL
user_id              INT NULL
integration_type      VARCHAR(100)  -- 'trello'
name                 VARCHAR(255)  -- User-defined name (z.B. "Personal Trello")
encrypted_credentials TEXT         -- Encrypted JSON: {api_key, api_token}
disabled_tools       JSON         -- Array of disabled tool names
active               BOOLEAN      -- Integration active flag
last_accessed_at     DATETIME
created_at           DATETIME
updated_at           DATETIME
```

## Use Cases & Beispiele

### Use Case 1: Sprint Planning

**Szenario**: AI Agent analysiert Sprint Board und erstellt neue Tasks

```json
// Step 1: Get Sprint Board
{
  "tool_id": "trello_get_boards_123",
  "parameters": {}
}

// Step 2: Get Board Lists
{
  "tool_id": "trello_get_board_lists_123",
  "parameters": {
    "boardId": "5f8a1b2c3d4e5f6a7b8c9d0e"
  }
}

// Step 3: Create new Card in "To Do" List
{
  "tool_id": "trello_create_card_123",
  "parameters": {
    "idList": "5f8a1b2c3d4e5f6a7b8c9d11",
    "name": "Implement user authentication",
    "desc": "Add OAuth2 login with Google",
    "pos": "top",
    "due": "2024-12-31T23:59:59.000Z"
  }
}
```

---

### Use Case 2: Bug Tracking Workflow

**Szenario**: AI Agent sucht nach Bugs, analysiert Details und updated Status

```json
// Step 1: Search for bug Cards
{
  "tool_id": "trello_search_123",
  "parameters": {
    "query": "bug critical",
    "maxResults": 50
  }
}

// Step 2: Get Card details with checklists
{
  "tool_id": "trello_get_card_123",
  "parameters": {
    "cardId": "5f8a1b2c3d4e5f6a7b8c9d0e"
  }
}

// Step 3: Get Card comments to understand context
{
  "tool_id": "trello_get_card_comments_123",
  "parameters": {
    "cardId": "5f8a1b2c3d4e5f6a7b8c9d0e"
  }
}

// Step 4: Add progress comment
{
  "tool_id": "trello_add_comment_123",
  "parameters": {
    "cardId": "5f8a1b2c3d4e5f6a7b8c9d0e",
    "text": "Bug wurde reproduziert. Fix in Arbeit."
  }
}

// Step 5: Move Card to "In Progress"
{
  "tool_id": "trello_update_card_123",
  "parameters": {
    "cardId": "5f8a1b2c3d4e5f6a7b8c9d0e",
    "idList": "5f8a1b2c3d4e5f6a7b8c9d12"
  }
}

// Step 6: After fix - Move to Done and mark complete
{
  "tool_id": "trello_update_card_123",
  "parameters": {
    "cardId": "5f8a1b2c3d4e5f6a7b8c9d0e",
    "idList": "5f8a1b2c3d4e5f6a7b8c9d13",
    "dueComplete": true
  }
}
```

---

### Use Case 3: Daily Standup Report

**Szenario**: AI Agent erstellt automatischen Standup-Report

```json
// Step 1: Get all Cards in "In Progress" List
{
  "tool_id": "trello_get_list_cards_123",
  "parameters": {
    "listId": "5f8a1b2c3d4e5f6a7b8c9d12"
  }
}

// Step 2: For each Card - Get Checklists to check progress
{
  "tool_id": "trello_get_card_checklists_123",
  "parameters": {
    "cardId": "5f8a1b2c3d4e5f6a7b8c9d0e"
  }
}

// Step 3: Get recent comments for updates
{
  "tool_id": "trello_get_card_comments_123",
  "parameters": {
    "cardId": "5f8a1b2c3d4e5f6a7b8c9d0e"
  }
}

// AI Agent generiert Report basierend auf gesammelten Daten
```

---

### Use Case 4: Card Migration & Archiving

**Szenario**: AI Agent archiviert abgeschlossene Cards automatisch

```json
// Step 1: Get all Cards from "Done" List
{
  "tool_id": "trello_get_list_cards_123",
  "parameters": {
    "listId": "5f8a1b2c3d4e5f6a7b8c9d13"
  }
}

// Step 2: Add final comment before archiving
{
  "tool_id": "trello_add_comment_123",
  "parameters": {
    "cardId": "5f8a1b2c3d4e5f6a7b8c9d0e",
    "text": "Task abgeschlossen und archiviert. Deployed to Production."
  }
}

// Step 3: Archive Card
{
  "tool_id": "trello_update_card_123",
  "parameters": {
    "cardId": "5f8a1b2c3d4e5f6a7b8c9d0e",
    "closed": true
  }
}
```

---

## Best Practices

### Security

1. **API Token Management**
   - Token niemals im Klartext loggen oder anzeigen
   - Token in `encrypted_credentials` sicher aufbewahren
   - Token-Erneuerung bei Verdacht auf Kompromittierung
   - Separate Tokens für verschiedene Environments

2. **Permission Management**
   - Tool-Level Deaktivierung für sensible Operationen
   - Read-Only Access wenn Write-Operations nicht benötigt
   - Audit Logging für Compliance aktiviert

3. **Board Access Control**
   - Nur notwendige Boards mit Integration teilen
   - Trello Board Permissions als zusätzliche Sicherheitsebene
   - Regelmäßige Review der Board-Zugänge

### Performance

1. **API Rate Limiting**
   - Trello Free: 100 requests/10 seconds per token
   - Trello Business Class: 300 requests/10 seconds
   - Rate Limit Handling im HttpClient implementieren
   - Retry mit Exponential Backoff

2. **Caching**
   - Board-Liste cachen (TTL: 5 Minuten)
   - List-Struktur cachen
   - Card-Details cachen für kurze Zeit (TTL: 1 Minute)

3. **Batch Operations**
   - Bei vielen Cards: Pagination beachten
   - Parallele Requests vermeiden (Rate Limits!)
   - Connection Pooling via HttpClient

### Maintenance

1. **Token Lifecycle**
   - Tokens haben kein Ablaufdatum (solange nicht revoked)
   - Regelmäßige Token-Rotation empfohlen (alle 6-12 Monate)
   - Token revoken bei User-Offboarding

2. **API Version Monitoring**
   - Trello API v1 ist stable
   - Breaking Changes sind selten
   - Deprecation Notices in Trello Developer Portal beachten

3. **Health Checks**
   - Regelmäßige `testConnection()` Calls
   - Board-Access Monitoring
   - Error Rate Tracking

## Troubleshooting

### Häufige Probleme

#### 1. 401 Unauthorized

**Ursachen**:
- API Key oder Token ungültig
- Token wurde revoked
- API Key passt nicht zum Token

**Lösung**:
```bash
# Test Connection durchführen
curl "https://api.trello.com/1/members/me?key={api_key}&token={api_token}"

# Wenn 401: Neues Token generieren
# https://trello.com/app-key → Token Link klicken
```

---

#### 2. 403 Forbidden

**Ursachen**:
- Keine Berechtigung für dieses Board/Card
- Board ist privat und Token-User kein Member
- Card existiert, aber kein Zugriff

**Lösung**:
- Board Permissions prüfen
- User zum Board einladen
- Board auf "Team Visible" oder "Public" setzen

---

#### 3. 404 Not Found

**Ursachen**:
- Board/List/Card ID falsch
- Ressource wurde gelöscht
- ID wurde falsch kopiert

**Lösung**:
```bash
# Board IDs validieren
trello_get_boards → Korrekte IDs anzeigen

# List IDs validieren
trello_get_board_lists → Korrekte List IDs

# Card IDs validieren
trello_get_board_cards → Alle Card IDs anzeigen
```

---

#### 4. 429 Too Many Requests

**Ursachen**:
- Trello Rate Limit überschritten
- Zu viele parallele Requests
- Rate Limit: 100 req/10 sec (Free) oder 300 req/10 sec (Business)

**Lösung**:
- Request-Frequenz reduzieren
- Retry-After Header beachten (falls vorhanden)
- Caching implementieren
- Batch-Operations optimieren

---

#### 5. Connection Timeout

**Problem**: Requests laufen in Timeout

**Lösung**:
```php
// Timeout in TrelloService erhöhen
// Aktuell: 10 Sekunden für testConnection()
// Für große Boards: Timeout erhöhen

// Oder: Pagination nutzen für große Datenmengen
```

---

### Debug Logging

```php
// Enable debug logging in .env
APP_ENV=dev
APP_DEBUG=true

// Check logs
tail -f var/log/integration_api.log
tail -f var/log/audit.log

// Trello API Response debugging
# Responses enthalten keine speziellen Debug-Headers
# Status Code und Error Message sind primäre Debug-Quellen
```

### Network Debugging

```bash
# Test Trello API direkt
curl "https://api.trello.com/1/members/me?key={api_key}&token={api_token}"

# Test Board Access
curl "https://api.trello.com/1/boards/{boardId}?key={api_key}&token={api_token}"

# Test Card Creation (POST)
curl -X POST "https://api.trello.com/1/cards?key={api_key}&token={api_token}&idList={listId}&name=Test%20Card"

# Mit Verbose Output
curl -v "https://api.trello.com/1/members/me?key={api_key}&token={api_token}"
```

## Erweiterungsmöglichkeiten

### Neue Trello Tools hinzufügen

1. **Tool Definition** in `TrelloIntegration::getTools()` ergänzen:
```php
new ToolDefinition(
    name: 'trello_new_feature',
    description: 'Beschreibung des neuen Tools',
    parameters: [
        [
            'name' => 'param1',
            'type' => 'string',
            'required' => true,
            'description' => 'Parameter Beschreibung'
        ]
    ]
)
```

2. **Execution Logic** in `TrelloIntegration::executeTool()` implementieren:
```php
case 'trello_new_feature':
    return $this->trelloService->newFeature(
        $credentials,
        $parameters['param1']
    );
```

3. **API Call** in `TrelloService` hinzufügen:
```php
public function newFeature(array $credentials, string $param): array
{
    $response = $this->httpClient->request('GET', self::BASE_URL . '/endpoint', [
        'query' => array_merge($this->buildAuthQuery($credentials), [
            'param' => $param,
        ]),
    ]);

    return $response->toArray();
}
```

### Fehlende Trello Features

**Aktuell nicht implementiert, aber möglich**:

1. **Checklist Management**
   - `trello_create_checklist` - Neue Checklist erstellen
   - `trello_create_checklist_item` - Checklist Item hinzufügen
   - `trello_update_checklist_item` - Item als complete/incomplete markieren

2. **Label Management**
   - `trello_get_board_labels` - Board Labels abrufen
   - `trello_create_label` - Neues Label erstellen
   - `trello_add_label_to_card` - Label zu Card hinzufügen

3. **Member Management**
   - `trello_get_board_members` - Board-Mitglieder abrufen
   - `trello_add_member_to_card` - Member zu Card zuweisen
   - `trello_remove_member_from_card` - Member von Card entfernen

4. **List Management**
   - `trello_create_list` - Neue List erstellen
   - `trello_update_list` - List umbenennen/positionieren
   - `trello_archive_list` - List archivieren

5. **Attachment Management**
   - `trello_add_attachment` - Datei oder Link anhängen
   - `trello_delete_attachment` - Attachment löschen

6. **Board Management**
   - `trello_create_board` - Neues Board erstellen
   - `trello_update_board` - Board-Einstellungen ändern

### Webhook Integration

**Future Enhancement**: Trello Webhooks für Real-time Updates

```yaml
# Mögliche Events
- createCard
- updateCard
- addMemberToCard
- commentCard
- addAttachmentToCard
- updateCheckItemStateOnCard
```

**Implementierung**:
- Webhook Endpoint in Controller
- Event-driven Architecture mit Symfony Messenger
- Async Processing für Performance
- Webhook Validation (Callback URL mit Secret)

### PowerUps Integration

**Advanced Feature**: Trello PowerUps nutzen

- Custom Fields PowerUp für strukturierte Daten
- Calendar PowerUp für Date Management
- Voting PowerUp für Prioritization

## Performance Metrics

### Typische Response Times

```
testConnection()              ~150ms
getBoards()                   ~200ms
getBoard()                    ~150ms
getBoardLists()               ~180ms
getBoardCards()               ~300ms (abhängig von Card-Anzahl)
getCard()                     ~170ms
search()                      ~400ms (abhängig von Suchbereich)
createCard()                  ~250ms
updateCard()                  ~200ms
```

### Rate Limits

**Trello Free**:
- 100 requests / 10 seconds per token
- 300 requests / 10 seconds per API key

**Trello Business Class**:
- 300 requests / 10 seconds per token
- 900 requests / 10 seconds per API key

**Trello Enterprise**:
- Höhere Limits (custom)
- SLA verfügbar

## Anhang

### Trello API v1 Dokumentation

- Official Docs: https://developer.atlassian.com/cloud/trello/rest/
- API Introduction: https://developer.atlassian.com/cloud/trello/guides/rest-api/api-introduction/
- Authorization: https://developer.atlassian.com/cloud/trello/guides/rest-api/authorization/
- Rate Limits: https://developer.atlassian.com/cloud/trello/guides/rest-api/rate-limits/

### API Key & Token Creation

1. **API Key holen**:
   - Gehe zu https://trello.com/app-key
   - API Key wird angezeigt (kann öffentlich sein)
   - Application Name: "Workoflow Integration"

2. **API Token generieren**:
   - Auf der API Key Seite: "Token" Link klicken
   - Oder direkt: https://trello.com/1/authorize?key={api_key}&name=Workoflow&expiration=never&response_type=token&scope=read,write
   - "Allow" klicken für Authorization
   - Token wird angezeigt (nur einmal!)
   - Token hat keine Expiration (außer manuell revoked)

3. **Token Permissions**:
   - `read` - Boards, Lists, Cards lesen
   - `write` - Cards erstellen, updaten, löschen
   - `account` - Account-Informationen (optional)

### Board, List, Card ID Beschaffung

**Board ID**:
- URL: `https://trello.com/b/{boardId}/board-name`
- Oder: `trello_get_boards` Tool nutzen

**List ID**:
- `trello_get_board_lists` Tool nutzen
- Oder: Board → Browser DevTools → Network Tab → List API Call

**Card ID**:
- URL: `https://trello.com/c/{cardId}/card-name`
- Oder: `trello_get_board_cards` Tool nutzen

### Trello Data Model

```
Workspace/Organization
  └── Board
      ├── Lists (Columns)
      │   └── Cards
      │       ├── Members (Assigned Users)
      │       ├── Labels (Tags/Categories)
      │       ├── Due Date
      │       ├── Checklists
      │       │   └── Checklist Items
      │       ├── Comments (Actions)
      │       └── Attachments (Files/Links)
      ├── Members (Board Access)
      └── Labels (Board-wide Labels)
```

### Integration Type

**User Integration** (nicht System Tool):
- Erfordert externe Credentials (API Key + Token)
- User-spezifische Konfiguration
- Credentials encrypted in Database
- Zugriff auf User's Trello Boards
