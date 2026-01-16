# SAP C4C OAuth2 User Delegation

## Goal

Enable AI Agent actions in SAP C4C to be attributed to **individual users** (e.g., "Patrick Schoenfeld created this Lead") instead of a generic technical user.

## Problem Statement

**Current Basic Auth approach:**
- Uses technical user credentials (username/password)
- All actions attributed to the technical user
- Doesn't work with SSO-enabled production systems

**Desired OAuth2 User Delegation:**
- Actions attributed to the actual user
- Works with Azure AD SSO
- Each user authenticates once, then AI Agent acts on their behalf

---

## Authentication Flow

### High-Level Overview

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ SETUP (One-time per user)                                                   │
├─────────────────────────────────────────────────────────────────────────────┤
│ 1. Admin enters: Base URL, App ID URI, OAuth Client ID/Secret              │
│ 2. User clicks "Connect with Azure AD"                                      │
│ 3. Redirect → login.microsoftonline.com/{customer-tenant}                  │
│ 4. User logs in with corporate credentials                                  │
│ 5. We receive refresh_token → store encrypted                               │
├─────────────────────────────────────────────────────────────────────────────┤
│ API CALL (Every request)                                                    │
├─────────────────────────────────────────────────────────────────────────────┤
│ 1. refresh_token → Azure AD → access_token                                  │
│ 2. access_token → Azure AD OBO → SAML2 assertion                           │
│ 3. SAML2 → SAP C4C OAuth endpoint → C4C access_token                       │
│ 4. C4C access_token → SAP C4C API → Action attributed to user              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Detailed Token Exchange Flow

```
User's Azure AD         Our Workoflow App           SAP C4C
     │                        │                        │
     │  1. OAuth Authorize    │                        │
     │<───────────────────────│                        │
     │                        │                        │
     │  2. User authenticates │                        │
     │  (same as SAP C4C SSO) │                        │
     │───────────────────────>│                        │
     │                        │                        │
     │  3. Authorization code │                        │
     │───────────────────────>│                        │
     │                        │                        │
     │  4. Exchange for       │                        │
     │     tokens             │                        │
     │<───────────────────────│                        │
     │  (access + refresh)    │                        │
     │                        │                        │
     │  5. OBO: Get SAML2     │                        │
     │<───────────────────────│                        │
     │───────────────────────>│                        │
     │  (SAML2 assertion)     │                        │
     │                        │                        │
     │                        │  6. SAML2 → C4C token  │
     │                        │───────────────────────>│
     │                        │<───────────────────────│
     │                        │  (C4C access_token)    │
     │                        │                        │
     │                        │  7. API call with      │
     │                        │     Bearer token       │
     │                        │───────────────────────>│
     │                        │  Action = User Context │
```

---

## Implementation Status

### What's Implemented

| Component | Status | File |
|-----------|--------|------|
| OAuth2 credential fields in UI | ✅ Done | `SapC4cIntegration.php` |
| Conditional field visibility | ✅ Done | `setup.html.twig` |
| AzureOboTokenService | ✅ Created | `src/Service/Integration/AzureOboTokenService.php` |
| SapC4cOAuthService | ✅ Created | `src/Service/Integration/SapC4cOAuthService.php` |
| SapC4cService OAuth support | ✅ Done | `src/Service/Integration/SapC4cService.php` |
| Setup instructions UI | ✅ Done | `SapC4cIntegration.php` |

### What's Missing

| Component | Status | Details |
|-----------|--------|---------|
| "Connect with Azure AD" button | ❌ Missing | Need oauth field type for OAuth2 mode |
| OAuth start route | ❌ Missing | `/integrations/oauth/sap-c4c/start/{configId}` |
| OAuth callback route | ❌ Missing | `/integrations/oauth/callback/sap-c4c` |
| App ID URI field | ❌ Missing | Need to collect SAP C4C's Azure AD resource identifier |
| Token exchange at API time | ❌ Missing | Connect services to actual API calls |
| Token caching | ❌ Missing | Cache C4C tokens to avoid repeated exchanges |

