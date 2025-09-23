# Changelog

All notable changes to this project will be documented in this file.

## 2025-09-24 (Latest)

### Added
- New document reading capability for SharePoint integration - AI agents can now extract and understand content from Word documents, Excel files, PowerPoint presentations, and PDFs
- Smart handling of large documents - automatically extracts the most relevant portions when files exceed size limits

### Changed
- Improved SharePoint tool descriptions to better guide AI agents on when and how to use each tool
- Search results now include content summaries, helping AI agents identify relevant documents without reading them fully
- Less frequently used SharePoint tools (file browsing, download links, technical list access) are now disabled by default for new integrations to streamline the AI agent experience

### Fixed
- SharePoint integration now properly supports content extraction from Office documents, enabling AI agents to answer questions about document contents rather than just finding files

## 2025-09-23

### Fixed
- Removed deprecated Integration entity system and migrated fully to IntegrationConfig
- Fixed SharePoint authentication by properly using the new IntegrationConfig system
- Resolved database relationship errors preventing user authentication

### Changed
- Consolidated integration management to use single IntegrationConfig system instead of dual Integration/IntegrationConfig
- Updated all commands and controllers to use the new IntegrationConfig repository

## 2025-09-19

### Changed
- Improved integration configuration to use organization-based workflow user IDs for better access control
- Integration configurations no longer store workflow user IDs directly - now properly linked through user organization relationships

### Fixed
- Integration status badge now correctly shows "Inactive" (yellow) when integration is disabled, instead of always showing "Connected" (green)
- Disabled integrations no longer provide tools through the API - all tools are hidden when integration is deactivated
- Fixed integration toggle to work with individual integration instances, not just integration types
- SharePoint tools now correctly appear in API responses when filtered by tool type
- Fixed tool toggle functionality for user integrations - now properly updates existing configurations instead of creating duplicates
- Tool enable/disable toggles now persist correctly and are properly filtered in API responses
- Fixed integration configuration creation to properly track the user who created each configuration
- Fixed workflow user filtering in the API to use the workflow_user_id from user organization relationships
- Fixed organization members page error by correcting database query associations
- Removed workflow user ID field from integration setup forms - now automatically uses the organization's assigned workflow user ID
- Restored SharePoint integration Microsoft authentication functionality
- Fixed edit and delete buttons to work with multiple integration instances

### Changed
- SharePoint and OAuth-based integrations now require immediate authentication - clicking save redirects directly to Microsoft login
- OAuth authentication is now mandatory for OAuth-based integrations - configurations cannot be saved without acquiring access token
- Improved OAuth cancellation handling - temporary configs are automatically cleaned up if user cancels authorization

## 2025-09-19

### Changed
- Reduced button sizes by 50% on the integrations page for a more compact interface
- System tools now grouped into a single full-width card for better organization
- System tools card spans the entire width for improved visibility and cleaner layout
- Integration cards now display user-defined names with badges on the next line for improved readability

## 2025-09-19

### Added
- 12 new AI agent system tools for enhanced automation capabilities:
  - Content learning from uploaded files (CSV, PDF, TXT)
  - Content search across platform data
  - File reading and processing
  - Employee search and profile lookup
  - PDF and PowerPoint generation
  - Web search and page reading
  - Company events management
  - Conversation memory management
  - Issue reporting system
- All system tools can be individually enabled or disabled per user

## 2025-09-19 (Earlier)

### Changed
- System integration "File Sharing" renamed to "Standard" for better clarity
- System badge positioning moved above integration name for improved visual hierarchy
- Reorganized integrations page layout - System Tools section now appears below External Integrations for better visual hierarchy

### Fixed
- API response filtering based on `tool_type` parameter now works correctly
  - `tool_type=jira` returns only Jira integration tools
  - `tool_type=confluence` returns only Confluence integration tools
  - `tool_type=sharepoint` returns only SharePoint integration tools
  - `tool_type=system` returns all system tools (file sharing, etc.)
  - `tool_type=system.share_file` returns specific system integration tools
  - No parameter returns all available tools (user integrations and system tools)
  - Enables precise tool selection for AI agents based on integration type

