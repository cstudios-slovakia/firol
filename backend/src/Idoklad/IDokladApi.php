<?php

declare(strict_types=1);

namespace Firol\Idoklad;

/**
 * The two iDoklad calls InvoiceIssuer needs. IDokladClient is the real one;
 * the billing test scripts pass a recorder instead, so no test ever reaches
 * the accounting system.
 */
interface IDokladApi
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload): array;

    /** @return array<string, mixed> */
    public function get(string $path): array;
}
