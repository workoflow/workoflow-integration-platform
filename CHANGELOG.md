# Changelog

All notable changes to this project will be documented in this file.

## 2025-10-30

### Changed
- Redesigned Skills page from card layout to table layout for better overview and information density
- Improved mobile responsiveness with adaptive table design that transforms into stacked cards on small screens
- Modernized architecture using component-based design (Twig Components, Stimulus controllers) for better maintainability
- Skills page now loads faster and provides better visual hierarchy for managing multiple integrations
- Simplified Skills page by removing search functionality - all integrations now displayed in a clean, organized table without search distractions
- Simplified Personalized Skills management - removed toggle switches from skills list, simply delete skills you no longer need
- Unified table design - both Personalized Skills and Platform Skills now use the same consistent column layout for easier navigation
- Platform Skills table now matches the look and feel of Personalized Skills with consistent columns: Instance Name, Type, Status, Last Accessed, and Actions
- "Manage Platform Skills" button moved to prominent header position with orange styling for better visibility

### Improved
- Cleaner action buttons layout in table format with consistent spacing and alignment
- Better use of screen space with condensed table view showing more integrations at once
- Streamlined interface reduces clutter while keeping all functionality easily accessible
- Jira and Confluence integration icons now display larger and clearer for better visibility
- Last accessed dates now appear more compact and fit better on screen without line breaks
- Delete button now has proper styling with consistent appearance across all integrations
- Test Connection modal now displays properly centered on screen instead of appearing at bottom-left
- Action buttons (Edit, Test Connection, Delete) now stay on the same line without wrapping for cleaner table layout
- Simpler workflow for managing skills - delete integrations you no longer use instead of toggling them on and off

### Fixed
- Notification bar positioning - status messages now correctly appear below the top navigation bar instead of covering it
- Test Connection modal positioning - connection test results now appear in the center of the screen as expected
- Integration icons for Jira and Confluence now show clearly at proper size without text overflow

### Changed (Earlier)
- Simplified notification messages - status messages now appear as a clean, full-width bar directly under the navigation instead of an animated overlay, making them less disruptive and easier to read
- Notifications now require manual dismissal - messages stay visible until you close them with the X button, ensuring you don't miss important information
- Improved skill management workflow - tool toggles for Personalized Skills moved to dedicated edit pages, allowing you to manage skill tools when editing credentials instead of from the skills list page
- Enhanced Platform Skills management - all platform tool settings now managed through a dedicated "Manage Tools" page, providing a centralized location to enable or disable built-in capabilities for your organization

## 2025-10-29

### Added
- Channel Type selection for organizations - choose between Common, eCommerce, or MS Teams to categorize your channel's purpose
- Microsoft Teams bot configuration - configure MS Teams bot integration with App Type (MultiTenant/SingleTenant), App ID, App Password, and Tenant ID
- Workflow URL field in Channel settings - separate field specifically for n8n workflow visualization, with clear placeholder examples showing the difference between webhook URLs and workflow URLs
- Help text explaining URL differences - tooltips now clarify that Webhook URLs are for AI agents to trigger workflows, while Workflow URLs are for viewing the workflow diagram
- Enlarge button for workflow visualization - users can now view workflows in a fullscreen modal (90% of screen size) for better visibility of complex workflows with many nodes
- Workflow User ID in Profile page - users can now easily view their personal workflow identifier in their profile