### Added
- Edit functionality for integrations allowing users to update names and credentials
- Table-style layout for integration tools providing better organization
- Icons for all action buttons (Edit, Test Connection, Delete)
- System Tools section showing all platform built-in tools (like file sharing)
- Enable/disable toggles for individual tools within each integration
- Visual separation between System Tools and External Integrations
- Automatic availability of new system tools without user login required
- Setup wizard for configuring external service credentials
- Support for multiple instances of the same integration (e.g., multiple Jira servers, SharePoint sites)
- Instance naming to identify different connections (e.g., "Production Jira", "Dev SharePoint")
- "Add Another Instance" button to easily connect additional services

### Changed
- Improved integration card layout with better visual structure and no overlapping elements
- Action buttons now contained within integration cards for cleaner interface
- Enhanced visual hierarchy with proper spacing between integration elements
- Complete redesign of integration management system
- All integrations now defined in code - instant availability of new features
- Simplified database structure - only stores user preferences and credentials
- System tools enabled by default for all users and organisations
- External integrations (Jira, Confluence) now have individual tool toggles
- Integration page now groups tools by type (System vs External)
- API now includes instance names in tool descriptions for clarity

### Removed
- Complex database relationships for integration management
- Need for manual database updates when adding new system tools

---

## 2025-09-19 (Earlier)

### Added
- Footer with legal information links (Legal Notice and Data Protection Declaration) available in both German and English
- Footer with links to Workoflow's open source repositories on GitHub (Hosting, Tests, Bot, Integration Platform)
- Global footer display across all pages, whether logged in or not
- System tools management for platform features - all users now have system integrations automatically
- Platform tools section in integration management page with distinct visual styling
- Per-user configuration for system tools - each user can enable/disable individual tools
- Plugin-based integration system for easier management
- Dynamic integration management UI - new integrations automatically appear in web interface
- Central integration registry for better organization

### Changed
- API authentication variables renamed from MCP_AUTH to API_AUTH for clarity
- System tools now excluded from API by default - use `tool_type=system` to include them
- Integrations now use flexible plugin architecture
- Web forms for adding integrations are now generated automatically
- System integrations cannot be deleted, only individual tools can be toggled

### Fixed
- Improved styling for OAuth authentication sections in integration forms to match dark theme
- Fixed visual consistency issues with credential input areas for SharePoint and other OAuth-based integrations
- Integration creation dropdown now properly displays available integration types (Jira, Confluence, SharePoint)
- Fixed service configuration to correctly register and tag integration services

### Improved
- Better separation between platform tools and external service integrations
- Clearer documentation about tool types and authentication requirements
- More maintainable code structure following design principles

## 2025-09-18

### Added
- Enhanced "How It Works" section with comprehensive enterprise-focused explanations
- Channel-based architecture explanation for isolated business areas
- Real-world examples showcasing customer chatbot, admin backend, and internal team use cases
- Enterprise benefits highlighting data sovereignty and precision control
- Visual improvements with color-coded example cards and gradient backgrounds

### Added
- Coming soon landing page with redesigned user interface
- Email waitlist functionality for early access signups
- Modern navigation bar with smooth scrolling
- Showcase of upcoming integrations (Teams, Shopware, Spryker, Magento, WhatsApp)
- Interactive waitlist signup form with real-time feedback
- Multi-tenant support for SharePoint integration - any Microsoft organization can now use the platform

### Changed
- SharePoint OAuth configuration now uses multi-tenant endpoints allowing access from any organization
- SharePoint integration automatically extracts and stores tenant ID from access tokens
- Added consent prompt option for SharePoint connection to force re-authorization when needed

## 2025-09-18

### Added
- Workflow user ID management moved to user-organization level for better multi-tenant support
- Copy curl command button on dashboard for easy API testing
- Direct workflow user ID display on dashboard for quick reference
- Link styling in integration function descriptions for better navigation
- Tool type filtering for API endpoints - can now filter tools by type (jira, confluence, sharepoint, system)

