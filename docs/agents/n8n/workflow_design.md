# Multi-Agent Workflow Design

## Architecture Overview

This document describes the technical architecture and design patterns for the n8n-based multi-agent AI orchestration system.

**Version:** 1.0.0
**Last Updated:** 2025-01-18

## System Architecture

### High-Level Flow

```
┌─────────────────────────────────────────────────────────────┐
│                     External User Request                    │
│                    (Teams, API, Webhook)                     │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│                      Webhook (POST)                          │
│  - Receives user message                                     │
│  - Extracts: userId, tenantId, locale, text                 │
│  - Protected with Basic Auth                                 │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│                   SET USER (n8n Set Node)                    │
│  - Maps webhook fields to variables                          │
│  - Creates: userId, userName, chatInput, sessionId, etc.    │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│                  Main Agent (AI Agent Node)                  │
│  ┌───────────────────────────────────────────────────────┐  │
│  │  Components:                                           │  │
│  │  1. System Prompt (main_agent.xml)                    │  │
│  │  2. LLM (Azure OpenAI GPT-4)                          │  │
│  │  3. Memory (PostgreSQL sessionId-based)               │  │
│  │  4. Tools (Sub-Agent Workflows)                       │  │
│  └───────────────────────────────────────────────────────┘  │
│                                                               │
│  Workflow:                                                    │
│  1. Analyze user intent + conversation history               │
│  2. Determine which sub-agent(s) needed                      │
│  3. Generate taskDescription for sub-agent via AI            │
│  4. Invoke sub-agent workflow (synchronous)                  │
│  5. Wait for sub-agent completion                            │
│  6. Extract data from sub-agent response                     │
│  7. (Optional) Invoke next sub-agent with extracted data     │
│  8. Aggregate results and respond (past tense)               │
└───────────────────────────┬─────────────────────────────────┘
                            │
                ┌───────────┴───────────┬──────────────┐
                │                       │              │
                ▼                       ▼              ▼
      ┌─────────────────┐   ┌─────────────────┐   [More
      │   Jira Agent    │   │  System Tools   │   Sub-Agents]
      │  (Sub-Workflow) │   │     Agent       │
      └─────────────────┘   └─────────────────┘
                │                       │
                ▼                       ▼
        [Returns JSON]          [Returns JSON]
                │                       │
                └───────────┬───────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│                    Successful? (If Node)                     │
│  - Checks for errors in AI Agent response                   │
│  - Routes to success or error response                       │
└───────────────────────────┬─────────────────────────────────┘
                            │
                ┌───────────┴───────────┐
                │                       │
                ▼                       ▼
    ┌──────────────────┐   ┌──────────────────┐
    │ Respond Success  │   │  Error Response  │
    │  (Webhook Reply) │   │  (Webhook Reply) │
    └──────────────────┘   └──────────────────┘
```

## Component Details

### Main Agent (Orchestrator)

**Purpose**: Central coordinator that routes user requests to specialized sub-agents

**n8n Nodes**:
1. **Webhook**: Receives external requests
2. **SET USER**: Extracts and maps user context
3. **AI Agent**: Core orchestrator with LLM
4. **Postgres Chat Memory**: Conversation history
5. **workoflow-proxy**: Azure OpenAI LLM connection
6. **Tool Nodes**: Sub-agent workflow callers (toolWorkflow type)
7. **Successful?**: Error detection and routing
8. **Respond to Webhook**: Success response
9. **Error Response**: Error handling

**Key Features**:
- ✅ Conversation memory (PostgreSQL, sessionId-keyed)
- ✅ Multi-agent coordination (sequential execution)
- ✅ Intelligent parameter generation ($fromAI)
- ✅ Error handling and user feedback

### Sub-Agents (e.g., Jira Agent)

**Purpose**: Specialized executors for specific integration types

**n8n Nodes**:
1. **When Executed by Another Workflow**: Receives parameters from Main Agent
2. **AI Agent**: Processes task with specialized system prompt
3. **workoflow-proxy**: Azure OpenAI LLM connection
4. **CURRENT_USER_TOOLS**: Fetches available tools from Integration Platform
5. **CURRENT_USER_EXECUTE_TOOL**: Executes selected tool
6. **Respond to Webhook**: Returns result to Main Agent

