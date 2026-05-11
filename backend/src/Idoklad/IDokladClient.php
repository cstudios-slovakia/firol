<?php

declare(strict_types=1);

namespace Firol\Idoklad;

/**
 * Thin iDoklad API v3 client. Authenticates with OAuth2
 * client_credentials and caches the access token in the running
 * process (~1 h lifetime, so a single PHP-FPM worker doesn't re-auth
 * on every webhook).
 *
 * iDoklad has no sandbox — the real API is the only one. We protect
 * dev/staging environments by routing every IssuedInvoice through
 * Draft mode (status=4 in iDoklad lingo) when IDOKLAD_DRAFT_MODE=true.
 * Drafts don't burn document numbers and don't get sent to clients.
 *
 * Docs: https://api.idoklad.cz/Help/v3/cs/
 */
final class IDokladClient
{
    /**
     * @see https://api.idoklad.cz/Help/v3/cs/    Authorization section.
     */
    private const TOKEN_URL = 'https://identity.idoklad.cz/server/connect/token';
    private const API_BASE  = 'https://api.idoklad.cz/v3';

    /**
     * iDoklad invoice status codes from the IssuedInvoiceStatus enum.
     * 0 = New, 1 = Issued, 4 = Draft, etc. We only ever set Draft or
     * leave Stripe-side default (Issued).
     */
    public const STATUS_ISSUED = 1;
    public const STATUS_DRAFT  = 4;

    private static ?string $cachedToken = null;
    private static int     $cachedTokenExpiresAt = 0;

    public function __construct(
        private string $clientId,
        private string $clientSecret,
    ) {}

    public static function fromEnv(): self
    {
        $id     = (string) ($_ENV['IDOKLAD_CLIENT_ID']     ?? '');
        $secret = (string) ($_ENV['IDOKLAD_CLIENT_SECRET'] ?? '');
        if ($id === '' || $secret === '') {
            throw new \RuntimeException('iDoklad credentials not configured');
        }
        return new self($id, $secret);
    }

    public static function isConfigured(): bool
    {
        return ($_ENV['IDOKLAD_CLIENT_ID'] ?? '') !== ''
            && ($_ENV['IDOKLAD_CLIENT_SECRET'] ?? '') !== '';
    }

    public static function isDraftMode(): bool
    {
        $v = strtolower((string) ($_ENV['IDOKLAD_DRAFT_MODE'] ?? 'true'));
        return in_array($v, ['1', 'true', 'yes'], true);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload): array
    {
        return $this->request('POST', $path, $payload);
    }

    /** @return array<string, mixed> */
    public function get(string $path): array
    {
        return $this->request('GET', $path, null);
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $payload): array
    {
        $token = $this->accessToken();
        $url   = self::API_BASE . '/' . ltrim($path, '/');

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 15,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
        }

        $body   = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($body === '' && $err !== '') {
            throw new \RuntimeException("iDoklad transport error: $err");
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("iDoklad $method $path failed: HTTP $status — $body");
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return [];
        }
        // iDoklad wraps everything in { Data: {...}, IsSuccess: true|false,
        // Message, StatusCode, ErrorCode }. Honour IsSuccess and unwrap
        // Data so callers don't need to know about the envelope.
        if (array_key_exists('IsSuccess', $decoded)) {
            if ($decoded['IsSuccess'] === false) {
                $msg = (string) ($decoded['Message'] ?? 'unknown error');
                throw new \RuntimeException("iDoklad $method $path: $msg");
            }
            return is_array($decoded['Data'] ?? null) ? $decoded['Data'] : [];
        }
        return $decoded;
    }

    private function accessToken(): string
    {
        if (self::$cachedToken !== null && self::$cachedTokenExpiresAt > time() + 30) {
            return self::$cachedToken;
        }

        $ch = curl_init(self::TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS     => http_build_query([
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type'    => 'client_credentials',
                'scope'         => 'idoklad_api',
            ]),
            CURLOPT_TIMEOUT => 15,
        ]);
        $body   = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("iDoklad token endpoint returned HTTP $status: $body");
        }
        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['access_token'])) {
            throw new \RuntimeException("iDoklad token response missing access_token: $body");
        }
        $expiresIn = isset($data['expires_in']) ? (int) $data['expires_in'] : 3600;
        self::$cachedToken         = (string) $data['access_token'];
        self::$cachedTokenExpiresAt = time() + $expiresIn;
        return self::$cachedToken;
    }
}
