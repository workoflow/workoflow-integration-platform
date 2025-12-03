#!/bin/bash
#
# n8n Execution Debug Script
# Fetches and analyzes n8n workflow executions to debug CURRENT_USER_EXECUTE_TOOL failures
#
# Usage:
#   ./scripts/n8n_get_execution.sh <execution_id>
#   ./scripts/n8n_get_execution.sh <execution_id> --raw
#
# Environment variables (from .env):
#   N8N_HOST - n8n instance URL (e.g., https://workflows.vcec.cloud)
#   N8N_API_KEY - n8n API key for authentication
#

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color
BOLD='\033[1m'

# Script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

# Load environment variables from .env
if [ -f "$PROJECT_ROOT/.env" ]; then
    export $(grep -E '^(N8N_HOST|N8N_API_KEY)=' "$PROJECT_ROOT/.env" | xargs)
fi

# Check required env vars
if [ -z "$N8N_HOST" ]; then
    echo -e "${RED}Error: N8N_HOST not set in .env${NC}"
    echo "Add N8N_HOST=https://your-n8n-instance.com to your .env file"
    exit 1
fi

if [ -z "$N8N_API_KEY" ]; then
    echo -e "${RED}Error: N8N_API_KEY not set in .env${NC}"
    echo "Add N8N_API_KEY=your-api-key to your .env file"
    exit 1
fi

# Check for jq
if ! command -v jq &> /dev/null; then
    echo -e "${RED}Error: jq is required but not installed${NC}"
    echo "Install with: sudo apt install jq (Debian/Ubuntu) or brew install jq (macOS)"
    exit 1
fi

# Parse arguments
EXECUTION_ID=""
RAW_MODE=false

for arg in "$@"; do
    case $arg in
        --raw)
            RAW_MODE=true
            ;;
        *)
            if [ -z "$EXECUTION_ID" ]; then
                EXECUTION_ID="$arg"
            fi
            ;;
    esac
done

# Validate execution ID
if [ -z "$EXECUTION_ID" ]; then
    echo -e "${YELLOW}Usage:${NC} $0 <execution_id> [--raw]"
    echo ""
    echo "Examples:"
    echo "  $0 60832"
    echo "  $0 60832 --raw"
    echo ""
    echo "Options:"
    echo "  --raw    Output full JSON instead of analyzed summary"
    exit 1
fi

# Remove any leading/trailing whitespace
EXECUTION_ID=$(echo "$EXECUTION_ID" | xargs)

# Build API URL
API_URL="${N8N_HOST}/api/v1/executions/${EXECUTION_ID}?includeData=true"

# Fetch execution data
RESPONSE=$(curl -s -w "\n%{http_code}" -X GET "$API_URL" \
    -H "X-N8N-API-KEY: $N8N_API_KEY" \
    -H "Accept: application/json")

# Extract HTTP status code (last line)
HTTP_CODE=$(echo "$RESPONSE" | tail -n1)
# Extract JSON body (all but last line)
JSON_BODY=$(echo "$RESPONSE" | sed '$d')

# Check for HTTP errors
if [ "$HTTP_CODE" != "200" ]; then
    echo -e "${RED}Error: API request failed with status $HTTP_CODE${NC}"
    echo "$JSON_BODY" | jq . 2>/dev/null || echo "$JSON_BODY"
    exit 1
fi

# Raw mode - output the JSON (filter out skills and system_prompt to reduce noise)
if [ "$RAW_MODE" = true ]; then
    echo "$JSON_BODY" | jq 'del(.. | .skills?, .system_prompt?)'
    exit 0
fi

# Smart analysis mode
print_separator() {
    echo -e "${CYAN}═══════════════════════════════════════════════════════════════${NC}"
}

print_section() {
    echo -e "${CYAN}─── $1 ───────────────────────────────────────────${NC}"
}

# Extract basic info
WORKFLOW_ID=$(echo "$JSON_BODY" | jq -r '.workflowId // "unknown"')
WORKFLOW_NAME=$(echo "$JSON_BODY" | jq -r '.workflowData.name // "Unknown Workflow"')
STATUS=$(echo "$JSON_BODY" | jq -r '.status // "unknown"')
STARTED_AT=$(echo "$JSON_BODY" | jq -r '.startedAt // "N/A"')
FINISHED_AT=$(echo "$JSON_BODY" | jq -r '.stoppedAt // .finishedAt // "N/A"')
MODE=$(echo "$JSON_BODY" | jq -r '.mode // "unknown"')

# Calculate duration if both timestamps exist
DURATION="N/A"
if [ "$STARTED_AT" != "N/A" ] && [ "$FINISHED_AT" != "N/A" ] && [ "$FINISHED_AT" != "null" ]; then
    START_EPOCH=$(date -d "$STARTED_AT" +%s 2>/dev/null || date -j -f "%Y-%m-%dT%H:%M:%S" "${STARTED_AT%%.*}" +%s 2>/dev/null || echo "0")
    END_EPOCH=$(date -d "$FINISHED_AT" +%s 2>/dev/null || date -j -f "%Y-%m-%dT%H:%M:%S" "${FINISHED_AT%%.*}" +%s 2>/dev/null || echo "0")
    if [ "$START_EPOCH" != "0" ] && [ "$END_EPOCH" != "0" ]; then
        DURATION_SECS=$((END_EPOCH - START_EPOCH))
        DURATION="${DURATION_SECS}s"
    fi
