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
}
