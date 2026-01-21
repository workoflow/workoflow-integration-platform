#!/bin/bash
#
# Projektron Worklog Debug Helper
# Debug and test Projektron worklog booking to identify issues
#
# Usage:
#   ./scripts/projektron_add_worklog_helper.sh --test-session
#   ./scripts/projektron_add_worklog_helper.sh --get-tasks
#   ./scripts/projektron_add_worklog_helper.sh --get-worklog <day> <month> <year>
#   ./scripts/projektron_add_worklog_helper.sh --add-worklog --task-oid <oid> --day <d> --month <m> --year <y> --hours <h> --minutes <m> --description <desc>
#
# Environment variables:
#   PROJEKTRON_DOMAIN - Projektron instance URL
#   PROJEKTRON_USERNAME - Username for form submission
#   PROJEKTRON_JSESSIONID - Session cookie
#   PROJEKTRON_CSRF_TOKEN - CSRF token
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
    while IFS='=' read -r key value; do
        if [[ $key =~ ^PROJEKTRON_ ]]; then
            export "$key=$value"
        fi
    done < <(grep -E '^PROJEKTRON_' "$PROJECT_ROOT/.env" 2>/dev/null || true)
fi

# Defaults (can be overridden by args)
DOMAIN="${PROJEKTRON_DOMAIN:-}"
USERNAME="${PROJEKTRON_USERNAME:-}"
JSESSIONID="${PROJEKTRON_JSESSIONID:-}"
CSRF_TOKEN="${PROJEKTRON_CSRF_TOKEN:-}"

# Action flags
ACTION=""
TASK_OID=""
DAY=""
MONTH=""
YEAR=""
HOURS=""
MINUTES=""
DESCRIPTION=""
DRY_RUN=false
VERBOSE=false

print_separator() {
    echo -e "${CYAN}═══════════════════════════════════════════════════════════════${NC}"
}

print_section() {
    echo -e "${CYAN}─── $1 ───────────────────────────────────────────${NC}"
}

print_error() {
    echo -e "${RED}Error: $1${NC}" >&2
}

print_success() {
    echo -e "${GREEN}$1${NC}"
}

print_warning() {
    echo -e "${YELLOW}$1${NC}"
}

print_info() {
    echo -e "${BLUE}$1${NC}"
}

usage() {
    cat << EOF
${BOLD}Projektron Worklog Debug Helper${NC}

${YELLOW}Usage:${NC}
  $0 <action> [options]

${YELLOW}Actions:${NC}
  --test-session      Test session validity and extract CSRF token
  --get-tasks         Get all bookable tasks
  --get-worklog       Get worklog for a specific date
  --add-worklog       Add a worklog entry (with full debug output)

${YELLOW}Options:${NC}
  --domain <url>      Projektron domain (e.g., https://projektron.valantic.com)
  --username <user>   Username for form submission
  --jsessionid <id>   JSESSIONID cookie value
  --csrf-token <tok>  CSRF_Token cookie value
  --task-oid <oid>    Task OID (e.g., 1744315212023_JTask)
  --day <d>           Day of month (1-31)
  --month <m>         Month (1-12)
  --year <y>          Year (e.g., 2025)
  --hours <h>         Hours to book (0-23)
  --minutes <m>       Minutes to book (0-59)
  --description <d>   Work description
  --dry-run           Show payload without executing (for --add-worklog)
  --verbose           Show full HTTP response details

${YELLOW}Environment Variables:${NC}
  PROJEKTRON_DOMAIN, PROJEKTRON_USERNAME, PROJEKTRON_JSESSIONID, PROJEKTRON_CSRF_TOKEN

${YELLOW}Examples:${NC}
  # Test session
  $0 --test-session --jsessionid "ABC123" --domain "https://projektron.valantic.com"

  # Get tasks
  $0 --get-tasks

  # Get worklog for December 5, 2025
  $0 --get-worklog --day 5 --month 12 --year 2025

  # Add worklog entry
  $0 --add-worklog \\
    --task-oid "1744315212023_JTask" \\
    --day 5 --month 12 --year 2025 \\
    --hours 8 --minutes 0 \\
    --description "Development work"
EOF
    exit 1
}

# Parse arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --test-session)
            ACTION="test-session"
            shift
            ;;
        --get-tasks)
            ACTION="get-tasks"
            shift
            ;;
        --get-worklog)
            ACTION="get-worklog"
            shift
            ;;
        --add-worklog)
            ACTION="add-worklog"
            shift
            ;;
        --domain)
            DOMAIN="$2"
            shift 2
            ;;
        --username)
            USERNAME="$2"
            shift 2
            ;;
        --jsessionid)
            JSESSIONID="$2"
            shift 2
            ;;
        --csrf-token)
            CSRF_TOKEN="$2"
            shift 2
            ;;
        --task-oid)
            TASK_OID="$2"
            shift 2
            ;;
        --day)
            DAY="$2"
            shift 2
            ;;
        --month)
            MONTH="$2"
            shift 2
            ;;
        --year)
            YEAR="$2"
            shift 2
            ;;
        --hours)
            HOURS="$2"
            shift 2
            ;;
        --minutes)
            MINUTES="$2"
            shift 2
            ;;
        --description)
            DESCRIPTION="$2"
            shift 2
            ;;
        --dry-run)
            DRY_RUN=true
            shift
            ;;
        --verbose|-v)
            VERBOSE=true
            shift
            ;;
        --help|-h)
            usage
            ;;
        *)
            print_error "Unknown option: $1"
            usage
            ;;
    esac
