<?php

namespace App\Integration;

class CredentialField
{
    public function __construct(
        private string $name,
        private string $type, // text, url, email, password, oauth
        private string $label,
        private ?string $placeholder = null,
        private bool $required = true,
        private ?string $description = null
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getPlaceholder(): ?string
    {
        return $this->placeholder;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getHint(): ?string
    {
        return $this->description;
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'label' => $this->label,
            'placeholder' => $this->placeholder,
            'required' => $this->required,
            'description' => $this->description,
        ];
    }
}
