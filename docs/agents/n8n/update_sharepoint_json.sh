#!/bin/bash

# Read the XML file
XML_CONTENT=$(cat ../system_prompts/sharepoint_agent.xml)

# Escape for JSON: backslash, quotes, newlines
# First escape backslashes, then quotes, then convert newlines to \n
JSON_ESCAPED=$(echo "$XML_CONTENT" | sed 's/\\/\\\\/g' | sed 's/"/\\"/g' | awk '{printf "%s\\n", $0}' | sed '$ s/\\n$//')

# Create a temporary Python script to update the JSON
python3 << PYTHON_EOF
import json

# Read the current JSON file
with open('sharepoint_agent.json', 'r') as f:
    data = json.load(f)

# The XML is in the systemMessage option of the AI Agent node
for node in data.get('nodes', []):
    if node.get('name') == 'AI Agent1' and node.get('type') == '@n8n/n8n-nodes-langchain.agent':
        if 'parameters' in node and 'options' in node['parameters']:
            # Update the systemMessage with the new XML
            node['parameters']['options']['systemMessage'] = '''$JSON_ESCAPED'''
            break

# Write back to file with proper formatting
with open('sharepoint_agent.json', 'w') as f:
    json.dump(data, f, indent=2, ensure_ascii=False)

print("✓ Updated sharepoint_agent.json with new XML")
PYTHON_EOF