**Key Features**:
- ✅ Stateless operation (no memory)
- ✅ Dynamic tool discovery at runtime
- ✅ Specialized domain knowledge (Jira, Confluence, etc.)
- ✅ Structured response format for Main Agent

## Design Patterns

### Pattern 1: Stateless Sub-Agents

**Principle**: Only Main Agent maintains conversation state. Sub-agents are pure functions.

```
Input: Parameters (tenantID, userID, taskDescription)
Process: Fetch tools → Execute → Format response
Output: JSON with output, data, attachment
```

**Benefits**:
- Simpler debugging (no hidden state)
- Easier testing (deterministic given inputs)
- Better scalability (sub-agents can be replicated)
- Clear separation of concerns

**Implementation**:
- Main Agent has Postgres Chat Memory node
- Sub-agents have NO memory nodes
- All context passed via parameters

### Pattern 2: Main Agent Orchestration

**Principle**: All user interaction flows through Main Agent. Sub-agents never directly communicate with user.

**User Asks Question** → Main Agent responds (never sub-agent)
**Missing Parameter** → Main Agent asks (sub-agent returns error to Main Agent)
**Multi-Step Task** → Main Agent coordinates sequence

**Benefits**:
- Consistent user experience
- Single point of conversation context
- Easier to implement complex workflows
- Better error handling

**Implementation**:
```
Main Agent System Prompt:
- "You handle all user questions"
- "Sub-agents return errors/needs to you"
- "You decide when to ask user vs retry"

Sub-Agent System Prompt:
- "If missing parameters, return error in output"
- "Don't ask user directly"
- "Return structured data for Main Agent"
```

### Pattern 3: Intelligent Data Passing

**Principle**: Main Agent uses AI ($fromAI) to intelligently pass data between agents.

**Single-Agent Task**:
```javascript
{
  "userPrompt": "Show me my Jira tickets",
  "taskDescription": $fromAI("taskDescription", "What should Jira Agent do?")
  // AI generates: "Search for Jira issues assigned to current user with status != Done"
}
```

**Multi-Agent Task**:
```javascript
// Step 1: Call Jira Agent
{
  "taskDescription": $fromAI("taskDescription", "Extract sprint data from Jira")
  // Returns: { "output": "...", "data": { "sprintId": 42, "tickets": [...] } }
}

// Step 2: Main Agent extracts sprint data from response

// Step 3: Call Confluence Agent
{
  "taskDescription": $fromAI("taskDescription", "Create page with: " + sprintData)
  // AI embeds sprint data into task description for Confluence Agent
}
```

**Benefits**:
- Flexible (AI adapts to any data structure)
- No rigid schemas needed
- Works for simple and complex workflows
- Natural language data passing

### Pattern 4: Dynamic Tool Discovery

**Principle**: Sub-agents fetch user-specific tools at runtime from Integration Platform.

**Flow**:
```
1. Sub-Agent receives task
2. Sub-Agent calls CURRENT_USER_TOOLS
   GET /api/integrations/{tenantID}/?workflow_user_id={userID}&tool_type=jira
3. Integration Platform returns available tools for this user
   [{id: "jira_search_123", name: "...", parameters: {...}}, ...]
4. Sub-Agent AI selects appropriate tool
5. Sub-Agent calls CURRENT_USER_EXECUTE_TOOL
   POST /api/integrations/{tenantID}/execute?workflow_user_id={userID}
   {"tool_id": "jira_search_123", "parameters": {...}}
6. Integration Platform executes tool and returns result
7. Sub-Agent formats result and returns to Main Agent
```

**Benefits**:
- User-specific permissions respected
- No hardcoded tool lists in workflows
- Easy to add new tools (just update Integration Platform)
- Supports per-user integrations

**Implementation in n8n**:
- HTTP Request Tool nodes with `$fromAI()` for tool selection
- Tool descriptions guide AI: "Always call CURRENT_USER_TOOLS first"
- System prompt explains tool discovery pattern

