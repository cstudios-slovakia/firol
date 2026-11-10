<?php

declare(strict_types=1);

namespace Firol\Http;

/**
 * JSON response helpers. Each method exits the request — callers don't need
 * to `return` afterwards.
 */
final class Response
{
    /** @param array<string, mixed> $data */
    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }

    public static function noContent(): never
    {
        http_response_code(204);
        exit;
    }

    /** @param array<string, mixed> $extra */
    public static function error(string $message, int $status, array $extra = []): never
    {
        self::json(['error' => $message] + $extra, $status);
    }

    /**
     * The machine-readable part of an internal `['error' => …, 'status' => …]`
     * result — `code` and its companions (e.g. `cert` of a `cert_missing`) —
     * so a helper's refusal reaches the client as fully as a direct one.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public static function errorExtras(array $result): array
    {
        return array_intersect_key($result, ['code' => true, 'cert' => true]);
    }
}
