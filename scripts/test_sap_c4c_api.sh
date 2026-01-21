#!/bin/bash
# SAP C4C API Debug Script
# Tests the CSRF token fetch and opportunity creation flow
# Usage: ./scripts/test_sap_c4c_api.sh [create|search|test-csrf]

set -e

# Configuration - MUST be passed as environment variables
# Example: SAP_DOMAIN=https://myXXXXXX.crm.ondemand.com SAP_USERNAME=user SAP_PASSWORD=pass ./scripts/test_sap_c4c_api.sh
SAP_DOMAIN="${SAP_DOMAIN:-}"
SAP_USERNAME="${SAP_USERNAME:-}"
SAP_PASSWORD="${SAP_PASSWORD:-}"

# Validate required environment variables
if [ -z "$SAP_DOMAIN" ] || [ -z "$SAP_USERNAME" ] || [ -z "$SAP_PASSWORD" ]; then
    echo "ERROR: Missing required environment variables"
    echo ""
    echo "Required:"
    echo "  SAP_DOMAIN   - SAP C4C domain (e.g., https://myXXXXXX.crm.ondemand.com)"
    echo "  SAP_USERNAME - SAP username"
    echo "  SAP_PASSWORD - SAP password"
    echo ""
    echo "Usage:"
    echo "  SAP_DOMAIN=https://myXXXXXX.crm.ondemand.com SAP_USERNAME=user SAP_PASSWORD=pass $0 [command]"
    exit 1
fi

API_BASE="$SAP_DOMAIN/sap/c4c/odata/v1/c4codataapi"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

log_info() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

log_warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Test 1: Fetch CSRF Token (with redirect analysis)
test_csrf_fetch() {
    log_info "=== Testing CSRF Token Fetch ==="

    # Test without trailing slash (should get 307)
    log_info "Testing WITHOUT trailing slash..."
    RESPONSE=$(curl -s -D- "$API_BASE" \
        -u "$SAP_USERNAME:$SAP_PASSWORD" \
        -H "Accept: application/json" \
        -H "x-csrf-token: fetch" \
        -o /dev/null 2>&1)

    STATUS=$(echo "$RESPONSE" | grep -E "^HTTP" | head -1)
    CSRF=$(echo "$RESPONSE" | grep -i "x-csrf-token:" | head -1 | awk '{print $2}' | tr -d '\r')

    echo "  Status: $STATUS"
    echo "  CSRF Token: $CSRF"

    if echo "$STATUS" | grep -q "307"; then
        log_warn "Got 307 redirect - this was the bug!"
    fi

    # Test with trailing slash (should get 200)
    log_info "Testing WITH trailing slash..."
    RESPONSE=$(curl -s -D- "$API_BASE/" \
        -u "$SAP_USERNAME:$SAP_PASSWORD" \
        -H "Accept: application/json" \
        -H "x-csrf-token: fetch" \
        -o /dev/null 2>&1)

    STATUS=$(echo "$RESPONSE" | grep -E "^HTTP" | head -1)
    CSRF=$(echo "$RESPONSE" | grep -i "x-csrf-token:" | head -1 | awk '{print $2}' | tr -d '\r')
    COOKIES=$(echo "$RESPONSE" | grep -i "set-cookie:" | sed 's/set-cookie: //gi' | cut -d';' -f1 | tr '\n' '; ' | tr -d '\r')

    echo "  Status: $STATUS"
    echo "  CSRF Token: $CSRF"
    echo "  Cookies: ${COOKIES:0:80}..."

    if echo "$STATUS" | grep -q "200"; then
        log_info "SUCCESS - Got 200 response with CSRF token"
    else
        log_error "FAILED - Expected 200 but got: $STATUS"
    fi
}

