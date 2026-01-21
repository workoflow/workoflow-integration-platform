# SAP C4C Lead API - CURL Reference Guide

Complete guide for authenticating and working with Leads in SAP Cloud for Customer (C4C) via OData API.

## Configuration

Replace these placeholders in all examples:

| Placeholder | Description | Example |
|-------------|-------------|---------|
| `myXXXXXX` | Your SAP C4C tenant ID | `my346571` |
| `USERNAME` | SAP username | `APIUSER` |
| `PASSWORD` | SAP password | `SecurePass123` |

**API Base URL:**
```
https://myXXXXXX.crm.ondemand.com/sap/c4c/odata/v1/c4codataapi/
```

> **Important:** The trailing slash `/` after `c4codataapi` is critical - without it you get a 307 redirect!

---

## 1. Authentication & Session Setup

### 1.1 Fetch CSRF Token (Required for Write Operations)

For POST/PATCH/DELETE operations, you must first fetch a CSRF token:

```bash
curl -s -D- "https://myXXXXXX.crm.ondemand.com/sap/c4c/odata/v1/c4codataapi/" \
    -u "USERNAME:PASSWORD" \
    -H "Accept: application/json" \
    -H "x-csrf-token: fetch" \
    -o /dev/null
```

**Extract from response headers:**
- `x-csrf-token: <TOKEN>`
- `set-cookie: sap-usercontext=...; sap-XSRF_...=...`

### 1.2 Session Setup Script

```bash
#!/bin/bash
# Save as: sap_session.sh
# Usage: source sap_session.sh

SAP_DOMAIN="https://myXXXXXX.crm.ondemand.com"
SAP_USER="USERNAME"
SAP_PASS="PASSWORD"
API_BASE="$SAP_DOMAIN/sap/c4c/odata/v1/c4codataapi"

# Fetch CSRF token and cookies
curl -s -D /tmp/csrf_headers.txt "$API_BASE/" \
    -u "$SAP_USER:$SAP_PASS" \
    -H "Accept: application/json" \
    -H "x-csrf-token: fetch" \
    -o /dev/null

export CSRF_TOKEN=$(grep -i "x-csrf-token:" /tmp/csrf_headers.txt | awk '{print $2}' | tr -d '\r')
export SAP_COOKIES=$(grep -i "set-cookie:" /tmp/csrf_headers.txt | sed 's/set-cookie: //gi' | cut -d';' -f1 | tr '\n' '; ' | tr -d '\r')

echo "CSRF Token: ${CSRF_TOKEN:0:30}..."
echo "Session cookies set."
```

---

## 2. Read Leads (GET Requests)

### 2.1 Get All Leads (Basic)

```bash
curl -s "https://myXXXXXX.crm.ondemand.com/sap/c4c/odata/v1/c4codataapi/LeadCollection?\$format=json" \
    -u "USERNAME:PASSWORD" \
    -H "Accept: application/json"
```

### 2.2 Get Leads with Pagination

```bash
# First 50 leads
curl -s "https://myXXXXXX.crm.ondemand.com/sap/c4c/odata/v1/c4codataapi/LeadCollection?\$format=json&\$top=50" \
    -u "USERNAME:PASSWORD" \
    -H "Accept: application/json"

# Skip first 100, get next 50
curl -s "https://myXXXXXX.crm.ondemand.com/sap/c4c/odata/v1/c4codataapi/LeadCollection?\$format=json&\$top=50&\$skip=100" \
    -u "USERNAME:PASSWORD" \
    -H "Accept: application/json"
```

### 2.3 Get Leads with Selected Fields

```bash
curl -s "https://myXXXXXX.crm.ondemand.com/sap/c4c/odata/v1/c4codataapi/LeadCollection?\$format=json&\$select=ObjectID,LeadID,Name,StatusCode,QualificationLevelCode,OriginTypeCode" \
    -u "USERNAME:PASSWORD" \
    -H "Accept: application/json"
```

### 2.4 Get Leads with Filters

