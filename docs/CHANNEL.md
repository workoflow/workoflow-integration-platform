# User to Channel Feature Documentation

## Overview

The "User to Channel" feature enables users to be associated with multiple channels within the Workoflow Integration Platform. This feature provides the foundation for future channel-based functionality, such as channel-specific integrations, permissions, or messaging capabilities.

## Purpose

This feature allows:
- Users to belong to multiple channels
- Channels to contain multiple users (many-to-many relationship)
- Automatic channel creation and association during user registration via API
- Foundation for future features that require channel-based organization

## Database Schema

### Channel Table

The `channel` table stores channel information:

| Column | Type | Description |
|--------|------|-------------|
| `id` | INT (Primary Key) | Auto-incrementing unique identifier |
| `uuid` | CHAR(36) | Unique UUID for the channel |
| `name` | VARCHAR(255) | Display name of the channel |
| `created_at` | DATETIME | Timestamp when the channel was created |
| `updated_at` | DATETIME | Timestamp of last update |

### UserChannel Table

The `user_channel` table manages the many-to-many relationship between users and channels:

| Column | Type | Description |
|--------|------|-------------|
| `id` | INT (Primary Key) | Auto-incrementing unique identifier |
| `user_id` | INT (Foreign Key) | Reference to user table |
| `channel_id` | INT (Foreign Key) | Reference to channel table |
| `joined_at` | DATETIME | Timestamp when user joined the channel |

**Unique Constraint**: The combination of `user_id` and `channel_id` must be unique.

## Entity Relationships

```
User (1) ----< (N) UserChannel (N) >---- (1) Channel

- One User can have many UserChannel relations
- One Channel can have many UserChannel relations
- UserChannel serves as the join table
```

## API Integration

### Registration API Enhancement

The `/api/register` endpoint has been enhanced with optional channel parameters:

#### Request Parameters

```json
{
    "name": "John Doe",
    "org_uuid": "550e8400-e29b-41d4-a716-446655440000",
    "workflow_user_id": "WF-USER-12345",
    "org_name": "Acme Corporation",
    "channel_uuid": "channel-123e4567-e89b-12d3-a456-426614174000",  // Optional
    "channel_name": "General Channel"  // Optional
}
```

#### Channel Creation Logic

1. **If both `channel_uuid` and `channel_name` are provided**:
   - System searches for existing channel with the UUID
   - If found: User is added to existing channel
   - If not found: New channel created with provided UUID and name

2. **If only `channel_uuid` is provided**:
   - System searches for existing channel with the UUID
   - If found: User is added to existing channel
   - If not found: New channel created with UUID and auto-generated name

3. **If only `channel_name` is provided**:
   - New channel created with auto-generated UUID and provided name
   - User is added to the new channel

4. **If neither is provided**:
   - No channel association is created
   - User registration proceeds normally

#### Response Format

When a channel is created or associated, the response includes channel information:

```json
{
    "success": true,
    "magic_link": "https://...",
    "user_id": 123,
    "email": "john.doe@local.local",
    "organisation": {
        "id": 456,
        "uuid": "550e8400-e29b-41d4-a716-446655440000",
        "name": "Acme Corporation"
    },
    "channel": {
        "id": 789,
        "uuid": "channel-123e4567-e89b-12d3-a456-426614174000",
        "name": "General Channel"
    }
}
```

## Bot Integration

The Microsoft Teams bot (`workoflow-bot`) has been updated to support channel parameters when creating magic links:

```javascript
const config = {
    baseUrl: 'https://app.workoflow.com',
    apiUser: 'api_user',
    apiPassword: 'api_password',
    orgName: 'Example Org',
    channelUuid: 'channel-uuid-here',  // Optional
    channelName: 'Team Channel'        // Optional
};

const result = await registerUserAndGetMagicLink(
    userName,
    orgUuid,
    workflowUserId,
    config
);
```

## Use Cases

### Current Implementation

1. **Automatic Channel Assignment**: When a bot registers a user, it can immediately assign them to a specific channel based on context (e.g., which Teams channel triggered the bot).

2. **Channel-based Organization**: Users can be grouped into channels for organizational purposes.

3. **Audit Trail**: All channel associations are logged for audit purposes.

### Future Possibilities

This feature provides the foundation for:

