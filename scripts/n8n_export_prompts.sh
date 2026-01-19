#!/bin/bash
#
# n8n Prompt Export Script
# Exports incoming user prompts and outgoing answers from a webhook workflow
#
# Usage:
#   ./scripts/n8n_export_prompts.sh                              # Export all executions
#   ./scripts/n8n_export_prompts.sh --limit 100                 # Limit to 100 executions
#   ./scripts/n8n_export_prompts.sh --output file.csv           # Output to specific file
#   ./scripts/n8n_export_prompts.sh --workflow ID               # Use different workflow ID
#   ./scripts/n8n_export_prompts.sh --date-from 2026-01-01      # From date (inclusive)
#   ./scripts/n8n_export_prompts.sh --date-to 2026-01-31        # To date (inclusive)
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
CYAN='\033[0;36m'
NC='\033[0m'
BOLD='\033[1m'

# Script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

# Load environment variables from .env (only N8N_HOST and N8N_API_KEY)
if [ -f "$PROJECT_ROOT/.env" ]; then
    N8N_HOST=$(grep -E '^N8N_HOST=' "$PROJECT_ROOT/.env" | cut -d= -f2)
    N8N_API_KEY=$(grep -E '^N8N_API_KEY=' "$PROJECT_ROOT/.env" | cut -d= -f2)
fi

# Check required env vars
if [ -z "$N8N_HOST" ]; then
    echo -e "${RED}Error: N8N_HOST not set in .env${NC}" >&2
    exit 1
fi

if [ -z "$N8N_API_KEY" ]; then
    echo -e "${RED}Error: N8N_API_KEY not set in .env${NC}" >&2
    exit 1
fi

# Check for jq
if ! command -v jq &> /dev/null; then
    echo -e "${RED}Error: jq is required but not installed${NC}" >&2
    exit 1
fi

# Check for parallel (optional but recommended)
HAS_PARALLEL=false
if command -v parallel &> /dev/null; then
    HAS_PARALLEL=true
fi

# Default values
WORKFLOW_ID="I58b9jub7zjWLVr7"
LIMIT=0  # 0 = fetch all
OUTPUT_FILE=""
STATUS_FILTER="success"
DATE_FROM=""
DATE_TO=""
PARALLEL_JOBS=10

# Parse arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --workflow)
            WORKFLOW_ID="$2"
            shift 2
            ;;
        --limit)
            LIMIT="$2"
            shift 2
            ;;
        --output|-o)
            OUTPUT_FILE="$2"
            shift 2
            ;;
        --all-status)
            STATUS_FILTER=""
            shift
            ;;
        --date-from)
            DATE_FROM="$2"
            shift 2
            ;;
        --date-to)
            DATE_TO="$2"
            shift 2
            ;;
        --jobs|-j)
            PARALLEL_JOBS="$2"
            shift 2
            ;;
        --help|-h)
            echo "Usage: $0 [options]"
            echo ""
            echo "Options:"
            echo "  --workflow ID      Workflow ID (default: I58b9jub7zjWLVr7)"
            echo "  --limit N          Maximum executions to export (default: 0 = all)"
            echo "  --output FILE      Output CSV file (default: prompt_export_YYYYMMDD_HHMMSS.csv)"
            echo "  --all-status       Include failed executions (default: success only)"
            echo "  --date-from DATE   Start date (inclusive), format: YYYY-MM-DD"
            echo "  --date-to DATE     End date (inclusive), format: YYYY-MM-DD"
            echo "  --jobs N           Parallel fetch jobs (default: 10)"
            echo "  --help             Show this help"
            echo ""
            echo "Examples:"
            echo "  $0 --date-from 2025-12-01 --date-to 2025-12-31    # December 2025"
            echo "  $0 --date-from 2026-01-01 --limit 100             # First 100 from Jan 1st"
            exit 0
            ;;
        *)
            echo -e "${RED}Unknown option: $1${NC}" >&2
            exit 1
            ;;
    esac
done

# Set default output file if not specified
if [ -z "$OUTPUT_FILE" ]; then
    OUTPUT_FILE="prompt_export_$(date +%Y%m%d_%H%M%S).csv"