### Changed
- Enhanced security for sensitive credentials - N8N API keys and Microsoft Teams app passwords are now encrypted when stored, providing better protection for your integration credentials
- Footer GitHub links now point to the official Workoflow organization repositories
- Channel page now organized with "Channel Settings" section - basic channel configuration fields including Webhook URL are now grouped under a clear section headline for easier navigation
- Agent Type field renamed from "Webhook Type" - clearer naming that better reflects the purpose of selecting how your AI agent communicates
- Profile page now displays Workflow User ID - moved from Channel settings to personal profile for better organization
- Workflow visualization now shows complete n8n workflows - the Channel page displays all nodes and connections from your n8n workflow, giving you a full view of your automation
- Faster workflow preview loading - workflow diagrams now appear more quickly with improved performance
- Workflow visualization uses official n8n component - ensures compatibility with all n8n workflow features and updates
- Improved error messages for workflow visualization - more specific feedback when workflow URLs are misconfigured
- Workflow visualization now displays in dark mode - matches the application's dark theme for a consistent visual experience
- Larger workflow canvas - increased from 600px to 800px height for better visibility of complex workflows
- Cleaner workflow view - removed instructional text overlay for a more professional, streamlined appearance
- Workflow viewer supports full interaction - users can pan, zoom, and explore node details for better workflow understanding

### Fixed
- Production workflow URLs now work correctly - workflow visualization no longer incorrectly adds port numbers to production domain URLs, resolving "Idle timeout" errors on hosted environments
- Status messages now appear at the top of the page - success, error, and info messages are now displayed consistently right below the navigation bar across all pages
- Added close button to status messages - users can now dismiss messages by clicking the X button
- Status messages auto-dismiss after 5 seconds - messages automatically fade out for better user experience
- Improved status message styling - all message types (success, error, warning, info) now have consistent, modern styling with smooth animations
- Workflow visualization loading error resolved - fixed browser module resolution issue that prevented the N8N workflow viewer from rendering
- Workflow visualization now loads properly on all browsers - resolved "module specifier" error that appeared in browser console
- All workflow connections now visible - node connections in the workflow visualization are now displayed properly

## 2025-10-28

### Added
- Intelligent JIRA status transitions - users can now simply say "set ticket to Done" and the system automatically navigates through the entire workflow path
- JIRA workflow transition support - AI agents can now change ticket statuses following proper workflow paths
- Automatic workflow path finding - system intelligently determines required intermediate steps to reach target status
- Loop detection and prevention - safely handles complex workflows without getting stuck in infinite loops
- Status change capability with automatic workflow validation - prevents invalid status transitions that would normally fail
- Available transitions discovery - bots can ask which status changes are possible for any ticket
- End-to-end tests for JIRA integration - automated tests now validate real JIRA API connections and data retrieval
- JIRA ticket content validation in tests - ensures issue summaries, story points, and acceptance criteria are correctly retrieved

### Fixed
- Skills page header layout - the heading and explanation text now display properly without overlapping, with improved spacing and readability
- GitLab "Test Connection" button now works correctly - the connection test feature is now accessible and functional
- Improved GitLab connection test error messages - users now see detailed diagnostics when GitLab connection fails, including specific error reasons and helpful suggestions
- Fixed API URL in dashboard - the API integration URL now uses the correct endpoint path

## 2025-10-27

### Changed
- Renamed "Tools" to "Skills" - the feature for managing integrations is now called "Skills" throughout the interface for better clarity about AI agent capabilities
- Updated navigation menu from "Tools" to "Skills" - access your AI agent skills from the main navigation
- Skills page now includes explanation section - helpful information about personalized and platform skills appears at the top of the Skills page
- "System Tools" renamed to "Platform Skills" - built-in capabilities that don't require external authentication are now called Platform Skills
- "Add Personal Integration" renamed to "Add Personalized Skill" - clearer terminology when connecting external services with your credentials
- Skills page URL changed from /tools to /skills - bookmarks and links will need to be updated

### Fixed
- Test suite now properly validates English translations - all automated tests updated to work with the English interface

### Changed
- Renamed "Instructions" page to "Channel" - the navigation menu and page title now reflect the new name for better clarity

