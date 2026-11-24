<?php

namespace App\Libraries\Workflow;

use App\Libraries\Core\Access;
use App\Libraries\Core\Db;
use App\Libraries\Core\Settings;
use App\Libraries\Core\Tenancy;
use Throwable;

/**
 * In-app notifications; e-mail copies are sent by the scheduled job. Messages carry a link
 * and a summary only, never an action: every decision is made in the app after sign-in.
 * A message belongs to the tenant it was raised in (or to the platform when raised outside any tenant).
 */
final class Notify
{
    public static function user(int $userId, string $type, string $title, ?string $body = null, ?string $link = null): void
    {
        Db::insert('notifications', [
            'tenant_id'  => Tenancy::id(),
            'user_id'    => $userId,
            'type'       => $type,
            'title'      => mb_substr($title, 0, 255),
            'body'       => $body === null ? null : mb_substr($body, 0, 1000),
            'link'       => $link,
            'created_at' => Db::now(),
        ]);
    }

    /** Everyone in the current organisation holding $perm at the warehouse (or organisation-wide), except $skip. */
    public static function holders(string $perm, ?int $locationId, string $type, string $title, ?string $body = null, ?string $link = null, ?int $skip = null): int
    {
        $n = 0;
        foreach (Access::usersWith($perm, $locationId) as $uid) {
            if ($uid !== $skip) {
                self::user($uid, $type, $title, $body, $link);
                $n++;
            }
        }
        return $n;
    }

    /** The job a message asked for is done: mark every unread message of these types that points at $link as read. */
    public static function resolve(string $link, array $types): int
    {
        if (! $types) {
            return 0;
        }
        return Db::exec('UPDATE {notifications} SET read_at = ? WHERE tenant_id = ? AND link = ? AND read_at IS NULL AND type IN (' . implode(',', array_fill(0, count($types), '?')) . ')',
            array_merge([Db::now(), Tenancy::require(), $link], $types));
    }

    /** The user has opened the page a message points at: their messages for that page are read. */
    public static function seen(int $userId, string $path): void
    {
        Db::exec('UPDATE {notifications} SET read_at = ? WHERE tenant_id <=> ? AND user_id = ? AND read_at IS NULL AND link = ?', [Db::now(), Tenancy::id(), $userId, '/' . ltrim($path, '/')]);
    }

    /**
     * core 1.1: the messages that pop up: the newest three unread, oldest first, each marked new when it arrived after
     * this device's last check ($after), and how many more are unread. Guest calls can be left out (they have their own pop-up).
     *
     * @return array{messages: list<array{id:int,title:string,body:string,link:string,new:bool}>, more:int}
     */
    public static function popups(int $userId, int $after, bool $skipGuest = false): array
    {
        $skip = $skipGuest ? " AND type NOT IN ('guest', 'guest_late')" : '';
        $rows = Db::rows('SELECT id, title, body, link FROM {notifications} WHERE tenant_id <=> ? AND user_id = ? AND read_at IS NULL' . $skip . ' ORDER BY id DESC LIMIT 3', [Tenancy::id(), $userId]);
        $all  = (int) Db::value('SELECT COUNT(*) FROM {notifications} WHERE tenant_id <=> ? AND user_id = ? AND read_at IS NULL' . $skip, [Tenancy::id(), $userId]);
        $out  = [];
        foreach (array_reverse($rows) as $r) {
            $out[] = ['id' => (int) $r['id'], 'title' => (string) $r['title'], 'body' => (string) $r['body'], 'link' => (string) $r['link'], 'new' => (int) $r['id'] > $after];
        }
        return ['messages' => $out, 'more' => max(0, $all - count($rows))];
    }
    /** core 1.1: read one message of this person (pop-up x, Open, Mark read). */
    public static function readOne(int $userId, int $id): void
    {
        Db::exec('UPDATE {notifications} SET read_at = ? WHERE tenant_id <=> ? AND user_id = ? AND id = ? AND read_at IS NULL', [Db::now(), Tenancy::id(), $userId, $id]);
    }
    /**
     * core 1.1: opening the page of one record reads the messages about that record. A list page (its last part is
     * not a number) reads nothing, so opening Approvals no longer reads every message that points to it.
     */
    public static function seenRecord(int $userId, string $path): void
    {
        $path = '/' . trim($path, '/');
        if (! preg_match('~/\d+$~', $path)) {
            return;
        }
        self::seen($userId, $path);
    }
    public static function unreadCount(int $userId): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM {notifications} WHERE tenant_id <=> ? AND user_id = ? AND read_at IS NULL', [Tenancy::id(), $userId]);
    }

    public static function list(int $userId, int $limit = 200): array
    {
        return Db::rows('SELECT * FROM {notifications} WHERE tenant_id <=> ? AND user_id = ? ORDER BY id DESC LIMIT ' . (int) $limit, [Tenancy::id(), $userId]);
    }

    public static function get(int $userId, int $id): ?array
    {
        return Db::row('SELECT * FROM {notifications} WHERE tenant_id <=> ? AND user_id = ? AND id = ?', [Tenancy::id(), $userId, $id]);
    }

    public static function readAll(int $userId): void
    {
        Db::exec('UPDATE {notifications} SET read_at = ? WHERE tenant_id <=> ? AND user_id = ? AND read_at IS NULL', [Db::now(), Tenancy::id(), $userId]);
    }

    /** Send pending staff e-mails for every tenant (scheduled job). Returns how many were sent. */
    public static function sendEmails(int $limit = 50): int
    {
        $from = Settings::get('mail_from');
        if (! $from) {
            return 0;
        }
        $rows = Db::unscoped(static fn () => Db::rows(
            "SELECT n.*, u.email, u.name, t.name tenant_name FROM {notifications} n JOIN {users} u ON u.id = n.user_id LEFT JOIN {tenants} t ON t.id = n.tenant_id
              WHERE n.emailed_at IS NULL AND u.email IS NOT NULL AND u.email <> '' AND n.created_at > ? ORDER BY n.id LIMIT " . (int) $limit,
            [gmdate('Y-m-d H:i:s', time() - 3 * 86400)]
        ));
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
                $email->setSubject(($n['tenant_name'] ? $n['tenant_name'] . ': ' : '') . $n['title']);
                $email->setMessage("Hello {$n['name']},\n\n{$n['title']}\n" . ($n['body'] ? "\n{$n['body']}\n" : '') . ($n['link'] ? "\nOpen: {$base}{$n['link']}\n" : '') . "\nSign in to act on it. This e-mail cannot be used to approve anything.\n");
                $email->setMailType('text');
                if ($email->send(false)) {
                    $sent++;
                }
            } catch (Throwable $e) {
                log_message('error', 'Mail failed: ' . $e->getMessage());
            }
            Db::unscoped(static fn () => Db::exec('UPDATE {notifications} SET emailed_at = ? WHERE id = ?', [Db::now(), $n['id']]));
        }
        return $sent;
    }
}