fi

# Convert dates to epoch for comparison
DATE_FROM_EPOCH=""
DATE_TO_EPOCH=""
if [ -n "$DATE_FROM" ]; then
    DATE_FROM_EPOCH=$(date -d "$DATE_FROM" +%s 2>/dev/null || date -j -f "%Y-%m-%d" "$DATE_FROM" +%s 2>/dev/null)
    if [ -z "$DATE_FROM_EPOCH" ]; then
        echo -e "${RED}Error: Invalid date format for --date-from. Use YYYY-MM-DD${NC}" >&2
        exit 1
    fi
fi
if [ -n "$DATE_TO" ]; then
    # Add 23:59:59 to include the entire end date
    DATE_TO_EPOCH=$(date -d "$DATE_TO 23:59:59" +%s 2>/dev/null || date -j -f "%Y-%m-%d %H:%M:%S" "$DATE_TO 23:59:59" +%s 2>/dev/null)
    if [ -z "$DATE_TO_EPOCH" ]; then
        echo -e "${RED}Error: Invalid date format for --date-to. Use YYYY-MM-DD${NC}" >&2
        exit 1
    fi
fi

echo -e "${CYAN}Fetching executions for workflow: ${BOLD}$WORKFLOW_ID${NC}" >&2
echo -e "${CYAN}Status filter: ${STATUS_FILTER:-all}${NC}" >&2
if [ -n "$DATE_FROM" ] || [ -n "$DATE_TO" ]; then
    echo -e "${CYAN}Date range: ${DATE_FROM:-*} to ${DATE_TO:-*}${NC}" >&2
fi

# Fetch ALL execution IDs with pagination
echo -e "${YELLOW}Fetching execution list (with pagination)...${NC}" >&2

ALL_EXECUTIONS="[]"
NEXT_CURSOR=""
PAGE=1
BATCH_SIZE=250

while true; do
    # Build API URL
    LIST_URL="${N8N_HOST}/api/v1/executions?workflowId=${WORKFLOW_ID}&limit=${BATCH_SIZE}"
    if [ -n "$STATUS_FILTER" ]; then
        LIST_URL="${LIST_URL}&status=${STATUS_FILTER}"
    fi
    if [ -n "$NEXT_CURSOR" ]; then
        LIST_URL="${LIST_URL}&cursor=${NEXT_CURSOR}"
    fi

    # Fetch page
    LIST_RESPONSE=$(curl -s -X GET "$LIST_URL" \
        -H "X-N8N-API-KEY: $N8N_API_KEY" \
        -H "Accept: application/json")

    # Check for errors
    if echo "$LIST_RESPONSE" | jq -e '.message' > /dev/null 2>&1; then
        ERROR_MSG=$(echo "$LIST_RESPONSE" | jq -r '.message')
        echo -e "${RED}API Error: $ERROR_MSG${NC}" >&2
        exit 1
    fi

    # Extract executions from this page
    PAGE_DATA=$(echo "$LIST_RESPONSE" | jq '.data')
    PAGE_COUNT=$(echo "$PAGE_DATA" | jq 'length')

    # Merge with all executions
    ALL_EXECUTIONS=$(echo "$ALL_EXECUTIONS $PAGE_DATA" | jq -s 'add')

    TOTAL_SO_FAR=$(echo "$ALL_EXECUTIONS" | jq 'length')
    echo -ne "\r${CYAN}Page $PAGE: fetched $TOTAL_SO_FAR executions...${NC}" >&2

    # Check for next page
    NEXT_CURSOR=$(echo "$LIST_RESPONSE" | jq -r '.nextCursor // empty')
    if [ -z "$NEXT_CURSOR" ] || [ "$PAGE_COUNT" -eq 0 ]; then
        break
    fi

    ((PAGE++))
done

echo "" >&2