1. **Channel-specific Integrations**: Different channels could have different sets of enabled integrations.

2. **Channel-based Permissions**: Access control could be managed at the channel level.

3. **Channel Messaging**: Internal messaging or notifications could be scoped to channels.

4. **Channel-specific Workflows**: Automated workflows could be triggered based on channel membership.

5. **Analytics and Reporting**: Usage statistics and reports could be grouped by channel.

## Service Methods

The `UserRegistrationService` provides the following methods for channel management:

### createOrFindChannel()
```php
public function createOrFindChannel(?string $channelUuid = null, ?string $channelName = null): ?Channel
```
- Creates a new channel or finds an existing one
- Returns null if no channel data provided
- Auto-generates UUID if not provided
- Auto-generates name if not provided

### addUserToChannel()
```php
public function addUserToChannel(User $user, Channel $channel): void
```
- Adds a user to a channel if not already a member
- Creates UserChannel relationship
- Logs the association for audit purposes
- Idempotent operation (safe to call multiple times)

## Entity Methods

### User Entity
- `getUserChannels()`: Get all UserChannel relationships
- `getChannels()`: Get all channels the user belongs to
- `addUserChannel()`: Add a UserChannel relationship
- `removeUserChannel()`: Remove a UserChannel relationship

### Channel Entity
- `getUserChannels()`: Get all UserChannel relationships
- `getUsers()`: Get all users in the channel
- `addUserChannel()`: Add a UserChannel relationship
- `removeUserChannel()`: Remove a UserChannel relationship

## Database Schema

The database schema is automatically managed through Doctrine entities and creates:
1. The `channel` table with appropriate indexes
2. The `user_channel` junction table
3. Foreign key constraints to maintain referential integrity
4. Unique constraint on (user_id, channel_id) to prevent duplicates

Tables are created/updated using: `doctrine:schema:update --force --complete`

## Security Considerations

1. **UUID Validation**: Channel UUIDs should be validated to ensure proper format
2. **Authorization**: Future implementations should verify user has permission to join channels
3. **Audit Logging**: All channel associations are logged for security audit
4. **Data Integrity**: Foreign key constraints ensure referential integrity

## Best Practices

1. **Always use UUIDs**: For external references, always use the channel UUID rather than the internal ID
2. **Idempotent Operations**: All channel operations are idempotent (safe to retry)
3. **Logging**: All channel operations are logged for debugging and audit purposes
4. **Error Handling**: Channel creation/association failures don't block user registration

## Testing

To test the channel feature:

1. **Create user with new channel**:
```bash
curl -X POST http://localhost:3979/api/register \
  -H "Content-Type: application/json" \
  -H "Authorization: Basic ..." \
  -d '{
    "name": "Test User",
    "org_uuid": "test-org-uuid",
    "workflow_user_id": "TEST-001",
    "channel_name": "Test Channel"
  }'
```

2. **Add user to existing channel**:
```bash
curl -X POST http://localhost:3979/api/register \
  -H "Content-Type: application/json" \
  -H "Authorization: Basic ..." \
  -d '{
    "name": "Another User",
    "org_uuid": "test-org-uuid",
    "workflow_user_id": "TEST-002",
    "channel_uuid": "channel-123e4567-e89b-12d3-a456-426614174000"
  }'
```

3. **Verify in database**:
```sql
-- Check channels
SELECT * FROM channel;

-- Check user-channel relationships
SELECT u.email, c.name as channel_name, uc.joined_at
FROM user_channel uc
JOIN user u ON uc.user_id = u.id
JOIN channel c ON uc.channel_id = c.id;
```

## Troubleshooting

### Common Issues

1. **Channel not created**: Check that either channel_uuid or channel_name is provided
2. **User not added to channel**: Verify user was created successfully first
3. **Duplicate channel UUID**: UUIDs must be unique across all channels

### Debugging

Check logs for channel-related operations:
```bash
docker-compose logs frankenphp | grep -i channel
```

## Future Enhancements

Planned improvements for this feature:
1. Channel management UI in the admin panel
2. Bulk channel operations (add/remove multiple users)
3. Channel hierarchies (parent/child channels)
4. Channel-specific settings and configurations
5. RESTful API for channel CRUD operations
6. Channel invitation system
7. Channel activity feeds