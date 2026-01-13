<?php

namespace App\Entity;

use App\Repository\PromptUpvoteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PromptUpvoteRepository::class)]
#[ORM\Table(name: 'prompt_upvote')]
#[ORM\UniqueConstraint(name: 'unique_user_prompt_vote', columns: ['prompt_id', 'user_id'])]
#[ORM\Index(name: 'idx_prompt_upvote_prompt', columns: ['prompt_id'])]
#[ORM\HasLifecycleCallbacks]
class PromptUpvote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Prompt::class, inversedBy: 'upvotes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Prompt $prompt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\PrePersist]
    public function setCreatedAtValue(): void
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPrompt(): ?Prompt
    {
        return $this->prompt;
    }

    public function setPrompt(?Prompt $prompt): static
    {
        $this->prompt = $prompt;
        return $this;
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

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }
}
