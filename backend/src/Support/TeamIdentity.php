<?php

declare(strict_types=1);

namespace Firol\Support;

use PDO;
use PDOException;

/**
 * How a technician is told apart in the calendar, the timeline and the Dnes
 * screen — chapter 11.5.
 *
 * Colour belongs to the odbor (revízie / PO / BOZP), so a technician is told
 * apart by a circle with their initials, filled with an avatar colour of
 * their own. Both live on the membership (`account_users`), not on the user:
 * a person working for two firms is "FS" in bright teal in one of them and
 * may need to be "FSU" in violet in the other, because uniqueness is a
 * property of the team, not of the person.
 *
 * Rules:
 *  - initials are derived from the name ("Filip Sucháň" → FS), editable,
 *    at most three characters; when two members would share them, the later
 *    member gets a third character (FSU) — the member who had them first
 *    keeps theirs;
 *  - the colour comes from a fixed palette, the first one nobody in the
 *    account has; `account_users` has a unique key on (account_id,
 *    avatar_color), so two members can never end up sharing one even under
 *    a race.
 *
 * Assignment is idempotent — {@see ensure()} only fills a membership that has
 * no initials or no colour yet. It runs when a member joins (invite accept,
 * registration) and again lazily whenever the roster is read, which also
 * covers memberships created by paths that predate this (the import's
 * placeholder technicians).
 */
final class TeamIdentity
{
    /**
     * Avatar palette, in the order it is handed out. Deep enough for white
     * initials on top, and none of them is one of the three odbor colours
     * (#C75B45, #E8433A, #3D7FC1) — the avatar must never read as an odbor.
     * Twenty entries match the self-service seat cap; a bigger team gets
     * generated colours beyond these ({@see generatedColor()}).
     */
    public const PALETTE = [
        '#0F766E', // teal
        '#7C3AED', // violet
        '#B45309', // amber
        '#15803D', // green
        '#DB2777', // pink
        '#4338CA', // indigo
        '#0E7490', // cyan
        '#A21CAF', // fuchsia
        '#4D7C0F', // olive
        '#475569', // slate
        '#1E3A8A', // navy
        '#92400E', // brown
        '#059669', // emerald
        '#6B21A8', // purple
        '#0369A1', // sky
        '#BE123C', // rose
        '#78716C', // stone
        '#D97706', // orange
        '#1F2937', // graphite
        '#65A30D', // lime
    ];

    /** Grey for someone who is no longer on the team (their old úkony remain). */
    public const FORMER_MEMBER_COLOR = '#94A3B8';

    public const MAX_INITIALS = 3;

    /**
     * Initials from a full name: first letter of the first and of the last
     * word, upper-cased. A single word gives its first two letters. Import
     * placeholders carry the e-mail as their name — its local part is split
     * on dots, dashes and underscores so "jan.novak@…" still reads JN.
     */
    public static function deriveInitials(string $fullname): string
    {
        $words = self::nameWords($fullname);
        if ($words === []) {
            return '?';
        }
        if (count($words) === 1) {
            return mb_strtoupper(mb_substr($words[0], 0, 2));
        }
        return mb_strtoupper(mb_substr($words[0], 0, 1) . mb_substr($words[count($words) - 1], 0, 1));
    }

    /**
     * Initials that nobody in `$taken` has (compared case-insensitively).
     * The two-letter form first, then a third character from the surname
     * (FS → FSU), then from the first name (FIS), then a digit.
     *
     * @param list<string> $taken
     */
    public static function uniqueInitials(string $fullname, array $taken): string
    {
        $takenUpper = array_map(static fn (string $t): string => mb_strtoupper($t), $taken);
        $words = self::nameWords($fullname);
        $base  = self::deriveInitials($fullname);

        $candidates = [$base];
        if ($words !== []) {
            $first = $words[0];
            $last  = $words[count($words) - 1];
            if (count($words) > 1) {
                $candidates[] = mb_substr($first, 0, 1) . mb_substr($last, 0, 2);
                $candidates[] = mb_substr($first, 0, 2) . mb_substr($last, 0, 1);
            } else {
                $candidates[] = mb_substr($first, 0, 3);
            }
        }
        foreach ($candidates as $candidate) {
            $candidate = mb_strtoupper($candidate);
            if (mb_strlen($candidate) >= 1 && !in_array($candidate, $takenUpper, true)) {
                return $candidate;
            }
        }
        $stem = mb_substr($base, 0, 2);
        for ($n = 2; $n < 100; $n++) {
            $candidate = mb_strtoupper($stem . $n);
            if (mb_strlen($candidate) <= self::MAX_INITIALS && !in_array($candidate, $takenUpper, true)) {
                return $candidate;
            }
        }
        return $base;
    }

    /**
     * First palette colour not in `$taken`; past the palette, a generated one.
     *
     * @param list<string> $taken
     */
    public static function nextColor(array $taken): string
    {
        $takenUpper = array_map('strtoupper', $taken);
        foreach (self::PALETTE as $color) {
            if (!in_array($color, $takenUpper, true)) {
                return $color;
            }
        }
        for ($i = 0; $i < 360; $i++) {
            $color = self::generatedColor($i);
            if (!in_array($color, $takenUpper, true)) {
                return $color;
            }
        }
        return self::generatedColor(random_int(0, 1000));
    }

    /** True for a colour of the fixed palette (what the main user may pick). */
    public static function isPaletteColor(string $color): bool
    {
        return in_array(strtoupper($color), self::PALETTE, true);
    }

