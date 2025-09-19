# Changelog

All notable changes to this project will be documented in this file.

## 2025-09-19

### Fixed
- Improved styling for OAuth authentication sections in integration forms to match dark theme
- Fixed visual consistency issues with credential input areas for SharePoint and other OAuth-based integrations

### Added
- Plugin-based integration system for easier management
- System tools filtering - platform-internal tools now excluded from API by default
- Dynamic integration management UI - new integrations automatically appear in web interface
- Central integration registry for better organization

### Changed
- Integrations now use flexible plugin architecture
- Web forms for adding integrations are now generated automatically
- API tools can be filtered by type - use `tool_type=system` to include internal tools

### Fixed
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