### Pattern 5: Synchronous Webhook Execution

**Principle**: All work must complete BEFORE webhook response closes.

```
❌ PROHIBITED:
- "I'm working on it..." → close connection → continue work
- Respond with promise → do work later
- Background jobs that complete after response

✅ REQUIRED:
1. Receive request
2. Execute ALL actions
3. Wait for ALL completions
4. ONLY THEN respond
5. Connection closes (work is done)
```

**Language Enforcement**:
- Past tense only: "I have updated" not "I will update"
- Completed states: "Done!" not "Working on..."
- No promises: Never "I'll do X after..."

**Implementation**:
- System prompts emphasize webhook constraint
- n8n workflows use synchronous tool calls (no background nodes)
- "Respond to Webhook" node is final step

## Parameter Schema

### Standard Parameters for All Sub-Agents

```typescript
interface SubAgentInput {
  // Identity & Context
  tenantID: string;        // Organization UUID from Integration Platform
  userID: string;          // User identifier for permission filtering
  locale: string;          // Language preference (de-DE, en-US)

  // Task Definition
  userPrompt: string;      // Original user message (for full context)
  taskDescription: string; // AI-generated refined task for this sub-agent
}
```

### n8n Parameter Mapping (Main Agent → Sub-Agent)

```javascript
// In toolWorkflow node configuration:
{
  "workflowInputs": {
    "mappingMode": "defineBelow",
    "value": {
      "tenantID": "={{ $('SET USER').item.json.tenantId }}",
      "userID": "={{ $('SET USER').item.json.userID }}",
      "locale": "={{ $('SET USER').item.json.locale }}",
      "userPrompt": "={{ $('SET USER').item.json.chatInput }}",
      "taskDescription": "={{ $fromAI('taskDescription', 'What specific task should this sub-agent perform for this user request?', 'string') }}"
    }
  }
}
```

**Key Insight**: `$fromAI()` allows Main Agent's LLM to dynamically generate task descriptions based on:
- User's original request
- Conversation history
- Which sub-agent is being called
- Data from previous sub-agents

### Standard Response Format from Sub-Agents

```typescript
interface SubAgentResponse {
  // User-facing message (always past tense)
  output: string;

  // Structured data for Main Agent to use (optional)
  data?: {
    [key: string]: any;
  };

  // File URL for generated documents (optional)
  attachment?: string | null;
}
```

**Example Responses**:

```json
// Simple response
{
  "output": "✅ I found 12 open Jira tickets assigned to you.",
  "data": {
    "count": 12,
    "issues": [
      {"key": "PROJ-123", "summary": "Fix login bug", "status": "In Progress"},
      ...
    ]
  },
  "attachment": null
}

// Response with file
{
  "output": "✅ I generated a PDF report of Sprint 42.",
  "data": {
    "sprintId": 42,
    "ticketCount": 23,
    "completedPoints": 75
  },
  "attachment": "https://subscribe-workflows.vcec.cloud/file/abc123/sprint-42.pdf"
}

// Error response
{
  "output": "I need more information to search Jira. Which project should I search in?",
  "data": {
    "error": "missing_parameter",
    "parameter": "project"
  },
  "attachment": null
}
```

## Workflow Coordination Patterns

### Single-Agent Workflow

**User Request**: "Show me my open Jira tickets"

```
1. Main Agent receives: "Show me my open Jira tickets"
2. Main Agent analyzes: Keywords [jira, tickets] → Route to Jira Agent
3. Main Agent generates taskDescription via AI:
   "Search for Jira issues assigned to current user where status != Done"
4. Main Agent invokes Jira Agent with parameters
5. Jira Agent fetches tools, executes jira_search_*, returns results
6. Main Agent receives results
7. Main Agent responds: "✅ I found 12 open tickets: [list]"
```

**n8n Flow**:
```
Webhook → SET USER → AI Agent (decides: call Jira)
  → Call 'Jira Agent' (executes and waits)
  → AI Agent (receives results, formats response)
  → Successful? → Respond to Webhook
```

### Multi-Agent Sequential Workflow