# Filter executions by date range in memory (much faster than individual API calls)
if [ -n "$DATE_FROM_EPOCH" ] || [ -n "$DATE_TO_EPOCH" ]; then
    echo -e "${YELLOW}Filtering by date range...${NC}" >&2

    FILTER_JQ='.'
    if [ -n "$DATE_FROM_EPOCH" ]; then
        FILTER_JQ="$FILTER_JQ | map(select((.startedAt | split(\".\")[0] + \"Z\" | fromdateiso8601) >= $DATE_FROM_EPOCH))"
    fi
    if [ -n "$DATE_TO_EPOCH" ]; then
        FILTER_JQ="$FILTER_JQ | map(select((.startedAt | split(\".\")[0] + \"Z\" | fromdateiso8601) <= $DATE_TO_EPOCH))"
    fi

    FILTERED_EXECUTIONS=$(echo "$ALL_EXECUTIONS" | jq --argjson DATE_FROM_EPOCH "${DATE_FROM_EPOCH:-0}" --argjson DATE_TO_EPOCH "${DATE_TO_EPOCH:-9999999999}" "$FILTER_JQ")

    TOTAL_BEFORE=$(echo "$ALL_EXECUTIONS" | jq 'length')
    TOTAL_AFTER=$(echo "$FILTERED_EXECUTIONS" | jq 'length')
    echo -e "${GREEN}Filtered: $TOTAL_BEFORE -> $TOTAL_AFTER executions in date range${NC}" >&2

    ALL_EXECUTIONS="$FILTERED_EXECUTIONS"
fi

# Apply limit if specified
if [ "$LIMIT" -gt 0 ]; then
    ALL_EXECUTIONS=$(echo "$ALL_EXECUTIONS" | jq ".[:$LIMIT]")
fi

# Get final execution IDs
EXECUTION_IDS=$(echo "$ALL_EXECUTIONS" | jq -r '.[].id')
EXEC_COUNT=$(echo "$ALL_EXECUTIONS" | jq 'length')

if [ "$EXEC_COUNT" -eq 0 ] || [ -z "$EXECUTION_IDS" ]; then
    echo -e "${YELLOW}No executions found matching criteria${NC}" >&2
    echo "executionId,timestamp,userName,userEmail,userPrompt,output" > "$OUTPUT_FILE"
    exit 0
fi

echo -e "${GREEN}Processing $EXEC_COUNT executions...${NC}" >&2

# CSV Header
echo "executionId,timestamp,userName,userEmail,userPrompt,output" > "$OUTPUT_FILE"

# Create temp directory for parallel processing
TEMP_DIR=$(mktemp -d)
trap "rm -rf $TEMP_DIR" EXIT

