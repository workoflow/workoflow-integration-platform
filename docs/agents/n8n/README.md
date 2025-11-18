# n8n Workflow Configuration Guide

## Overview

This directory contains n8n workflow JSON files for the multi-agent AI orchestration system. These workflows implement the agent architecture defined in `../system_prompts/`.

**Version:** 1.0.0
**Last Updated:** 2025-01-18

## Workflows

| Workflow | File | Type | Description |
|----------|------|------|-------------|
| Main Agent | `main_agent.json` | Orchestrator | Routes user requests to specialized sub-agents |
| Jira Agent | `jira_agent.json` | Sub-Agent | Handles Jira operations (issues, sprints, comments) |

## Architecture

```
User Request (Webhook)
    ↓
Main Agent (Orchestrator)
    ├─→ Jira Agent (Sub-Workflow)
    ├─→ Confluence Agent (Sub-Workflow) [planned]
    ├─→ SharePoint Agent (Sub-Workflow) [planned]
    └─→ System Tools Agent (Sub-Workflow) [planned]
```

### Key Principles

1. **Stateless Sub-Agents**: Only Main Agent has conversation memory
2. **Main Agent Orchestration**: All user interaction flows through Main Agent
3. **Synchronous Execution**: All work completes before webhook response
4. **Dynamic Tool Discovery**: Sub-agents fetch user-specific tools at runtime

## Importing Workflows

### Prerequisites

1. **n8n Instance**: Version 1.0.0 or higher
2. **Required Credentials**:
   - Azure OpenAI API credentials (for LLM)
   - PostgreSQL credentials (for chat memory)
   - HTTP Basic Auth credentials (for Workoflow Integration Platform API)

### Import Steps

1. **Navigate to n8n**: Open your n8n instance
2. **Import Main Agent**:
   - Go to Workflows → Import from File
   - Select `main_agent.json`
   - Click "Import"
3. **Import Jira Agent**:
   - Go to Workflows → Import from File
   - Select `jira_agent.json`
   - Click "Import"

### Configure Credentials

#### 1. Azure OpenAI API

Both workflows use the same OpenAI proxy:

- **Credential Name**: `azure-open-ai-proxy`
- **Type**: Azure OpenAI API
- **Required Fields**:
  - Resource Name
  - API Key
  - Deployment Name (model: `gpt-4.1`)

**Configuration Location**: Both Main Agent and Jira Agent → "workoflow-proxy" node

#### 2. PostgreSQL (Chat Memory)

Only Main Agent uses PostgreSQL for conversation history:

- **Credential Name**: `Postgres account`
- **Type**: PostgreSQL
- **Required Fields**:
  - Host
  - Database
  - User
  - Password
  - Port (default: 5432)

**Configuration Location**: Main Agent → "Postgres Chat Memory" node

#### 3. HTTP Basic Auth (Integration Platform)

Jira Agent uses Basic Auth to access the Workoflow Integration Platform API:

- **Credential Name**: `Workoflow Integration Platform`
- **Type**: HTTP Basic Auth
- **Required Fields**:
  - User: (API username from Integration Platform)
  - Password: (API password from Integration Platform)

**Configuration Location**: Jira Agent → "CURRENT_USER_TOOLS" and "CURRENT_USER_EXECUTE_TOOL" nodes

#### 4. Webhook Basic Auth (Main Agent)

Main Agent webhook is protected with Basic Auth:

- **Credential Name**: `Workoflow basic auth`
- **Type**: HTTP Basic Auth
- **Required Fields**:
  - User: (your webhook username)
  - Password: (your webhook password)

**Configuration Location**: Main Agent → "Webhook" node

## Workflow Configuration

### Main Agent Setup

1. **Activate Webhook**:
   - Open Main Agent workflow
   - Click on "Webhook" node
   - Copy the Production URL (e.g., `https://your-n8n.com/webhook/3fc1f315-9805-49fd-af01-bcc92ee7551d`)
   - This is your API endpoint for user requests

2. **Connect Sub-Agents**:
   - The "Call 'Jira Agent'" node must reference the correct Jira Agent workflow ID
   - After importing, verify: Click "Call 'Jira Agent'" node → Check "Workflow" dropdown shows "Jira Agent"

3. **Configure Session Memory**:
   - The "Postgres Chat Memory" node uses `userId` as session key
   - Each user gets isolated conversation history

### Jira Agent Setup

1. **Configure Integration Platform URLs**:
   - Both HTTP Request Tool nodes have URLs pointing to `https://subscribe-workflows.vcec.cloud`
   - If your Integration Platform is hosted elsewhere, update these URLs in:
     - "CURRENT_USER_TOOLS" node
     - "CURRENT_USER_EXECUTE_TOOL" node

2. **Tool Discovery Endpoint**:
   ```
   GET /api/integrations/{tenantID}/?workflow_user_id={userID}&tool_type=jira
   ```

3. **Tool Execution Endpoint**:
   ```
   POST /api/integrations/{tenantID}/execute?workflow_user_id={userID}
   ```

## Testing

### Test Main Agent