---

## Configuration Fields

### Basic Auth Mode (Current)

| Field | Required | Description |
|-------|----------|-------------|
| SAP C4C Base URL | Yes | `https://myXXXXXX.crm.ondemand.com` |
| Username | Yes | Technical user ID |
| Password | Yes | Technical user password |

### OAuth2 Mode (Planned)

| Field | Required | Description |
|-------|----------|-------------|
| SAP C4C Base URL | Yes | `https://myXXXXXX.crm.ondemand.com` |
| SAP C4C Azure App ID URI | Yes | Resource identifier in Azure AD (e.g., `https://myXXXXXX.crm.ondemand.com` or `api://sap-c4c-xxx`) |
| SAP C4C OAuth Client ID | Yes | From SAP C4C Admin → OAuth 2.0 Client Registration |
| SAP C4C OAuth Client Secret | Yes | From SAP C4C Admin |
| Connect with Azure AD | Yes | OAuth button to authenticate user |

---

## Customer Prerequisites

### Azure AD Configuration (Customer's Tenant)

1. **Grant admin consent for Workoflow app**
   - App ID: `16041668-fbae-47d4-829b-75e64ced4ff6`
   - Go to: Azure Portal → Enterprise Applications → Search for App ID → Grant admin consent

2. **Verify SAP C4C Enterprise Application**
   - SAP C4C should be registered as an Enterprise Application
   - Note the **App ID URI** (needed for OAuth scope)
   - Example: `https://myXXXXXX.crm.ondemand.com` or `api://sap-c4c-guid`

3. **Configure API Permissions (if needed)**
   - Workoflow app may need delegated permissions to access SAP C4C resource
   - This depends on customer's Azure AD configuration

### SAP C4C Configuration

1. **Configure Azure AD as Identity Provider**
   - Location: Administrator → Common Tasks → Configure Single Sign-On
   - Upload Azure AD Federation Metadata XML
   - Map Azure AD user attributes to C4C login names

2. **Register OAuth Client for API Access**
   - Location: Administrator → OAuth 2.0 Client Registration
   - Create new client (e.g., `WORKOFLOW_API`)
   - Set scope: `UIWC:CC_HOME`
   - Token lifetime: 3600 seconds (recommended)
   - Note the Client ID and Client Secret

3. **Provision Users**
   - Each user who uses the AI Agent **must exist as a Business User** in SAP C4C
   - Email address must match between Azure AD and SAP C4C
   - Without this, API calls will fail with "No business partner found"

---

## Implementation Plan

### Phase 1: Add App ID URI Field

Update `SapC4cIntegration.php` to collect the Azure AD App ID URI:

```php
new CredentialField(
    'azure_app_id_uri',
    'text',
    'SAP C4C Azure App ID URI',
    'https://myXXXXXX.crm.ondemand.com',
    false,
    'The App ID URI of SAP C4C in your Azure AD (found in Enterprise Applications)',
    null,
    'auth_mode',
    'oauth2'
),
```

### Phase 2: Add OAuth Button

For OAuth2 mode, show a "Connect with Azure AD" button instead of (or in addition to) the credential fields:

```php
new CredentialField(
    'oauth',
    'oauth',
    'Connect with Azure AD',
    null,
    false,
    'Authenticate with your corporate Azure AD account',
    null,
    'auth_mode',
    'oauth2'
),
```

### Phase 3: Add OAuth Routes

Add to `IntegrationOAuthController.php`:

```php
#[Route('/sap-c4c/start/{configId}', name: 'app_tool_oauth_sap_c4c_start')]
public function sapC4cStart(int $configId, Request $request, ClientRegistry $clientRegistry): RedirectResponse
{
    // Get config and validate
    $config = $this->getConfig($configId);
    $credentials = $this->getCredentials($config);

    // Store config ID in session
    $request->getSession()->set('sap_c4c_oauth_config_id', $configId);

    // Redirect to Azure AD with SAP C4C scope
    $appIdUri = $credentials['azure_app_id_uri'];
    return $clientRegistry
        ->getClient('azure')
        ->redirect([
            'openid',
            'offline_access',
            "{$appIdUri}/.default"  // SAP C4C resource scope
        ], []);
}

#[Route('/callback/sap-c4c', name: 'app_tool_oauth_sap_c4c_callback')]
public function sapC4cCallback(Request $request, ClientRegistry $clientRegistry): Response
{
    // Similar to SharePoint callback
    // Store access_token and refresh_token
}
```

