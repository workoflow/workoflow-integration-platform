#!/bin/bash
#
# Projektron Get Absences Debug Helper
# Fetch and analyze Projektron vacation/absence data from HTML
#
# Usage:
#   ./scripts/projektron_get_absences.sh --get-absences --year 2025
#   ./scripts/projektron_get_absences.sh --get-absences --day 10 --month 12 --year 2025
#   ./scripts/projektron_get_absences.sh --test-session
#
# Environment variables:
#   PROJEKTRON_DOMAIN - Projektron instance URL
#   PROJEKTRON_JSESSIONID - Session cookie
#   PROJEKTRON_CSRF_TOKEN - CSRF token (optional for read-only)
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
JSESSIONID="${PROJEKTRON_JSESSIONID:-}"
CSRF_TOKEN="${PROJEKTRON_CSRF_TOKEN:-}"

# Action flags
ACTION=""
DAY=""
MONTH=""
YEAR=""
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
${BOLD}Projektron Get Absences Debug Helper${NC}

${YELLOW}Usage:${NC}
  $0 <action> [options]

${YELLOW}Actions:${NC}
  --test-session      Test session validity
  --get-absences      Get absences/vacation data for a specific year

${YELLOW}Options:${NC}
  --domain <url>      Projektron domain (e.g., https://projektron.valantic.com)
  --jsessionid <id>   JSESSIONID cookie value
  --csrf-token <tok>  CSRF_Token cookie value (optional)
  --day <d>           Day of month (1-31) - default: 10
  --month <m>         Month (1-12) - default: 12
  --year <y>          Year (e.g., 2025) - default: current year
  --verbose           Show full HTTP response details

${YELLOW}Environment Variables:${NC}
  PROJEKTRON_DOMAIN, PROJEKTRON_JSESSIONID, PROJEKTRON_CSRF_TOKEN

${YELLOW}Examples:${NC}
  # Test session
  $0 --test-session

  # Get absences for 2025 (default day/month)
  $0 --get-absences --year 2025

  # Get absences with specific date reference
  $0 --get-absences --day 10 --month 12 --year 2025 --verbose
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
        --get-absences)
            ACTION="get-absences"
            shift
            ;;
        --domain)
            DOMAIN="$2"
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

# Get user OID from tasklist page
get_user_oid() {
    local cookie_header
    cookie_header=$(build_cookie_header)

    TASK_LIST_URL="$DOMAIN/bcs/mybcs/tasklist"

    USER_OID=$(curl -s "$TASK_LIST_URL" \
        -H "Cookie: $cookie_header" \
        -H "User-Agent: Projektron-Debug/1.0" | grep -oP '"\d+_JUser"' | head -1 | tr -d '"')

    if [ -z "$USER_OID" ]; then
        print_error "Could not extract user OID - session may be expired"
        exit 1
    fi

    echo "$USER_OID"
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

    # Extract user OID
    print_section "Extracting User OID"
    USER_OID=$(echo "$HTML_BODY" | grep -oP '"\d+_JUser"' | head -1 | tr -d '"')
    if [ -n "$USER_OID" ]; then
        print_success "User OID: $USER_OID"
    else
        print_warning "Could not extract User OID"
    fi

    print_separator
}

