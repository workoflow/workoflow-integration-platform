#!/bin/bash
# Helper script to create n8n agent JSON files from XML prompts

AGENT_TYPE=$1
XML_FILE=$2
OUTPUT_FILE=$3

# Read and escape XML content for JSON
XML_CONTENT=$(cat "$XML_FILE" | sed 's/\\/\\\\/g' | sed 's/"/\\"/g' | sed ':a;N;$!ba;s/\n/\\n/g')

# Generate UUIDs for nodes
UUID1=$(uuidgen | tr '[:upper:]' '[:lower:]')
UUID2=$(uuidgen | tr '[:upper:]' '[:lower:]')
UUID3=$(uuidgen | tr '[:upper:]' '[:lower:]')
UUID4=$(uuidgen | tr '[:upper:]' '[:lower:]')
UUID5=$(uuidgen | tr '[:upper:]' '[:lower:]')
UUID6=$(uuidgen | tr '[:upper:]' '[:lower:]')

# Create the JSON file
cat > "$OUTPUT_FILE" << EOF
{
  "nodes": [
    {
      "parameters": {
        "workflowInputs": {
          "values": [
            {
              "name": "userID",
              "type": "string"
            },
            {
              "name": "tenantID",
              "type": "string"
            },
            {
              "name": "locale",
              "type": "string"
            },
            {
              "name": "userPrompt",
              "type": "string"
            },
            {
              "name": "taskDescription",
              "type": "string"
            }
          ]
        }
      },
      "type": "n8n-nodes-base.executeWorkflowTrigger",
      "typeVersion": 1.1,
      "position": [-208, -128],
      "id": "${UUID1}",
      "name": "When Executed by Another Workflow"
    },
    {
      "parameters": {
        "promptType": "define",
        "text": "={{ \$json.taskDescription }}",
        "options": {
          "systemMessage": "${XML_CONTENT}",
          "maxIterations": 15
        }
      },
      "type": "@n8n/n8n-nodes-langchain.agent",
      "typeVersion": 2.2,
      "position": [32, -128],
      "id": "${UUID2}",
      "name": "AI Agent"
    },
    {
      "parameters": {
        "model": "gpt-4.1",
        "options": {
          "maxRetries": 2
        }
      },
      "type": "@n8n/n8n-nodes-langchain.lmChatAzureOpenAi",
      "typeVersion": 1,
      "position": [32, 48],
      "id": "${UUID3}",
      "name": "workoflow-proxy",
      "credentials": {
        "azureOpenAiApi": {
          "id": "cLd5WshSqySpWGKz",
          "name": "azure-open-ai-proxy"
        }
      }
    },
    {
      "parameters": {
        "toolDescription": "Always call this tool FIRST to understand what ${AGENT_TYPE^} tools are available for this user. This returns a dynamic list of tools based on the user's ${AGENT_TYPE^} integration configuration. The response will show you which tools you can execute via CURRENT_USER_EXECUTE_TOOL. If the tools array is empty, the user hasn't configured a ${AGENT_TYPE^} integration yet.",
        "url": "=https://subscribe-workflows.vcec.cloud/api/integrations/{{ \$('When Executed by Another Workflow').item.json.tenantID }}/?workflow_user_id={{ \$('When Executed by Another Workflow').item.json.userID }}&tool_type=${AGENT_TYPE}",
        "authentication": "genericCredentialType",
        "genericAuthType": "httpBasicAuth",
        "options": {}
      },
      "type": "n8n-nodes-base.httpRequestTool",
      "typeVersion": 4.2,
      "position": [384, 192],
      "id": "${UUID4}",
      "name": "CURRENT_USER_TOOLS",
      "credentials": {
        "httpBasicAuth": {
          "id": "6DYEkcLl6x47zCLE",
          "name": "Workoflow Integration Platform"
        }
      }
    },
    {
      "parameters": {
        "toolDescription": "Execute a ${AGENT_TYPE^} tool after discovering available tools with CURRENT_USER_TOOLS. You must specify the tool_id from the discovery response and provide all required parameters as strings. Analyze the tool's parameter schema from CURRENT_USER_TOOLS to know which parameters to include.",
        "method": "POST",
        "url": "=https://subscribe-workflows.vcec.cloud/api/integrations/{{ \$('When Executed by Another Workflow').item.json.tenantID }}/execute?workflow_user_id={{ \$('When Executed by Another Workflow').item.json.userID }}",
        "authentication": "genericCredentialType",
        "genericAuthType": "httpBasicAuth",
        "sendBody": true,
        "specifyBody": "json",
        "jsonBody": "={\\n  \\\"tool_id\\\": \\\"{{ \$fromAI('toolId', 'The exact tool_id from CURRENT_USER_TOOLS response', 'string') }}\\\",\\n  \\\"parameters\\\": {{ JSON.stringify(\$fromAI('parameters', 'Tool parameters as a JSON object. All values must be strings. Refer to the tool schema from CURRENT_USER_TOOLS.', 'json')) }}\\n}",
        "options": {}
      },
      "type": "n8n-nodes-base.httpRequestTool",
      "typeVersion": 4.2,
      "position": [560, 192],
      "id": "${UUID5}",
      "name": "CURRENT_USER_EXECUTE_TOOL",
      "credentials": {
        "httpBasicAuth": {
          "id": "6DYEkcLl6x47zCLE",
          "name": "Workoflow Integration Platform"
        }
      }
    },
    {
      "parameters": {
        "options": {}
      },
      "type": "n8n-nodes-base.respondToWebhook",
      "typeVersion": 1.4,
      "position": [384, -128],
      "id": "${UUID6}",
      "name": "Respond to Webhook"
    }
  ],
  "connections": {
    "When Executed by Another Workflow": {
      "main": [
        [
          {
            "node": "AI Agent",
            "type": "main",
            "index": 0
          }
        ]
      ]
    },
    "AI Agent": {
      "main": [
        [
          {
            "node": "Respond to Webhook",
            "type": "main",
            "index": 0
          }
        ]
      ]
    },
    "workoflow-proxy": {
      "ai_languageModel": [
        [
          {
            "node": "AI Agent",
            "type": "ai_languageModel",
            "index": 0
          }
        ]
      ]
    },
    "CURRENT_USER_TOOLS": {
      "ai_tool": [
        [
          {
            "node": "AI Agent",
            "type": "ai_tool",
            "index": 0
          }
        ]
      ]
    },
    "CURRENT_USER_EXECUTE_TOOL": {
      "ai_tool": [
        [
          {
            "node": "AI Agent",
            "type": "ai_tool",
            "index": 0
          }
        ]
      ]
    }
  },
  "pinData": {
    "When Executed by Another Workflow": [
      {
        "userID": "test-user-123",
        "tenantID": "0cd3a714-adc1-4540-bd08-7316a80b34f3",
        "locale": "de-DE",
        "userPrompt": "Test ${AGENT_TYPE} operation",
        "taskDescription": "Test task description for ${AGENT_TYPE} agent"
      }
    ]
  },
  "meta": {
    "instanceId": "c05075230b6e7924fad6850755c864f4945f3a365e0e5e0749c41c356f32c0bc"
  }
}
EOF

echo "Created $OUTPUT_FILE successfully"
