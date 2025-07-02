<?php

namespace App\Command;

use App\Service\FileStorageService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:create-bucket',
    description: 'Creates the MinIO bucket if it does not exist',
)]
class CreateBucketCommand extends Command
{
    public function __construct(
        private FileStorageService $fileStorageService
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            // The bucket is created automatically in the FileStorageService constructor
            $io->success('MinIO bucket verified/created successfully.');
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Failed to create bucket: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}