# Get absences/vacation data
get_absences() {
    # Set defaults
    [ -z "$DAY" ] && DAY=10
    [ -z "$MONTH" ] && MONTH=12
    [ -z "$YEAR" ] && YEAR=$(date +%Y)

    print_separator
    echo -e "${BOLD}Fetching Projektron Absences${NC}"
    print_separator

    echo -e "Date Reference: ${CYAN}$YEAR-$MONTH-$DAY${NC}"
    echo ""

    # Get user OID
    print_section "Getting User OID"
    USER_OID=$(get_user_oid)
    print_success "User OID: $USER_OID"

    # Build vacation URL
    TIMESTAMP=$(date +%s%3N)
    VACATION_URL="$DOMAIN/bcs/mybcs/vacation/display"
    VACATION_URL+="?oid=$USER_OID"
    VACATION_URL+="&group,Choices,vacationlist,Selections,dateRange,day=$DAY"
    VACATION_URL+="&group,Choices,vacationlist,Selections,dateRange,month=$MONTH"
    VACATION_URL+="&group,Choices,vacationlist,Selections,dateRange,year=$YEAR"
    VACATION_URL+="&pagetimestamp=$TIMESTAMP"

    print_section "Fetching Vacation/Absences Page"
    echo -e "URL: ${CYAN}$VACATION_URL${NC}"
    echo ""

    COOKIE_HEADER=$(build_cookie_header)

    RESPONSE=$(curl -s -w "\n---HTTP_CODE:%{http_code}---" -X GET "$VACATION_URL" \
        -H "Cookie: $COOKIE_HEADER" \
        -H "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8" \
        -H "User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36")

    HTTP_CODE=$(echo "$RESPONSE" | sed -n 's/.*---HTTP_CODE:\([0-9]*\)---.*/\1/p')
    HTML_BODY=$(echo "$RESPONSE" | sed 's/---HTTP_CODE:[0-9]*---$//')

    echo -e "HTTP Status: ${BOLD}$HTTP_CODE${NC}"

    # Check for login redirect
    if echo "$HTML_BODY" | grep -qi "login"; then
        if ! echo "$HTML_BODY" | grep -qi "vacation\|absence"; then
            print_error "Session expired! Response contains login page."
            exit 1
        fi
    fi

    # Save response for analysis
    RESPONSE_FILE="/tmp/projektron_absences_$(date +%s).html"
    echo "$HTML_BODY" > "$RESPONSE_FILE"
    print_info "Full response saved to: $RESPONSE_FILE"
    echo ""

    # Analyze HTML structure
    print_section "HTML Structure Analysis"

    # Check for vacation-related elements
    echo "Searching for common absence/vacation patterns..."
    echo ""

    # Look for table structures
    TABLE_COUNT=$(echo "$HTML_BODY" | grep -c '<table' || echo "0")
    echo "Tables found: $TABLE_COUNT"

    # Look for vacation-related text
    if echo "$HTML_BODY" | grep -qi "urlaub\|vacation"; then
        print_success "Found 'Urlaub/vacation' text"
    fi

    if echo "$HTML_BODY" | grep -qi "krank\|sick"; then
        print_success "Found 'Krank/sick' text"
    fi

    if echo "$HTML_BODY" | grep -qi "abwesenheit\|absence"; then
        print_success "Found 'Abwesenheit/absence' text"
    fi

    # Look for date patterns
    DATE_PATTERN_COUNT=$(echo "$HTML_BODY" | grep -oP '\d{2}\.\d{2}\.\d{4}' | wc -l)
    echo "Date patterns (DD.MM.YYYY) found: $DATE_PATTERN_COUNT"

    # Look for status indicators
    if echo "$HTML_BODY" | grep -qi "genehmigt\|approved"; then
        print_success "Found 'genehmigt/approved' status"
    fi

    if echo "$HTML_BODY" | grep -qi "beantragt\|pending\|requested"; then
        print_success "Found 'beantragt/pending' status"
    fi

    if echo "$HTML_BODY" | grep -qi "abgelehnt\|rejected"; then
        print_success "Found 'abgelehnt/rejected' status"
    fi

    echo ""
    print_section "Key HTML Snippets"

    # Extract potential absence rows/entries
    echo "Looking for table rows with dates..."
    echo "$HTML_BODY" | grep -oP '<tr[^>]*>.*?</tr>' 2>/dev/null | head -5 || echo "(no table rows found with simple pattern)"

    echo ""
    echo "Looking for div elements with vacation/absence classes..."
    echo "$HTML_BODY" | grep -oP '<div[^>]*class="[^"]*vacation[^"]*"[^>]*>.*?</div>' 2>/dev/null | head -3 || echo "(no vacation divs found)"

    echo ""
    print_section "Summary Statistics (if visible)"

    # Try to extract vacation balance/statistics
    # Common German patterns: "Urlaubstage", "Resturlaub", "verbraucht"
    echo "$HTML_BODY" | grep -oiP '(urlaub|vacation|tage|days|rest|verbraucht|used|remaining)[^<]*\d+[^<]*' 2>/dev/null | head -10 || echo "(no summary statistics found with simple pattern)"

    # Show verbose output if requested
    if [ "$VERBOSE" = true ]; then
        echo ""
        print_section "Response Snippet (first 5000 chars)"
        echo "$HTML_BODY" | head -c 5000
        echo ""
    fi

    print_separator
    echo ""
    print_info "Next Steps:"
    echo "1. Open the saved HTML file: $RESPONSE_FILE"
    echo "2. Analyze the HTML structure to identify:"
    echo "   - Table/list structure for absences"
    echo "   - Fields: type, start_date, end_date, duration, status, approver"
    echo "   - Summary statistics location"
    echo ""
    print_info "Use browser DevTools or: bat -l html $RESPONSE_FILE"
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
    get-absences)
        get_absences
        ;;
    *)
        print_error "Unknown action: $ACTION"
        usage
        ;;
esac
