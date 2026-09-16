<?php

declare(strict_types=1);

namespace Firol\Mail;

use Firol\Db;

/**
 * Reply-To address for protocol e-mails sent to a client.
 *
 * MAIL_FROM is a noreply@ address shared by the whole platform, so without a
 * Reply-To a client who receives a protocol and hits Reply reaches nobody.
 * The address that belongs there is the technician who pressed send: they
 * performed the inspection and are the one person able to answer a question
 * about that particular protocol.
 *
 * Platform admins are the exception. An admin can send a protocol belonging
 * to an account they are not a member of (the `Admin::isAdmin` branch in
 * DocumentController::emailDocument), and routing a client's reply to the
 * platform operator rather than to their own supplier would be wrong. When
 * the sender is not an active member of the owning account we therefore fall
 * back to that account's main user.
 *
 * Only protocol mail uses this. PasswordResetEmail, InviteEmail and
 * InvoiceFallbackEmail are platform mail — noreply is correct for those.
 */
final class ReplyTo
{
    /**
     * @param int $accountId Account that owns the document being sent.
     * @param int $userId    User performing the send.
     *
     * @return string|null Null only if neither address exists, which the
     *                     schema should prevent; Message treats null as
     *                     "no Reply-To header" rather than failing the send.
     */
    public static function forSender(int $accountId, int $userId): ?string
    {
        $pdo = Db::pdo();

        $stmt = $pdo->prepare(
            'SELECT u.email
             FROM   users u
             JOIN   account_users au ON au.user_id = u.id
             WHERE  au.account_id = ? AND au.is_active = 1 AND u.id = ?'
        );
        $stmt->execute([$accountId, $userId]);
        $email = $stmt->fetchColumn();
        if (is_string($email) && $email !== '') {
            return $email;
        }

        $stmt = $pdo->prepare(
            'SELECT u.email
             FROM   accounts a
             JOIN   users u ON u.id = a.main_user_id
             WHERE  a.id = ?'
        );
        $stmt->execute([$accountId]);
        $owner = $stmt->fetchColumn();

        return is_string($owner) && $owner !== '' ? $owner : null;
    }
}
