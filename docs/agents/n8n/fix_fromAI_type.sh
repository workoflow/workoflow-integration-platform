#!/bin/bash

# Fix: Change $fromAI type from 'object' to 'string' for previousAgentData

INPUT_FILE="main_agent.json"
BACKUP_FILE="main_agent_before_type_fix.json"

# Backup
cp "$INPUT_FILE" "$BACKUP_FILE"
echo "📦 Created backup: $BACKUP_FILE"

# Fix the type parameter in $fromAI from "object" to "string"
# This uses sed to find and replace the problematic pattern
sed -i 's/, \\"object\\") }}"$/, \\"string\\") }}"/g' "$INPUT_FILE"

# Also fix it without the ending quote variation
sed -i "s/, 'object') }}/, 'string') }}/g" "$INPUT_FILE"

if [ $? -eq 0 ]; then
  echo "✅ Successfully fixed $INPUT_FILE"
  echo ""
  echo "Changed: \$fromAI('previousAgentData', '...', 'object')"
  echo "To:      \$fromAI('previousAgentData', '...', 'string')"
  echo ""
  echo "Note: The AI will return a JSON string, which n8n parses to an object."
else
  echo "❌ Error updating $INPUT_FILE"
  exit 1
fi
