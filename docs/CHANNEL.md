# Channel Documentation

## Overview

A **Channel** (implemented as the `Organisation` entity) is the primary entry point to the Workoflow Integration Platform. It serves as a secure, scoped API gateway that enables third-party providers (such as AI agents, automation platforms like n8n, or custom applications) to execute tools on behalf of users.

## Purpose

Channels provide:

1. **Scoped API Access**: Each channel has a unique UUID that acts as an isolated entry point to a specific set of integrations
2. **Tool Execution**: Third-party providers can discover and execute user-configured tools (Jira, Confluence, GitLab, Trello, etc.)
3. **Multi-Tenant Support**: Each channel can have multiple users, with optional per-user scoping via `workflow_user_id`
4. **Security Boundary**: Basic authentication and channel-specific credentials ensure secure access
5. **Integration Management**: Channels contain and manage all integration configurations for their members

## Conceptual Model

```
Third-Party Provider (n8n, AI Agent, etc.)
    ↓
    Authenticates with Basic Auth
    ↓
Channel API Gateway (/api/integrations/{channel-uuid})
    ↓
    Discovers available tools (GET /api/integrations/{uuid})
    Executes tools (POST /api/integrations/{uuid}/execute)
    ↓
User Integrations (Jira, Confluence, GitLab, etc.)
    ↓
External Services (Jira Server, Confluence Cloud, etc.)
```

## Technical Architecture

### Channel Entity (Organisation)

Each channel is represented by an `Organisation` entity with:

| Property | Type | Description |
|----------|------|-------------|
| `id` | INT | Internal database ID |
| `uuid` | UUID | Unique identifier for API access (public-facing) |
| `name` | String | Display name of the channel |
| `createdAt` | DateTime | Creation timestamp |
| `updatedAt` | DateTime | Last update timestamp |

### API Base URL

All channel API endpoints follow this pattern:

```
/api/integrations/{channel-uuid}
```

The UUID is the public identifier that third-party providers use to access the channel.

## API Endpoints

### 1. List Available Tools

**Endpoint**: `GET /api/integrations/{channel-uuid}`

Retrieves all tools available within the channel that the authenticated user can access.

**Query Parameters**:
- `workflow_user_id` (optional): Filter tools to a specific user within the channel
- `tool_type` (optional): Filter by integration type (e.g., `jira`, `confluence`, `system`)

**Authentication**: Basic Auth required

**Response**:
```json
{
  "tools": [
    {
      "type": "function",
      "function": {
        "name": "jira_search_123",
        "description": "Search for Jira issues using JQL (Production Jira)",
        "parameters": {
          "type": "object",
          "properties": {
            "jql": {
              "type": "string",
              "description": "JQL query string"
            },
            "maxResults": {
              "type": "integer",
              "description": "Maximum number of results"
            }
          },
          "required": ["jql"]
        }
      }
    },
    {
      "type": "function",
      "function": {
        "name": "confluence_get_page_456",
        "description": "Get a Confluence page by ID (Team Wiki)",
        "parameters": {
          "type": "object",
          "properties": {
            "pageId": {
              "type": "string",
              "description": "Confluence page ID"
            }
          },
          "required": ["pageId"]
        }
      }
    }
  ]
}
```

**Tool Naming Convention**:
- User integrations: `{integration_type}_{tool_name}_{config_id}`
  - Example: `jira_search_123` (Jira search for config ID 123)
  - Allows multiple instances of the same integration (e.g., Production Jira + Dev Jira)
- System integrations: `{integration_type}_{tool_name}`
  - Example: `system_share_file` (no config ID needed)

### 2. Execute Tool

**Endpoint**: `POST /api/integrations/{channel-uuid}/execute`

Executes a specific tool on behalf of the user.

**Authentication**: Basic Auth required

**Request Body**:
```json
{
  "tool": "jira_search_123",
  "params": {
    "jql": "project = PROJ AND status = Open",
    "maxResults": 10
  }
}
```

**Response** (success):
```json
{
  "success": true,
  "result": {
    "issues": [
      {
        "key": "PROJ-123",
        "summary": "Fix login bug",
        "status": "Open"
      }
    ],
    "total": 1
  }
}
```

