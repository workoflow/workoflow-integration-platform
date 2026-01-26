<?php

namespace App\OAuth2;

use League\OAuth2\Client\Provider\ResourceOwnerInterface;

/**
 * Wrike Resource Owner (current user)
 */
class WrikeResourceOwner implements ResourceOwnerInterface
{
    /**
     * @param array<string, mixed> $response
     */
    public function __construct(
        protected array $response
    ) {
    }

    /**
     * Get user ID
     *
     * @return string|null
     */
    public function getId(): ?string
    {
        $data = $this->response['data'][0] ?? [];
        return $data['id'] ?? null;
    }

    /**
     * Get user email
     *
     * @return string|null
     */
    public function getEmail(): ?string
    {
        $data = $this->response['data'][0] ?? [];
        $profiles = $data['profiles'] ?? [];
        return $profiles[0]['email'] ?? null;
    }

    /**
     * Get first name
     *
     * @return string|null
     */
    public function getFirstName(): ?string
    {
        $data = $this->response['data'][0] ?? [];
        return $data['firstName'] ?? null;
    }

    /**
     * Get last name
     *
     * @return string|null
     */
    public function getLastName(): ?string
    {
        $data = $this->response['data'][0] ?? [];
        return $data['lastName'] ?? null;
    }

    /**
     * Return all response data
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->response;
    }
}
