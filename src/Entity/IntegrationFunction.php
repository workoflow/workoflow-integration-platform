<?php

namespace App\Entity;

use App\Repository\IntegrationFunctionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: IntegrationFunctionRepository::class)]
class IntegrationFunction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'functions')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Integration $integration = null;

    #[ORM\Column(length: 100)]
    private ?string $functionName = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $description = null;

    #[ORM\Column]
    private ?bool $active = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIntegration(): ?Integration
    {
        return $this->integration;
    }

    public function setIntegration(?Integration $integration): static
    {
        $this->integration = $integration;
        return $this;
    }

    public function getFunctionName(): ?string
    {
        return $this->functionName;
    }

    public function setFunctionName(string $functionName): static
    {
        $this->functionName = $functionName;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;
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

    public static function getJiraFunctions(): array
    {
        return [
            'jira_search' => 'Sucht nach Jira Issues mit JQL. Nutze dies wenn der User nach Tickets, Bugs oder Tasks fragt.',
            'jira_get_issue' => 'Ruft Details zu einem spezifischen Jira Issue ab. Nutze dies wenn der User nach Details zu einem Ticket fragt.',
            'jira_get_sprints_from_board' => 'Listet alle Sprints eines Jira Boards auf. Nutze dies für Sprint-Übersichten.',
            'jira_get_sprint_issues' => 'Zeigt alle Issues eines Sprints. Nutze dies für Sprint-Planungen.',
        ];
    }

    public static function getConfluenceFunctions(): array
    {
        return [
            'confluence_search' => 'Durchsucht Confluence Seiten. Nutze dies für Dokumentations-Anfragen.',
            'confluence_get_page' => 'Ruft eine spezifische Confluence Seite ab. Nutze dies um Dokumentationen zu lesen.',
            'confluence_get_comments' => 'Zeigt Kommentare einer Confluence Seite. Nutze dies für Diskussionen.',
        ];
    }
}