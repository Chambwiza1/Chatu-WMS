<?php

namespace App\Libraries\Workflow;

use App\Libraries\Core\Db;
use App\Libraries\Core\Settings;
use Throwable;

/**
 * In-app notifications; e-mail copies are sent by the scheduled job. Messages carry a link
 * and a summary only, never an action: every decision is made in the app after sign-in.
 */
final class Notify
{
    public static function user(int $userId, string $type, string $title, ?string $body = null, ?string $link = null): void
    {
        Db::insert('notifications', [
            'user_id'    => $userId,
            'type'       => $type,
            'title'      => mb_substr($title, 0, 255),
            'body'       => $body === null ? null : mb_substr($body, 0, 1000),
            'link'       => $link,
            'created_at' => Db::now(),
        ]);
    }

    /** Everyone holding $perm at the warehouse (or company-wide), except $skip. */
    public static function holders(string $perm, ?int $warehouseId, string $type, string $title, ?string $body = null, ?string $link = null, ?int $skip = null): int
    {
        $n = 0;
        foreach (\App\Libraries\Core\Access::usersWith($perm, $warehouseId) as $uid) {
            if ($uid !== $skip) {
                self::user($uid, $type, $title, $body, $link);
                $n++;
            }
        }
        return $n;
    }

    public static function unreadCount(int $userId): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM {notifications} WHERE user_id = ? AND read_at IS NULL', [$userId]);
    }

    /** Send pending e-mails (scheduled job). Returns how many were sent. */
    public static function sendEmails(int $limit = 50): int
    {
        $from = Settings::get('mail_from');
        if (! $from) {
            return 0;
        }
        $rows = Db::rows(
            "SELECT n.*, u.email, u.name FROM {notifications} n JOIN {users} u ON u.id = n.user_id
              WHERE n.emailed_at IS NULL AND u.email IS NOT NULL AND u.email <> '' AND n.created_at > ? ORDER BY n.id LIMIT " . (int) $limit,
            [gmdate('Y-m-d H:i:s', time() - 3 * 86400)]
        );
        $sent = 0;
        $base = rtrim((string) config('App')->baseURL, '/');
        foreach ($rows as $n) {
            try {
                $email = service('email');
                $smtp  = (string) Settings::get('smtp_host');
                $email->initialize($smtp !== '' ? [
                    'protocol' => 'smtp', 'SMTPHost' => $smtp, 'SMTPPort' => (int) (Settings::get('smtp_port') ?: 587),
                    'SMTPUser' => (string) Settings::get('smtp_user'), 'SMTPPass' => (string) Settings::get('smtp_pass'),
                    'SMTPCrypto' => (string) Settings::get('smtp_crypto', 'tls'), 'mailType' => 'text',
                ] : ['protocol' => 'mail', 'mailType' => 'text']);
                $email->clear();
                $email->setFrom($from, Settings::get('mail_from_name'));
                $email->setTo($n['email']);
                $email->setSubject($n['title']);
                $email->setMessage("Hello {$n['name']},\n\n{$n['title']}\n" . ($n['body'] ? "\n{$n['body']}\n" : '') . ($n['link'] ? "\nOpen: {$base}{$n['link']}\n" : '') . "\nSign in to act on it. This e-mail cannot be used to approve anything.\n");
                $email->setMailType('text');
                if ($email->send(false)) {
                    $sent++;
                }
            } catch (Throwable $e) {
                log_message('error', 'Mail failed: ' . $e->getMessage());
            }
            Db::exec('UPDATE {notifications} SET emailed_at = ? WHERE id = ?', [Db::now(), $n['id']]);
        }
        return $sent;
    }
}