### Added
- Channel creation button on Channel page - administrators can now create multiple channels directly from the Channel page
- Multi-channel support for administrators - admin users can manage multiple separate channels with independent configurations
- Comprehensive Channel documentation - detailed guide explaining how channels work as API gateways for third-party integrations
- GitLab integration for source code management - AI agents can now access GitLab repositories, merge requests, issues, and CI/CD pipelines
- Browse GitLab projects - view all accessible projects or filter by membership
- Access repository content - read files, browse directory trees, and navigate branches and tags
- View commit history - see detailed commit information and track code changes over time
- Manage merge requests - search, view, create, update, and comment on merge requests with full diff support
- Work with issues - search, view, create, and update issues within projects
- Monitor CI/CD pipelines - check pipeline status, view execution details, and track deployment progress
- Access package registry - search and view packages published to GitLab's package registry
- Full API integration - all GitLab tools available through REST API for automation platforms
- Trello integration for project management - AI agents can now access and manage Trello boards, lists, and cards
- Search across all Trello boards and cards - AI agents can find specific cards using keywords or criteria
- View all accessible Trello boards - get a complete overview of available project boards
- Read board structure - see all lists and cards organized on any board
- Extract card details - access card titles, descriptions, members, labels, due dates, and attachments
- Read card comments - see all discussion threads on cards
- Access card checklists - view todo items and their completion status within cards
- Create new cards - AI agents can add tasks to Trello lists based on user requests
- Update existing cards - modify card details, move between lists, or change due dates
- Add comments to cards - AI agents can post updates and responses directly to Trello cards
- Full workflow automation support - all Trello tools available through REST API for n8n and other automation platforms

### Changed
- Channel name editing centralized to Instructions page - channel names can now only be changed from the Instructions page for better organization

## 2025-10-24

### Fixed
- Jira connection test now works correctly and no longer shows false permission errors when credentials are valid

### Improved
- Connection test error messages now provide detailed explanations instead of generic "Connection failed" messages
- Each error includes specific suggestions on how to fix the problem (e.g., "Check your API token", "Verify the URL is correct")
- Test connection results now show technical details about what went wrong (authentication failed, network timeout, wrong permissions, etc.)
- Error messages help troubleshoot integration issues faster with clear guidance
- Both Jira and Confluence integrations now provide comprehensive error feedback during connection testing

## 2025-10-25

### Fixed
- Connection test modal now displays proper titles instead of technical translation keys
- Icons now display correctly throughout the application (previously missing due to Font Awesome not being installed)

## 2025-10-24

### Fixed
- Confluence page creation now works correctly when using space keys (like "AT", "PROJ", "IT") - previously failed with "Space not found" errors
- Confluence API connections now work correctly - fixed incorrect API endpoint paths that caused "404 Not Found" errors
- All Confluence features (search, page retrieval, comments, page creation) now use correct API paths

### Improved
- Confluence URL input now provides instant validation feedback with helpful visual indicators
- Clear guidance on expected URL format prevents configuration errors (base URL without /wiki path)
- Real-time validation shows green checkmark for correct URLs and red error for invalid formats
- Validation now prevents users from entering URLs with /wiki path (which causes API errors)
- "Test Connection" button now performs comprehensive API testing for Confluence integrations
- Connection tests verify both v1 and v2 API endpoints to ensure all features work correctly
- Detailed error messages explain exactly what went wrong (authentication failed, wrong permissions, network issues, etc.)
- Each error includes specific suggestions on how to fix the problem (e.g., "Create a new API token at...")
- Credential validation during setup now performs actual API calls instead of just checking if fields are filled
- Test Connection results now display in a beautiful modal dialog instead of browser alerts
- Modal includes animated success/error icons with smooth transitions for better visual feedback
- Error messages are now easier to read with better formatting and color coding
- Modal can be closed by clicking outside, pressing ESC key, or clicking the close button

## 2025-10-23

### Fixed
- Tool toggle buttons on integration management page now work correctly - fixed incorrect API endpoint URLs

## 2025-10-21

### Added
- Language switcher in header - easily switch between German and English with flag indicators
- Tagline "EFFICIENCY IS YOURS" displayed under platform title for brand reinforcement

### Changed
- Default language changed from German to English for wider accessibility