fi

# Status emoji and color
case $STATUS in
    "success")
        STATUS_DISPLAY="${GREEN}SUCCESS${NC}"
        STATUS_EMOJI="✓"
        ;;
    "error")
        STATUS_DISPLAY="${RED}ERROR${NC}"
        STATUS_EMOJI="✗"
        ;;
    "waiting")
        STATUS_DISPLAY="${YELLOW}WAITING${NC}"
        STATUS_EMOJI="⏳"
        ;;
    "running")
        STATUS_DISPLAY="${BLUE}RUNNING${NC}"
        STATUS_EMOJI="▶"
        ;;
    *)
        STATUS_DISPLAY="${YELLOW}$STATUS${NC}"
        STATUS_EMOJI="?"
        ;;
esac

# Print header
print_separator
echo -e "${BOLD}n8n Execution #${EXECUTION_ID}${NC}"
print_separator
echo -e "Workflow:    ${BOLD}$WORKFLOW_NAME${NC} ($WORKFLOW_ID)"
echo -e "Status:      $STATUS_EMOJI $STATUS_DISPLAY"
echo -e "Mode:        $MODE"
echo -e "Started:     $STARTED_AT"
echo -e "Finished:    $FINISHED_AT"
echo -e "Duration:    $DURATION"
echo ""