```bash
# Filter by status (Open leads)
curl -s "https://myXXXXXX.crm.ondemand.com/sap/c4c/odata/v1/c4codataapi/LeadCollection?\$format=json&\$filter=StatusCode%20eq%20'1'" \
    -u "USERNAME:PASSWORD" \
    -H "Accept: application/json"

# Filter by qualification (Hot leads)
curl -s "https://myXXXXXX.crm.ondemand.com/sap/c4c/odata/v1/c4codataapi/LeadCollection?\$format=json&\$filter=QualificationLevelCode%20eq%20'1'" \
    -u "USERNAME:PASSWORD" \
    -H "Accept: application/json"

# Filter by name containing keyword
curl -s "https://myXXXXXX.crm.ondemand.com/sap/c4c/odata/v1/c4codataapi/LeadCollection?\$format=json&\$filter=substringof('Acme',Name)" \
    -u "USERNAME:PASSWORD" \
    -H "Accept: application/json"

# Filter by contact last name ending with 'son'
curl -s "https://myXXXXXX.crm.ondemand.com/sap/c4c/odata/v1/c4codataapi/LeadCollection?\$format=json&\$filter=endswith(ContactLastName,'son')" \
    -u "USERNAME:PASSWORD" \
    -H "Accept: application/json"

# Combined filters (Hot AND Open)
curl -s "https://myXXXXXX.crm.ondemand.com/sap/c4c/odata/v1/c4codataapi/LeadCollection?\$format=json&\$filter=QualificationLevelCode%20eq%20'1'%20and%20StatusCode%20eq%20'1'" \
    -u "USERNAME:PASSWORD" \
    -H "Accept: application/json"
```

### 2.5 Get Single Lead by ObjectID

```bash
curl -s "https://myXXXXXX.crm.ondemand.com/sap/c4c/odata/v1/c4codataapi/LeadCollection('00163E0DBD9E1ED9B5B1B528E3AA56FB')?\$format=json" \
    -u "USERNAME:PASSWORD" \
    -H "Accept: application/json"
```

### 2.6 Count Total Leads

```bash
curl -s "https://myXXXXXX.crm.ondemand.com/sap/c4c/odata/v1/c4codataapi/LeadCollection/\$count" \
    -u "USERNAME:PASSWORD"
```

### 2.7 Get Marketing Leads (LeanLead)

For Marketing work center (instead of Sales):

```bash
curl -s "https://myXXXXXX.crm.ondemand.com/sap/c4c/odata/v1/c4codataapi/LeanLeadCollection?\$format=json" \
    -u "USERNAME:PASSWORD" \
    -H "Accept: application/json"
```

---

## 3. Update Leads (PATCH Requests)

### 3.1 Update Lead Score/Qualification

**Step 1: Fetch CSRF Token**
```bash
curl -s -D /tmp/headers.txt \
    "https://myXXXXXX.crm.ondemand.com/sap/c4c/odata/v1/c4codataapi/" \
    -u "USERNAME:PASSWORD" \
    -H "Accept: application/json" \
    -H "x-csrf-token: fetch" \
    -o /dev/null

CSRF_TOKEN=$(grep -i "x-csrf-token:" /tmp/headers.txt | awk '{print $2}' | tr -d '\r')
COOKIES=$(grep -i "set-cookie:" /tmp/headers.txt | sed 's/set-cookie: //gi' | cut -d';' -f1 | tr '\n' '; ' | tr -d '\r')
```

**Step 2: PATCH the Lead**
```bash
curl -s -X PATCH \
    "https://myXXXXXX.crm.ondemand.com/sap/c4c/odata/v1/c4codataapi/LeadCollection('00163E0DBD9E1ED9B5B1B528E3AA56FB')" \
    -u "USERNAME:PASSWORD" \
    -H "Accept: application/json" \
    -H "Content-Type: application/json" \
    -H "X-CSRF-Token: $CSRF_TOKEN" \
    -H "Cookie: $COOKIES" \
    -d '{"QualificationLevelCode": "1"}' \
    -w "\nHTTP_CODE: %{http_code}\n"
```

### 3.2 Update Multiple Fields

```bash
curl -s -X PATCH \
    "https://myXXXXXX.crm.ondemand.com/sap/c4c/odata/v1/c4codataapi/LeadCollection('OBJECT_ID')" \
    -u "USERNAME:PASSWORD" \
    -H "Accept: application/json" \
    -H "Content-Type: application/json" \
    -H "X-CSRF-Token: $CSRF_TOKEN" \
    -H "Cookie: $COOKIES" \
    -d '{
        "QualificationLevelCode": "1",
        "PriorityCode": "2",
        "Note": "Updated via API"
    }'
```

### 3.3 Update with ETag (If Required)

Some tenants require optimistic locking:

```bash
# Get current ETag
ETAG=$(curl -s -I \
    "https://myXXXXXX.crm.ondemand.com/sap/c4c/odata/v1/c4codataapi/LeadCollection('OBJECT_ID')" \
    -u "USERNAME:PASSWORD" \
    -H "Accept: application/json" | grep -i "etag:" | awk '{print $2}' | tr -d '\r"')

# PATCH with If-Match
curl -s -X PATCH \
    "https://myXXXXXX.crm.ondemand.com/sap/c4c/odata/v1/c4codataapi/LeadCollection('OBJECT_ID')" \
    -u "USERNAME:PASSWORD" \
    -H "Accept: application/json" \
    -H "Content-Type: application/json" \
    -H "X-CSRF-Token: $CSRF_TOKEN" \
    -H "Cookie: $COOKIES" \
    -H "If-Match: $ETAG" \
    -d '{"QualificationLevelCode": "1"}'
```

