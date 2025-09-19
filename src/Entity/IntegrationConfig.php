<?php

namespace App\Entity;

use App\Repository\IntegrationConfigRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: IntegrationConfigRepository::class)]
#[ORM\Table(name: 'integration_config')]
#[ORM\HasLifecycleCallbacks]
class IntegrationConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Organisation $organisation = null;

    #[ORM\Column(length: 100)]
    private ?string $integrationType = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $workflowUserId = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $encryptedCredentials = null;

    #[ORM\Column(type: Types::JSON)]
    private array $disabledTools = [];

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastAccessedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->disabledTools = [];
        $this->active = true;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrganisation(): ?Organisation
    {
        return $this->organisation;
    }

    public function setOrganisation(?Organisation $organisation): static
    {
        $this->organisation = $organisation;
        return $this;
    }

    public function getIntegrationType(): ?string
    {
        return $this->integrationType;
    }

    public function setIntegrationType(string $integrationType): static
    {
        $this->integrationType = $integrationType;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getWorkflowUserId(): ?string
    {
        return $this->workflowUserId;
    }

    public function setWorkflowUserId(?string $workflowUserId): static
    {
        $this->workflowUserId = $workflowUserId;
        return $this;
    }

    public function getEncryptedCredentials(): ?string
    {
        return $this->encryptedCredentials;
    }

    public function setEncryptedCredentials(?string $encryptedCredentials): static
    {
        $this->encryptedCredentials = $encryptedCredentials;
        return $this;
    }

    public function getDisabledTools(): array
    {
        return $this->disabledTools;
    }

    public function setDisabledTools(array $disabledTools): static
    {
        $this->disabledTools = array_unique(array_values($disabledTools));
        return $this;
    }

    public function isToolDisabled(string $toolName): bool
    {
        return in_array($toolName, $this->disabledTools, true);
    }

    public function enableTool(string $toolName): static
    {
        $this->disabledTools = array_diff($this->disabledTools, [$toolName]);
        $this->disabledTools = array_values($this->disabledTools);
        return $this;
    }

    public function disableTool(string $toolName): static
    {
        if (!in_array($toolName, $this->disabledTools, true)) {
            $this->disabledTools[] = $toolName;
        }
        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;
        return $this;
    }

    public function getLastAccessedAt(): ?\DateTimeInterface
    {
        return $this->lastAccessedAt;
    }

    public function setLastAccessedAt(?\DateTimeInterface $lastAccessedAt): static
    {
        $this->lastAccessedAt = $lastAccessedAt;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTime();
    }

    public function isSystemIntegration(): bool
    {
        return str_starts_with($this->integrationType, 'system.');
    }

    public function hasCredentials(): bool
    {
        return !empty($this->encryptedCredentials);
    }
}