# Test 2: Create an Opportunity
create_opportunity() {
    local name="${1:-Test_Debug_$(date +%s)}"

    log_info "=== Creating Opportunity: $name ==="

    # Step 1: Fetch CSRF Token
    log_info "Step 1: Fetching CSRF Token..."
    CSRF_RESPONSE=$(curl -s -D /tmp/csrf_headers.txt "$API_BASE/" \
        -u "$SAP_USERNAME:$SAP_PASSWORD" \
        -H "Accept: application/json" \
        -H "x-csrf-token: fetch" \
        -o /dev/null 2>&1)

    CSRF=$(grep -i "x-csrf-token:" /tmp/csrf_headers.txt | head -1 | awk '{print $2}' | tr -d '\r')
    COOKIES=$(grep -i "set-cookie:" /tmp/csrf_headers.txt | sed 's/set-cookie: //gi' | cut -d';' -f1 | tr '\n' '; ' | tr -d '\r')

    if [ -z "$CSRF" ]; then
        log_error "Failed to get CSRF token"
        return 1
    fi

    log_info "  CSRF Token: $CSRF"
    log_info "  Cookies: ${COOKIES:0:50}..."

    # Step 2: Create Opportunity
    log_info "Step 2: Creating opportunity..."
    PAYLOAD=$(cat <<EOF
{
    "Name": "$name"
}
EOF
)

    RESPONSE=$(curl -s -X POST "$API_BASE/OpportunityCollection" \
        -u "$SAP_USERNAME:$SAP_PASSWORD" \
        -H "Accept: application/json" \
        -H "Content-Type: application/json" \
        -H "X-CSRF-Token: $CSRF" \
        -H "Cookie: $COOKIES" \
        -d "$PAYLOAD" \
        -w "\nHTTP_CODE:%{http_code}" 2>&1)

    HTTP_CODE=$(echo "$RESPONSE" | grep "HTTP_CODE:" | cut -d: -f2)
    BODY=$(echo "$RESPONSE" | grep -v "HTTP_CODE:")

    if [ "$HTTP_CODE" = "201" ]; then
        OBJ_ID=$(echo "$BODY" | jq -r '.d.results.ObjectID // .d.ObjectID // "N/A"' 2>/dev/null)
        log_info "SUCCESS! Opportunity created with ObjectID: $OBJ_ID"
    else
        log_error "FAILED! HTTP Code: $HTTP_CODE"
        echo "$BODY" | jq . 2>/dev/null || echo "$BODY"
    fi
}

# Test 3: Search Opportunities
search_opportunities() {
    local filter="${1:-}"

    log_info "=== Searching Opportunities ==="

    URL="$API_BASE/OpportunityCollection?\$top=5&\$format=json"
    if [ -n "$filter" ]; then
        URL="$URL&\$filter=$filter"
    fi

    RESPONSE=$(curl -s "$URL" \
        -u "$SAP_USERNAME:$SAP_PASSWORD" \
        -H "Accept: application/json" \
        -w "\nHTTP_CODE:%{http_code}" 2>&1)

    HTTP_CODE=$(echo "$RESPONSE" | grep "HTTP_CODE:" | cut -d: -f2)
    BODY=$(echo "$RESPONSE" | grep -v "HTTP_CODE:")

    if [ "$HTTP_CODE" = "200" ]; then
        COUNT=$(echo "$BODY" | jq '.d.results | length' 2>/dev/null)
        log_info "Found $COUNT opportunities"
        echo "$BODY" | jq '.d.results[] | {Name: .Name, ObjectID: .ObjectID, Status: .LifeCycleStatusCodeText}' 2>/dev/null
    else
        log_error "Search failed with HTTP $HTTP_CODE"
        echo "$BODY"
    fi
}

# Main
case "${1:-test-csrf}" in
    test-csrf)
        test_csrf_fetch
        ;;
    create)
        create_opportunity "${2:-}"
        ;;
    search)
        search_opportunities "${2:-}"
        ;;
    *)
        echo "Usage: SAP_DOMAIN=... SAP_USERNAME=... SAP_PASSWORD=... $0 [command]"
        echo ""
        echo "Commands:"
        echo "  test-csrf  - Test CSRF token fetch (default)"
        echo "  create     - Create a test opportunity"
        echo "  search     - Search opportunities"
        echo ""
        echo "Required environment variables:"
        echo "  SAP_DOMAIN   - SAP C4C domain (e.g., https://myXXXXXX.crm.ondemand.com)"
        echo "  SAP_USERNAME - SAP username"
        echo "  SAP_PASSWORD - SAP password"
        ;;
esac