---

## 4. Create Leads (POST Requests)

### 4.1 Create New Lead

```bash
curl -s -X POST \
    "https://myXXXXXX.crm.ondemand.com/sap/c4c/odata/v1/c4codataapi/LeadCollection" \
    -u "USERNAME:PASSWORD" \
    -H "Accept: application/json" \
    -H "Content-Type: application/json" \
    -H "X-CSRF-Token: $CSRF_TOKEN" \
    -H "Cookie: $COOKIES" \
    -d '{
        "Name": "New Lead from API",
        "ContactFirstName": "John",
        "ContactLastName": "Doe",
        "Company": "Acme Corp",
        "QualificationLevelCode": "2",
        "OriginTypeCode": "01"
    }' \
    -w "\nHTTP_CODE: %{http_code}\n"
```

---

## 5. Complete Scripts

### 5.1 Export All Leads to JSON

```bash
#!/bin/bash
# export_leads.sh - Export all leads to JSON file

SAP_DOMAIN="https://myXXXXXX.crm.ondemand.com"
SAP_USER="USERNAME"
SAP_PASS="PASSWORD"
API_BASE="$SAP_DOMAIN/sap/c4c/odata/v1/c4codataapi"
OUTPUT_FILE="leads_export_$(date +%Y%m%d_%H%M%S).json"

echo "Exporting leads from SAP C4C..."

curl -s "$API_BASE/LeadCollection?\$format=json&\$top=10000" \
    -u "$SAP_USER:$SAP_PASS" \
    -H "Accept: application/json" | jq '.d.results' > "$OUTPUT_FILE"

COUNT=$(jq 'length' "$OUTPUT_FILE")
echo "Exported $COUNT leads to $OUTPUT_FILE"
```

### 5.2 Update Lead Score Script

```bash
#!/bin/bash
# update_lead_score.sh - Update a lead's qualification score
# Usage: ./update_lead_score.sh <OBJECT_ID> <SCORE>
#        Score: 1=Hot, 2=Warm, 3=Cold

SAP_DOMAIN="https://myXXXXXX.crm.ondemand.com"
SAP_USER="USERNAME"
SAP_PASS="PASSWORD"
API_BASE="$SAP_DOMAIN/sap/c4c/odata/v1/c4codataapi"

LEAD_OBJECT_ID="${1:?Usage: $0 <OBJECT_ID> <SCORE>}"
NEW_SCORE="${2:?Score required: 1=Hot, 2=Warm, 3=Cold}"

# Step 1: Fetch CSRF Token
echo "Fetching CSRF token..."
curl -s -D /tmp/csrf_headers.txt "$API_BASE/" \
    -u "$SAP_USER:$SAP_PASS" \
    -H "Accept: application/json" \
    -H "x-csrf-token: fetch" \
    -o /dev/null

CSRF=$(grep -i "x-csrf-token:" /tmp/csrf_headers.txt | awk '{print $2}' | tr -d '\r')
COOKIES=$(grep -i "set-cookie:" /tmp/csrf_headers.txt | sed 's/set-cookie: //gi' | cut -d';' -f1 | tr '\n' '; ' | tr -d '\r')

if [ -z "$CSRF" ]; then
    echo "ERROR: Failed to get CSRF token"
    exit 1
fi

# Step 2: Update Lead
echo "Updating Lead $LEAD_OBJECT_ID with score $NEW_SCORE..."
RESPONSE=$(curl -s -X PATCH "$API_BASE/LeadCollection('$LEAD_OBJECT_ID')" \
    -u "$SAP_USER:$SAP_PASS" \
    -H "Accept: application/json" \
    -H "Content-Type: application/json" \
    -H "X-CSRF-Token: $CSRF" \
    -H "Cookie: $COOKIES" \
    -d "{\"QualificationLevelCode\": \"$NEW_SCORE\"}" \
    -w "\nHTTP_CODE:%{http_code}")

HTTP_CODE=$(echo "$RESPONSE" | grep "HTTP_CODE:" | cut -d: -f2)

if [ "$HTTP_CODE" = "204" ]; then
    echo "SUCCESS! Lead score updated to $NEW_SCORE"
else
    echo "FAILED with HTTP $HTTP_CODE"
    echo "$RESPONSE" | grep -v "HTTP_CODE:"
fi
```

### 5.3 Find Lead ObjectID by Name