**Response** (error):
```json
{
  "success": false,
  "error": "Tool not found: jira_search_999"
}
```

## Authentication

### Basic Authentication

All API requests must include Basic Auth credentials:

```bash
Authorization: Basic base64(username:password)
```

Credentials are configured in the application's `.env` file:
```env
API_AUTH_USER=your_api_user
API_AUTH_PASSWORD=your_api_password
```

### Security Considerations

1. **Transport Security**: Always use HTTPS in production
2. **Credential Rotation**: Regularly rotate API credentials
3. **Access Logging**: All API access is logged for audit purposes
4. **Rate Limiting**: Consider implementing rate limiting for production use
5. **Channel Isolation**: Each channel can only access its own integrations

## Integration Scoping

### User Integrations

User integrations require external credentials (API tokens, OAuth tokens):
- **Jira Integration**: Requires Jira URL, email, and API token
- **Confluence Integration**: Requires Confluence URL and authentication
- **GitLab Integration**: Requires GitLab URL and personal access token
- **Trello Integration**: Requires Trello API key and token

Each user in a channel can configure their own instances of these integrations.

### System Integrations

System integrations are platform-internal and don't require external credentials:
- **ShareFile**: File sharing within the platform
- Must be explicitly requested via `tool_type=system` parameter

### Multi-User Channels

When multiple users belong to a channel:
- By default, all user integrations are available
- Use `workflow_user_id` parameter to scope to a specific user
- Each user's integrations are isolated and use their credentials

## Third-Party Provider Integration

### n8n Workflow Setup

1. **HTTP Request Node** - List Tools:
```javascript
{
  "method": "GET",
  "url": "https://your-domain.com/api/integrations/{{$node["Channel UUID"].json["uuid"]}}",
  "authentication": "basicAuth",
  "options": {
    "qs": {
      "workflow_user_id": "{{$json["workflow_user_id"]}}"
    }
  }
}
```

2. **HTTP Request Node** - Execute Tool:
```javascript
{
  "method": "POST",
  "url": "https://your-domain.com/api/integrations/{{$json["channel_uuid"]}}/execute",
  "authentication": "basicAuth",
  "body": {
    "tool": "{{$json["tool_name"]}}",
    "params": "{{$json["tool_params"]}}"
  }
}
```

### Python Client Example

```python
import requests
from requests.auth import HTTPBasicAuth

class WorkoflowClient:
    def __init__(self, base_url, channel_uuid, username, password):
        self.base_url = base_url
        self.channel_uuid = channel_uuid
        self.auth = HTTPBasicAuth(username, password)

    def list_tools(self, workflow_user_id=None, tool_type=None):
        """List available tools in the channel."""
        url = f"{self.base_url}/api/integrations/{self.channel_uuid}"
        params = {}
        if workflow_user_id:
            params['workflow_user_id'] = workflow_user_id
        if tool_type:
            params['tool_type'] = tool_type

        response = requests.get(url, auth=self.auth, params=params)
        response.raise_for_status()
        return response.json()

    def execute_tool(self, tool_name, params):
        """Execute a tool with given parameters."""
        url = f"{self.base_url}/api/integrations/{self.channel_uuid}/execute"
        payload = {
            "tool": tool_name,
            "params": params
        }

        response = requests.post(url, auth=self.auth, json=payload)
        response.raise_for_status()
        return response.json()

# Usage
client = WorkoflowClient(
    base_url="https://app.workoflow.com",
    channel_uuid="550e8400-e29b-41d4-a716-446655440000",
    username="api_user",
    password="api_password"
)

# List all tools
tools = client.list_tools()

# Execute Jira search
result = client.execute_tool(
    tool_name="jira_search_123",
    params={
        "jql": "project = PROJ AND status = Open",
        "maxResults": 10
    }
)
```

### JavaScript/Node.js Client Example

