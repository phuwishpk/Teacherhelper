<?php

namespace App\Domain\Notifications\Fcm;

/**
 * The Firebase service account FCM sends with (DESIGN §7.6: the JSON key file
 * lives outside the document root, FIREBASE_CREDENTIALS holds its path).
 * Only the fields the OAuth JWT flow needs are kept; the private key is never
 * logged or serialised.
 */
final class ServiceAccount
{
    public const DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';

    private function __construct(
        public readonly string $projectId,
        public readonly string $clientEmail,
        #[\SensitiveParameter] private readonly string $privateKey,
        public readonly ?string $privateKeyId,
        public readonly string $tokenUri,
    ) {}

    /**
     * @param  string  $path  absolute, or relative to the Laravel base path
     * @param  string|null  $projectId  overrides project_id of the file (FIREBASE_PROJECT_ID)
     */
    public static function fromFile(string $path, ?string $projectId = null): self
    {
        $resolved = str_starts_with($path, '/') ? $path : base_path($path);
        if (! is_file($resolved) || ! is_readable($resolved)) {
            throw new FirebaseCredentialsInvalid('FIREBASE_CREDENTIALS: file not found or not readable');
        }

        try {
            $json = json_decode((string) file_get_contents($resolved), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new FirebaseCredentialsInvalid('FIREBASE_CREDENTIALS: not a JSON file');
        }

        return self::fromArray(is_array($json) ? $json : [], $projectId);
    }

    /**
     * @param  array<string, mixed>  $json  the downloaded service-account key
     */
    public static function fromArray(array $json, ?string $projectId = null): self
    {
        if (($json['type'] ?? null) !== 'service_account') {
            throw new FirebaseCredentialsInvalid('FIREBASE_CREDENTIALS: not a service-account key (type must be service_account)');
        }
        foreach (['client_email', 'private_key'] as $field) {
            if (! is_string($json[$field] ?? null) || trim($json[$field]) === '') {
                throw new FirebaseCredentialsInvalid("FIREBASE_CREDENTIALS: missing {$field}");
            }
        }
        $project = trim((string) ($projectId ?: ($json['project_id'] ?? '')));
        if ($project === '') {
            throw new FirebaseCredentialsInvalid('FIREBASE_CREDENTIALS: missing project_id (or set FIREBASE_PROJECT_ID)');
        }
        if (openssl_pkey_get_private($json['private_key']) === false) {
            throw new FirebaseCredentialsInvalid('FIREBASE_CREDENTIALS: private_key is not a valid PEM private key');
        }
        $tokenUri = is_string($json['token_uri'] ?? null) && str_starts_with($json['token_uri'], 'https://')
            ? $json['token_uri']
            : self::DEFAULT_TOKEN_URI;

        return new self(
            projectId: $project,
            clientEmail: trim($json['client_email']),
            privateKey: $json['private_key'],
            privateKeyId: is_string($json['private_key_id'] ?? null) ? $json['private_key_id'] : null,
            tokenUri: $tokenUri,
        );
    }

    public function privateKey(): string
    {
        return $this->privateKey;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['projectId' => $this->projectId, 'clientEmail' => $this->clientEmail, 'privateKey' => '[hidden]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('A service account is never serialised.');
    }
}
