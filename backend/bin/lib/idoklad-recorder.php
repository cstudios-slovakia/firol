<?php

declare(strict_types=1);

/**
 * Test stand-in for iDoklad. Required by the billing test scripts BEFORE
 * vendor/autoload.php: it defines Firol\Idoklad\IDokladClient itself, so the
 * autoloader never loads the real client in that process and no code path —
 * also one that builds the client from env — can reach iDoklad. Every call is
 * recorded; answers look like iDoklad's.
 *
 * After the autoloader is loaded the test calls IDokladClient::assertStub(),
 * which aborts if the real class was loaded after all.
 */

namespace Firol\Idoklad;

require_once __DIR__ . '/../../src/Idoklad/IDokladApi.php';

final class IDokladClient implements IDokladApi
{
    /** The instance fromEnv() hands out, so a test can read what was sent. */
    public static ?self $shared = null;

    /** @var list<array{0: string, 1: string, 2: ?array<string, mixed>}> */
    public array $calls = [];
    public ?\Throwable $failInvoicePost = null;
    /** @var array<string, mixed>|null answer of the invoice POST instead of a normal one */
    public ?array $invoicePostAnswer = null;

    public function __construct(string $clientId = '', string $clientSecret = '')
    {
    }

    public static function fromEnv(): self
    {
        return self::$shared ??= new self();
    }

    public static function isConfigured(): bool
    {
        return true;
    }

    public static function assertStub(): void
    {
        $file = (new \ReflectionClass(self::class))->getFileName();
        if ($file !== __FILE__) {
            fwrite(STDERR, "Refusing: the real iDoklad client was loaded ($file).\n");
            exit(1);
        }
    }

    public function get(string $path): array
    {
        $this->calls[] = ['GET', $path, null];
        if ($path === 'IssuedInvoices/Default') {
            return [
                'Id' => 0, 'DocumentSerialNumber' => 42, 'NumericSequenceId' => 7, 'PaymentStatus' => 0,
                'Items' => [['Id' => 0, 'DiscountPercentage' => 0, 'IsTaxMovement' => true, 'PriceType' => 1, 'VatRateType' => 2, 'Unit' => 'ks']],
            ];
        }
        return ['PublicHtmlUrl' => 'https://example.com/idoklad/invoice'];
    }

    public function post(string $path, array $payload): array
    {
        $this->calls[] = ['POST', $path, $payload];
        if ($path === 'Contacts') {
            return ['Id' => 555];
        }
        if ($this->failInvoicePost !== null) {
            throw $this->failInvoicePost;
        }
        return $this->invoicePostAnswer ?? ['Id' => 9001, 'DocumentNumber' => 'FV-TEST-1', 'PublicHtmlUrl' => 'https://example.com/idoklad/9001'];
    }

    /** @return array<string, mixed>|null */
    public function lastInvoicePost(): ?array
    {
        foreach (array_reverse($this->calls) as [$m, $p, $body]) {
            if ($m === 'POST' && $p === 'IssuedInvoices') {
                return $body;
            }
        }
        return null;
    }

    public function invoicePosts(): int
    {
        return count(array_filter($this->calls, static fn (array $c): bool => $c[0] === 'POST' && $c[1] === 'IssuedInvoices'));
    }
}
