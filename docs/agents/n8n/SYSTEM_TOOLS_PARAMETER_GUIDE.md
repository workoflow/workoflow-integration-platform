# System Tools Agent - Parameter Reference Guide

## Overview

When adding tool nodes to the System Tools Agent in n8n, they must reference parameters from the **"When Executed by Another Workflow"** trigger node, NOT from a "SET USER" node (which doesn't exist in sub-agents).

## Available Parameters

The System Tools Agent receives these parameters from Main Agent:

| Parameter | Type | Source | Purpose |
|-----------|------|--------|---------|
| `userID` | string | User identifier | For workflow_user_id in API calls |
| `tenantID` | string | Organization identifier | For tenant-specific operations |
| `locale` | string | Language preference | For localized responses (de-DE, en-US) |
| `userPrompt` | string | Original user message | For context |
| `taskDescription` | string | AI-generated task | For agent execution |
| `downloadUrl` | string | File download URL | For file operations |
| `filetype` | string | File type/extension | For file operations |
| `filename` | string | Original filename | For file operations |
| `userName` | string | User's display name | For personalizing file operations |

## Correct Parameter Reference Format

❌ **WRONG** (references non-existent SET USER node):
```javascript
={{ $('SET USER').item.json.downloadUrl }}
={{ $('SET USER').item.json.tenantId }}  // lowercase 'd'
```

✅ **CORRECT** (references workflow trigger):
```javascript
={{ $('When Executed by Another Workflow').item.json.downloadUrl }}
={{ $('When Executed by Another Workflow').item.json.tenantID }}  // uppercase 'ID'
```

## Tool-Specific Parameter Requirements

### 1. content_learn (n8n Workflow Tool)

**Purpose**: Learn from uploaded files to add knowledge to system

**Required Parameters**:
```javascript
{
  "url": "={{ $('When Executed by Another Workflow').item.json.downloadUrl }}",
  "filetype": "={{ $('When Executed by Another Workflow').item.json.filetype }}",
  "userName": "={{ $('When Executed by Another Workflow').item.json.userName }}",
  "filename": "={{ $('When Executed by Another Workflow').item.json.filename }}",
  "tenantId": "={{ $('When Executed by Another Workflow').item.json.tenantID }}"
}
```

**Example from user's n8n instance**:
```json
{
  "workflowInputs": {
    "value": {
      "url": "={{ $('When Executed by Another Workflow').item.json.downloadUrl }}",
      "filetype": "={{ $('When Executed by Another Workflow').item.json.filetype }}",
      "userName": "={{ $('When Executed by Another Workflow').item.json.userName }}",
      "filename": "={{ $('When Executed by Another Workflow').item.json.filename }}",
      "tenantId": "={{ $('When Executed by Another Workflow').item.json.tenantID }}"
    }
  }
}
```

---

### 2. read_file_tool (n8n Workflow Tool)

**Purpose**: Read and extract content from uploaded files

**Required Parameters**:
```javascript
{
  "downloadUrl": "={{ $('When Executed by Another Workflow').item.json.downloadUrl }}"
}
```

---

### 3. generate_and_upload_pdf (n8n Workflow Tool)

**Purpose**: Generate PDF documents from content

**Required Parameters**:
```javascript
{
  "content": "={{ $fromAI('content', 'string') }}",  // AI-provided content
  "filename": "={{ $fromAI('filename', 'string') || 'document.pdf' }}",  // AI-provided or default
  "tenantID": "={{ $('When Executed by Another Workflow').item.json.tenantID }}",
  "userName": "={{ $('When Executed by Another Workflow').item.json.userName }}"
}
```

**Note**: Content is AI-generated based on user request, not passed from Main Agent

---

### 4. generate_pptx (n8n Workflow Tool)

**Purpose**: Generate PowerPoint presentations

**Required Parameters**:
```javascript
{
  "title": "={{ $fromAI('title', 'string') }}",  // AI-provided
  "slides": "={{ $fromAI('slides', 'array') }}",  // AI-provided array of slide objects
  "filename": "={{ $fromAI('filename', 'string') || 'presentation.pptx' }}",
  "tenantID": "={{ $('When Executed by Another Workflow').item.json.tenantID }}",
  "userName": "={{ $('When Executed by Another Workflow').item.json.userName }}"
}
```

---

### 5. share_file (n8n Workflow Tool)

**Purpose**: Upload and share files with users

**Required Parameters**:
```javascript
{
  "file": "={{ $fromAI('file', 'file') }}",  // AI-provided file
  "filename": "={{ $('When Executed by Another Workflow').item.json.filename }}",
  "tenantID": "={{ $('When Executed by Another Workflow').item.json.tenantID }}"
}
```

---

### 6. SearXNG_tool (n8n HTTP Request Tool)

**Purpose**: Search the web using multiple search engines

**Required Parameters**:
```javascript
{
  "query": "={{ $fromAI('query', 'string') }}",  // AI-generated search query
  "engines": "={{ $fromAI('engines', 'string') || 'google,duckduckgo' }}"  // Optional
}
```

**No user parameters needed** - purely AI-driven

---

### 7. read_page_tool (n8n Workflow Tool)

**Purpose**: Extract text content from web pages

**Required Parameters**:
```javascript
{
  "url": "={{ $fromAI('url', 'string') }}"  // AI-provided URL from user request
}
```

**No user parameters needed**

---

### 8. content_query (n8n Workflow Tool)

**Purpose**: Query company content and knowledge base

**Required Parameters**:
```javascript
{
  "query": "={{ $fromAI('query', 'string') }}",  // AI-generated query
  "tenantID": "={{ $('When Executed by Another Workflow').item.json.tenantID }}",
  "userID": "={{ $('When Executed by Another Workflow').item.json.userID }}"
}
```

---

### 9. employees_query_v2 (n8n Workflow Tool)

**Purpose**: Search for employees by various criteria

**Required Parameters**:
```javascript
{
  "name": "={{ $fromAI('name', 'string') || '' }}",
  "skills": "={{ $fromAI('skills', 'string') || '' }}",
  "languages": "={{ $fromAI('languages', 'string') || '' }}",
  "roles": "={{ $fromAI('roles', 'string') || '' }}",
  "certificates": "={{ $fromAI('certificates', 'string') || '' }}",
  "tenantID": "={{ $('When Executed by Another Workflow').item.json.tenantID }}"
}
```

**Important**: Pass empty string "" for unused filters, never null

---

### 10. employee_profile (n8n Workflow Tool)

**Purpose**: Get detailed employee profile information

**Required Parameters**:
```javascript
{
  "employee_id": "={{ $fromAI('employee_id', 'string') }}",  // From previous employee search
  "tenantID": "={{ $('When Executed by Another Workflow').item.json.tenantID }}"
}
```

---

### 11. fetch_valantic_events (n8n Workflow Tool)

**Purpose**: Find upcoming company events

**Required Parameters**:
```javascript
{
  "startDate": "={{ $fromAI('startDate', 'string') || '' }}",  // Optional: YYYY-MM-DD
  "endDate": "={{ $fromAI('endDate', 'string') || '' }}",      // Optional: YYYY-MM-DD
  "tenantID": "={{ $('When Executed by Another Workflow').item.json.tenantID }}"
}
```

---

### 12. clear_memory_tool (n8n Workflow Tool)

**Purpose**: Clear conversation memory/history

**Required Parameters**:
```javascript
{
  "confirm": "={{ $fromAI('confirm', 'boolean') }}",  // AI asks user for confirmation first
  "userID": "={{ $('When Executed by Another Workflow').item.json.userID }}",
  "sessionID": "={{ $('When Executed by Another Workflow').item.json.userID }}"  // Using userID as session
}
```

---

### 13. report_issue_tool (n8n Workflow Tool)

**Purpose**: Report issues, bugs, or problems to support team

**Required Parameters**:
```javascript
{
  "description": "={{ $fromAI('description', 'string') }}",
  "severity": "={{ $fromAI('severity', 'string') || 'medium' }}",  // low, medium, high, critical
  "userID": "={{ $('When Executed by Another Workflow').item.json.userID }}",
  "userName": "={{ $('When Executed by Another Workflow').item.json.userName }}",
  "tenantID": "={{ $('When Executed by Another Workflow').item.json.tenantID }}"
}
```

---

## Quick Reference: Parameter Usage by Tool

| Tool | userID | tenantID | downloadUrl | filetype | filename | userName | locale |
|------|--------|----------|-------------|----------|----------|----------|--------|
| content_learn | ❌ | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ |
| read_file_tool | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ |
| generate_and_upload_pdf | ❌ | ✅ | ❌ | ❌ | ❌ | ✅ | ❌ |
| generate_pptx | ❌ | ✅ | ❌ | ❌ | ❌ | ✅ | ❌ |
| share_file | ❌ | ✅ | ❌ | ❌ | ✅ | ❌ | ❌ |
| SearXNG_tool | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| read_page_tool | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| content_query | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| employees_query_v2 | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| employee_profile | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| fetch_valantic_events | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| clear_memory_tool | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| report_issue_tool | ✅ | ✅ | ❌ | ❌ | ❌ | ✅ | ❌ |

## Common Mistakes to Avoid

### Mistake 1: Wrong Node Reference
```javascript
// ❌ WRONG
={{ $('SET USER').item.json.downloadUrl }}

// ✅ CORRECT
={{ $('When Executed by Another Workflow').item.json.downloadUrl }}
```

### Mistake 2: Wrong Case for ID Parameters
```javascript
// ❌ WRONG
={{ $('When Executed by Another Workflow').item.json.tenantId }}  // lowercase 'd'
={{ $('When Executed by Another Workflow').item.json.userId }}    // lowercase 'd'

// ✅ CORRECT
={{ $('When Executed by Another Workflow').item.json.tenantID }}  // uppercase 'ID'
={{ $('When Executed by Another Workflow').item.json.userID }}    // uppercase 'ID'
```

### Mistake 3: Using Null Instead of Empty String
```javascript
// ❌ WRONG (for employees_query_v2)
"skills": null

// ✅ CORRECT
"skills": "={{ $fromAI('skills', 'string') || '' }}"
```

## Testing After Adding Tools

1. **Import system_tools_agent.json** to n8n
2. **Add each tool node** to the workflow
3. **Connect tools to AI Agent** (ai_tool connection)
4. **Update parameter references** using this guide
5. **Test with pin data**:
   ```json
   {
     "userID": "test-user-123",
     "tenantID": "0cd3a714-adc1-4540-bd08-7316a80b34f3",
     "locale": "de-DE",
     "userPrompt": "Learn from this file",
     "taskDescription": "Use content_learn to process the uploaded file",
     "downloadUrl": "https://example.com/test.pdf",
     "filetype": "pdf",
     "filename": "test.pdf",
     "userName": "Test User"
   }
   ```

## Next Steps

1. ✅ Main Agent now passes all required file parameters
2. ✅ System Tools Agent now accepts all required file parameters
3. ⚠️ **TODO**: Add the 13 tool nodes to system_tools_agent.json in n8n
4. ⚠️ **TODO**: Update all parameter references using this guide
5. ⚠️ **TODO**: Test each tool individually
6. ⚠️ **TODO**: Test end-to-end workflow from Main Agent → System Tools Agent → Tool execution

---

**Last Updated**: 2025-01-19
**Version**: 1.0.0