    /**
     * Fill in initials and colour for one membership if either is missing.
     * Safe to call repeatedly and from concurrent requests.
     */
    public static function ensure(PDO $pdo, int $accountId, int $userId): void
    {
        $stmt = $pdo->prepare(
            'SELECT au.initials, au.avatar_color, u.fullname
             FROM   account_users au
             JOIN   users u ON u.id = au.user_id
             WHERE  au.account_id = ? AND au.user_id = ?'
        );
        $stmt->execute([$accountId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return;
        }

        if ($row['initials'] === null || $row['initials'] === '') {
            $taken = self::column($pdo, $accountId, $userId, 'initials');
            $pdo->prepare(
                'UPDATE account_users SET initials = ?
                 WHERE  account_id = ? AND user_id = ? AND (initials IS NULL OR initials = "")'
            )->execute([self::uniqueInitials((string) $row['fullname'], $taken), $accountId, $userId]);
        }

        if ($row['avatar_color'] === null || $row['avatar_color'] === '') {
            // The unique key settles a race: whoever loses retries with the
            // next free colour.
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $taken = self::column($pdo, $accountId, $userId, 'avatar_color');
                try {
                    $pdo->prepare(
                        'UPDATE account_users SET avatar_color = ?
                         WHERE  account_id = ? AND user_id = ? AND avatar_color IS NULL'
                    )->execute([self::nextColor($taken), $accountId, $userId]);
                    break;
                } catch (PDOException $e) {
                    if ((string) $e->getCode() !== '23000') {
                        throw $e;
                    }
                }
            }
        }
    }

    /**
     * Assign identities to every member of the account that lacks one, in
     * the order they joined — so on a clash the earlier member keeps the
     * plain two letters.
     */
    public static function ensureAll(PDO $pdo, int $accountId): void
    {
        $stmt = $pdo->prepare(
            'SELECT user_id FROM account_users
             WHERE  account_id = ? AND (initials IS NULL OR initials = "" OR avatar_color IS NULL)
             ORDER  BY created_at ASC, user_id ASC'
        );
        $stmt->execute([$accountId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $userId) {
            self::ensure($pdo, $accountId, (int) $userId);
        }
    }

    /**
     * Every member of the account (active or not) with their identity — the
     * `team` block of /api/me that the calendar, the timeline and Dnes draw
     * avatars and the technician filter from.
     *
     * @return list<array{id: int, fullname: string, initials: string, avatar_color: string, is_active: bool}>
     */
    public static function roster(PDO $pdo, int $accountId): array
    {
        self::ensureAll($pdo, $accountId);

        $stmt = $pdo->prepare(
            'SELECT u.id, u.fullname, au.initials, au.avatar_color, au.is_active
             FROM   account_users au
             JOIN   users u ON u.id = au.user_id
             WHERE  au.account_id = ?
             ORDER  BY au.is_active DESC, u.fullname ASC'
        );
        $stmt->execute([$accountId]);

        return array_map(static fn (array $r): array => [
            'id'           => (int) $r['id'],
            'fullname'     => (string) $r['fullname'],
            'initials'     => (string) ($r['initials'] ?? self::deriveInitials((string) $r['fullname'])),
            'avatar_color' => (string) ($r['avatar_color'] ?? self::FORMER_MEMBER_COLOR),
            'is_active'    => (bool) $r['is_active'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Validates edited initials: trimmed, 1–3 characters, no whitespace.
     * Returns null when the value is not acceptable.
     */
    public static function normalizeInitials(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > self::MAX_INITIALS || preg_match('/\s/u', $value)) {
            return null;
        }
        return $value;
    }

    /** @return list<string> */
    private static function column(PDO $pdo, int $accountId, int $exceptUserId, string $column): array
    {
        $stmt = $pdo->prepare(
            "SELECT $column FROM account_users
             WHERE  account_id = ? AND user_id <> ? AND $column IS NOT NULL AND $column <> ''"
        );
        $stmt->execute([$accountId, $exceptUserId]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<string> */
    private static function nameWords(string $fullname): array
    {
        $name = trim($fullname);
        if (str_contains($name, '@')) {
            $name = (string) strstr($name, '@', true);
            $name = (string) preg_replace('/[._\-+]+/u', ' ', $name);
        }
        // Titles before or after the name ("Ing. Ján Novák, PhD.") are not
        // part of anyone's initials.
        $words = preg_split('/[\s,]+/u', $name) ?: [];
        $words = array_values(array_filter(
            $words,
            static fn (string $w): bool => $w !== '' && !str_ends_with($w, '.') && preg_match('/\p{L}/u', $w) === 1,
        ));
        return array_map(
            static fn (string $w): string => (string) preg_replace('/[^\p{L}\p{N}]/u', '', $w),
            $words,
        );
    }

    /** A deterministic extra colour for teams larger than the palette. */
    private static function generatedColor(int $i): string
    {
        // Golden-angle hue steps, fixed saturation/lightness dark enough for
        // white text.
        $h = fmod($i * 137.508 + 20.0, 360.0) / 360.0;
        $s = 0.55;
        $l = 0.36;
        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;
        $rgb = array_map(static function (float $t) use ($p, $q): int {
            if ($t < 0) {
                $t += 1;
            }
            if ($t > 1) {
                $t -= 1;
            }
            $v = match (true) {
                $t < 1 / 6 => $p + ($q - $p) * 6 * $t,
                $t < 1 / 2 => $q,
                $t < 2 / 3 => $p + ($q - $p) * (2 / 3 - $t) * 6,
                default    => $p,
            };
            return (int) round($v * 255);
        }, [$h + 1 / 3, $h, $h - 1 / 3]);
        return sprintf('#%02X%02X%02X', ...$rgb);
    }
}