```javascript
const axios = require('axios');

class WorkoflowClient {
  constructor(baseURL, channelUuid, username, password) {
    this.client = axios.create({
      baseURL: baseURL,
      auth: {
        username: username,
        password: password
      }
    });
    this.channelUuid = channelUuid;
  }

  async listTools(workflowUserId = null, toolType = null) {
    const params = {};
    if (workflowUserId) params.workflow_user_id = workflowUserId;
    if (toolType) params.tool_type = toolType;

    const response = await this.client.get(
      `/api/integrations/${this.channelUuid}`,
      { params }
    );
    return response.data;
  }

  async executeTool(toolName, params) {
    const response = await this.client.post(
      `/api/integrations/${this.channelUuid}/execute`,
      {
        tool: toolName,
        params: params
      }
    );
    return response.data;
  }
}

// Usage
const client = new WorkoflowClient(
  'https://app.workoflow.com',
  '550e8400-e29b-41d4-a716-446655440000',
  'api_user',
  'api_password'
);

// List all tools
const tools = await client.listTools();

// Execute Confluence page retrieval
const result = await client.executeTool(
  'confluence_get_page_456',
  { pageId: '123456' }
);
```

## Use Cases

### 1. AI Agent Integration

Enable AI agents (Claude, ChatGPT, etc.) to interact with your team's tools:

```
User: "Show me all open bugs in project PROJ"
  ↓
AI Agent (via n8n)
  ↓
Workoflow Channel API
  ↓
Execute: jira_search (JQL: "project = PROJ AND type = Bug AND status = Open")
  ↓
Return results to AI Agent
  ↓
AI formats and presents to user
```

### 2. Automation Platform (n8n, Zapier, Make)

Create automated workflows that combine multiple tools:

```
Trigger: New Trello card in "To Do" list
  ↓
n8n Workflow
  ↓
Channel API: Create Jira issue
  ↓
Channel API: Create Confluence page for documentation
  ↓
Channel API: Post summary to Trello card
```

### 3. Custom Integration

Build your own applications that leverage user-configured integrations:

```python
# Your custom application
def sync_jira_to_database():
    # Get open issues from Jira via Channel API
    issues = client.execute_tool('jira_search_123', {
        'jql': 'status = Open',
        'maxResults': 100
    })

    # Store in your database
    for issue in issues['result']['issues']:
        db.save(issue)
```

### 4. Multi-Tenant Scenarios

Support multiple teams with separate channels:

```python
# Team A's channel
team_a_client = WorkoflowClient(
    base_url="https://app.workoflow.com",
    channel_uuid="team-a-uuid",
    username="api_user",
    password="api_password"
)

# Team B's channel
team_b_client = WorkoflowClient(
    base_url="https://app.workoflow.com",
    channel_uuid="team-b-uuid",
    username="api_user",
    password="api_password"
)

# Each team has isolated integrations
team_a_tools = team_a_client.list_tools()
team_b_tools = team_b_client.list_tools()
```

## Channel Management

### Creating Channels

**Admin users** can create multiple channels:

1. Navigate to **Instructions** page
2. Click **"Create New Channel"** button
3. Enter channel name
4. Channel is created with auto-generated UUID
5. New channel becomes active immediately

### Multi-Channel Support

- Users can belong to multiple channels
- Admin users can create unlimited channels
- Channels are independent and isolated
- Each channel has separate integration configurations

### Channel Switching

When a user belongs to multiple channels:

1. Channel switcher appears in the header
2. Click dropdown to select active channel
3. All views (Dashboard, Tools, Instructions) show selected channel's data
4. API requests use the selected channel's UUID

### Integration Management per Channel

Each channel manages its own integrations:

1. **Tools Page**: Add/configure integrations (Jira, Confluence, GitLab, etc.)
2. **Test Connection**: Verify credentials work
3. **Enable/Disable**: Control which integrations are active
4. **Function Toggle**: Enable/disable specific tools within an integration

## Complete Usage Example

### Scenario: AI Agent searches Jira and creates Confluence documentation

**Step 1**: List available tools

```bash
curl -X GET "https://app.workoflow.com/api/integrations/550e8400-e29b-41d4-a716-446655440000" \
  -H "Authorization: Basic YXBpX3VzZXI6YXBpX3Bhc3N3b3Jk" \
  -H "Accept: application/json"
```

Response identifies: `jira_search_123` and `confluence_create_page_456`