### Fixed
- Instructions page workflow visualization no longer gets stuck on "Loading..." when N8N webhook URL is not configured - now shows helpful message to configure the webhook URL
- Non-admin users on Instructions page now see clear guidance to contact an administrator when workflow configuration is needed
- Language switching now works properly across all pages - selected language persists when navigating between pages
- All navigation menu items now properly translate when switching languages (Instructions, Profile, Members, Login, Logout)
- Instructions page content now fully translates including labels and section headings
- Test suite now works correctly after integration management URL structure update

## 2025-10-20 (Latest)

### Improved
- Workflow visualization now shows only essential nodes for clearer understanding - displays webhooks, AI agent with connected tools, and response nodes in an organized layout
- N8N workflow visualization automatically filters out intermediate processing nodes to focus on the main workflow structure
- Workflow nodes are now arranged vertically for better readability with tools grouped below the AI agent
- Docker compatibility for N8N API connections - workflow visualizations now work correctly when running in containerized environments

## 2025-10-20

### Added
- New Instructions page for managing AI agent configuration and workflow settings
- Agent personality customization through system prompts - define how your AI agent responds
- Webhook integration support for connecting external workflow systems (Common and N8N)
- Visual workflow viewer for N8N integrations - see your automation workflows at a glance
- N8N API key authentication for secure workflow data access
- Organization-wide agent settings management
- Workflow user ID display on Instructions page for easy reference

### Changed
- Button to add integrations now labeled "Persönliche Integration" to better distinguish user integrations from system tools
- Profile page layout improved to a cleaner 2-column grid design for better readability

### Fixed
- Workflow visualization on Instructions page now displays correctly - interactive controls are visible with proper dark theme styling
- User registration API now correctly uses the real email address from external systems (like Microsoft Teams) instead of generating placeholder emails
- Magic link authentication now correctly redirects to the general overview page after successful login
- Magic link authentication now correctly identifies users when their display name changes - prevents duplicate accounts and authentication failures

## 2025-10-18

### Added
- Jira integration can now add comments to issues - AI agents can post comments directly to Jira tickets

## 2025-10-17

### Added
- New command to export all integration tools to XML format - provides complete documentation of all available tools with descriptions and parameters
- Tool export command supports filtering by category (system tools or user integrations) for targeted documentation
- New channel system allowing users to belong to multiple channels for future feature expansion
- Channel association during user registration - external systems can now assign users to specific channels when creating accounts
- Automatic channel creation through the registration API - channels are created on-demand when users are registered
- Optional channel parameters in registration API for seamless channel assignment during onboarding
- New automated user registration API for seamless onboarding - external systems can now create users instantly via API call
- Registration API returns magic links for immediate user authentication - no waiting for users to click links first
- Users and organizations are now created immediately when registration API is called - improves integration with external workflow systems
- Comprehensive API documentation for the new registration endpoint with examples in multiple programming languages
- Comprehensive documentation about magic link authentication lifecycle and session management
- Confluence page creation capability for AI agents - can now create new pages directly in Confluence spaces
- Confluence page update capability for AI agents - can now update existing pages with new content while handling version conflicts automatically
- Support for multiple content formats when creating or updating Confluence pages - AI agents can use familiar markdown, plain text, or HTML instead of Confluence's native format
- Intelligent space identification for Confluence pages - accepts both human-readable space keys (like 'PROJ') and technical space IDs
- AI-friendly error messages for Confluence operations - provides clear guidance when page creation or update fails, helping AI agents correct and retry
- Automatic content format conversion - markdown and plain text are automatically converted to Confluence's storage format
- Version conflict detection and handling for page updates - automatically retrieves current version to prevent update conflicts
- MinIO service now included in automated testing workflows to prevent test failures

