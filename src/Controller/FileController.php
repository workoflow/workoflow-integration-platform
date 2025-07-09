<?php

namespace App\Controller;

use App\Service\AuditLogService;
use App\Service\FileStorageService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/files')]
#[IsGranted('ROLE_USER')]
class FileController extends AbstractController
{
    public function __construct(
        private FileStorageService $fileStorageService,
        private AuditLogService $auditLogService
    ) {}

    #[Route('/', name: 'app_files')]
    public function index(): Response
    {
        $user = $this->getUser();
        $organisation = $user->getOrganisation();
        
        if (!$organisation) {
            return $this->redirectToRoute('app_organisation_create');
        }

        $files = [];
        $connectionError = null;
        $isConnected = false;

        try {
            // Check connection first
            $isConnected = $this->fileStorageService->isConnected();

            if ($isConnected) {
                echo $organisation->getUuid();
                $files = $this->fileStorageService->listFiles($organisation->getUuid());
                print_r($files);
                die("TEST");
            } else {
                $connectionError = 'Unable to connect to file storage service. Please check if MinIO is running and accessible.';
            }
        } catch (\Exception $e) {
            $connectionError = 'Error accessing file storage: ' . $e->getMessage();
            $this->addFlash('error', $connectionError);
            
            // Log the error for debugging
            error_log('FileController: Connection error - ' . $e->getMessage());
            error_log('FileController: isConnected = ' . ($isConnected ? 'true' : 'false'));
        }

        return $this->render('file/index.html.twig', [
            'files' => $files,
            'organisation' => $organisation,
            'connectionError' => $connectionError,
            'isConnected' => $isConnected,
        ]);
    }

    #[Route('/upload', name: 'app_file_upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        $user = $this->getUser();
        $organisation = $user->getOrganisation();
        
        if (!$organisation) {
            return new JsonResponse(['error' => 'No organisation'], 400);
        }

        $uploadedFiles = $request->files->all();
        $results = [];
        $errors = [];

        foreach ($uploadedFiles as $uploadedFile) {
            if ($uploadedFile instanceof UploadedFile) {
                try {
                    $result = $this->fileStorageService->upload(
                        $uploadedFile,
                        $organisation->getUuid(),
                        $user->getId()
                    );
                    
                    $results[] = $result;
                    
                    $this->auditLogService->log(
                        'file.uploaded',
                        $user,
                        [
                            'filename' => $result['original_name'],
                            'size' => $result['size']
                        ]
                    );
                } catch (\Exception $e) {
                    $errors[] = [
                        'file' => $uploadedFile->getClientOriginalName(),
                        'error' => $e->getMessage()
                    ];
                }
            }
        }

        if (!empty($errors)) {
            return new JsonResponse([
                'success' => false,
                'errors' => $errors,
                'uploaded' => $results
            ], 400);
        }

        return new JsonResponse([
            'success' => true,
            'files' => $results
        ]);
    }

    #[Route('/{key}/delete', name: 'app_file_delete', methods: ['POST'])]
    public function delete(string $key): Response
    {
        $user = $this->getUser();
        $organisation = $user->getOrganisation();
        
        // Verify file belongs to user's organisation
        if (!str_starts_with($key, $organisation->getUuid() . '/')) {
            throw $this->createAccessDeniedException();
        }

        if ($this->fileStorageService->delete($key)) {
            $this->auditLogService->log(
                'file.deleted',
                $user,
                ['key' => $key]
            );
            
            $this->addFlash('success', 'file.deleted.success');
        } else {
            $this->addFlash('error', 'file.deleted.error');
        }

        return $this->redirectToRoute('app_files');
    }

    #[Route('/{key}/download', name: 'app_file_download')]
    public function download(string $key): Response
    {
        $user = $this->getUser();
        $organisation = $user->getOrganisation();
        
        // Verify file belongs to user's organisation
        if (!str_starts_with($key, $organisation->getUuid() . '/')) {
            throw $this->createAccessDeniedException();
        }

        try {
            $url = $this->fileStorageService->getDownloadUrl($key);
            
            $this->auditLogService->log(
                'file.downloaded',
                $user,
                ['key' => $key]
            );
            
            return $this->redirect($url);
        } catch (\Exception $e) {
            $this->addFlash('error', 'file.download.error');
            return $this->redirectToRoute('app_files');
        }
    }
}
