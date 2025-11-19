# Multi-Agent Data Passing - Test Scenarios

**Version:** 1.1.0
**Date:** 2025-01-19
**Feature:** Explicit `previousAgentData` parameter for architecture-guaranteed data flow

---

## Overview

This document provides test scenarios to validate the new explicit data passing mechanism between agents using the `previousAgentData` parameter.

## Test Scenario 1: JIRA → PDF (Basic Data Passing)

### User Request
```
"Get my current sprint tickets and create a PDF report"
```

### Expected Flow

#### Step 1: Main Agent → JIRA Agent
**Parameters Sent:**
```json
{
  "tenantID": "abc-123",
  "userID": "user-456",
  "locale": "de",
  "taskDescription": "Get current sprint data including all tickets, story points, and status breakdown",
  "previousAgentData": null
}
```

**JIRA Agent Response:**
```json
{
  "output": "✅ Ich habe die Sprint-Daten für Sprint 42 - Auth System abgerufen. Der Sprint enthält 23 Tickets mit insgesamt 87 Story Points (75 abgeschlossen).",
  "data": {
    "sprintId": 42,
    "name": "Sprint 42 - Auth System",
    "totalTickets": 23,
    "totalPoints": 87,
    "completedPoints": 75,
    "tickets": [
      {
        "key": "PROJ-140",
        "summary": "OAuth implementation",
        "status": "Done",
        "points": 13,
        "assignee": "Sarah J."
      },
      {
        "key": "PROJ-141",
        "summary": "JWT tokens",
        "status": "In Progress",
        "points": 8,
        "assignee": "Mike C."
      }
    ]
  },
  "attachment": null
}
```

#### Step 2: Main Agent → System Tools Agent
**Parameters Sent:**
```json
{
  "tenantID": "abc-123",
  "userID": "user-456",
  "locale": "de",
  "taskDescription": "Generate a PDF report with the Sprint 42 data showing all tickets, story points, and progress",
  "previousAgentData": {
    "agentType": "jira",
    "data": {
      "sprintId": 42,
      "name": "Sprint 42 - Auth System",
      "totalTickets": 23,
      "totalPoints": 87,
      "completedPoints": 75,
      "tickets": [...]
    },
    "context": "Sprint data for PDF report generation"
  }
}
```

**System Tools Agent Response:**
```json
{
  "output": "✅ Ich habe das Sprint-PDF erstellt. Der Report für Sprint 42 - Auth System enthält:\n- 23 Tickets mit insgesamt 87 Story Points\n- Fortschritt: 75 von 87 Punkten abgeschlossen (86%)\n- Detaillierte Ticket-Übersicht mit Status und Zuweisungen",
  "data": null,
  "attachment": "https://subscribe-workflows.vcec.cloud/file/abc123/Sprint_42_Report.pdf"
}
```

#### Step 3: Main Agent Final Response
```json
{
  "output": "✅ Ich habe die Sprint-Daten aus Jira abgerufen und ein PDF-Report erstellt.\n\nSprint 42 - Auth System:\n- 23 Tickets, 87 Story Points (75 abgeschlossen)\n- Fortschritt: 86%\n\nDein Report ist bereit zum Download.",
  "attachment": "https://subscribe-workflows.vcec.cloud/file/abc123/Sprint_42_Report.pdf"
}
```

### Validation Checklist
- [ ] JIRA Agent returns structured `data` field with sprint information
- [ ] Main Agent extracts `response.data` from JIRA Agent
- [ ] Main Agent constructs `previousAgentData` object with agentType, data, context
- [ ] System Tools Agent receives `previousAgentData` parameter
- [ ] System Tools Agent accesses ticket data from `previousAgentData.data`
- [ ] PDF is generated with correct sprint information
- [ ] No data loss or truncation occurs

---

## Test Scenario 2: JIRA → Confluence (Data Transformation)

### User Request
```
"Create a Confluence page documenting the current sprint progress"
```

### Expected Flow

#### Step 1: Main Agent → JIRA Agent
Same as Test Scenario 1.

#### Step 2: Main Agent → Confluence Agent
**Parameters Sent:**
```json
{
  "tenantID": "abc-123",
  "userID": "user-456",
  "locale": "de",
  "taskDescription": "Create a Confluence page titled 'Sprint 42 - Auth System Progress' documenting all tickets and sprint metrics",
  "previousAgentData": {
    "agentType": "jira",
    "data": {
      "sprintId": 42,
      "name": "Sprint 42 - Auth System",
      "totalTickets": 23,
      "totalPoints": 87,
      "completedPoints": 75,
      "tickets": [...]
    },
    "context": "Sprint data to be documented in Confluence"
  }
}
```

**Confluence Agent Response:**
```json
{
  "output": "✅ Ich habe die Confluence-Seite 'Sprint 42 - Auth System Progress' erstellt mit allen Sprint-Daten und Ticket-Details.",
  "data": {
    "pageId": "12345",
    "url": "https://confluence.example.com/pages/12345",
    "title": "Sprint 42 - Auth System Progress"
  },
  "attachment": null
}
```

### Validation Checklist
- [ ] Confluence Agent receives structured sprint data
- [ ] Confluence page is created with correct content
- [ ] All tickets from JIRA are included
- [ ] Sprint metrics are preserved (points, progress)

---

## Test Scenario 3: SharePoint → System Tools (Document to PDF)

### User Request
```
"Find the API documentation in SharePoint and convert it to PDF"
```

### Expected Flow

#### Step 1: Main Agent → SharePoint Agent
**Parameters Sent:**
```json
{
  "tenantID": "abc-123",
  "userID": "user-456",
  "locale": "en",
  "taskDescription": "Search for API documentation in SharePoint and read the document",
  "previousAgentData": null
}
```