### Fixed
- Application startup error that prevented the platform from loading properly
- Authentication system configuration issue that blocked user login functionality
- Login page styling for Magic Link authentication instructions box
- Corrected magic link validity display from "15 minutes" to actual "24 hours" on login page
- Fixed incorrect "single-use" claim - magic links can be used multiple times within validity period
- Database field size for channel UUIDs increased to support Microsoft Teams channel IDs
- Removed deprecated database update option to prepare for future compatibility
- Fixed git repository permission warnings in Docker container environment
- Updated frontend dependencies to fix security vulnerability
- File storage service startup issues that prevented application from loading when MinIO was unavailable
- Automated test workflow now builds frontend assets before running tests

### Changed
- Login page now displays instructions for Magic Link authentication instead of Google login
- Users are directed to use Magic Links sent via Microsoft Teams or other connected chat applications
- Magic link authentication now supports both immediate registration (new API flow) and deferred registration (existing flow) for backward compatibility
- User creation logic centralized into reusable service for consistent behavior across registration methods
- Extended user session duration from default (~24 minutes) to 24 hours for better user experience
- Sessions now remain active for full 24 hours regardless of user activity
- **Simplified database management** - removed migration files in favor of automatic schema updates directly from entity definitions
- Database updates now use automatic schema synchronization for faster development workflow

### Improved
- Login page layout now uses wider design for better readability
- Magic Link authentication channels displayed side-by-side for cleaner presentation

## 2025-10-15

### Added
- Server-side validation for integration forms - prevents saving integrations with empty or duplicate names
- Clear error messages displayed when integration name is missing or already in use
- Form error messages now properly translated in both German and English

### Improved
- JIRA connection test now validates credentials with actual API calls - immediately detects invalid tokens or incorrect URLs
- Integration setup forms now preserve entered data when validation fails for easier corrections
- Editing integrations is now faster - changing only the name no longer requires re-validating credentials with external APIs
- URLs are automatically normalized when saving integrations - trailing slashes are removed for consistency
- Form validation errors now display with clear bullet points and proper spacing for better readability

## 2025-10-09

### Added
- SAP Commerce and Shopify added to the integration showcase on homepage - demonstrating platform's expanded e-commerce capabilities

### Fixed
- Jira search now uses the correct API v3 endpoint - resolves issue where search queries were failing with recent Jira Cloud instances

## 2025-10-08

### Fixed
- Integration management now properly isolated per user - users can no longer view or modify other users' integrations within the same organization
- Integration editing, deletion, and tool toggling now require user ownership verification

## 2025-09-25

### Added
- New Omnichannel AI Agent promotion section on landing page highlighting platform's ability to create channel-specific AI agents
- Visual hub-and-spoke diagram showing how the platform connects to multiple channels (Teams, E-Commerce, WhatsApp, etc.)
- Three key platform benefits prominently displayed: Channel-Specific Intelligence, Maximum Reusability, and Connected Ecosystem

### Fixed
- Fixed duplicate slashes in Jira and Confluence API URLs that caused 404 errors
- Jira and Confluence integrations now properly validate and format URLs before making API calls
- Added helpful error messages when integration URLs are incorrectly formatted
- Fixed file sharing tool that was failing when uploading files - now properly handles base64 encoded files
- File uploads now work correctly with PDF and other document formats
- Updated Confluence URL placeholder to include /wiki path for better user guidance

## 2025-09-24

### Improved
- SharePoint document reading now works with PDF, Word, and Excel files stored in SharePoint
- AI agents can now extract and understand text content from PDF documents, eliminating previous "cannot extract text" errors
- Excel spreadsheet content is now fully readable by AI agents, including all sheets and cell data
- Word document content extraction now works properly, allowing AI agents to analyze document text
- Documents that previously failed with "unsupported format" errors can now be processed successfully

### Fixed
- SharePoint document reading now correctly identifies and uses site information from search results
- AI agents can now successfully read SharePoint documents after finding them through search
- Improved site identification when reading documents stored in different SharePoint locations
- Personal OneDrive documents can now be accessed and read by AI agents - previously failed with "site not found" errors

## 2025-09-24

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
