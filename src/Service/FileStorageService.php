<?php

namespace App\Service;

use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class FileStorageService
{
    private S3Client $s3Client;
    private string $bucket;
    private array $allowedExtensions = [
        'pdf', 'docx', 'xlsx', 'csv', 'txt', 'json', 
        'jpg', 'jpeg', 'png', 'gif', 'bmp'
    ];
    private int $maxFileSize = 104857600; // 100MB

    public function __construct(
        ParameterBagInterface $params,
        private LoggerInterface $logger
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
        
        $this->bucket = $params->get('minio.bucket');
        $this->ensureBucketExists();
    }

    private function ensureBucketExists(): void
    {
        try {
            if (!$this->s3Client->doesBucketExist($this->bucket)) {
                $this->s3Client->createBucket([
                    'Bucket' => $this->bucket,
                ]);
            }
        } catch (S3Exception $e) {
            $this->logger->error('Failed to create bucket', [
                'bucket' => $this->bucket,
                'error' => $e->getMessage()
            ]);
        }
    }

    public function upload(UploadedFile $file, string $orgUuid, int $userId): array
    {
        // Validate file
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, $this->allowedExtensions)) {
            throw new \InvalidArgumentException('File type not allowed');
        }

        if ($file->getSize() > $this->maxFileSize) {
            throw new \InvalidArgumentException('File size exceeds maximum allowed size');
        }

        $filename = sprintf(
            '%s/%d/%s_%s',
            $orgUuid,
            $userId,
            uniqid(),
            $file->getClientOriginalName()
        );

        try {
            $result = $this->s3Client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $filename,
                'Body' => fopen($file->getPathname(), 'r'),
                'ContentType' => $file->getMimeType(),
                'Metadata' => [
                    'original_name' => $file->getClientOriginalName(),
                    'uploaded_by' => (string) $userId,
                    'uploaded_at' => date('Y-m-d H:i:s'),
                ]
            ]);

            return [
                'key' => $filename,
                'original_name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'url' => $result['ObjectURL'] ?? null,
            ];
        } catch (S3Exception $e) {
            $this->logger->error('File upload failed', [
                'error' => $e->getMessage(),
                'file' => $file->getClientOriginalName()
            ]);
            throw new \RuntimeException('Failed to upload file');
        }
    }

    public function listFiles(string $orgUuid): array
    {
        try {
            $objects = $this->s3Client->listObjectsV2([
                'Bucket' => $this->bucket,
                'Prefix' => $orgUuid . '/',
            ]);

            $files = [];
            foreach ($objects['Contents'] ?? [] as $object) {
                $metadata = $this->s3Client->headObject([
                    'Bucket' => $this->bucket,
                    'Key' => $object['Key'],
                ]);

                $files[] = [
                    'key' => $object['Key'],
                    'original_name' => $metadata['Metadata']['original_name'] ?? basename($object['Key']),
                    'size' => $object['Size'],
                    'last_modified' => $object['LastModified'],
                    'uploaded_by' => $metadata['Metadata']['uploaded_by'] ?? null,
                ];
            }

            return $files;
        } catch (S3Exception $e) {
            $this->logger->error('Failed to list files', [
                'error' => $e->getMessage(),
                'org_uuid' => $orgUuid
            ]);
            return [];
        }
    }

    public function delete(string $key): bool
    {
        try {
            $this->s3Client->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);
            return true;
        } catch (S3Exception $e) {
            $this->logger->error('Failed to delete file', [
                'error' => $e->getMessage(),
                'key' => $key
            ]);
            return false;
        }
    }

    public function getDownloadUrl(string $key, int $expiration = 3600): string
    {
        try {
            $cmd = $this->s3Client->getCommand('GetObject', [
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);

            $request = $this->s3Client->createPresignedRequest($cmd, '+' . $expiration . ' seconds');
            return (string) $request->getUri();
        } catch (S3Exception $e) {
            $this->logger->error('Failed to generate download URL', [
                'error' => $e->getMessage(),
                'key' => $key
            ]);
            throw new \RuntimeException('Failed to generate download URL');
        }
    }
}