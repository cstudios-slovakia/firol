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
 * forward, so a stolen cookie stops working the moment the legitimate browser
 * makes its next request, and the absolute window is capped by inactivity.
 *
 * Cookie matches the session cookie's hardening: HttpOnly, SameSite=Lax,
 * Secure on production.
 */
final class RememberToken
{
    private const COOKIE   = 'firol_remember';
    private const TTL_DAYS = 30;

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
            'SELECT id, validator_hash, user_id, account_id, expires_at
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
        // Constant-time compare against the stored hash. A selector match with a
        // validator mismatch is suspicious (theft/replay) — kill the token.
        if (!hash_equals((string) $row['validator_hash'], hash('sha256', $validator))) {
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

        // Rotate the validator and slide the expiry forward.
        $newValidator = bin2hex(random_bytes(32));
        $newExpires   = new \DateTimeImmutable('+' . self::TTL_DAYS . ' days');
        $pdo->prepare(
            'UPDATE remember_tokens
             SET validator_hash = ?, account_id = ?, expires_at = ?, last_used_at = NOW()
             WHERE id = ?'
        )->execute([
            hash('sha256', $newValidator),
            $accountId,
            $newExpires->format('Y-m-d H:i:s'),
            (int) $row['id'],
        ]);
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
