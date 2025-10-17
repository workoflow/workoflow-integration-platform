<?php

namespace App\Command;

use App\Integration\IntegrationRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:tools:export',
    description: 'Export all integration tools to XML format',
)]
class ExportToolsCommand extends Command
{
    public function __construct(
        private IntegrationRegistry $integrationRegistry
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'Output file path (default: stdout)'
            )
            ->addOption(
                'filter',
                'f',
                InputOption::VALUE_REQUIRED,
                'Filter by category: system, user, or all (default: all)',
                'all'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $outputFile = $input->getOption('output');
        $filter = $input->getOption('filter');

        // Validate filter option
        if (!in_array($filter, ['all', 'system', 'user'])) {
            $io->error('Invalid filter option. Use: all, system, or user');
            return Command::FAILURE;
        }

        $io->title('Exporting Integration Tools');

        try {
            // Get integrations based on filter
            $integrations = match ($filter) {
                'system' => $this->integrationRegistry->getSystemIntegrations(),
                'user' => $this->integrationRegistry->getUserIntegrations(),
                default => $this->integrationRegistry->all(),
            };

            if (empty($integrations)) {
                $io->warning('No integrations found');
                return Command::SUCCESS;
            }

            // Generate XML
            $xml = $this->generateXml($integrations);

            // Output to file or stdout
            if ($outputFile) {
                file_put_contents($outputFile, $xml);
                $io->success(sprintf('Tools exported to: %s', $outputFile));
            } else {
                $output->writeln($xml);
            }

            // Show statistics
            $toolCount = 0;
            foreach ($integrations as $integration) {
                $toolCount += count($integration->getTools());
            }

            $io->section('Export Statistics');
            $io->table(
                ['Metric', 'Count'],
                [
                    ['Integrations', count($integrations)],
                    ['Total Tools', $toolCount],
                ]
            );

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Failed to export tools: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function generateXml(array $integrations): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        // Create root element
        $root = $dom->createElement('integrations');
        $dom->appendChild($root);

        // Iterate through integrations
        foreach ($integrations as $integration) {
            $category = $integration->requiresCredentials() ? 'user' : 'system';

            // Create integration element
            $integrationElement = $dom->createElement('integration');
            $integrationElement->setAttribute('type', $integration->getType());
            $integrationElement->setAttribute('name', $integration->getName());
            $integrationElement->setAttribute('category', $category);

            // Add tools
            foreach ($integration->getTools() as $tool) {
                $toolElement = $dom->createElement('tool');
                $toolElement->setAttribute('name', $tool->getName());

                // Add description
                $descriptionElement = $dom->createElement('description');
                $descriptionElement->appendChild(
                    $dom->createTextNode($tool->getDescription())
                );
                $toolElement->appendChild($descriptionElement);

                // Add parameters
                $parameters = $tool->getParameters();
                if (!empty($parameters)) {
                    $parametersElement = $dom->createElement('parameters');

                    foreach ($parameters as $param) {
                        $paramElement = $dom->createElement('parameter');
                        $paramElement->setAttribute('name', $param['name']);
                        $paramElement->setAttribute('type', $param['type']);
                        $paramElement->setAttribute(
                            'required',
                            $param['required'] ? 'true' : 'false'
                        );

                        if (isset($param['description'])) {
                            $paramDescElement = $dom->createElement('description');
                            $paramDescElement->appendChild(
                                $dom->createTextNode($param['description'])
                            );
                            $paramElement->appendChild($paramDescElement);
                        }

                        $parametersElement->appendChild($paramElement);
                    }

                    $toolElement->appendChild($parametersElement);
                }

                $integrationElement->appendChild($toolElement);
            }

            $root->appendChild($integrationElement);
        }

        return $dom->saveXML();
    }
}
