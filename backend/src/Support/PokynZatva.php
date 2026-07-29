<?php

declare(strict_types=1);

namespace Firol\Support;

/**
 * Payload of the "Pokyn na zabezpečenie ochrany pred požiarmi pri žatevných
 * prácach" (change request 2.3), stored in `trainings.fields`.
 *
 * Shape: { year: int, approver: ?string, sections: [{title, text}] }
 *
 *   year     — the harvest season the Pokyn covers.
 *   approver — per-document override of the company's schvaľujúca osoba;
 *              null means "use the one recorded on the company".
 *   sections — the instruction text as an ordered list of editable blocks.
 *              Kept with the document rather than referencing a shared
 *              template, because the technician edits it before generating
 *              and an issued document must keep saying what it said the day
 *              it was issued — even if the default template is later revised.
 */
final class PokynZatva
{
    private const MAX_SECTIONS      = 40;
    private const MAX_TITLE_LENGTH  = 191;
    private const MAX_TEXT_LENGTH   = 8000;

    /**
     * Normalizes a client payload into the canonical stored shape.
     * Sections with neither a title nor a text are dropped (the UI keeps
     * empty rows around while editing).
     *
     * @param array<string, mixed> $body
     * @return array{year: int, approver: ?string, sections: list<array{title: string, text: string}>}
     * @throws \DomainException with a user-facing Slovak message
     */
    public static function validate(array $body): array
    {
        $year = $body['year'] ?? null;
        if (is_string($year) && ctype_digit($year)) {
            $year = (int) $year;
        }
        if (!is_int($year) || $year < 1900 || $year > 2200) {
            throw new \DomainException('Rok žatevných prác musí byť v rozsahu 1900–2200.');
        }

        $approver = null;
        if (isset($body['approver']) && is_string($body['approver'])) {
            $approver = trim($body['approver']);
            if ($approver === '') {
                $approver = null;
            } elseif (mb_strlen($approver) > self::MAX_TITLE_LENGTH) {
                throw new \DomainException('Meno schvaľujúcej osoby je príliš dlhé (max 191 znakov).');
            }
        }

        $sectionsRaw = $body['sections'] ?? null;
        if (!is_array($sectionsRaw) || $sectionsRaw === []) {
            throw new \DomainException('Pokyn musí obsahovať aspoň jednu sekciu textu.');
        }
        if (count($sectionsRaw) > self::MAX_SECTIONS) {
            throw new \DomainException('Pokyn môže mať najviac ' . self::MAX_SECTIONS . ' sekcií.');
        }

        $sections = [];
        foreach ($sectionsRaw as $s) {
            if (!is_array($s)) {
                throw new \DomainException('Sekcie pokynu majú neplatný formát.');
            }
            $title = isset($s['title']) && is_string($s['title']) ? trim($s['title']) : '';
            $text  = isset($s['text'])  && is_string($s['text'])  ? trim($s['text'])  : '';
            if ($title === '' && $text === '') {
                continue;
            }
            if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
                throw new \DomainException('Nadpis sekcie je príliš dlhý (max 191 znakov).');
            }
            if (mb_strlen($text) > self::MAX_TEXT_LENGTH) {
                throw new \DomainException('Text sekcie je príliš dlhý (max 8000 znakov).');
            }
            $sections[] = ['title' => $title, 'text' => $text];
        }
        if ($sections === []) {
            throw new \DomainException('Pokyn musí obsahovať aspoň jednu vyplnenú sekciu.');
        }

        return ['year' => $year, 'approver' => $approver, 'sections' => $sections];
    }

    /**
     * Reads a stored payload back. Returns null for anything that isn't a
     * usable Pokyn — a NULL column (the six attendance-based training types)
     * or a row written before the document had its text filled in.
     *
     * @return array{year: int, approver: ?string, sections: list<array{title: string, text: string}>}|null
     */
    public static function decode(?string $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return null;
        }
        $sections = [];
        foreach ($decoded['sections'] ?? [] as $s) {
            if (!is_array($s)) {
                continue;
            }
            $sections[] = [
                'title' => (string) ($s['title'] ?? ''),
                'text'  => (string) ($s['text']  ?? ''),
            ];
        }
        if ($sections === []) {
            return null;
        }
        $approver = $decoded['approver'] ?? null;

        return [
            'year'     => (int) ($decoded['year'] ?? 0),
            'approver' => is_string($approver) && $approver !== '' ? $approver : null,
            'sections' => $sections,
        ];
    }
}