**Step 2**: Search Jira

```bash
curl -X POST "https://app.workoflow.com/api/integrations/550e8400-e29b-41d4-a716-446655440000/execute" \
  -H "Authorization: Basic YXBpX3VzZXI6YXBpX3Bhc3N3b3Jk" \
  -H "Content-Type: application/json" \
  -d '{
    "tool": "jira_search_123",
    "params": {
      "jql": "project = PROJ AND status = Open AND priority = High",
      "maxResults": 10
    }
  }'
```

**Step 3**: Create Confluence documentation

```bash
curl -X POST "https://app.workoflow.com/api/integrations/550e8400-e29b-41d4-a716-446655440000/execute" \
  -H "Authorization: Basic YXBpX3VzZXI6YXBpX3Bhc3N3b3Jk" \
  -H "Content-Type: application/json" \
  -d '{
    "tool": "confluence_create_page_456",
    "params": {
      "space": "PROJ",
      "title": "High Priority Issues - 2025-10-27",
      "content": "<h1>High Priority Open Issues</h1><ul><li>PROJ-123: Fix login bug</li><li>PROJ-124: Performance issue</li></ul>"
    }
  }'
```

## Troubleshooting

### Common Issues

**1. Authentication Failed (401)**
- Verify Basic Auth credentials in `.env`
- Ensure credentials are base64 encoded correctly
- Check that API_AUTH_USER and API_AUTH_PASSWORD are set

**2. Channel Not Found (404)**
- Verify channel UUID is correct
- Check that channel exists in database: `SELECT * FROM organisation WHERE uuid = 'your-uuid';`

**3. Tool Not Found**
- Tool may be disabled in the channel
- Integration may be inactive
- Check tool list with `GET /api/integrations/{uuid}`

**4. Tool Execution Failed**
- Verify integration credentials are valid
- Check external service (Jira, Confluence) is accessible
- Review audit logs for detailed error messages

### Debugging

**Check audit logs**:
```bash
docker-compose logs frankenphp | grep -i integration_api
```

**Verify channel**:
```sql
SELECT id, uuid, name FROM organisation WHERE uuid = 'your-uuid';
```

**List channel integrations**:
```sql
SELECT ic.id, ic.name, ic.integration_type, ic.active
FROM integration_config ic
JOIN organisation o ON ic.organisation_id = o.id
WHERE o.uuid = 'your-uuid';
```

## Security Best Practices

1. **Use HTTPS**: Always use HTTPS in production to encrypt credentials
2. **Rotate Credentials**: Regularly rotate API Basic Auth credentials
3. **Audit Logging**: Monitor API access logs for suspicious activity
4. **Rate Limiting**: Implement rate limiting to prevent abuse
5. **Minimal Permissions**: Grant users minimal required integration permissions
6. **Secure Storage**: Integration credentials stored encrypted with Sodium
7. **Token Expiration**: Consider implementing token expiration for long-lived sessions

## Performance Considerations

1. **Tool Discovery Caching**: Consider caching tool list responses
2. **Connection Pooling**: Use connection pooling for external services
3. **Async Execution**: Long-running tools should be executed asynchronously
4. **Batch Operations**: Group multiple tool executions when possible
5. **Timeout Configuration**: Configure appropriate timeouts for external services

## API Reference Summary

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/integrations/{uuid}` | GET | List available tools |
| `/api/integrations/{uuid}/execute` | POST | Execute a tool |

| Query Parameter | Endpoint | Description |
|----------------|----------|-------------|
| `workflow_user_id` | GET | Filter tools by user |
| `tool_type` | GET | Filter by integration type |

| Authentication | Required | Method |
|----------------|----------|---------|
| Basic Auth | Yes | Authorization header |

## Related Documentation

- [Jira Integration](JIRA.md) - Jira-specific tools and configuration
- [GitLab Integration](GITLAB.md) - GitLab-specific tools and configuration
- [Trello Integration](TRELLO.md) - Trello-specific tools and configuration
- [Setup Guide](SETUP_SH.md) - Platform installation and configuration
- [Testing Guide](TESTING.md) - Testing integrations and API
