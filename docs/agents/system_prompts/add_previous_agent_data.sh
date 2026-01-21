#!/bin/bash

# Script to add previousAgentData context to remaining sub-agent XML files

# Define the multi-agent context block to add
MULTI_AGENT_CONTEXT='
    <multi-agent-context>
      <previous-agent-data>{{ $('\''When Executed by Another Workflow'\'').item.json.previousAgentData || null }}</previous-agent-data>
      <explanation>
        In multi-agent workflows, the Main Agent may pass structured data from a previous agent
        (e.g., JIRA tickets, Confluence pages, SharePoint documents, System Tools output). This data
        is available in the previousAgentData field as a JSON object containing:
        - agentType: Which agent provided this data (jira, confluence, sharepoint, gitlab, trello, system)
        - data: The actual structured data
        - context: Description of what this data represents

        Use this data when it'\''s relevant to your task (e.g., creating pages from JIRA data,
        generating documents from Confluence content, etc.).
      </explanation>
    </multi-agent-context>'

# List of agent files to update (excluding main_agent, jira_agent, and system_tools_agent - already done)
AGENTS=(
  "confluence_agent.xml"
  "sharepoint_agent.xml"
  "gitlab_agent.xml"
  "trello_agent.xml"
)

for agent_file in "${AGENTS[@]}"; do
  if [ -f "$agent_file" ]; then
    echo "Processing $agent_file..."

    # Check if previousAgentData already exists
    if grep -q "previous-agent-data" "$agent_file"; then
      echo "  ⚠️  $agent_file already has previousAgentData - skipping"
      continue
    fi

    # Find the line with </context> and insert before it
    # Using sed to insert the multi-agent context block
    sed -i '/<\/context>/i\
\
    <multi-agent-context>\
      <previous-agent-data>{{ $('"'"'When Executed by Another Workflow'"'"').item.json.previousAgentData || null }}</previous-agent-data>\
      <explanation>\
        In multi-agent workflows, the Main Agent may pass structured data from a previous agent\
        (e.g., JIRA tickets, Confluence pages, SharePoint documents, System Tools output). This data\
        is available in the previousAgentData field as a JSON object containing:\
        - agentType: Which agent provided this data (jira, confluence, sharepoint, gitlab, trello, system)\
        - data: The actual structured data\
        - context: Description of what this data represents\
\
        Use this data when it'"'"'s relevant to your task (e.g., creating pages from JIRA data,\
        generating documents from Confluence content, etc.).\
      </explanation>\
    </multi-agent-context>' "$agent_file"

    if [ $? -eq 0 ]; then
      echo "  ✅ Successfully updated $agent_file"
    else
      echo "  ❌ Error updating $agent_file"
    fi
  else
    echo "  ⚠️  $agent_file not found - skipping"
  fi
done

echo ""
echo "✅ Batch update complete!"
