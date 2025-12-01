<?php

namespace App\Integration\UserIntegrations;

use App\Entity\IntegrationConfig;
use App\Integration\PersonalizedSkillInterface;
use App\Integration\ToolDefinition;
use App\Integration\CredentialField;
use App\Service\Integration\ProjektronService;
use Twig\Environment;

class ProjektronIntegration implements PersonalizedSkillInterface
{
    public function __construct(
        private ProjektronService $projektronService,
        private Environment $twig
    ) {
    }

    public function getType(): string
    {
        return 'projektron';
    }

    public function getName(): string
    {
        return 'Projektron';
    }

    public function getTools(): array
    {
        return [
            new ToolDefinition(
                'projektron_get_all_tasks',
                'Get all bookable tasks from the user\'s personal Projektron task list. Returns an array of items, each containing: oid (unique task identifier like "1744315212023_JTask"), name (task display name), and booking_url (URL for time tracking). Use this to discover available tasks before booking time entries.',
                []
            ),
            new ToolDefinition(
                'projektron_get_worklog',
                'Get time entries (worklog) for a specific date. Returns booked hours per task for the week containing the specified date. Each entry includes task_oid, task_name, total_minutes, total_hours, and daily_entries breakdown. Use this to see what time has been booked.',
                [
                    [
                        'name' => 'day',
                        'type' => 'integer',
                        'description' => 'Day of month (1-31)',
                        'required' => true,
                    ],
                    [
                        'name' => 'month',
                        'type' => 'integer',
                        'description' => 'Month (1-12)',
                        'required' => true,
                    ],
                    [
                        'name' => 'year',
                        'type' => 'integer',
                        'description' => 'Year (e.g., 2025)',
                        'required' => true,
                    ],
                ]
            ),
        ];
    }

    public function executeTool(string $toolName, array $parameters, ?array $credentials = null): array
    {
        if ($credentials === null) {
            throw new \InvalidArgumentException('Credentials are required for Projektron integration');
        }

        return match ($toolName) {
            'projektron_get_all_tasks' => $this->getAllTasks($credentials),
            'projektron_get_worklog' => $this->getWorklog($credentials, $parameters),
            default => throw new \InvalidArgumentException("Unknown tool: {$toolName}")
        };
    }

    public function requiresCredentials(): bool
    {
        return true;
    }

    public function validateCredentials(array $credentials): bool
    {
        $requiredFields = ['domain', 'username', 'jsessionid'];

        foreach ($requiredFields as $field) {
            if (empty($credentials[$field])) {
                return false;
            }
        }

        return $this->projektronService->testConnection($credentials);
    }

    public function getCredentialFields(): array
    {
        return [
            new CredentialField(
                'domain',
                'url',
                'Projektron Domain',
                'https://projektron.valantic.com',
                true,
                'Your Projektron instance URL (e.g., https://projektron.company.com)'
            ),
            new CredentialField(
                'username',
                'text',
                'Username',
                'your.name@company.com',
                true,
                'Your Projektron username or email address'
            ),
            new CredentialField(
                'jsessionid',
                'password',
                'JSESSIONID',
                null,
                true,
                'Open Browser DevTools (F12) → Application → Cookies → Find JSESSIONID value. Or use console: <code>document.cookie.match(/JSESSIONID=([^;]+)/)?.[1]</code>'
            ),
        ];
    }

    public function getSystemPrompt(?IntegrationConfig $config = null): string
    {
        return $this->twig->render('skills/prompts/projektron_full.xml.twig', [
            'integration_id' => $config?->getId() ?? 'ID',
            'tool_count' => count($this->getTools()),
        ]);
    }

    public function isExperimental(): bool
    {
        return true;
    }

    private function getAllTasks(array $credentials): array
    {
        $tasks = $this->projektronService->getAllTasks($credentials);

        return [
            'success' => true,
            'count' => count($tasks),
            'tasks' => $tasks
        ];
    }

    /**
     * Get worklog (time entries) for a specific date
     *
     * @param array $credentials Projektron credentials
     * @param array $parameters Tool parameters (day, month, year)
     * @return array Worklog data with success flag
     */
    private function getWorklog(array $credentials, array $parameters): array
    {
        $day = (int) ($parameters['day'] ?? 0);
        $month = (int) ($parameters['month'] ?? 0);
        $year = (int) ($parameters['year'] ?? 0);

        if ($day < 1 || $day > 31) {
            throw new \InvalidArgumentException('Invalid day: must be between 1 and 31');
        }
        if ($month < 1 || $month > 12) {
            throw new \InvalidArgumentException('Invalid month: must be between 1 and 12');
        }
        if ($year < 2000 || $year > 2100) {
            throw new \InvalidArgumentException('Invalid year: must be between 2000 and 2100');
        }

        $worklog = $this->projektronService->getWorklog($credentials, $day, $month, $year);

        return [
            'success' => true,
            'date' => $worklog['date'],
            'week_dates' => $worklog['week_dates'],
            'total_hours' => $worklog['total_hours'],
            'count' => count($worklog['entries']),
            'entries' => $worklog['entries'],
        ];
    }
}