# Function to process a single execution
process_execution() {
    local EXEC_ID="$1"
    local N8N_HOST="$2"
    local N8N_API_KEY="$3"
    local TEMP_DIR="$4"

    # Fetch full execution data
    EXEC_DATA=$(curl -s -X GET "${N8N_HOST}/api/v1/executions/${EXEC_ID}?includeData=true" \
        -H "X-N8N-API-KEY: $N8N_API_KEY" \
        -H "Accept: application/json")

    # Extract timestamp
    TIMESTAMP=$(echo "$EXEC_DATA" | jq -r '.startedAt // "N/A"')

    # Extract user prompt from Webhook1 node (body.text)
    USER_PROMPT=$(echo "$EXEC_DATA" | jq -r '
        .data.resultData.runData.Webhook1[0].data.main[0][0].json.body.text //
        .data.resultData.runData["Webhook1"][0].data.main[0][0].json.body.text //
        .data.resultData.runData.Webhook[0].data.main[0][0].json.body.text //
        ""
    ' 2>/dev/null)

    # Skip if no prompt found
    if [ -z "$USER_PROMPT" ] || [ "$USER_PROMPT" = "null" ]; then
        return
    fi

    # Extract user info
    USER_NAME=$(echo "$EXEC_DATA" | jq -r '
        .data.resultData.runData.Webhook1[0].data.main[0][0].json.body.custom.user.name //
        .data.resultData.runData["Webhook1"][0].data.main[0][0].json.body.custom.user.name //
        .data.resultData.runData.Webhook[0].data.main[0][0].json.body.custom.user.name //
        "N/A"
    ' 2>/dev/null)

    USER_EMAIL=$(echo "$EXEC_DATA" | jq -r '
        .data.resultData.runData.Webhook1[0].data.main[0][0].json.body.custom.user.email //
        .data.resultData.runData["Webhook1"][0].data.main[0][0].json.body.custom.user.email //
        .data.resultData.runData.Webhook[0].data.main[0][0].json.body.custom.user.email //
        "N/A"
    ' 2>/dev/null)

    # Extract output from "Respond to Webhook" node
    RAW_OUTPUT=$(echo "$EXEC_DATA" | jq -r '
        .data.resultData.runData["Respond to Webhook"][0].data.main[0][0].json.output //
        ""
    ' 2>/dev/null)

    # Parse the nested JSON to get the actual output text
    if [ -n "$RAW_OUTPUT" ] && [ "$RAW_OUTPUT" != "null" ]; then
        OUTPUT=$(echo "$RAW_OUTPUT" | jq -r '.output // .' 2>/dev/null || echo "$RAW_OUTPUT")
    else
        OUTPUT="N/A"
    fi

    # Clean up values (remove newlines, trim whitespace)
    USER_PROMPT_CLEAN=$(echo "$USER_PROMPT" | tr '\n\r' '  ' | sed 's/  */ /g; s/^ *//; s/ *$//')
    OUTPUT_CLEAN=$(echo "$OUTPUT" | tr '\n\r' '  ' | sed 's/  */ /g; s/^ *//; s/ *$//')
    USER_NAME_CLEAN=$(echo "$USER_NAME" | tr '\n\r' '  ' | sed 's/^ *//; s/ *$//')
    USER_EMAIL_CLEAN=$(echo "$USER_EMAIL" | tr '\n\r' '  ' | sed 's/^ *//; s/ *$//')

    # Escape double quotes for CSV
    USER_PROMPT_CLEAN="${USER_PROMPT_CLEAN//\"/\"\"}"
    OUTPUT_CLEAN="${OUTPUT_CLEAN//\"/\"\"}"

    # Write to temp file (use execution ID for ordering)
    echo "${EXEC_ID},${TIMESTAMP},\"${USER_NAME_CLEAN}\",\"${USER_EMAIL_CLEAN}\",\"${USER_PROMPT_CLEAN}\",\"${OUTPUT_CLEAN}\"" > "${TEMP_DIR}/${EXEC_ID}.csv"
}

export -f process_execution

# Process executions (parallel if available, otherwise sequential)
if [ "$HAS_PARALLEL" = true ]; then
    echo -e "${CYAN}Processing with GNU parallel ($PARALLEL_JOBS jobs)...${NC}" >&2
    echo "$EXECUTION_IDS" | parallel -j "$PARALLEL_JOBS" process_execution {} "$N8N_HOST" "$N8N_API_KEY" "$TEMP_DIR" 2>/dev/null
    echo -e "${GREEN}Parallel processing complete${NC}" >&2
else
    echo -e "${YELLOW}GNU parallel not found, processing sequentially (install 'parallel' for faster exports)${NC}" >&2
    PROCESSED=0
    for EXEC_ID in $EXECUTION_IDS; do
        process_execution "$EXEC_ID" "$N8N_HOST" "$N8N_API_KEY" "$TEMP_DIR"
        ((PROCESSED++)) || true
        echo -ne "\r${CYAN}Processing: $PROCESSED/$EXEC_COUNT${NC}" >&2
    done
    echo "" >&2
fi

# Combine all temp files into output (sorted by execution ID descending = most recent first)
RESULT_COUNT=$(ls -1 "$TEMP_DIR"/*.csv 2>/dev/null | wc -l || echo 0)
if [ "$RESULT_COUNT" -gt 0 ]; then
    ls -1 "$TEMP_DIR"/*.csv | sort -t/ -k3 -rn | xargs cat >> "$OUTPUT_FILE"
fi

echo -e "${GREEN}Export complete!${NC}" >&2
echo -e "Exported: ${BOLD}$RESULT_COUNT${NC} executions" >&2
echo -e "Output:   ${BOLD}$OUTPUT_FILE${NC}" >&2
