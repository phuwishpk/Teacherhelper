<?php

namespace App\Domain\Auth\Google;

/**
 * What a verified Google ID token says about its account (DESIGN §24.9.2).
 * Every sign-in and link path uses only this, never a value the client sent
 * next to the token. `email` is lower case and verified by Google.
 */
final class VerifiedGoogleIdentity
{
    public function __construct(
        public readonly string $sub,
        public readonly string $email,
        public readonly ?string $name = null,
        public readonly ?string $picture = null,
        public readonly ?string $hd = null,
    ) {}

    /** The part after the last `@` of the verified e-mail, lower case. */
    public function domain(): string
    {
        $at = strrpos($this->email, '@');

        return $at === false ? '' : substr($this->email, $at + 1);
    }

    /** @return array{sub: string, email: string, name: ?string, picture: ?string, hd: ?string} */
    public function toArray(): array
    {
        return [
            'sub' => $this->sub,
            'email' => $this->email,
            'name' => $this->name,
            'picture' => $this->picture,
            'hd' => $this->hd,
        ];
    }

    /** @param  mixed  $data  a toArray() read back from the cache */
    public static function fromArray(mixed $data): ?self
    {
        if (! is_array($data) || ! is_string($data['sub'] ?? null) || ! is_string($data['email'] ?? null)) {
            return null;
        }
        $optional = fn (string $key) => is_string($data[$key] ?? null) ? $data[$key] : null;

        return new self($data['sub'], $data['email'], $optional('name'), $optional('picture'), $optional('hd'));
    }
}