**SharePoint Agent Response:**
```json
{
  "output": "✅ I found and read the API documentation file.",
  "data": {
    "fileName": "API_Documentation_v2.docx",
    "fileId": "sp-file-789",
    "content": "# API Endpoints\n\n## Authentication\n..."
  },
  "attachment": null
}
```

#### Step 2: Main Agent → System Tools Agent
**Parameters Sent:**
```json
{
  "tenantID": "abc-123",
  "userID": "user-456",
  "locale": "en",
  "taskDescription": "Convert the API documentation to PDF format",
  "previousAgentData": {
    "agentType": "sharepoint",
    "data": {
      "fileName": "API_Documentation_v2.docx",
      "content": "# API Endpoints\n\n## Authentication\n..."
    },
    "context": "SharePoint document content for PDF conversion"
  }
}
```

### Validation Checklist
- [ ] SharePoint document content is passed completely
- [ ] No truncation of large documents
- [ ] PDF is generated with full content

---

## Test Scenario 4: Complex 3-Agent Workflow (JIRA → Confluence → PDF)

### User Request
```
"Get sprint data from JIRA, create a Confluence page, then generate a PDF of that page"
```

### Expected Flow

#### Step 1: Main Agent → JIRA Agent
Returns sprint data in `response.data`

#### Step 2: Main Agent → Confluence Agent
Receives JIRA data via `previousAgentData`, creates page, returns page data in `response.data`

#### Step 3: Main Agent → System Tools Agent
Receives Confluence page data via `previousAgentData`, generates PDF

### Validation Checklist
- [ ] Data flows through all 3 agents without loss
- [ ] Each agent receives correct `previousAgentData`
- [ ] Each agent returns structured `data` for next agent
- [ ] Final PDF contains original JIRA ticket information

---

## Test Scenario 5: Null previousAgentData (Single Agent)

### User Request
```
"Search for React developers in the employee database"
```

### Expected Flow

#### Step 1: Main Agent → System Tools Agent
**Parameters Sent:**
```json
{
  "tenantID": "abc-123",
  "userID": "user-456",
  "locale": "en",
  "taskDescription": "Search for employees with React skills",
  "previousAgentData": null  // No previous agent
}
```

### Validation Checklist
- [ ] System Tools Agent handles `null` previousAgentData correctly
- [ ] No errors when previousAgentData is null
- [ ] Agent executes normally for single-agent workflows

---

## Test Scenario 6: Large Dataset Handling

### User Request
```
"Get all 150 tickets from project PROJ and create a PDF"
```

### Expected Flow
Same as Scenario 1, but with 150 tickets instead of 23.

### Validation Checklist
- [ ] All 150 tickets are passed via `previousAgentData`
- [ ] No truncation or data loss
- [ ] PDF contains all tickets
- [ ] Performance is acceptable (< 30 seconds total)

---

## Test Scenario 7: Error Handling

### User Request
```
"Get sprint data from JIRA (but JIRA is not configured)"
```

### Expected Flow

#### Step 1: Main Agent attempts to call JIRA Agent
JIRA Agent returns error (no integration configured)

#### Step 2: Main Agent handles error
Main Agent does NOT call System Tools Agent, informs user about missing JIRA integration

### Validation Checklist
- [ ] Main Agent detects JIRA Agent error
- [ ] Main Agent does not pass error data to next agent
- [ ] User receives clear error message

---

## Regression Tests

### Test 1: Backward Compatibility
- [ ] Agents without `previousAgentData` still work (receives null)
- [ ] Old workflows continue functioning
- [ ] Single-agent tasks unchanged

### Test 2: Parameter Schema Validation
- [ ] `previousAgentData` is correctly typed as `object` in n8n
- [ ] Schema includes all 6 sub-agents
- [ ] Parameter is optional (not required)

### Test 3: AI Agent Behavior
- [ ] $fromAI correctly constructs `previousAgentData` from tool responses
- [ ] AI extracts `response.data` field accurately
- [ ] AI includes agentType, data, and context fields

---

## Performance Benchmarks

| Scenario | Expected Time | Token Usage | Success Criteria |
|----------|---------------|-------------|------------------|
| JIRA → PDF (23 tickets) | < 15s | < 10k tokens | All tickets in PDF |
| JIRA → Confluence → PDF | < 25s | < 15k tokens | Data flows through all agents |
| Large dataset (150 tickets) | < 30s | < 20k tokens | No truncation |

---

## Known Limitations

1. **Maximum Data Size**: Very large datasets (>1000 items) may hit n8n payload limits
2. **AI Dependency**: Still relies on AI to construct `previousAgentData` object
3. **No Direct Agent-to-Agent**: Data must flow through Main Agent (architectural decision)

---

## Troubleshooting

### Issue: previousAgentData is always null
**Cause**: Main Agent AI not extracting `response.data`
**Solution**: Check $fromAI prompt includes instructions to extract data field

### Issue: Data is incomplete in second agent
**Cause**: First agent not returning structured `data` field
**Solution**: Update first agent to include data in response

### Issue: PDF doesn't contain JIRA tickets
**Cause**: System Tools Agent not reading `previousAgentData`
**Solution**: Verify System Tools Agent XML has multi-agent-context section

---

## Success Metrics

- ✅ 100% of multi-agent workflows pass data correctly
- ✅ Zero data truncation incidents
- ✅ < 30 second latency for 3-agent workflows
- ✅ All agents support `previousAgentData` parameter
- ✅ Documentation is complete and accurate

---

**Implementation Status**: ✅ COMPLETE (All 8 agents updated)
**Documentation Status**: ✅ COMPLETE (XML, n8n, README, workflow_design)
**Testing Status**: ⏳ PENDING (Requires n8n environment validation)