### Changed
- Workflow user IDs are now managed per user-organization relationship instead of per integration
- Enhanced dashboard UI with improved workflow user ID visibility

### Fixed
- FrankenPHP runtime configuration for proper Symfony integration

## 2025-09-09

### Fixed
- SharePoint page search now uses Microsoft Graph Search API for better results
- SharePoint page reading now accepts page names/titles in addition to GUIDs - automatically resolves to correct page ID
- Added detailed logging to help diagnose search issues

### Added
- Enhanced SharePoint search to include SharePoint pages and site pages
- New dedicated SharePoint pages search function for targeted page searches
- Automatic synchronization of integration functions when editing existing integrations
- Inline organization name editing for administrators directly on dashboard
- Organization filtering for integrations list and dashboard views
- Automatic SharePoint site name resolution when accessing pages - system now accepts site names in addition to site IDs
- SharePoint page content extraction - AI agents can now read the actual content of SharePoint pages, not just metadata

### Improved
- SharePoint document search now searches within site-specific content
- Search results now include page metadata and site information
- SharePoint integration error messages now provide detailed information for troubleshooting
- SharePoint page access is now more flexible, accepting both site names and technical site IDs
- SharePoint page reading now includes the actual page content (up to 1000 characters) for AI processing

### Fixed
- API integration validation now correctly checks the integration's assigned organization
- Integrations now properly filtered by selected organization
- Dashboard now only shows integrations for the current organization
- Resolved system deprecation warnings for improved compatibility with future versions
- SharePoint page reading now works correctly with standard Microsoft Graph API
- SharePoint file operations now include error handling for better reliability
- SharePoint pages can now be accessed using site display names instead of requiring technical site IDs

## 2025-09-08

### Removed
- Email functionality and mail server requirements - application no longer sends emails
- MailHog development mail catcher service

### Added
- Unified deployment script for production environments (`./deploy.sh prod`)
- Health check endpoint for monitoring application status
- SharePoint integration with OAuth2 authentication
- Document search and file management for SharePoint libraries
- SharePoint page reading and list item access
- Automatic token refresh for SharePoint connections
- Magic link authentication for automatic user login from external applications
- Display saved integration credentials in edit form for easier updates  
- Documentation translated to English for international users
- Automatic user creation and organization assignment via secure links
- Support for Microsoft Teams bot integration through signed URLs
- Support for multiple organizations per user with many-to-many relationship
- Name-based authentication without requiring email addresses
- Organization switcher dropdown in header for users with multiple organizations
- Session-based organization selection that persists across page navigation
- Automated nightly releases with GitHub Actions workflow

### Fixed
- File viewing and uploading functionality restored
- Integration credentials now visible when editing for verification
- Missing translations for SharePoint OAuth authentication interface

### Changed
- API token fields changed from password to text input for better usability

## 2025-07-09

### Added
- Getting started guide for new users

## 2025-07-07

### Added
- Static assets for improved user interface
- Configurable MinIO public bucket name through environment variables

### Changed
- MinIO bucket configuration now uses environment variables for flexibility

## 2025-07-03

### Added
- Initial release of Workoflow Integration Platform
- OAuth2 Google authentication for secure user access
- Multi-tenant organization management
- Integration management for Jira and Confluence
- REST API for AI agent access
- File management with MinIO S3 storage
- Comprehensive audit logging
- Multi-language support (German and English)
- Automated setup scripts for development and production
- Docker containerization for easy deployment

### Fixed
- Production Docker build with multi-stage asset compilation
- OAuth redirect URI configuration
- HTTPS redirect URLs with trusted proxy support
- MinIO port configuration for better compatibility
- File sharing MIME type detection

### Changed
- Simplified OAuth configuration by removing unused redirect URI parameter
- Optimized production deployment with proper volume mounts
- Improved build process with integrated npm asset building

### Security
- JWT token authentication for API access
- Encrypted credential storage using Sodium encryption
- Basic authentication for REST API endpoints
