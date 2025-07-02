# Share File Tool Documentation

## Overview
The `share_file` tool is a system-level tool available in the Workoflow Integration Platform that allows AI agents to upload and share files. The tool accepts base64-encoded binary data and returns a publicly accessible URL.

## Features
- Upload any file type via base64 encoding
- Automatic MIME type handling
- Public URL generation for easy sharing
- 1-day automatic expiration for shared files
- No authentication required for file access

## API Usage

### Tool Discovery
The `share_file` tool appears in the tools list endpoint:

```
GET /api/integration/{orgUuid}/tools?id={workflowUserId}
Authorization: Basic d29ya29mbG93Ondvcmtvd2xvdw==
```

### Tool Execution
```
POST /api/integration/{orgUuid}/execute?id={workflowUserId}
Authorization: Basic d29ya29mbG93Ondvcmtvd2xvdw==
Content-Type: application/json

{
    "tool_id": "share_file",
    "parameters": {
        "binaryData": "base64_encoded_content",
        "contentType": "application/pdf"
    }
}
```

### Response
```json
{
    "success": true,
    "result": {
        "url": "http://localhost:3979/{orgUuid}/file/{fileId}",
        "contentType": "application/pdf",
        "fileId": "unique-file-id",
        "expiresAt": "2025-07-03 17:00:00"
    }
}
```

## Supported Content Types
- `application/pdf` - PDF documents
- `application/vnd.openxmlformats-officedocument.wordprocessingml.document` - Word documents
- `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` - Excel spreadsheets
- `text/csv` - CSV files
- `text/plain` - Plain text files
- `application/json` - JSON files
- `image/jpeg`, `image/png`, `image/gif`, `image/bmp` - Images
- `application/zip` - ZIP archives
- And more...

## File Access
- Files are accessible without authentication
- URLs follow the pattern: `/{orgUuid}/file/{fileId}`
- Files are automatically deleted after 1 day
- Files are served with appropriate Content-Type headers

## Implementation Details

### Architecture
1. **ShareFileService** - Handles file upload to MinIO
2. **IntegrationApiController** - Exposes the tool via API
3. **SharedFileController** - Serves files via public URLs
4. **MinIO Storage** - S3-compatible object storage with lifecycle policies

### Security
- Files are stored in a dedicated public bucket
- Each file has a unique UUID
- Files are organized by organization UUID
- 1-day lifecycle policy ensures automatic cleanup

### Storage Configuration
- Bucket: `workoflow-shared`
- Public read access enabled
- Lifecycle policy: 1-day expiration
- Path structure: `{orgUuid}/{fileId}.{extension}`

## Example Usage

### Python Example
```python
import requests
import base64

# Read file
with open('document.pdf', 'rb') as f:
    content = base64.b64encode(f.read()).decode()

# Share file
response = requests.post(
    'http://localhost:3979/api/integration/your-org-uuid/execute?id=your-workflow-id',
    headers={
        'Authorization': 'Basic d29ya29mbG93Ondvcmtvd2xvdw==',
        'Content-Type': 'application/json'
    },
    json={
        'tool_id': 'share_file',
        'parameters': {
            'binaryData': content,
            'contentType': 'application/pdf'
        }
    }
)

result = response.json()
print(f"File URL: {result['result']['url']}")
```

### Use Cases
1. **Document Sharing** - Share PDFs, Word docs, spreadsheets
2. **Image Sharing** - Share screenshots, diagrams, photos
3. **Data Export** - Share CSV files, JSON data
4. **Report Generation** - Generate and share reports
5. **Temporary File Exchange** - Share files between systems

## Notes
- Files expire after 1 day and are automatically deleted
- Maximum file size is limited by PHP and web server settings
- Base64 encoding increases data size by ~33%
- The public URL can be shared with any external system