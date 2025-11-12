<?php

namespace App\Entity;

use App\Repository\ChannelRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ChannelRepository::class)]
#[ORM\Table(name: 'channel')]
#[ORM\HasLifecycleCallbacks]
class Channel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255, unique: true)]
    private ?string $uuid = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\OneToMany(targetEntity: UserChannel::class, mappedBy: 'channel', cascade: ['persist', 'remove'])]
    private Collection $userChannels;

    public function __construct()
    {
        $this->userChannels = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): ?string
    {
        return $this->uuid;
    }

    public function setUuid(string $uuid): static
    {
        $this->uuid = $uuid;
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

    /**
     * @return Collection<int, UserChannel>
     */
    public function getUserChannels(): Collection
    {
        return $this->userChannels;
    }

    public function addUserChannel(UserChannel $userChannel): static
    {
        if (!$this->userChannels->contains($userChannel)) {
            $this->userChannels->add($userChannel);
            $userChannel->setChannel($this);
        }

        return $this;
    }

    public function removeUserChannel(UserChannel $userChannel): static
    {
        if ($this->userChannels->removeElement($userChannel)) {
            // set the owning side to null (unless already changed)
            if ($userChannel->getChannel() === $this) {
                $userChannel->setChannel(null);
            }
        }

        return $this;
    }

    /**
     * Get users in this channel
     * @return Collection<int, User>
     */
    public function getUsers(): Collection
    {
        $users = new ArrayCollection();
        foreach ($this->userChannels as $userChannel) {
            if ($userChannel->getUser()) {
                $users->add($userChannel->getUser());
            }
        }
        return $users;
    }
}