**User Request**: "Create a Confluence page from my current Jira sprint"

```
1. Main Agent receives request
2. Main Agent analyzes: Need [Jira data] then [Confluence page]
3. Main Agent generates taskDescription for Jira:
   "Get current sprint data including all tickets, story points, and status breakdown"
4. Main Agent invokes Jira Agent → waits for completion
5. Jira Agent returns:
   {
     "output": "I retrieved Sprint 42 data",
     "data": {
       "sprintId": 42,
       "name": "Sprint 42 - Auth System",
       "tickets": [23 items],
       "totalPoints": 87,
       "completedPoints": 75
     }
   }
6. Main Agent extracts data.sprintId, data.tickets, etc.
7. Main Agent generates taskDescription for Confluence:
   "Create a Confluence page titled 'Sprint 42 - Auth System Summary' with the following data:
    - 23 tickets (18 done, 3 in progress, 2 to do)
    - 87 total story points (75 completed)
    - Include ticket breakdown by status"
8. Main Agent invokes Confluence Agent → waits for completion
9. Confluence Agent returns:
   {
     "output": "I created the Confluence page",
     "data": {
       "pageId": "12345",
       "url": "https://confluence.example.com/pages/12345"
     }
   }
10. Main Agent aggregates and responds:
    "✅ I created a Confluence page with your Sprint 42 data. The page includes all 23 tickets and shows 75/87 story points completed. View it here: [url]"
```

**Key Points**:
- Sequential execution (Jira completes BEFORE Confluence starts)
- Data flows through Main Agent (not directly between sub-agents)
- Main Agent uses AI to intelligently embed data into next task description
- User sees single, cohesive response at the end

**n8n Flow**:
```
Webhook → SET USER → AI Agent
  → Call 'Jira Agent' (executes, returns sprint data)
  → AI Agent (extracts sprint data from response, decides to call Confluence)
  → Call 'Confluence Agent' (executes with sprint data embedded in taskDescription)
  → AI Agent (receives Confluence response, aggregates results)
  → Successful? → Respond to Webhook
```

### Error Handling Workflow

**User Request**: "Add a comment to PROJ-123" (but Jira not configured)

```
1. Main Agent receives request
2. Main Agent analyzes: Keywords [comment, PROJ-123] → Route to Jira Agent
3. Main Agent invokes Jira Agent
4. Jira Agent calls CURRENT_USER_TOOLS
5. Integration Platform returns empty tools array (no Jira integration)
6. Jira Agent system prompt detects no tools available
7. Jira Agent returns:
   {
     "output": "I don't have access to Jira tools yet. Please set up a Jira integration at https://subscribe-workflows.vcec.cloud. You'll need your Jira URL, email, and API token."
   }
8. Main Agent receives this response
9. Main Agent forwards to user (no retry, clear setup instructions)
```

## Tool Description Best Practices

### For Main Agent's Sub-Agent Callers

The tool description is what Main Agent's LLM uses to decide whether to call this sub-agent.

**Good Tool Description (Jira Agent)**:
```
Use this tool when the user wants to interact with Jira. This includes:
- Searching for issues, tickets, or tasks
- Viewing issue details or status
- Creating or updating issues
- Adding comments to tickets
- Managing sprints and boards
- Transitioning issue status
- Analyzing sprint data or team progress

Keywords: jira, ticket, issue, sprint, epic, story, bug, task, backlog, board, kanban, scrum, assignee, status, transition, comment
```

**Why It Works**:
- ✅ Clear capabilities list
- ✅ Rich keywords for intent matching
- ✅ Covers all use cases
- ✅ Helps LLM make good routing decisions

**Bad Tool Description**:
```
Jira integration
```

**Why It Fails**:
- ❌ Too vague
- ❌ No keywords
- ❌ LLM can't determine when to use it

### For Sub-Agent's Internal Tools

**Good Tool Description (CURRENT_USER_TOOLS)**:
```
Always call this tool first to understand what user-specific tools are available. This returns a dynamic list of tools based on the current user's integrations. Use the response to determine which tools you can execute via CURRENT_USER_EXECUTE_TOOL.
```