```bash
#!/bin/bash
# find_lead.sh - Find lead ObjectID by name
# Usage: ./find_lead.sh "Company Name"

SAP_DOMAIN="https://myXXXXXX.crm.ondemand.com"
SAP_USER="USERNAME"
SAP_PASS="PASSWORD"
API_BASE="$SAP_DOMAIN/sap/c4c/odata/v1/c4codataapi"

SEARCH_NAME="${1:?Usage: $0 <search_name>}"
ENCODED_NAME=$(echo "$SEARCH_NAME" | sed 's/ /%20/g')

curl -s "$API_BASE/LeadCollection?\$format=json&\$filter=substringof('$ENCODED_NAME',Name)&\$select=ObjectID,LeadID,Name,StatusCode,QualificationLevelCode" \
    -u "$SAP_USER:$SAP_PASS" \
    -H "Accept: application/json" | jq '.d.results[] | {ObjectID, LeadID, Name, Status: .StatusCode, Qualification: .QualificationLevelCode}'
```

---

## 6. Field Reference

### 6.1 Common Lead Fields

| Field | Description | Type |
|-------|-------------|------|
| `ObjectID` | Unique identifier (for API calls) | String |
| `LeadID` | Human-readable Lead ID | String |
| `Name` | Lead name/title | String |
| `Company` | Company name | String |
| `ContactFirstName` | Contact first name | String |
| `ContactLastName` | Contact last name | String |
| `StatusCode` | Lead status | Code |
| `QualificationLevelCode` | Lead qualification/score | Code |
| `PriorityCode` | Lead priority | Code |
| `OriginTypeCode` | Lead source/origin | Code |

### 6.2 QualificationLevelCode Values

| Code | Description |
|------|-------------|
| `1` | Hot |
| `2` | Warm |
| `3` | Cold |

### 6.3 StatusCode Values

| Code | Description |
|------|-------------|
| `1` | Open |
| `2` | Qualified |
| `3` | Accepted |
| `4` | Declined |

### 6.4 PriorityCode Values

| Code | Description |
|------|-------------|
| `1` | Immediate |
| `2` | High |
| `3` | Normal |
| `7` | Low |

---

## 7. Discover Available Fields

### 7.1 Get Lead Metadata

```bash
curl -s "https://myXXXXXX.crm.ondemand.com/sap/c4c/odata/v1/c4codataapi/\$metadata" \
    -u "USERNAME:PASSWORD" | grep -A 100 'EntityType Name="Lead"'
```

### 7.2 Check Updatable Fields

Fields with `sap:updatable="true"` can be modified via PATCH:

```bash
curl -s "https://myXXXXXX.crm.ondemand.com/sap/c4c/odata/v1/c4codataapi/\$metadata" \
    -u "USERNAME:PASSWORD" | \
    grep -A 100 'EntityType Name="Lead"' | \
    grep -E "(Property Name|sap:updatable)" | head -50
```

---

## 8. Response Codes

| HTTP Code | Description |
|-----------|-------------|
| `200 OK` | GET request successful |
| `201 Created` | POST request successful (new entity created) |
| `204 No Content` | PATCH/DELETE successful |
| `307 Redirect` | Missing trailing slash on base URL |
| `400 Bad Request` | Invalid property or value |
| `401 Unauthorized` | Authentication failed |
| `403 Forbidden` | Permission denied |
| `404 Not Found` | Entity not found |
| `428 Precondition Required` | If-Match header required |

---

## 9. Quick Reference

| Operation | Method | Endpoint | Auth Required |
|-----------|--------|----------|---------------|
| List Leads | GET | `/LeadCollection` | Basic Auth |
| Get Lead | GET | `/LeadCollection('ObjectID')` | Basic Auth |
| Count Leads | GET | `/LeadCollection/$count` | Basic Auth |
| Create Lead | POST | `/LeadCollection` | Basic Auth + CSRF |
| Update Lead | PATCH | `/LeadCollection('ObjectID')` | Basic Auth + CSRF |
| Delete Lead | DELETE | `/LeadCollection('ObjectID')` | Basic Auth + CSRF |

---

## Sources

- [SAP Cloud for Customer OData API Documentation](https://help.sap.com/docs/sap-cloud-for-customer/odata-services/sap-cloud-for-customer-odata-api)
- [SAP Business Accelerator Hub - C4C OData V2 APIs](https://api.sap.com/package/C4C/odata)
- [C4C Is Easy - OData API GET Requests](https://c4ciseasy.com/get-requests/)
- [C4C Is Easy - OData API PATCH Requests](https://c4ciseasy.com/patch-request-for-updating/)
- [SAP-archive/C4CODATAAPIDEVGUIDE](https://github.com/SAP-archive/C4CODATAAPIDEVGUIDE)
