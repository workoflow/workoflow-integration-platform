<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\AuditLogService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[Route('/instructions')]
#[IsGranted('ROLE_USER')]
class InstructionsController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AuditLogService $auditLogService,
        private HttpClientInterface $httpClient
    ) {
    }

    #[Route('', name: 'app_instructions')]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        if (!$organisation) {
            return $this->redirectToRoute('app_organisation_create');
        }

        $userOrganisation = $user->getCurrentUserOrganisation($sessionOrgId);

        return $this->render('instructions/index.html.twig', [
            'organisation' => $organisation,
            'userOrganisation' => $userOrganisation,
            'user' => $user,
        ]);
    }

    #[Route('/update', name: 'app_instructions_update', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function update(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        if (!$organisation) {
            return $this->redirectToRoute('app_organisation_create');
        }

        $userOrganisation = $user->getCurrentUserOrganisation($sessionOrgId);

        // Get form data
        $data = $request->request->all();

        // Update Organisation fields
        if (isset($data['name'])) {
            $organisation->setName($data['name']);
        }

        if (isset($data['webhook_type'])) {
            $organisation->setWebhookType($data['webhook_type']);
        }

        if (isset($data['webhook_url'])) {
            $organisation->setWebhookUrl($data['webhook_url']);
        }

        if (isset($data['n8n_api_key'])) {
            $organisation->setN8nApiKey($data['n8n_api_key']);
        }

        // Update UserOrganisation fields
        if ($userOrganisation && isset($data['system_prompt'])) {
            $userOrganisation->setSystemPrompt($data['system_prompt']);
        }

        try {
            $this->entityManager->flush();

            $this->auditLogService->log(
                'instructions.updated',
                $user,
                [
                    'organisation_id' => $organisation->getId(),
                    'webhook_type' => $organisation->getWebhookType(),
                    'has_webhook_url' => !empty($organisation->getWebhookUrl()),
                    'has_system_prompt' => !empty($userOrganisation?->getSystemPrompt())
                ]
            );

            $this->addFlash('success', 'Instructions settings have been saved successfully.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to save settings: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_instructions');
    }

    #[Route('/api/n8n-workflow/{orgId}', name: 'app_instructions_n8n_workflow', methods: ['GET'])]
    public function fetchN8nWorkflow(string $orgId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        if (!$organisation || $organisation->getUuid() !== $orgId) {
            return new JsonResponse(['error' => 'Invalid organisation'], 403);
        }

        if ($organisation->getWebhookType() !== 'N8N' || !$organisation->getWebhookUrl()) {
            return new JsonResponse(['error' => 'N8N not configured'], 400);
        }

        try {
            // Parse N8N webhook URL to get workflow ID
            $webhookUrl = $organisation->getWebhookUrl();

            // Try to extract workflow ID from URL
            // Example: http://localhost:5678/workflow/xRYAqofSE2ljb0d5
            preg_match('/\/workflow\/([a-zA-Z0-9]+)/', $webhookUrl, $matches);
            $workflowId = $matches[1] ?? null;

            if (!$workflowId) {
                return new JsonResponse(['error' => 'Invalid N8N webhook URL format'], 400);
            }

            // Parse base URL from webhook URL
            $parsedUrl = parse_url($webhookUrl);
            $host = $parsedUrl['host'];

            // If localhost and running in Docker, use host.docker.internal
            if ($host === 'localhost' || $host === '127.0.0.1') {
                // Check if we're running in Docker
                if (file_exists('/.dockerenv') || is_file('/proc/self/cgroup') && strpos(file_get_contents('/proc/self/cgroup'), 'docker') !== false) {
                    $host = 'host.docker.internal';
                }
            }

            $baseUrl = $parsedUrl['scheme'] . '://' . $host . ':' . ($parsedUrl['port'] ?? 5678);

            // Fetch workflow data from N8N API
            $apiUrl = $baseUrl . '/api/v1/workflows/' . $workflowId;

            // Try to fetch real N8N data if API key is available
            if ($organisation->getN8nApiKey()) {
                try {
                    $response = $this->httpClient->request('GET', $apiUrl, [
                        'headers' => [
                            'X-N8N-API-KEY' => $organisation->getN8nApiKey(),
                            'Accept' => 'application/json',
                        ],
                        'timeout' => 10,  // Increased timeout
                    ]);

                    if ($response->getStatusCode() === 200) {
                        $workflowData = $response->toArray();

                        // Transform N8N nodes to ReactFlow format with filtering
                        $reactFlowNodes = [];
                        $reactFlowEdges = [];
                        $filteredNodeIds = [];
                        $nodeMap = [];

                        // First, build a map of all nodes for easy access
                        if (isset($workflowData['nodes'])) {
                            foreach ($workflowData['nodes'] as $node) {
                                $nodeId = $node['id'] ?? $node['name'];
                                $nodeMap[$node['name']] = [
                                    'id' => $nodeId,
                                    'name' => $node['name'],
                                    'type' => $node['type'] ?? 'unknown',
                                    'position' => $node['position'] ?? [0, 0]
                                ];
                            }
                        }

                        // Identify nodes to include based on type and connections
                        $nodesToInclude = [];
                        $aiAgentNodes = [];
                        $toolNodes = [];
                        $webhookNodes = [];
                        $responseNodes = [];

                        // Find webhook nodes, AI agent nodes, and response nodes by type
                        foreach ($nodeMap as $nodeName => $nodeData) {
                            $nodeType = $nodeData['type'];

                            // Webhook nodes
                            if ($nodeType === 'n8n-nodes-base.webhook') {
                                $webhookNodes[] = $nodeName;
                                $nodesToInclude[$nodeName] = $nodeData;
                            } elseif (strpos($nodeType, 'agent') !== false) {
                                // AI Agent nodes
                                $aiAgentNodes[] = $nodeName;
                                $nodesToInclude[$nodeName] = $nodeData;
                            } elseif ($nodeType === 'n8n-nodes-base.respondToWebhook') {
                                // Response nodes
                                $responseNodes[] = $nodeName;
                                $nodesToInclude[$nodeName] = $nodeData;
                            }
                        }

                        // Find nodes connected to AI Agent via ai_tool, ai_languageModel, ai_memory, ai_outputParser
                        if (isset($workflowData['connections'])) {
                            foreach ($workflowData['connections'] as $sourceName => $connectionTypes) {
                                foreach ($connectionTypes as $connectionType => $connections) {
                                    if (in_array($connectionType, ['ai_tool', 'ai_languageModel', 'ai_memory', 'ai_outputParser'])) {
                                        foreach ($connections as $connectionArray) {
                                            foreach ($connectionArray as $connection) {
                                                if (in_array($connection['node'], $aiAgentNodes)) {
                                                    $toolNodes[] = $sourceName;
                                                    if (isset($nodeMap[$sourceName])) {
                                                        $nodesToInclude[$sourceName] = $nodeMap[$sourceName];
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }

                        // Calculate positions for vertical layout
                        $xCenter = 400;
                        $ySpacing = 150;
                        $xSpacing = 200;
                        $currentY = 100;

                        // Position webhook nodes at the top
                        foreach ($webhookNodes as $i => $nodeName) {
                            $nodesToInclude[$nodeName]['position'] = [
                                'x' => $xCenter,
                                'y' => $currentY
                            ];
                        }
                        $currentY += $ySpacing * 1.5;

                        // Position AI Agent in the middle with its dependencies around it
                        $aiAgentY = $currentY;
                        foreach ($aiAgentNodes as $i => $nodeName) {
                            $nodesToInclude[$nodeName]['position'] = [
                                'x' => $xCenter,
                                'y' => $aiAgentY
                            ];
                        }

                        // Position tool nodes in a grid pattern below AI Agent
                        $toolCount = count($toolNodes);
                        if ($toolCount > 0) {
                            $currentY += $ySpacing;
                            $toolsPerRow = 3;
                            $rows = ceil($toolCount / $toolsPerRow);

                            foreach ($toolNodes as $i => $nodeName) {
                                $row = floor($i / $toolsPerRow);
                                $col = $i % $toolsPerRow;
                                $totalInRow = min($toolsPerRow, $toolCount - $row * $toolsPerRow);
                                $xOffset = ($col - ($totalInRow - 1) / 2) * $xSpacing;

                                if (isset($nodesToInclude[$nodeName])) {
                                    $nodesToInclude[$nodeName]['position'] = [
                                        'x' => $xCenter + $xOffset,
                                        'y' => $currentY + ($row * $ySpacing * 0.8)
                                    ];
                                }
                            }
                            $currentY += $rows * $ySpacing;
                        }

                        // Position response nodes at the bottom
                        $currentY += $ySpacing * 0.5;
                        foreach ($responseNodes as $i => $nodeName) {
                            $xOffset = ($i - (count($responseNodes) - 1) / 2) * $xSpacing * 1.2;
                            $nodesToInclude[$nodeName]['position'] = [
                                'x' => $xCenter + $xOffset,
                                'y' => $currentY
                            ];
                        }

                        // Create ReactFlow nodes from filtered list
                        foreach ($nodesToInclude as $nodeName => $nodeData) {
                            $filteredNodeIds[] = $nodeData['id'];
                            $reactFlowNodes[] = [
                                'id' => $nodeData['id'],
                                'data' => [
                                    'label' => $nodeData['name'],
                                    'type' => $nodeData['type']
                                ],
                                'position' => [
                                    'x' => $nodeData['position']['x'],
                                    'y' => $nodeData['position']['y']
                                ],
                                'type' => 'default'
                            ];
                        }

                        // Filter edges to only include connections between filtered nodes
                        if (isset($workflowData['connections'])) {
                            $edgeIndex = 0;
                            foreach ($workflowData['connections'] as $sourceNode => $connections) {
                                if (!isset($nodesToInclude[$sourceNode])) {
                                    continue;
                                }
                                $sourceId = $nodesToInclude[$sourceNode]['id'];

                                foreach ($connections as $outputType => $outputs) {
                                    foreach ($outputs as $outputIndex => $targets) {
                                        foreach ($targets as $target) {
                                            if (isset($nodesToInclude[$target['node']])) {
                                                $targetId = $nodesToInclude[$target['node']]['id'];
                                                $reactFlowEdges[] = [
                                                    'id' => 'e' . (++$edgeIndex),
                                                    'source' => $sourceId,
                                                    'target' => $targetId,
                                                    'type' => 'default'
                                                ];
                                            }
                                        }
                                    }
                                }
                            }
                        }

                        return new JsonResponse([
                            'nodes' => $reactFlowNodes,
                            'edges' => $reactFlowEdges
                        ]);
                    }
                } catch (\Exception $e) {
                    // Fall back to mock data if API fails
                    error_log('N8N API error: ' . $e->getMessage());
                }
            }

            // Return mock data if no API key or API fails
            $mockNodes = [
                [
                    'id' => 'node1',
                    'name' => 'Webhook Trigger',
                    'type' => 'n8n-nodes-base.webhook',
                    'position' => ['x' => 250, 'y' => 100]
                ],
                [
                    'id' => 'node2',
                    'name' => 'Transform Data',
                    'type' => 'n8n-nodes-base.set',
                    'position' => ['x' => 500, 'y' => 100]
                ],
                [
                    'id' => 'node3',
                    'name' => 'Send Response',
                    'type' => 'n8n-nodes-base.respondToWebhook',
                    'position' => ['x' => 750, 'y' => 100]
                ]
            ];

            $mockEdges = [
                ['source' => 'node1', 'target' => 'node2'],
                ['source' => 'node2', 'target' => 'node3']
            ];

            // Transform to ReactFlow format
            $reactFlowNodes = array_map(function ($node) {
                return [
                    'id' => $node['id'],
                    'data' => [
                        'label' => $node['name'],
                        'type' => $node['type']
                    ],
                    'position' => $node['position'],
                    'type' => 'default'
                ];
            }, $mockNodes);

            $reactFlowEdges = array_map(function ($edge, $index) {
                return [
                    'id' => 'e' . ($index + 1),
                    'source' => $edge['source'],
                    'target' => $edge['target'],
                    'type' => 'default'
                ];
            }, $mockEdges, array_keys($mockEdges));

            return new JsonResponse([
                'nodes' => $reactFlowNodes,
                'edges' => $reactFlowEdges
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Failed to fetch workflow: ' . $e->getMessage()], 500);
        }
    }
}
