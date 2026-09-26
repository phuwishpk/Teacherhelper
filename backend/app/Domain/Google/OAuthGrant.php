<?php

namespace App\Domain\Google;

/**
 * What https://oauth2.googleapis.com/token answered. Holds tokens in memory
 * only; never log or serialise it.
 */
final readonly class OAuthGrant
{
    /**
     * @param  list<string>  $scopes  granted scopes (the `scope` field)
     */
    public function __construct(
        #[\SensitiveParameter] public string $accessToken,
        public int $expiresIn,
        #[\SensitiveParameter] public ?string $refreshToken,
        public array $scopes,
    ) {}

    public function __debugInfo(): array
    {
        return ['expiresIn' => $this->expiresIn, 'scopes' => $this->scopes, 'tokens' => '[hidden]'];
    }
}
