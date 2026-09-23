<?php

declare(strict_types=1);

namespace Firol\Auth;

use Firol\Db;
use PDO;

/**
 * Persistent "remember me" login.
 *
 * A long-lived cookie keeps a user signed in across browser restarts and past
 * the short PHP session idle-timeout. The cookie carries `<selector>:<validator>`:
 *   - selector  — random public lookup key (indexed column)
 *   - validator — random secret; only its SHA-256 hash is stored, so a leaked
 *                 database cannot be used to forge cookies
 *
 * On every successful resume the validator is rotated and the expiry slid
 * forward, so a stolen cookie stops working shortly after the legitimate
 * browser makes its next request (the replaced value is honoured only for a
 * brief grace window, for sibling requests in the same burst), and the
 * absolute window is capped by inactivity.
 *
 * Cookie matches the session cookie's hardening: HttpOnly, SameSite=Lax,
 * Secure on production.
 */
final class RememberToken
{
    private const COOKIE   = 'firol_remember';
    private const TTL_DAYS = 30;

    /**
     * How long the previous validator stays valid after a rotation. After an
     * idle timeout the SPA fires several requests at once, all with the same
     * cookie; only the first rotates it, and the rest would otherwise look like
     * a replayed (stolen) token and log the user out. Short enough that a
     * genuinely stolen old value is useless shortly after the owner's browser
     * moved on.
     */
    private const ROTATION_GRACE_SECONDS = 30;

    /** Issue a fresh token for $userId/$accountId and set the cookie. */
    public static function issue(int $userId, int $accountId): void
    {
        $selector  = bin2hex(random_bytes(9));   // 18 hex chars — matches CHAR(18)
        $validator = bin2hex(random_bytes(32));  // 64 hex chars
        $expires   = new \DateTimeImmutable('+' . self::TTL_DAYS . ' days');

        Db::pdo()->prepare(
            'INSERT INTO remember_tokens (selector, validator_hash, user_id, account_id, expires_at)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $selector,
            hash('sha256', $validator),
            $userId,
            $accountId,
            $expires->format('Y-m-d H:i:s'),
        ]);

        self::setCookie($selector . ':' . $validator, $expires->getTimestamp());
    }

    /**
     * If the session has no user but a valid remember cookie is present,
     * re-establish the session (user + active account), rotate the token and
     * slide the expiry. No-op otherwise — safe to call on every request.
     */
    public static function resume(): void
    {
        if (Session::userId() !== null) {
            return; // already authenticated this request
        }

        $raw = $_COOKIE[self::COOKIE] ?? null;
        if (!is_string($raw) || !str_contains($raw, ':')) {
            return;
        }
        [$selector, $validator] = explode(':', $raw, 2);
        if ($selector === '' || $validator === '') {
            return;
        }

        $pdo  = Db::pdo();
        $stmt = $pdo->prepare(
            'SELECT id, validator_hash, prev_validator_hash, user_id, account_id, expires_at,
                    (rotated_at IS NOT NULL
                     AND rotated_at >= NOW() - INTERVAL ' . self::ROTATION_GRACE_SECONDS . ' SECOND) AS in_grace
             FROM remember_tokens WHERE selector = ?'
        );
        $stmt->execute([$selector]);
        $row = $stmt->fetch();

        if ($row === false) {
            return;
        }
        if (strtotime((string) $row['expires_at']) < time()) {
            self::deleteById((int) $row['id']);
            self::clearCookie();
            return;
        }

        // Constant-time compare against the stored hash.
        $presentedHash = hash('sha256', $validator);
        $isCurrent     = hash_equals((string) $row['validator_hash'], $presentedHash);

        // A validator that was replaced moments ago comes from a sibling request
        // fired in the same burst, not from an attacker (see ROTATION_GRACE_SECONDS).
        $isRecentPrevious = !$isCurrent
            && (bool) $row['in_grace']
            && is_string($row['prev_validator_hash'])
            && hash_equals($row['prev_validator_hash'], $presentedHash);

        // Any other selector match with a validator mismatch is suspicious
        // (theft/replay) — kill the token.
        if (!$isCurrent && !$isRecentPrevious) {
            self::deleteById((int) $row['id']);
            self::clearCookie();
            return;
        }

        $userId    = (int) $row['user_id'];
        $accountId = self::resolveAccount($pdo, $userId, (int) $row['account_id']);
        if ($accountId === null) {
            // User no longer belongs to any active account — token is useless.
            self::deleteById((int) $row['id']);
            self::clearCookie();
            return;
        }

        // Re-establish the session (setUserId regenerates the session id).
        Session::setUserId($userId);
        Session::setActiveAccountId($accountId);

        if ($isRecentPrevious) {
            // The sibling request that rotated the token is sending the browser
            // the new cookie. Leave it alone — rotating again would invalidate
            // the value the browser is about to store.
            return;
        }

        // Rotate the validator and slide the expiry forward. The UPDATE only
        // applies while the row still holds the validator we just checked, so
        // when two requests of a burst both matched the current value, only one
        // rotates; the other would otherwise overwrite it and hand the browser a
        // cookie that no longer matches.
        $newValidator = bin2hex(random_bytes(32));
        $newExpires   = new \DateTimeImmutable('+' . self::TTL_DAYS . ' days');
        $update = $pdo->prepare(
            'UPDATE remember_tokens
             SET prev_validator_hash = validator_hash, validator_hash = ?, rotated_at = NOW(),
                 account_id = ?, expires_at = ?, last_used_at = NOW()
             WHERE id = ? AND validator_hash = ?'
        );
        $update->execute([
            hash('sha256', $newValidator),
            $accountId,
            $newExpires->format('Y-m-d H:i:s'),
            (int) $row['id'],
            $presentedHash,
        ]);
        if ($update->rowCount() === 0) {
            return; // a concurrent request rotated first — its cookie wins
        }
        self::setCookie($selector . ':' . $newValidator, $newExpires->getTimestamp());
    }

    /** Delete the current browser's token and clear its cookie (on logout). */
    public static function clear(): void
    {
        $raw = $_COOKIE[self::COOKIE] ?? null;
        if (is_string($raw) && str_contains($raw, ':')) {
            [$selector] = explode(':', $raw, 2);
            if ($selector !== '') {
                Db::pdo()->prepare('DELETE FROM remember_tokens WHERE selector = ?')
                    ->execute([$selector]);
            }
        }
        self::clearCookie();
    }

    /**
     * Honour the account stored in the token when it is still an active
     * membership; otherwise fall back to the user's first active account.
     * Returns null when the user has no active account at all.
     */
    private static function resolveAccount(PDO $pdo, int $userId, int $preferredAccountId): ?int
    {
        $stmt = $pdo->prepare(
            'SELECT account_id FROM account_users
             WHERE user_id = ? AND is_active = 1
             ORDER BY (account_id = ?) DESC, account_id ASC LIMIT 1'
        );
        $stmt->execute([$userId, $preferredAccountId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    private static function deleteById(int $id): void
    {
        Db::pdo()->prepare('DELETE FROM remember_tokens WHERE id = ?')->execute([$id]);
    }

    private static function setCookie(string $value, int $expires): void
    {
        setcookie(self::COOKIE, $value, [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => self::isProd(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE] = $value;
    }

    private static function clearCookie(): void
    {
        setcookie(self::COOKIE, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => self::isProd(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[self::COOKIE]);
    }

    private static function isProd(): bool
    {
        return ($_ENV['APP_ENV'] ?? 'local') === 'production';
    }
}