# Error Summary (if status is error)
if [ "$STATUS" = "error" ]; then
    print_section "ERROR SUMMARY"

    # Try to extract error from executionData
    ERROR_MESSAGE=$(echo "$JSON_BODY" | jq -r '
        .data.resultData.error.message //
        .data.executionData.resultData.error.message //
        "No error message found"
    ')

    ERROR_NODE=$(echo "$JSON_BODY" | jq -r '
        .data.resultData.error.node.name //
        .data.executionData.resultData.error.node.name //
        "Unknown node"
    ')

    echo -e "Node:        ${RED}$ERROR_NODE${NC}"
    echo -e "Message:     ${RED}$ERROR_MESSAGE${NC}"
    echo ""
fi

# Find CURRENT_USER_EXECUTE_TOOL node data
print_section "CURRENT_USER_EXECUTE_TOOL ANALYSIS"

# Extract node execution data - check multiple possible paths in the JSON structure
# n8n can have multiple executions of the same node, so we get all of them
TOOL_NODE_DATA=$(echo "$JSON_BODY" | jq '
    # Try different paths where node data might be stored
    (.data.resultData.runData["CURRENT_USER_EXECUTE_TOOL"] //
     .data.executionData.resultData.runData["CURRENT_USER_EXECUTE_TOOL"] //
     .data.resultData.runData["CURRENT_USER_EXECUTE_TOOL1"] //
     .data.executionData.resultData.runData["CURRENT_USER_EXECUTE_TOOL1"] //
     null)
')

if [ "$TOOL_NODE_DATA" = "null" ] || [ -z "$TOOL_NODE_DATA" ]; then
    # Try to find any node that contains "EXECUTE_TOOL" in its name
    TOOL_NODE_NAME=$(echo "$JSON_BODY" | jq -r '
        [.data.resultData.runData // .data.executionData.resultData.runData // {} | keys[] | select(contains("EXECUTE_TOOL"))] | first // empty
    ')

    if [ -n "$TOOL_NODE_NAME" ]; then
        TOOL_NODE_DATA=$(echo "$JSON_BODY" | jq --arg name "$TOOL_NODE_NAME" '
            (.data.resultData.runData[$name] // .data.executionData.resultData.runData[$name])
        ')
        echo -e "${YELLOW}Found node: $TOOL_NODE_NAME${NC}"
    else
        echo -e "${YELLOW}CURRENT_USER_EXECUTE_TOOL node not found in execution data${NC}"
        echo -e "This might be a workflow that doesn't use the tool execution pattern,"
        echo -e "or the execution didn't reach this node."
        echo ""

        # Show available nodes
        AVAILABLE_NODES=$(echo "$JSON_BODY" | jq -r '
            [.data.resultData.runData // .data.executionData.resultData.runData // {} | keys[]] | join(", ")
        ')
        if [ -n "$AVAILABLE_NODES" ]; then
            echo -e "Available nodes: ${CYAN}$AVAILABLE_NODES${NC}"
        fi
        print_separator
        exit 0
    fi
fi

# Count how many tool executions happened
TOOL_EXEC_COUNT=$(echo "$TOOL_NODE_DATA" | jq 'length')
echo -e "Tool Executions: ${BOLD}$TOOL_EXEC_COUNT${NC}"
echo ""

# Process each tool execution
for i in $(seq 0 $((TOOL_EXEC_COUNT - 1))); do
    EXEC_DATA=$(echo "$TOOL_NODE_DATA" | jq ".[$i]")

    if [ "$TOOL_EXEC_COUNT" -gt 1 ]; then
        echo -e "${CYAN}── Execution $((i + 1))/$TOOL_EXEC_COUNT ──${NC}"
    fi

    # Node execution status
    NODE_STATUS=$(echo "$EXEC_DATA" | jq -r '.executionStatus // "unknown"')
    EXEC_TIME=$(echo "$EXEC_DATA" | jq -r '.executionTime // "N/A"')

    case $NODE_STATUS in
        "success")
            echo -e "Status: ${GREEN}$NODE_STATUS${NC} (${EXEC_TIME}ms)"
            ;;
        "error")
            echo -e "Status: ${RED}$NODE_STATUS${NC} (${EXEC_TIME}ms)"
            ;;
        *)
            echo -e "Status: ${YELLOW}$NODE_STATUS${NC} (${EXEC_TIME}ms)"
            ;;
    esac

    # Check if there's an error in the node
    NODE_ERROR=$(echo "$EXEC_DATA" | jq -r '.error.message // empty')
    if [ -n "$NODE_ERROR" ]; then
        echo -e "${RED}Error: $NODE_ERROR${NC}"
        ERROR_DETAILS=$(echo "$EXEC_DATA" | jq '.error' 2>/dev/null)
        if [ "$ERROR_DETAILS" != "null" ] && [ -n "$ERROR_DETAILS" ]; then
            echo "$ERROR_DETAILS" | jq --color-output '.'
        fi
        echo ""
        continue
    fi

    # Extract REQUEST from inputOverride.ai_tool (what the AI agent requested)
    # Filter out "skills" property as it contains huge system prompts
    echo ""
    echo -e "${BOLD}REQUEST (Tool Call from AI):${NC}"
    TOOL_REQUEST=$(echo "$EXEC_DATA" | jq '
        .inputOverride.ai_tool[0][0].json // {} |
        del(.skills) |
        {
            toolId: .toolId,
            parameters: .parameters,
            toolCallId: .toolCallId
        } | del(.[] | nulls)
    ')

    if [ "$TOOL_REQUEST" != "{}" ]; then
        echo "$TOOL_REQUEST" | jq --color-output '.'
    else
        # Fallback: try data.main path
        TOOL_REQUEST=$(echo "$EXEC_DATA" | jq '.data.main[0][0].json // {}')
        echo "$TOOL_REQUEST" | jq --color-output '.'
    fi

    # Extract RESPONSE from data.ai_tool (what the API returned)
    echo ""
    echo -e "${BOLD}RESPONSE (API Result):${NC}"
    TOOL_RESPONSE=$(echo "$EXEC_DATA" | jq '
        .data.ai_tool[0][0].json // {} |
        if .result then
            # Create a concise summary
            {
                success: .success,
                result_type: (
                    if .result.accounts then "accounts"
                    elif .result.opportunities then "opportunities"
                    elif .result.contacts then "contacts"
                    elif .result.leads then "leads"
                    elif .result.opportunity then "opportunity"
                    elif .result.account then "account"
                    elif .result.contact then "contact"
                    elif .result.lead then "lead"
                    else (.result | type)
                    end
                ),
                count: (
                    if .result.accounts then (.result.accounts | length)
                    elif .result.opportunities then (.result.opportunities | length)
                    elif .result.contacts then (.result.contacts | length)
                    elif .result.leads then (.result.leads | length)
                    elif .result.opportunity then 1
                    elif .result.account then 1
                    elif .result.contact then 1
                    elif .result.lead then 1
                    else null
                    end
                ),
                # Show key identifiers only
                preview: (
                    if .result.accounts then
                        [.result.accounts[] | {Name, ObjectID, AccountID}]
                    elif .result.opportunities then
                        [.result.opportunities[] | {Name: .Name, ObjectID, Status: .LifeCycleStatusCodeText}]
                    elif .result.contacts then
                        [.result.contacts[] | {Name: .FormattedName, ObjectID, ContactID}]
                    elif .result.leads then
                        [.result.leads[] | {Name, ObjectID, Status: .LifeCycleStatusCodeText}]
                    elif .result.opportunity then
                        {Name: .result.opportunity.Name, ObjectID: .result.opportunity.ObjectID}
                    elif .result.account then
                        {Name: .result.account.Name, ObjectID: .result.account.ObjectID}
                    elif .result.contact then
                        {Name: .result.contact.FormattedName, ObjectID: .result.contact.ObjectID}
                    elif .result.lead then
                        {Name: .result.lead.Name, ObjectID: .result.lead.ObjectID}
                    else
                        (.result | keys)
                    end
                )
            }
        elif .error then
            {
                success: false,
                error: .error
            }
        else
            .
        end
    ')

    if [ "$TOOL_RESPONSE" != "{}" ]; then
        echo "$TOOL_RESPONSE" | jq --color-output '.'
    else
        echo -e "${YELLOW}No response data found${NC}"
    fi

    echo ""
done

echo ""
print_separator
echo -e "${CYAN}Tip: Use --raw flag to see the complete execution JSON${NC}"
