#!/bin/bash

# Script to add previousAgentData parameter to all toolWorkflow nodes in main_agent.json

INPUT_FILE="main_agent.json"
TEMP_FILE="main_agent_temp.json"

# Define the new previousAgentData parameter value
PREV_AGENT_DATA_VALUE='"previousAgentData": "={{ $fromAI('\''previousAgentData'\'', '\''If this is a multi-agent workflow and a previous agent (JIRA, Confluence, SharePoint, GitLab, Trello, System Tools) returned data in its response, construct a previousAgentData object: {agentType: \"jira|confluence|sharepoint|gitlab|trello|system\", data: {the data object from previous agent response}, context: \"brief description of the data\"}. IMPORTANT: Extract data from the previous tool response if available. Return null if no previous agent was called or no data field exists.'\'', '\''object'\'') }}"'

# Define the new schema entry for previousAgentData
PREV_AGENT_DATA_SCHEMA='{
  "id": "previousAgentData",
  "displayName": "previousAgentData",
  "required": false,
  "defaultMatch": false,
  "display": true,
  "canBeUsedToMatch": true,
  "type": "object",
  "removed": false
}'

# Use jq to update the JSON
# This adds previousAgentData to all toolWorkflow nodes
jq --argjson schema_entry "$PREV_AGENT_DATA_SCHEMA" '
  # Update all nodes of type toolWorkflow
  .nodes |= map(
    if .type == "@n8n/n8n-nodes-langchain.toolWorkflow" then
      # Add previousAgentData to workflowInputs.value if it does not exist
      if .parameters.workflowInputs.value.previousAgentData == null then
        .parameters.workflowInputs.value.previousAgentData = "={{ $fromAI(\"previousAgentData\", \"If this is a multi-agent workflow and a previous agent (JIRA, Confluence, SharePoint, GitLab, Trello, System Tools) returned data in its response, construct a previousAgentData object: {agentType: \\\"jira|confluence|sharepoint|gitlab|trello|system\\\", data: {the data object from previous agent response}, context: \\\"brief description of the data\\\"}. IMPORTANT: Extract data from the previous tool response.data field if available. Return null if no previous agent was called or response.data does not exist.\", \"object\") }}"
      else . end |

      # Add previousAgentData to workflowInputs.schema if it does not exist
      if (.parameters.workflowInputs.schema | map(.id) | index("previousAgentData")) == null then
        .parameters.workflowInputs.schema += [$schema_entry]
      else . end
    else . end
  )
' "$INPUT_FILE" > "$TEMP_FILE"

# Check if jq succeeded
if [ $? -eq 0 ]; then
  mv "$TEMP_FILE" "$INPUT_FILE"
  echo "✅ Successfully updated main_agent.json"
  echo "Added previousAgentData parameter to all toolWorkflow nodes"
else
  echo "❌ Error updating main_agent.json"
  rm -f "$TEMP_FILE"
  exit 1
fi
