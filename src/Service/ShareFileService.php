<?php

namespace App\Service;

use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Uid\Uuid;

class ShareFileService
{
    private S3Client $s3Client;
    private string $bucket;
    private string $publicBucket = 'workoflow-shared';
    private string $publicEndpoint;
    private string $appUrl;

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
        
        // Extract the public endpoint without internal docker hostname
        $endpoint = $params->get('minio.endpoint');
        // Replace internal minio hostname with localhost for public access
        $this->publicEndpoint = str_replace('minio:', 'localhost:', $endpoint);
        
        // Get the application URL from environment
        $this->appUrl = $params->get('app.url') ?? 'http://localhost:3979';
        
        $this->ensureBucketExists();
    }

    private function ensureBucketExists(): void
    {
        try {
            // Ensure public bucket exists
            if (!$this->s3Client->doesBucketExist($this->publicBucket)) {
                $this->s3Client->createBucket([
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
                            'Resource' => ["arn:aws:s3:::${this->publicBucket}/*"]
                        ]
                    ]
                ]);
                
                $this->s3Client->putBucketPolicy([
                    'Bucket' => $this->publicBucket,
                    'Policy' => $policy
                ]);
                
                // Set lifecycle policy for 1-day expiration
                $this->s3Client->putBucketLifecycleConfiguration([
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
        } catch (S3Exception $e) {
            $this->logger->error('Failed to setup public bucket', [
                'bucket' => $this->publicBucket,
                'error' => $e->getMessage()
            ]);
        }
    }

    public function shareFile(string $binaryData, string $contentType, string $orgUuid): array
    {
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
            $result = $this->s3Client->putObject([
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
            $publicUrl = sprintf('%s/%s/file/%s', 
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