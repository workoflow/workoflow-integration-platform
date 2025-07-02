<?php

namespace App\Entity;

use App\Repository\IntegrationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: IntegrationRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Integration
{
    public const TYPE_JIRA = 'jira';
    public const TYPE_CONFLUENCE = 'confluence';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'integrations')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(length: 50)]
    private ?string $type = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: Types::JSON)]
    private array $config = [];

    #[ORM\Column(type: Types::TEXT)]
    private ?string $encryptedCredentials = null;

    #[ORM\Column]
    private ?bool $active = true;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastAccessedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\OneToMany(targetEntity: IntegrationFunction::class, mappedBy: 'integration', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $functions;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $workflowUserId = null;

    public function __construct()
    {
        $this->functions = new ArrayCollection();
        $this->config = [];
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;
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

    public function getConfig(): array
    {
        return $this->config;
    }

    public function setConfig(array $config): static
    {
        $this->config = $config;
        return $this;
    }

    public function getEncryptedCredentials(): ?string
    {
        return $this->encryptedCredentials;
    }

    public function setEncryptedCredentials(string $encryptedCredentials): static
    {
        $this->encryptedCredentials = $encryptedCredentials;
        return $this;
    }

    public function isActive(): ?bool
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
    public function setCreatedAtValue(): void
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new \DateTime();
    }

    public function getFunctions(): Collection
    {
        return $this->functions;
    }

    public function addFunction(IntegrationFunction $function): static
    {
        if (!$this->functions->contains($function)) {
            $this->functions->add($function);
            $function->setIntegration($this);
        }
        return $this;
    }

    public function removeFunction(IntegrationFunction $function): static
    {
        if ($this->functions->removeElement($function)) {
            if ($function->getIntegration() === $this) {
                $function->setIntegration(null);
            }
        }
        return $this;
    }

    public function getActiveFunctions(): Collection
    {
        return $this->functions->filter(fn(IntegrationFunction $f) => $f->isActive());
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

    public static function getAvailableTypes(): array
    {
        return [
            self::TYPE_JIRA => 'Jira',
            self::TYPE_CONFLUENCE => 'Confluence',
        ];
    }
}