<?php

declare(strict_types=1);

namespace App\Domain\Notification\Support;

use App\Domain\Notification\Exceptions\FcmIsNotConfigured;
use App\Domain\Notification\Exceptions\FcmRequestFailed;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * An OAuth2 access token for a Google service account, minted and kept warm.
 *
 * ### Why this is forty lines instead of a dependency
 *
 * The plan called for `google/auth`. **It cannot be installed**: Composer's `block-insecure`
 * audit refuses to resolve `league/commonmark`, which `laravel/framework` requires, so *no* new
 * package can be added to this project until that is dealt with — a pre-existing condition
 * unrelated to notifications. The alternatives were to disable a security check for the whole
 * repository, or to write the one thing the library would have done. This is that thing.
 *
 * **We only ever *sign*, never *verify*.** That distinction is what makes hand-rolling this
 * reasonable rather than reckless: JWT's dangerous ground is verification — `alg: none`,
 * algorithm confusion, non-constant-time comparison — and none of it is on this path. Here the
 * algorithm is fixed at RS256, the key is ours, and the output is an assertion Google validates.
 *
 * `ext-openssl` is already required by the framework, so this adds nothing to deploy.
 *
 * The token is cached until shortly before it expires, so a burst of thirty pushes performs one
 * token exchange rather than thirty.
 */
final readonly class GoogleServiceAccountToken
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const CACHE_KEY = 'fcm.access_token';

    /** Renew this long before expiry, so a token cannot die mid-flight. */
    private const EXPIRY_MARGIN_SECONDS = 120;

    /**
     * @param  array<string, mixed>  $config  the `services.fcm` block, injected whole
     */
    public function __construct(private array $config) {}

    /**
     * A bearer token, from cache when one is still good.
     */
    public function get(): string
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $credentials = $this->credentials();
        $assertion = $this->assertion($credentials);

        $response = Http::asForm()
            ->connectTimeout((int) ($this->config['connect_timeout'] ?? 5))
            ->timeout((int) ($this->config['timeout'] ?? 15))
            // A callback rather than a `catch`, so RULES.md §5's no-try/catch rule holds here
            // exactly as it does in NawrisClient.
            ->throw(fn (Response $failed) => throw FcmRequestFailed::make($failed->status(), $failed->body()))
            ->post(self::TOKEN_URL, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);

        $token = (string) $response->json('access_token', '');
        $expiresIn = (int) $response->json('expires_in', 3600);

        if ($token === '') {
            throw FcmRequestFailed::make($response->status(), 'no access_token in the response');
        }

        Cache::put(self::CACHE_KEY, $token, max($expiresIn - self::EXPIRY_MARGIN_SECONDS, 60));

        return $token;
    }

    /**
     * The signed JWT that is exchanged for the access token.
     *
     * @param  array<string, mixed>  $credentials
     */
    private function assertion(array $credentials): string
    {
        $now = time();

        $header = $this->base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $this->base64Url((string) json_encode([
            'iss' => $credentials['client_email'],
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            // Google caps the assertion's life at an hour, and rejects anything longer.
            'exp' => $now + 3600,
        ]));

        $signature = '';

        // Returns false rather than throwing on a malformed key, so the result is checked
        // instead of trusted — an unreadable key would otherwise send an empty signature and
        // come back as a puzzling 400 from Google.
        $signed = openssl_sign(
            "{$header}.{$claims}",
            $signature,
            (string) $credentials['private_key'],
            OPENSSL_ALGO_SHA256,
        );

        if ($signed === false) {
            throw FcmIsNotConfigured::make();
        }

        return "{$header}.{$claims}.".$this->base64Url($signature);
    }

    /**
     * The service account JSON, read off disk.
     *
     * **Never committed and never logged.** It is a private key: the same class of secret as
     * `.env`, and it travels to each box out of band.
     *
     * @return array{client_email: string, private_key: string}
     */
    private function credentials(): array
    {
        $path = (string) ($this->config['credentials'] ?? '');

        if ($path === '' || ! is_file($path)) {
            throw FcmIsNotConfigured::make();
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)
            || ! is_string($decoded['client_email'] ?? null)
            || ! is_string($decoded['private_key'] ?? null)) {
            throw FcmIsNotConfigured::make();
        }

        return ['client_email' => $decoded['client_email'], 'private_key' => $decoded['private_key']];
    }

    /**
     * base64url — JWT's alphabet, which is base64 with two characters swapped and no padding.
     */
    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