### Phase 4: Token Exchange at API Time

Update `SapC4cIntegration.php` `executeTool()` method:

```php
if ($this->sapC4cService->isOAuth2Mode($credentials)) {
    // 1. Refresh Azure AD token if needed
    $azureToken = $this->refreshAzureToken($credentials);

    // 2. OBO: Exchange for SAML2
    $saml2 = $this->azureOboService->exchangeJwtForSaml2(
        $azureToken,
        $credentials['tenant_id'],
        $_ENV['AZURE_CLIENT_ID'],
        $_ENV['AZURE_CLIENT_SECRET'],
        $credentials['azure_app_id_uri']
    );

    // 3. Exchange SAML2 for C4C token
    $c4cToken = $this->sapC4cOAuthService->exchangeSaml2ForC4cToken(
        $saml2,
        $credentials['base_url'],
        $credentials['c4c_oauth_client_id'],
        $credentials['c4c_oauth_client_secret']
    );

    // 4. Add C4C token to credentials for API call
    $credentials['access_token'] = $c4cToken['access_token'];
}
```

### Phase 5: Token Caching

Implement caching to avoid repeated token exchanges:

```php
// Cache key: user_id + integration_id
$cacheKey = "c4c_oauth_{$userId}_{$integrationId}";

// Check cache (valid for 55 min, tokens expire at 60 min)
$cachedToken = $this->cache->get($cacheKey);
if ($cachedToken && $cachedToken['expires_at'] > time() + 300) {
    return $cachedToken;
}

// Exchange tokens and cache
$token = $this->exchangeTokens($credentials);
$this->cache->set($cacheKey, $token, 3300); // 55 min TTL
```

---

## Workoflow Azure AD App

We use the existing multi-tenant Azure AD app registration:

```env
AZURE_CLIENT_ID="16041668-fbae-47d4-829b-75e64ced4ff6"
AZURE_CLIENT_SECRET="ln18Q~..."
AZURE_TENANT_ID="common"  # Multi-tenant
AZURE_REDIRECT_URL="https://subscribe-workflows.vcec.cloud/api/oauth/callback/microsoft"
```

This app is already used for SharePoint integration and can be reused for SAP C4C.

---

## Comparison: Basic Auth vs OAuth2

| Aspect | Basic Auth | OAuth2 User Delegation |
|--------|------------|------------------------|
| Setup complexity | Simple | Complex |
| User attribution | Technical user only | Individual users |
| SSO compatibility | No (needs password) | Yes |
| Token management | None | Refresh tokens, caching |
| Customer config | Username/password | Azure AD + SAP C4C OAuth |
| Recommended for | Test/dev systems | Production SSO systems |

---

## References

- [Azure AD On-Behalf-Of Flow](https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-on-behalf-of-flow)
- [SAP OAuth SAML Bearer Assertion](https://community.sap.com/t5/technology-blog-posts-by-members/configuring-oauth-2-0-between-sap-c4c-and-sap-btp/ba-p/13894132)
- [RFC 7522: SAML 2.0 Bearer Assertion](https://datatracker.ietf.org/doc/html/rfc7522)
- [SAP C4C OData API](https://help.sap.com/docs/sap-cloud-for-customer/odata-services/sap-cloud-for-customer-odata-api)

---

## Next Steps

1. **Validate with customer**: Test the OBO flow manually with a customer's Azure AD tenant
2. **Implement Phase 1-2**: Add App ID URI field and OAuth button
3. **Implement Phase 3**: Add OAuth routes
4. **Implement Phase 4-5**: Token exchange and caching
5. **Test end-to-end**: Full flow with real SAP C4C instance
