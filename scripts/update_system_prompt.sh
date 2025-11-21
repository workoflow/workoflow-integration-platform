#!/bin/bash
#
# Update System Prompt Script
# Converts XML system prompts to Twig templates with proper escaping
#
# Usage: ./update_system_prompt.sh <tool_type> <path_to_xml>
# Example: ./update_system_prompt.sh jira docs/agents/system_prompts/jira_agent.xml
#

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

# Validate arguments
if [ $# -ne 2 ]; then
    echo -e "${RED}Error: Invalid number of arguments${NC}"
    echo "Usage: $0 <tool_type> <path_to_xml>"
    echo "Example: $0 jira docs/agents/system_prompts/jira_agent.xml"
    exit 1
fi

TOOL_TYPE="$1"
SOURCE_XML="$2"

# Valid tool types
VALID_TYPES=("jira" "confluence" "trello" "sharepoint" "gitlab")

# Check if tool type is valid
if [[ ! " ${VALID_TYPES[*]} " =~ " ${TOOL_TYPE} " ]]; then
    echo -e "${RED}Error: Invalid tool type '${TOOL_TYPE}'${NC}"
    echo "Valid types: ${VALID_TYPES[*]}"
    exit 1
fi

# Target Twig template path
TARGET_TWIG="${PROJECT_ROOT}/templates/skills/prompts/${TOOL_TYPE}_full.xml.twig"

# Check if source XML exists
if [ ! -f "$SOURCE_XML" ]; then
    # Try with project root prefix
    SOURCE_XML="${PROJECT_ROOT}/${SOURCE_XML}"
    if [ ! -f "$SOURCE_XML" ]; then
        echo -e "${RED}Error: Source XML file not found: $2${NC}"
        exit 1
    fi
fi

echo -e "${YELLOW}Converting system prompt...${NC}"
echo "  Tool type: ${TOOL_TYPE}"
echo "  Source: ${SOURCE_XML}"
echo "  Target: ${TARGET_TWIG}"
echo ""

# Create Python conversion script
python3 << PYEOF
import re
import sys

tool_type = "${TOOL_TYPE}"
source_file = "${SOURCE_XML}"
target_file = "${TARGET_TWIG}"

# Read source XML
with open(source_file, 'r', encoding='utf-8') as f:
    content = f.read()

# Store original for comparison
original = content

# 1. Replace hardcoded production URL with Twig variable
content = re.sub(
    r'https://subscribe-workflows\.vcec\.cloud',
    '{{ api_base_url }}',
    content
)

# 2. Replace static date in header comment with Twig expression
# Match: Last Updated: YYYY-MM-DD
content = re.sub(
    r'(Last Updated:\s*)\d{4}-\d{2}-\d{2}',
    r"\1{{ 'now'|date('Y-m-d') }}",
    content
)

# 3. Replace tool names with dynamic integration_id
# Match: tool_name_NUMBER (e.g., jira_search_1, confluence_get_page_123)
content = re.sub(
    rf'(<name>{tool_type}_\w+)_\d+</name>',
    r"\1_{{ integration_id }}</name>",
    content
)

# 4. Escape n8n template syntax {{ ... }} to Twig escaped form
# We need to convert {{ something }} to {{ '{{' }} something {{ '}}' }}
# But NOT touch already converted Twig variables like {{ api_base_url }}

# First, identify and protect Twig variables we've already added
twig_vars = ['api_base_url', 'integration_id', "'now'|date"]

def escape_n8n_syntax(match):
    inner = match.group(1)

    # Check if this is already a Twig variable we want to keep
    for var in twig_vars:
        if var in inner:
            return match.group(0)  # Keep as-is

    # This is n8n syntax - escape it
    # Remove any existing whitespace around the content
    inner = inner.strip()
    return "{{ '{{' }} " + inner + "{{ '}}' }}"

# Match {{ ... }} but be careful with nested content
content = re.sub(r'\{\{\s*([^}]+?)\s*\}\}', escape_n8n_syntax, content)

# 5. Fix any double-escaped patterns that might have occurred
content = content.replace("{{ '{{' }}  '{{' }}", "{{ '{{' }}")
content = content.replace("{{ '}}' }} {{ '}}' }}", "{{ '}}' }}")

# Write to target Twig file
with open(target_file, 'w', encoding='utf-8') as f:
    f.write(content)

print(f"✓ Converted {source_file}")
print(f"✓ Written to {target_file}")
PYEOF

if [ $? -ne 0 ]; then
    echo -e "${RED}Error: Python conversion failed${NC}"
    exit 1
fi

echo ""
echo -e "${YELLOW}Validating Twig template...${NC}"

# Lint the Twig template using Symfony's built-in linter
cd "$PROJECT_ROOT"
if docker-compose exec -T frankenphp php bin/console lint:twig "templates/skills/prompts/${TOOL_TYPE}_full.xml.twig" 2>/dev/null; then
    echo -e "${GREEN}✓ Twig template is valid${NC}"
else
    echo -e "${RED}✗ Twig template validation failed${NC}"
    echo ""
    echo "You may need to manually fix the template at:"
    echo "  ${TARGET_TWIG}"
    exit 1
fi

echo ""
echo -e "${GREEN}Done! System prompt updated successfully.${NC}"
echo ""
echo "Next steps:"
echo "  1. Review the changes: git diff ${TARGET_TWIG}"
echo "  2. Test the API endpoint:"
echo "     curl 'http://localhost:3979/api/skills/?organisation_uuid=YOUR_UUID&tool_type=${TOOL_TYPE}' \\"
echo "       --header 'Authorization: Basic YOUR_AUTH'"
echo "  3. Commit if everything looks good"
