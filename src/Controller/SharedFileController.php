<?php

namespace App\Controller;

use App\Repository\OrganisationRepository;
use Aws\S3\S3Client;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class SharedFileController extends AbstractController
{
    private S3Client $s3Client;
    private string $publicBucket = 'workoflow-shared';

    public function __construct(
        private OrganisationRepository $organisationRepository,
        private LoggerInterface $logger,
        ParameterBagInterface $params
    ) {
        $this->s3Client = new S3Client([
            'version' => 'latest',
            'region' => $params->get('minio.region'),
            'endpoint' => $params->get('minio.endpoint'),
            'use_path_style_endpoint' => $params->get('minio.use_path_style'),
            'credentials' => [
                'key' => $params->get('minio.root_user'),
                'secret' => $params->get('minio.root_password'),
            ],
        ]);
    }

    #[Route('/{orgUuid}/file/{fileId}', name: 'shared_file', methods: ['GET'])]
    public function getSharedFile(string $orgUuid, string $fileId): Response
    {
        // Validate organisation
        $organisation = $this->organisationRepository->findByUuid($orgUuid);
        if (!$organisation) {
            return new Response('File not found', 404);
        }

        try {
            // List objects with this prefix to find the actual file
            $prefix = sprintf('%s/%s', $orgUuid, $fileId);
            $objects = $this->s3Client->listObjectsV2([
                'Bucket' => $this->publicBucket,
                'Prefix' => $prefix,
                'MaxKeys' => 1
            ]);

            if (empty($objects['Contents'])) {
                return new Response('File not found', 404);
            }

            $key = $objects['Contents'][0]['Key'];

            // Get object metadata
            $headResult = $this->s3Client->headObject([
                'Bucket' => $this->publicBucket,
                'Key' => $key
            ]);

            $contentType = $headResult['ContentType'] ?? 'application/octet-stream';

            // Stream the file
            $response = new StreamedResponse(function () use ($key) {
                $stream = $this->s3Client->getObject([
                    'Bucket' => $this->publicBucket,
                    'Key' => $key
                ])['Body'];

                while (!$stream->eof()) {
                    echo $stream->read(8192);
                    flush();
                }
            });

            $response->headers->set('Content-Type', $contentType);
            $response->headers->set('Cache-Control', 'public, max-age=3600');
            
            // Add Content-Disposition for better download experience
            $filename = basename($key);
            $response->headers->set('Content-Disposition', sprintf('inline; filename="%s"', $filename));

            $this->logger->info('Shared file accessed', [
                'org_uuid' => $orgUuid,
                'file_id' => $fileId,
                'key' => $key
            ]);

            return $response;

        } catch (\Exception $e) {
            $this->logger->error('Failed to serve shared file', [
                'org_uuid' => $orgUuid,
                'file_id' => $fileId,
                'error' => $e->getMessage()
            ]);

            return new Response('File not found', 404);
        }
    }
}