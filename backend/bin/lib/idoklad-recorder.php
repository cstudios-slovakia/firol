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

/*
 * Environment of a test process, set BEFORE Dotenv reads backend/.env (an
 * immutable Dotenv never overwrites a variable that exists, also an empty
 * one):
 *
 *  - SMTP is always blank: no test process can reach a real mail server
 *    (with real SMTP in .env it would mail the real admin).
 *  - Every name listed in FIROL_TEST_BLANK (comma separated) is blank too.
 *    The test scripts list there each variable they want empty in a child —
 *    proc_open() drops variables with an empty value, so `IDOKLAD_CLIENT_ID=`
 *    alone would let the child load the real credentials from .env.
 *  - FIROL_TEST_MAIL_CAPTURE (a file path) makes Mailer append every message
 *    to that file instead of sending it, and count it as sent.
 *
 * A child (FIROL_TEST_BLANK set) checks at its end that the blank names are
 * still blank and writes „[recorder-env] ok" or „[recorder-env] LEAK: …" to
 * STDERR; the parent asserts it.
 */
(static function (): void {
    $blank = ['SMTP_HOST', 'SMTP_USERNAME', 'SMTP_PASSWORD'];
    $listed = (string) (getenv('FIROL_TEST_BLANK') ?: '');
    foreach (array_filter(array_map('trim', explode(',', $listed))) as $name) {
        $blank[] = $name;
    }
    $blank = array_values(array_unique($blank));
    foreach ($blank as $name) {
        $_ENV[$name] = '';
        $_SERVER[$name] = '';
        putenv("$name=");
    }
    $capture = (string) (getenv('FIROL_TEST_MAIL_CAPTURE') ?: '');
    if ($capture !== '' && !defined('FIROL_TEST_MAIL_CAPTURE')) {
        define('FIROL_TEST_MAIL_CAPTURE', $capture);
    }
    if ($listed !== '') {
        register_shutdown_function(static function () use ($blank): void {
            $leaks = array_values(array_filter($blank, static fn (string $n): bool => (string) ($_ENV[$n] ?? '') !== '' || (string) (getenv($n) ?: '') !== ''));
            fwrite(STDERR, $leaks === [] ? "[recorder-env] ok\n" : '[recorder-env] LEAK: ' . implode(', ', $leaks) . "\n");
        });
    }
})();

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

    /**
     * The shared recorder. A child process started with
     * IDOKLAD_RECORDER_FAIL=1 gets one whose invoice POST fails (HTTP 400),
     * so a test can drive the daily run through a failing iDoklad.
     */
    public static function fromEnv(): self
    {
        if (self::$shared === null) {
            self::$shared = new self();
            if ((string) ($_ENV['IDOKLAD_RECORDER_FAIL'] ?? getenv('IDOKLAD_RECORDER_FAIL') ?: '') === '1') {
                self::$shared->failInvoicePost = new \RuntimeException('iDoklad POST IssuedInvoices failed: HTTP 400 — recorder told to fail');
            }
            // IDOKLAD_RECORDER_FAIL_SEND=1: the mail send fails with HTTP 400;
            // =timeout: no definite answer (transport error).
            $sendFail = (string) ($_ENV['IDOKLAD_RECORDER_FAIL_SEND'] ?? getenv('IDOKLAD_RECORDER_FAIL_SEND') ?: '');
            if ($sendFail === '1') {
                self::$shared->failSend = new \RuntimeException('iDoklad POST Mails/IssuedInvoice/Send failed: HTTP 400 — recorder told to fail');
            } elseif ($sendFail === 'timeout') {
                self::$shared->failSend = new IDokladTransportException('iDoklad transport error: Operation timed out (recorder)');
            }
        }
        return self::$shared;
    }

    /**
     * Same rule as the real client (both credentials set), so a test can
     * show the `skipped` path; the values are never used for anything.
     */
    public static function isConfigured(): bool
    {
        return ($_ENV['IDOKLAD_CLIENT_ID'] ?? '') !== ''
            && ($_ENV['IDOKLAD_CLIENT_SECRET'] ?? '') !== '';
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
        if ($path === 'IssuedInvoices/Recount') {
            if ($this->recountAnswer !== null) {
                return $this->recountAnswer;
            }
            return ['Prices' => ['TotalWithVat' => self::recount((array) ($payload['Items'] ?? []))]];
        }
        if ($path === 'Mails/IssuedInvoice/Send') {
            if ($this->failSend !== null) {
                throw $this->failSend;
            }
            return $this->sendAnswer ?? ['Sent' => ['customer@example.com'], 'NotSent' => []];
        }
        if ($this->failInvoicePost !== null) {
            throw $this->failInvoicePost;
        }
        return $this->invoicePostAnswer ?? ['Id' => 9001, 'DocumentNumber' => 'FV-TEST-1', 'PublicHtmlUrl' => 'https://example.com/idoklad/9001'];
    }

    public function put(string $path, ?array $payload = null): array
    {
        $this->calls[] = ['PUT', $path, $payload];
        if (str_starts_with($path, 'IssuedDocumentPayments/FullyPay/') && $this->failMarkPaid !== null) {
            throw $this->failMarkPaid;
        }
        return ['Value' => true];
    }

    public function patch(string $path, array $payload): array
    {
        $this->calls[] = ['PATCH', $path, $payload];
        return ['Id' => (int) ($payload['Id'] ?? 0)];
    }

    /**
     * The document total in EUR as the recorder assumes iDoklad counts it:
     * per item, an item priced without VAT (PriceType 1) gets its VAT
     * rounded to the cent (half away from zero) on Amount × UnitPrice, an
     * item priced with VAT (PriceType 0) counts as it is. The real rule is not
     * in the iDoklad reference — InvoiceIssuer compares iDoklad's own Recount
     * with the amount paid before every POST.
     *
     * @param list<array<string, mixed>> $items
     */
    public static function recount(array $items): float
    {
        $cents = 0;
        foreach ($items as $i) {
            $line = (int) round((float) $i['Amount'] * (float) $i['UnitPrice'] * 100);
            $cents += (int) ($i['PriceType'] ?? 1) === 0 ? $line : $line + (int) round($line * self::$vatPercent / 100);
        }
        return round($cents / 100, 2);
    }

    /** VAT percent the recorder's recount uses for a Basic-rate item. */
    public static float $vatPercent = 23.0;

    /** @var array<string, mixed>|null answer of the Recount instead of the computed one */
    public ?array $recountAnswer = null;
    public ?\Throwable $failSend = null;
    /** @var array<string, mixed>|null answer of the mail send instead of a normal one */
    public ?array $sendAnswer = null;
    public ?\Throwable $failMarkPaid = null;

    /** Calls made to one path (method + path prefix). */
    public function count(string $method, string $pathPrefix): int
    {
        return count(array_filter($this->calls, static fn (array $c): bool => $c[0] === $method && str_starts_with($c[1], $pathPrefix)));
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