1. **Pin Test Data**:
   - Main Agent → "Webhook" node
   - Pin test data (already configured):
   ```json
   {
     "body": {
       "from": {
         "id": "test-user-123",
         "name": "Test User",
         "aadObjectId": "test-aad-id"
       },
       "conversation": {
         "id": "test-session",
         "tenantId": "0cd3a714-adc1-4540-bd08-7316a80b34f3"
       },
       "text": "Show me my open Jira tickets",
       "locale": "de-DE"
     }
   }
   ```

2. **Execute Workflow**:
   - Click "Test Workflow"
   - Verify Main Agent calls Jira Agent
   - Check response format

### Test Jira Agent

1. **Pin Test Data**:
   - Jira Agent → "When Executed by Another Workflow" node
   - Pin test data (already configured):
   ```json
   {
     "userID": "test-user-123",
     "tenantID": "0cd3a714-adc1-4540-bd08-7316a80b34f3",
     "userPrompt": "Show me my open tickets",
     "taskDescription": "Search for open Jira issues assigned to current user",
     "locale": "de-DE"
   }
   ```

2. **Execute Workflow**:
   - Click "Test Workflow"
   - Verify tool discovery works
   - Check execution completes

## Parameter Flow

### Standard Sub-Agent Parameters

All sub-agents receive these parameters from Main Agent:

| Parameter | Type | Source | Purpose |
|-----------|------|--------|---------|
| `tenantID` | string | Webhook body | Organization identifier for API calls |
| `userID` | string | Webhook body | User identifier for permission/tool filtering |
| `locale` | string | Webhook body | Language preference (de-DE, en-US) |
| `userPrompt` | string | Webhook body | Original user message (for context) |
| `taskDescription` | string | AI-generated | Refined task for this specific sub-agent |

### How Main Agent Generates taskDescription

The Main Agent uses `$fromAI()` to dynamically generate task descriptions based on:
- User's original request
- Conversation context
- Which sub-agent is being called
- Data from previous sub-agents (for multi-agent workflows)

**Example**:
- User: "Create a Confluence page from my current Jira sprint"
- Main Agent → Jira Agent: `taskDescription: "Get current sprint data with all tickets and story points"`
- Main Agent → Confluence Agent: `taskDescription: "Create page titled 'Sprint 42 Summary' with this data: {sprint_data}"`

## Response Format

All sub-agents return responses in this format:

```json
{
  "output": "User-facing message in past tense",
  "data": {
    // Optional: Structured data for Main Agent to use
  },
  "attachment": null // Or file URL
}
```

**Key Points**:
- `output`: Always past tense (e.g., "I retrieved 12 tickets" not "I will retrieve")
- `data`: Used by Main Agent to pass information to next sub-agent
- `attachment`: File URLs for generated documents (PDFs, PowerPoints)

## Troubleshooting

### Main Agent Issues

**Issue**: Sub-agent not being called
- **Check**: Tool description in "Call 'Jira Agent'" node
- **Fix**: Ensure description contains relevant keywords (jira, ticket, issue, etc.)

**Issue**: Session memory not working
- **Check**: PostgreSQL connection
- **Fix**: Verify credentials and table schema in Postgres Chat Memory node

**Issue**: Webhook not responding
- **Check**: "Respond to Webhook" nodes are connected
- **Fix**: Ensure "Successful?" If node properly routes to response nodes

### Jira Agent Issues

**Issue**: No tools available
- **Check**: Integration Platform API URL and credentials
- **Fix**: Verify user has Jira integration configured in Integration Platform

**Issue**: Tool execution fails
- **Check**: Tool parameters in CURRENT_USER_EXECUTE_TOOL node
- **Fix**: Ensure JSON body format matches API expectations

**Issue**: Wrong tools returned
- **Check**: `tool_type=jira` query parameter
- **Fix**: Verify URL in CURRENT_USER_TOOLS node includes correct tool_type

## Adding New Sub-Agents

To add a new sub-agent (e.g., Confluence Agent):

1. **Create Workflow**:
   - Copy `jira_agent.json` as template
   - Update system prompt with Confluence-specific instructions
   - Update HTTP Request Tool URLs to use `tool_type=confluence`

2. **Update Main Agent**:
   - Add new "toolWorkflow" node
   - Connect to AI Agent
   - Set tool description with Confluence keywords
   - Configure parameter mapping

3. **Update System Prompts**:
   - Create `../system_prompts/confluence_agent.xml`
   - Update `../system_prompts/main_agent.xml` with Confluence routing

4. **Document**:
   - Add workflow to this README
   - Update `workflow_design.md` with new agent

## Version History

- **1.0.0** (2025-01-18): Initial release with Main Agent and Jira Agent

## Support

- **Documentation**: `/docs/agents/system_prompts/README.md`
- **Architecture**: `workflow_design.md`
- **Integration Platform**: https://subscribe-workflows.vcec.cloud
- **Repository**: /home/patrickjaja/development/workoflow-promopage-v2

## Next Steps

After importing and configuring workflows:

1. ✅ Verify all credentials are configured
2. ✅ Test Main Agent with pinned data
3. ✅ Test Jira Agent with pinned data
4. ✅ Test end-to-end with real webhook call
5. ✅ Configure user integrations in Integration Platform
6. ✅ Monitor audit logs for issues

---

**Last Updated:** 2025-01-18
**Maintainer:** Development Team