done

# Validate required credentials
validate_credentials() {
    local missing=()

    [ -z "$DOMAIN" ] && missing+=("DOMAIN (--domain or PROJEKTRON_DOMAIN)")
    [ -z "$JSESSIONID" ] && missing+=("JSESSIONID (--jsessionid or PROJEKTRON_JSESSIONID)")

    if [ ${#missing[@]} -gt 0 ]; then
        print_error "Missing required credentials:"
        for m in "${missing[@]}"; do
            echo "  - $m"
        done
        exit 1
    fi
}

# Build cookie header
build_cookie_header() {
    if [ -n "$CSRF_TOKEN" ]; then
        echo "JSESSIONID=$JSESSIONID; CSRF_Token=$CSRF_TOKEN"
    else
        echo "JSESSIONID=$JSESSIONID"
    fi
}

# Test session validity
test_session() {
    print_separator
    echo -e "${BOLD}Testing Projektron Session${NC}"
    print_separator

    echo -e "Domain:      ${CYAN}$DOMAIN${NC}"
    echo -e "JSESSIONID:  ${CYAN}${JSESSIONID:0:8}...${NC}"
    [ -n "$CSRF_TOKEN" ] && echo -e "CSRF Token:  ${CYAN}${CSRF_TOKEN:0:8}...${NC}"
    echo ""

    print_section "Fetching Task List"

    TASK_LIST_URL="$DOMAIN/bcs/mybcs/tasklist"
    COOKIE_HEADER=$(build_cookie_header)

    RESPONSE=$(curl -s -w "\n%{http_code}" -X GET "$TASK_LIST_URL" \
        -H "Cookie: $COOKIE_HEADER" \
        -H "User-Agent: Projektron-Debug/1.0")

    HTTP_CODE=$(echo "$RESPONSE" | tail -n1)
    HTML_BODY=$(echo "$RESPONSE" | sed '$d')

    echo -e "HTTP Status: ${BOLD}$HTTP_CODE${NC}"

    # Check for login redirect
    if echo "$HTML_BODY" | grep -qi "login"; then
        if ! echo "$HTML_BODY" | grep -qi "tasklist"; then
            print_error "Session expired! Response contains login page."
            echo ""
            echo "Please update your JSESSIONID and CSRF_Token."
            exit 1
        fi
    fi

    if [ "$HTTP_CODE" = "200" ]; then
        print_success "Session is valid!"
    else
        print_error "Unexpected HTTP status: $HTTP_CODE"
        exit 1
    fi

    # Extract CSRF token from response
    print_section "Extracting CSRF Token from Response"

    EXTRACTED_CSRF=""

    # Try to extract from hidden input
    if [ -z "$EXTRACTED_CSRF" ]; then
        EXTRACTED_CSRF=$(echo "$HTML_BODY" | grep -oP 'name="CSRF_Token"[^>]*value="\K[^"]+' 2>/dev/null || true)
    fi

    # Try alternative pattern
    if [ -z "$EXTRACTED_CSRF" ]; then
        EXTRACTED_CSRF=$(echo "$HTML_BODY" | grep -oP 'value="\K[^"]+(?="[^>]*name="CSRF_Token")' 2>/dev/null || true)
    fi

    if [ -n "$EXTRACTED_CSRF" ]; then
        print_success "Found CSRF Token in page: $EXTRACTED_CSRF"

        if [ -n "$CSRF_TOKEN" ] && [ "$CSRF_TOKEN" != "$EXTRACTED_CSRF" ]; then
            print_warning "Note: Provided CSRF token differs from page token!"
            echo "  Provided: $CSRF_TOKEN"
            echo "  In Page:  $EXTRACTED_CSRF"
        fi
    else
        print_warning "Could not extract CSRF token from page HTML"
    fi

    # Count tasks found
    TASK_COUNT=$(echo "$HTML_BODY" | grep -oP 'data-tt="\d+_JTask"' | wc -l)
    echo ""
    print_info "Found $TASK_COUNT bookable tasks in task list"

    print_separator
}

# Get all tasks
get_tasks() {
    print_separator
    echo -e "${BOLD}Fetching Projektron Tasks${NC}"
    print_separator

    TASK_LIST_URL="$DOMAIN/bcs/mybcs/tasklist"
    COOKIE_HEADER=$(build_cookie_header)

    RESPONSE=$(curl -s "$TASK_LIST_URL" \
        -H "Cookie: $COOKIE_HEADER" \
        -H "User-Agent: Projektron-Debug/1.0")

    # Extract tasks using grep
    echo "$RESPONSE" | grep -oP 'data-tt="(\d+_JTask)"[^>]*>.*?<span class="hover"[^>]*>\K[^<]+' | head -20 || true

    # Alternative: show task OIDs
    print_section "Task OIDs"
    echo "$RESPONSE" | grep -oP '\d+_JTask' | sort -u | head -20

    print_separator
}

# Get worklog for a date
get_worklog() {
    if [ -z "$DAY" ] || [ -z "$MONTH" ] || [ -z "$YEAR" ]; then
        print_error "Missing date parameters (--day, --month, --year)"
        exit 1
    fi

    print_separator
    echo -e "${BOLD}Fetching Projektron Worklog${NC}"
    print_separator

    echo -e "Date: ${CYAN}$YEAR-$MONTH-$DAY${NC}"
    echo ""

    # First get user OID
    print_section "Getting User OID"

    TASK_LIST_URL="$DOMAIN/bcs/mybcs/tasklist"
    COOKIE_HEADER=$(build_cookie_header)

    USER_OID=$(curl -s "$TASK_LIST_URL" \
        -H "Cookie: $COOKIE_HEADER" \
        -H "User-Agent: Projektron-Debug/1.0" | grep -oP '"\d+_JUser"' | head -1 | tr -d '"')

    if [ -z "$USER_OID" ]; then
        print_error "Could not extract user OID"
        exit 1
    fi

    print_success "User OID: $USER_OID"

    # Build worklog URL
    TIMESTAMP=$(date +%s%3N)
    WORKLOG_URL="$DOMAIN/bcs/mybcs/multidaytimerecording/display?oid=$USER_OID"
    WORKLOG_URL+="&multidaytimerecording,Selections,effortRecordingDate,day=$DAY"
    WORKLOG_URL+="&multidaytimerecording,Selections,effortRecordingDate,month=$MONTH"
    WORKLOG_URL+="&multidaytimerecording,Selections,effortRecordingDate,year=$YEAR"
    WORKLOG_URL+="&pagetimestamp=$TIMESTAMP"

    print_section "Fetching Worklog"

    RESPONSE=$(curl -s "$WORKLOG_URL" \
        -H "Cookie: $COOKIE_HEADER" \
        -H "User-Agent: Projektron-Debug/1.0")

    # Extract and display time entries
    # Pattern: listeditoid_TASKOID.days_TIMESTAMP..._hour" value="X"
    echo ""
    echo -e "${BOLD}Time Entries Found:${NC}"

    # Extract hour values with task OIDs - use perl for reliable parsing
    echo "$RESPONSE" | perl -ne '
        while (/listeditoid_(\d+_JTask)\.days_(\d+)[^"]*_hour"[^>]*value="(\d+)"/g) {
            my ($task, $ts, $hour) = ($1, $2, $3);
            next if $hour eq "" || $hour eq "0";
            my $date_epoch = int($ts / 1000);
            my @t = localtime($date_epoch);
            my $date = sprintf("%04d-%02d-%02d", $t[5]+1900, $t[4]+1, $t[3]);
            print "  Task: $task | Date: $date | Hours: $hour\n";
        }
    ' 2>/dev/null || echo "  (No entries found or parsing error)"

    print_separator
}

# Add worklog entry
add_worklog() {
    # Validate required parameters
    local missing=()
    [ -z "$TASK_OID" ] && missing+=("--task-oid")
    [ -z "$DAY" ] && missing+=("--day")
    [ -z "$MONTH" ] && missing+=("--month")
    [ -z "$YEAR" ] && missing+=("--year")
    [ -z "$HOURS" ] && missing+=("--hours")
    [ -z "$MINUTES" ] && missing+=("--minutes")
    [ -z "$DESCRIPTION" ] && missing+=("--description")
    [ -z "$USERNAME" ] && missing+=("--username or PROJEKTRON_USERNAME")
    [ -z "$CSRF_TOKEN" ] && missing+=("--csrf-token or PROJEKTRON_CSRF_TOKEN")

    if [ ${#missing[@]} -gt 0 ]; then
        print_error "Missing required parameters for add-worklog:"
        for m in "${missing[@]}"; do
            echo "  - $m"
        done
        exit 1
    fi

    print_separator
    echo -e "${BOLD}Adding Projektron Worklog Entry${NC}"
    print_separator

    echo -e "Task OID:    ${CYAN}$TASK_OID${NC}"
    echo -e "Date:        ${CYAN}$YEAR-$MONTH-$DAY${NC}"
    echo -e "Time:        ${CYAN}${HOURS}h ${MINUTES}m${NC}"
    echo -e "Description: ${CYAN}$DESCRIPTION${NC}"
    echo -e "Username:    ${CYAN}$USERNAME${NC}"
    echo ""

    # Calculate timestamp
    # NOTE: The PHP code subtracts 1 day (ProjektronService.php lines 615-618)
    # but this appears to be wrong. Testing WITHOUT the -1 day quirk.
    print_section "Date Calculation"

    TARGET_DATE=$(printf '%04d-%02d-%02d' "$YEAR" "$MONTH" "$DAY")
    echo "Target date:   $TARGET_DATE"

    # Set to 00:00 and get timestamp in milliseconds (NO -1 day adjustment)
    TIMESTAMP=$(date -d "$TARGET_DATE 00:00:00" +%s)
    TIMESTAMP_MS="${TIMESTAMP}000"
    echo "Timestamp:     $TIMESTAMP_MS ($TARGET_DATE 00:00:00)"
    echo ""

    # Build form payload (matching ProjektronService.php lines 621-650)
    print_section "Building Form Payload"

    # Format minutes with leading zero
    MINUTES_PADDED=$(printf '%02d' "$MINUTES")

    PAYLOAD="daytimerecording,formsubmitted=true"
    PAYLOAD+="&daytimerecording,Content,singleeffort,TaskSelector,fixedtask,Data_CustomTitle=Einzelbuchen"
    PAYLOAD+="&daytimerecording,Content,singleeffort,TaskSelector,fixedtask,Data_FirstOnPage=daytimerecording"
    PAYLOAD+="&daytimerecording,Content,singleeffort,TaskSelector,fixedtask,task,task=$TASK_OID"
    PAYLOAD+="&daytimerecording,Content,singleeffort,TaskSelector,fixedtask,edit_form_data_submitted=true"
    PAYLOAD+="&daytimerecording,Content,singleeffort,EffortEditor,effort1,effortStart,effortStart_hour="
    PAYLOAD+="&daytimerecording,Content,singleeffort,EffortEditor,effort1,effortStart,effortStart_minute="
    PAYLOAD+="&daytimerecording,Content,singleeffort,EffortEditor,effort1,effortEnd,effortEnd_hour="
    PAYLOAD+="&daytimerecording,Content,singleeffort,EffortEditor,effort1,effortEnd,effortEnd_minute="
    PAYLOAD+="&daytimerecording,Content,singleeffort,EffortEditor,effort1,effortExpense,effortExpense_hour=$HOURS"
    PAYLOAD+="&daytimerecording,Content,singleeffort,EffortEditor,effort1,effortExpense,effortExpense_minute=$MINUTES_PADDED"
    PAYLOAD+="&daytimerecording,Content,singleeffort,EffortEditor,effort1,effortChargeability,effortChargeability=effortIsChargable_false+effortIsShown_false"
    PAYLOAD+="&daytimerecording,Content,singleeffort,EffortEditor,effort1,effortActivity,effortActivity=nicht_anrechenbar"
    PAYLOAD+="&daytimerecording,Content,singleeffort,EffortEditor,effort1,effortActivity,effortActivity_widgetType=option"
    PAYLOAD+="&daytimerecording,Content,singleeffort,EffortEditor,effort1,effortWorkingTimeType,effortWorkingTimeType=Standard"
    PAYLOAD+="&daytimerecording,Content,singleeffort,EffortEditor,effort1,description,description=$(echo "$DESCRIPTION" | jq -sRr @uri)"
    PAYLOAD+="&daytimerecording,Content,singleeffort,EffortEditor,effort1,edit_form_data_submitted=true"
    PAYLOAD+="&daytimerecording,Content,singleeffort,recordType=neweffort"
    PAYLOAD+="&daytimerecording,Content,singleeffort,recordOid="
    PAYLOAD+="&daytimerecording,Content,singleeffort,recordDate=$TIMESTAMP_MS"
    PAYLOAD+="&daytimerecording,editableComponentNames=daytimerecording,Content,singleeffort+daytimerecording,Content,daytimerecordingAllBookingsOfTheDay"
    PAYLOAD+="&oid=$TASK_OID"
    PAYLOAD+="&user=$USERNAME"
    PAYLOAD+="&action,MultiComponentDayTimeRecordingAction,daytimerecording=0"
    PAYLOAD+="&BCS.ConfirmDiscardChangesDialog,InitialApplyButtonsOnError=daytimerecording,Apply"
    PAYLOAD+="&CSRF_Token=$CSRF_TOKEN"
    PAYLOAD+="&daytimerecording,Apply=daytimerecording,Apply"
    PAYLOAD+="&submitButtonPressed=daytimerecording,Apply"

    BOOKING_URL="$DOMAIN/bcs/taskdetail/effortrecording/edit?oid=$TASK_OID"
    COOKIE_HEADER=$(build_cookie_header)

    echo "URL: $BOOKING_URL"
    echo ""

    if [ "$VERBOSE" = true ]; then
        echo -e "${YELLOW}Full Payload:${NC}"
        echo "$PAYLOAD" | tr '&' '\n'
        echo ""
    fi

    # Show curl command for debugging
    print_section "cURL Command (for manual testing)"
    echo "curl '$BOOKING_URL' \\"
    echo "  -H 'Content-Type: application/x-www-form-urlencoded' \\"
    echo "  -H 'Cookie: $COOKIE_HEADER' \\"
    echo "  -H 'User-Agent: Projektron-Debug/1.0' \\"
    echo "  --data-raw '<payload>'"
    echo ""

    if [ "$DRY_RUN" = true ]; then
        print_warning "DRY RUN - Not executing request"
        print_separator
        return 0
    fi

    # Execute request
    print_section "Executing Request"

    RESPONSE_FILE="/tmp/projektron_response_$(date +%s).html"

    RESPONSE=$(curl -s -w "\n---HTTP_CODE:%{http_code}---" -X POST "$BOOKING_URL" \
        -H "Content-Type: application/x-www-form-urlencoded" \
        -H "Cookie: $COOKIE_HEADER" \
        -H "User-Agent: Projektron-Debug/1.0" \
        --data-raw "$PAYLOAD")

    HTTP_CODE=$(echo "$RESPONSE" | sed -n 's/.*---HTTP_CODE:\([0-9]*\)---.*/\1/p')
    HTML_BODY=$(echo "$RESPONSE" | sed 's/---HTTP_CODE:[0-9]*---$//')

    # Save response for analysis
    echo "$HTML_BODY" > "$RESPONSE_FILE"
    print_info "Full response saved to: $RESPONSE_FILE"

    echo ""
    echo -e "HTTP Status: ${BOLD}$HTTP_CODE${NC}"

    # Check for success/error indicators
    print_section "Response Analysis"

    HAS_AFFIRMATION=false
    HAS_ERROR=false
    HAS_WARNING=false
    HAS_LOGIN=false

    if echo "$HTML_BODY" | grep -q '<div class="msg affirmation">'; then
        HAS_AFFIRMATION=true
        print_success "Found SUCCESS indicator: <div class=\"msg affirmation\">"
        # Extract affirmation message
        AFFIRMATION_MSG=$(echo "$HTML_BODY" | grep -oP '<div class="msg affirmation">[^<]*' | head -1)
        if [ -n "$AFFIRMATION_MSG" ]; then
            echo "  Message: $AFFIRMATION_MSG"
        fi
    fi

    if echo "$HTML_BODY" | grep -q '<div class="msg error">'; then
        HAS_ERROR=true
        print_error "Found ERROR indicator: <div class=\"msg error\">"
        # Extract error message
        ERROR_MSG=$(echo "$HTML_BODY" | grep -oP '<div class="msg error">[^<]*' | head -1)
        if [ -n "$ERROR_MSG" ]; then
            echo "  Message: $ERROR_MSG"
        fi
    fi

    if echo "$HTML_BODY" | grep -q '<div class="msg warning">'; then
        HAS_WARNING=true
        print_warning "Found WARNING indicator: <div class=\"msg warning\">"
        # Extract warning message
        WARNING_MSG=$(echo "$HTML_BODY" | grep -oP '<div class="msg warning">[^<]*' | head -1)
        if [ -n "$WARNING_MSG" ]; then
            echo "  Message: $WARNING_MSG"
        fi
    fi

    if echo "$HTML_BODY" | grep -qi 'login'; then
        if ! echo "$HTML_BODY" | grep -qi 'tasklist\|effortrecording'; then
            HAS_LOGIN=true
            print_error "Response appears to be a LOGIN page - session expired!"
        fi
    fi

    echo ""

    # Summary
    print_section "Summary"

    if [ "$HAS_AFFIRMATION" = true ] && [ "$HAS_ERROR" = false ]; then
        print_success "Booking appears SUCCESSFUL based on affirmation message"
        echo ""
        print_warning "However, please verify in Projektron that the entry was actually created!"
        echo "Check: $DOMAIN/bcs/mybcs/multidaytimerecording/display"
    elif [ "$HAS_ERROR" = true ]; then
        print_error "Booking FAILED - error message found in response"
    elif [ "$HAS_LOGIN" = true ]; then
        print_error "Session EXPIRED - please update JSESSIONID and CSRF_Token"
    else
        print_warning "Could not determine success/failure from response"
        echo "Check the saved response file: $RESPONSE_FILE"
    fi

    # Show response snippet
    if [ "$VERBOSE" = true ]; then
        echo ""
        print_section "Response Snippet (first 2000 chars)"
        echo "$HTML_BODY" | head -c 2000
        echo ""
    fi

    print_separator
}

# Main
if [ -z "$ACTION" ]; then
    usage
fi

validate_credentials

case $ACTION in
    test-session)
        test_session
        ;;
    get-tasks)
        get_tasks
        ;;
    get-worklog)
        get_worklog
        ;;
    add-worklog)
        add_worklog
        ;;
    *)
        print_error "Unknown action: $ACTION"
        usage
        ;;
esac