**Why It Works**:
- ✅ Clear when to use ("Always call first")
- ✅ Explains dynamic nature
- ✅ Guides AI workflow

## Testing Strategies

### Unit Testing Sub-Agents

**Approach**: Pin test data to "When Executed by Another Workflow" trigger

```json
{
  "userID": "test-user-123",
  "tenantID": "test-tenant-uuid",
  "locale": "de-DE",
  "userPrompt": "Show me my tickets",
  "taskDescription": "Search for Jira issues assigned to current user"
}
```

**Test Cases**:
1. Valid request → Verify tools fetched and executed
2. No integration configured → Verify helpful error message
3. Missing parameters → Verify error returned to Main Agent
4. API failure → Verify graceful error handling

### Integration Testing Main Agent

**Approach**: Pin test data to Webhook trigger

```json
{
  "body": {
    "from": {
      "id": "test-user",
      "name": "Test User",
      "aadObjectId": "test-aad-id"
    },
    "conversation": {
      "id": "test-session",
      "tenantId": "test-tenant-uuid"
    },
    "text": "Show me my Jira tickets",
    "locale": "de-DE"
  }
}
```

**Test Cases**:
1. Single-agent task → Verify correct routing
2. Multi-agent task → Verify sequential execution
3. Ambiguous request → Verify Main Agent asks clarifying question
4. No sub-agent needed → Verify Main Agent responds directly

### End-to-End Testing

**Approach**: Real webhook calls to production URL

```bash
curl -X POST https://your-n8n.com/webhook/xxx \
  -u username:password \
  -H "Content-Type: application/json" \
  -d '{
    "body": {
      "from": {"id": "real-user-id", "name": "Real User", "aadObjectId": "..."},
      "conversation": {"id": "real-session", "tenantId": "real-tenant"},
      "text": "Create a PDF from my Jira sprint",
      "locale": "en-US"
    }
  }'
```

**Verify**:
1. Response is in past tense
2. All work completed before response
3. Attachment URLs are valid
4. Data in response matches actual state
5. Conversation memory works across requests

## Performance Considerations

### Reducing Latency

1. **Tool Discovery Caching**: Consider caching CURRENT_USER_TOOLS response for session
2. **Parallel Sub-Agents**: When tasks are independent (not yet implemented)
3. **LLM Model Selection**: Use faster models for simple tasks
4. **Limit Chat Memory**: Don't load entire history, just recent context

### Scaling

1. **Stateless Sub-Agents**: Easy to replicate and load balance
2. **Database Connection Pooling**: PostgreSQL memory node uses pooling
3. **n8n Execution Mode**: Use queue mode for high-volume scenarios

## Security Considerations

### Authentication

1. **Webhook**: Protected with HTTP Basic Auth
2. **Integration Platform API**: Requires Basic Auth credentials
3. **LLM**: Azure OpenAI API key authentication

### Data Privacy

1. **Conversation Memory**: Isolated by sessionId (users can't see each other's chats)
2. **Credentials**: Integration Platform encrypts user credentials (Jira tokens, etc.)
3. **Logs**: Audit logging at Integration Platform level

### Input Validation

1. **Webhook**: Validate required fields (userId, tenantId, text)
2. **Sub-Agents**: Validate parameters before API calls
3. **Tool Execution**: Integration Platform validates tool permissions

## Future Enhancements

### Planned Features

1. **Parallel Multi-Agent Execution**: When tasks don't depend on each other
2. **Streaming Responses**: For long-running tasks
3. **Agent State Management**: For complex, stateful workflows
4. **Tool Result Caching**: Avoid redundant API calls
5. **Advanced Error Recovery**: Automatic retries, fallback strategies

### Additional Sub-Agents

1. **Confluence Agent**: Wiki pages, documentation
2. **SharePoint Agent**: Document management, intelligent search
3. **GitLab Agent**: Repository management, CI/CD, merge requests
4. **Trello Agent**: Board management, cards, checklists

---

**Version:** 1.0.0
**Last Updated:** 2025-01-18
**Maintainer:** Development Team
