# Integration API Examples

## Authentication
All requests require Basic Authentication:
- Username: `workoflow`
- Password: `workoflow`

## Base URL
```
https://your-domain.com/api/integration/{orgUuid}
```

Replace `{orgUuid}` with your organization's UUID (visible in Organization Settings).

## 1. List Available Tools

**Endpoint:** `GET /api/integration/{orgUuid}/tools?id={workflow-user-id}`

**Example Request:**
```bash
curl -X GET \
  'http://localhost:3979/api/integration/550e8400-e29b-41d4-a716-446655440000/tools?id=user123' \
  -H 'Authorization: Basic d29ya29mbG93Ondvcmtvd2xvdw==' \
  -H 'Accept: application/json'
```

**Example Response:**
```json
{
  "tools": [
    {
      "id": "jira_search_1",
      "name": "jira_search",
      "description": "Search for Jira issues using JQL",
      "integration_id": 1,
      "integration_name": "My Jira Workspace",
      "integration_type": "jira",
      "parameters": [
        {
          "name": "jql",
          "type": "string",
          "required": true,
          "description": "JQL query string"
        },
        {
          "name": "maxResults",
          "type": "integer",
          "required": false,
          "default": 50,
          "description": "Maximum number of results"
        }
      ]
    },
    {
      "id": "confluence_search_2",
      "name": "confluence_search",
      "description": "Search Confluence pages using CQL",
      "integration_id": 2,
      "integration_name": "My Confluence Space",
      "integration_type": "confluence",
      "parameters": [
        {
          "name": "query",
          "type": "string",
          "required": true,
          "description": "CQL query string"
        },
        {
          "name": "limit",
          "type": "integer",
          "required": false,
          "default": 25,
          "description": "Maximum number of results"
        }
      ]
    }
  ],
  "count": 2
}
```

## 2. Execute a Tool

**Endpoint:** `POST /api/integration/{orgUuid}/execute?id={workflow-user-id}`

### Example 1: Jira Search

**Request:**
```bash
curl -X POST \
  'http://localhost:3979/api/integration/550e8400-e29b-41d4-a716-446655440000/execute?id=user123' \
  -H 'Authorization: Basic d29ya29mbG93Ondvcmtvd2xvdw==' \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{
    "tool_id": "jira_search_1",
    "parameters": {
      "jql": "project = PROJ AND status = Open",
      "maxResults": 10
    }
  }'
```

**Success Response:**
```json
{
  "success": true,
  "result": {
    "issues": [
      {
        "key": "PROJ-123",
        "summary": "Example issue",
        "status": "Open",
        "assignee": "john.doe@example.com"
      }
    ],
    "total": 1
  }
}
```

### Example 2: Confluence Page Search

**Request:**
```bash
curl -X POST \
  'http://localhost:3979/api/integration/550e8400-e29b-41d4-a716-446655440000/execute?id=user123' \
  -H 'Authorization: Basic d29ya29mbG93Ondvcmtvd2xvdw==' \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{
    "tool_id": "confluence_search_2",
    "parameters": {
      "query": "type=page AND text ~ \"documentation\"",
      "limit": 5
    }
  }'
```

### Example 3: Get Jira Issue Details

**Request:**
```bash
curl -X POST \
  'http://localhost:3979/api/integration/550e8400-e29b-41d4-a716-446655440000/execute?id=user123' \
  -H 'Authorization: Basic d29ya29mbG93Ondvcmtvd2xvdw==' \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{
    "tool_id": "jira_get_issue_1",
    "parameters": {
      "issueKey": "PROJ-123"
    }
  }'
```

## Error Responses

### Missing Authentication
```json
{
  "error": "Unauthorized"
}
```
HTTP Status: 401

### Invalid Organization UUID
```json
{
  "error": "Organisation not found"
}
```
HTTP Status: 404

### Missing Workflow User ID
```json
{
  "error": "Missing workflow user ID parameter"
}
```
HTTP Status: 400

### Tool Not Found
```json
{
  "error": "Integration not found or inactive"
}
```
HTTP Status: 404

### Tool Execution Error
```json
{
  "success": false,
  "error": "Connection failed: Unable to reach Jira instance"
}
```
HTTP Status: 500

## Integration with n8n

In n8n, you can use the HTTP Request node with these settings:

1. **Method**: GET or POST
2. **URL**: `https://your-domain.com/api/integration/{orgUuid}/tools?id={workflow-user-id}`
3. **Authentication**: Basic Auth
   - Username: `workoflow`
   - Password: `workoflow`
4. **Headers**:
   - Content-Type: `application/json` (for POST requests)
   - Accept: `application/json`

### n8n Workflow Example

1. **HTTP Request Node** - List available tools
2. **Function Node** - Parse tools and select one
3. **HTTP Request Node** - Execute selected tool
4. **Process results** - Use the API response in your workflow