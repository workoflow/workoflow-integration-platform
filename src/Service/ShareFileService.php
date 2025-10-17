<?php

namespace App\Service;

use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Uid\Uuid;

class ShareFileService
{
    private ?S3Client $s3Client = null;
    private string $bucket;
    private string $publicBucket;
    private string $publicEndpoint;
    private string $appUrl;
    private bool $bucketChecked = false;

    public function __construct(
        private ParameterBagInterface $params,
        private LoggerInterface $logger
    ) {
        $this->bucket = $params->get('minio.bucket');
        $this->publicBucket = $params->get('minio.public_bucket');

        // Extract the public endpoint without internal docker hostname
        $endpoint = $params->get('minio.endpoint');
        // Replace internal minio hostname with localhost for public access
        $this->publicEndpoint = str_replace('minio:', 'localhost:', $endpoint);

        // Get the application URL from environment
        $this->appUrl = $params->get('app.url') ?? 'http://localhost:3979';
    }

    private function getS3Client(): S3Client
    {
        if ($this->s3Client === null) {
            $this->s3Client = new S3Client([
                'version' => 'latest',
                'region' => $this->params->get('minio.region'),
                'endpoint' => $this->params->get('minio.endpoint'),
                'use_path_style_endpoint' => $this->params->get('minio.use_path_style'),
                'credentials' => [
                    'key' => $this->params->get('minio.root_user'),
                    'secret' => $this->params->get('minio.root_password'),
                ],
            ]);
        }
        return $this->s3Client;
    }

    private function ensureBucketExists(): void
    {
        if ($this->bucketChecked) {
            return;
        }

        try {
            $s3Client = $this->getS3Client();
            // Ensure public bucket exists
            if (!$s3Client->doesBucketExist($this->publicBucket)) {
                $s3Client->createBucket([
                    'Bucket' => $this->publicBucket,
                ]);

                // Set bucket policy to allow public read access
                $policy = json_encode([
                    'Version' => '2012-10-17',
                    'Statement' => [
                        [
                            'Sid' => 'PublicRead',
                            'Effect' => 'Allow',
                            'Principal' => ['AWS' => ['*']],
                            'Action' => ['s3:GetObject'],
                            'Resource' => ["arn:aws:s3:::" . $this->publicBucket . "/*"]
                        ]
                    ]
                ]);

                $s3Client->putBucketPolicy([
                    'Bucket' => $this->publicBucket,
                    'Policy' => $policy
                ]);

                // Set lifecycle policy for 1-day expiration
                $s3Client->putBucketLifecycleConfiguration([
                    'Bucket' => $this->publicBucket,
                    'LifecycleConfiguration' => [
                        'Rules' => [
                            [
                                'ID' => 'expire-shared-files',
                                'Status' => 'Enabled',
                                'Prefix' => '',
                                'Expiration' => [
                                    'Days' => 1
                                ]
                            ]
                        ]
                    ]
                ]);
            }
            $this->bucketChecked = true;
        } catch (S3Exception $e) {
            $this->logger->error('Failed to setup public bucket', [
                'bucket' => $this->publicBucket,
                'error' => $e->getMessage()
            ]);
            $this->bucketChecked = true; // Mark as checked even on failure to avoid repeated attempts
        }
    }

    public function shareFile(string $binaryData, string $contentType, string $orgUuid): array
    {
        $this->ensureBucketExists();

        try {
            // Generate unique filename
            $fileId = Uuid::v4()->toString();
            $extension = $this->getExtensionFromMimeType($contentType);
            $filename = sprintf('%s/%s.%s', $orgUuid, $fileId, $extension);

            // Decode base64 data
            $decodedData = base64_decode($binaryData, true);
            if ($decodedData === false) {
                throw new \InvalidArgumentException('Invalid base64 encoded data');
            }

            // Upload to public bucket
            $result = $this->getS3Client()->putObject([
                'Bucket' => $this->publicBucket,
                'Key' => $filename,
                'Body' => $decodedData,
                'ContentType' => $contentType,
                'Metadata' => [
                    'shared_at' => date('Y-m-d H:i:s'),
                    'org_uuid' => $orgUuid
                ]
            ]);

            // Generate public URL using the application route
            $publicUrl = sprintf(
                '%s/%s/file/%s',
                rtrim($this->appUrl, '/'),
                $orgUuid,
                $fileId
            );

            $this->logger->info('File shared successfully', [
                'org_uuid' => $orgUuid,
                'file_id' => $fileId,
                'content_type' => $contentType,
                'size' => strlen($decodedData)
            ]);

            return [
                'url' => $publicUrl,
                'contentType' => $contentType,
                'fileId' => $fileId,
                'expiresAt' => (new \DateTime('+1 day'))->format('Y-m-d H:i:s')
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to share file', [
                'error' => $e->getMessage(),
                'org_uuid' => $orgUuid
            ]);
            throw new \RuntimeException('Failed to share file: ' . $e->getMessage());
        }
    }

    private function getExtensionFromMimeType(string $mimeType): string
    {
        $extensions = [
            'application/pdf' => 'pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'application/vnd.ms-powerpoint' => 'ppt',
            'text/csv' => 'csv',
            'text/plain' => 'txt',
            'application/json' => 'json',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/bmp' => 'bmp',
            'application/zip' => 'zip',
            'application/x-rar-compressed' => 'rar',
            'application/x-7z-compressed' => '7z'
        ];

        return $extensions[$mimeType] ?? 'bin';
    }
}
