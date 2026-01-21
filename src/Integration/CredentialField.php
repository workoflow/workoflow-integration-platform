<?php

namespace App\Integration;

class CredentialField
{
    /**
     * @param string $name Field name (used as form field key)
     * @param string $type Field type: text, url, email, password, oauth, select
     * @param string $label Human-readable label
     * @param string|null $placeholder Placeholder text
     * @param bool $required Whether field is required
     * @param string|null $description Help text / hint
     * @param array<string, string>|null $options For select type: ['value' => 'Display Label']
     * @param string|null $conditionalOn Field name this depends on (show only when condition met)
     * @param string|null $conditionalValue Value of conditionalOn field that enables this field
     */
    public function __construct(
        private string $name,
        private string $type, // text, url, email, password, oauth, select
        private string $label,
        private ?string $placeholder = null,
        private bool $required = true,
        private ?string $description = null,
        private ?array $options = null,
        private ?string $conditionalOn = null,
        private ?string $conditionalValue = null,
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

    /**
     * Get options for select type fields
     *
     * @return array<string, string>|null
     */
    public function getOptions(): ?array
    {
        return $this->options;
    }

    /**
     * Get the field name this field depends on
     */
    public function getConditionalOn(): ?string
    {
        return $this->conditionalOn;
    }

    /**
     * Get the value of conditionalOn field that enables this field
     */
    public function getConditionalValue(): ?string
    {
        return $this->conditionalValue;
    }

    /**
     * Check if this field has conditional visibility
     */
    public function isConditional(): bool
    {
        return $this->conditionalOn !== null;
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
            'options' => $this->options,
            'conditionalOn' => $this->conditionalOn,
            'conditionalValue' => $this->conditionalValue,
        ];
    }
}
