<?php

declare(strict_types=1);

namespace Firol\Mail;

/**
 * Plain DTO returned by templates and consumed by Mailer::send().
 * Kept separate so templates have a stable contract and call sites
 * don't have to remember positional arguments.
 */
final class Message
{
    /**
     * @param list<array{filename: string, content: string}> $attachments
     *        Attachments: filename + base64-encoded content (decoded by the
     *        transport before send).
     */
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $html,
        public readonly string $text,
        public readonly ?string $replyTo = null,
        public readonly array $attachments = [],
    ) {}
}
