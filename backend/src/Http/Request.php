<?php

declare(strict_types=1);

namespace Firol\Http;

/**
 * Thin wrapper around the PHP request superglobals. Lazy-decodes the JSON
 * body once on first access.
 */
final class Request
{
    /** @var array<string, mixed>|null */
    private ?array $jsonCache = null;

    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    /**
     * Resolves the route path. Production transports it as `?path=` (no
     * mod_rewrite); local dev hits index.php directly through nginx so the
     * path lives in REQUEST_URI.
     */
    public function path(): string
    {
        if (isset($_GET['path']) && is_string($_GET['path']) && $_GET['path'] !== '') {
            return $_GET['path'];
        }
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        return is_string($uri) && $uri !== '' ? $uri : '/';
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        if ($this->jsonCache !== null) {
            return $this->jsonCache;
        }
        $body = file_get_contents('php://input') ?: '';
        $decoded = json_decode($body, true);
        $this->jsonCache = is_array($decoded) ? $decoded : [];
        return $this->jsonCache;
    }

    public function jsonString(string $key): ?string
    {
        $value = $this->json()[$key] ?? null;
        return is_string($value) ? trim($value) : null;
    }

    public function jsonInt(string $key): ?int
    {
        $value = $this->json()[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }
        return null;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $_SERVER[$key] ?? null;
        return is_string($value) ? $value : null;
    }
}
