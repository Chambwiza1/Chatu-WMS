<?php

namespace App\Libraries\Messaging;

use App\Libraries\Core\Access;
use App\Libraries\Core\Audit;
use App\Libraries\Core\Auth;
use App\Libraries\Core\BizException;
use App\Libraries\Core\Db;
use App\Libraries\Core\NotFoundException;
use App\Libraries\Core\Tenancy;

/**
 * The outbox (core 1.4, E5; from ChatuPass): every e-mail and WhatsApp message an organisation sends, one row per
 * person per channel: copies of its people's messages, and notices to its customers.
 *
 * Messages are queued, then sent by the scheduled job. Each goes out through the organisation's own sender
 * (Senders); while it has none, its messages wait, untouched, and its administrators get an alert. A run claims rows with a single UPDATE, so two runs never send
 * the same message. A temporary failure is tried again after 1, 5, 15 and 60 minutes (5 tries in all); a permanent one
 * (not on WhatsApp, template missing, token expired) fails at once. The text is
 * stored encrypted and wiped once the message is sent or cancelled, and a week after it failed for good.
 *
 * Delivery reports from WhatsApp (webhook) move a sent message to delivered and read, or to failed.
 */
final class Outbox
{
    public const BACKOFF_MINUTES = [1, 5, 15, 60];
    public const MAX_ATTEMPTS    = 5;
    private const RANK = ['queued' => 0, 'sending' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3];

    public const LABELS = ['queued' => 'Waiting to send', 'sending' => 'Sending', 'sent' => 'Sent', 'delivered' => 'Delivered', 'read' => 'Read', 'failed' => 'Not delivered', 'cancelled' => 'Cancelled'];

    /**
     * Queue one message. $m: channel, purpose, object_type, object_id, recipient, sender, template (WhatsApp),
     * body (array: params for WhatsApp; subject, text, from_name, reply_to for e-mail). Returns the outbox id.
     */
    public static function queue(array $m): int
    {
        $tid = Tenancy::require();
        $id  = Db::insert('message_outbox', [
            'tenant_id' => $tid, 'channel' => $m['channel'], 'purpose' => $m['purpose'], 'object_type' => $m['object_type'] ?? null, 'object_id' => $m['object_id'] ?? null,
            'recipient' => mb_substr((string) $m['recipient'], 0, 190), 'sender' => $m['sender'], 'template' => $m['template'] ?? null,
            'body_enc' => Senders::enc(json_encode($m['body'], JSON_UNESCAPED_UNICODE)), 'status' => 'queued', 'attempts' => 0,
            'next_attempt_at' => Db::now(), 'created_by' => Auth::id(), 'created_at' => Db::now(),
        ]);
        return $id;
    }

    /**
     * Send what is due for the current organisation: at most $limit messages, stopping after $seconds.
     * Returns ['sent' => n, 'failed' => n, 'retry' => n] (and 'waiting' => n while the organisation has no sender).
     */
    public static function process(int $limit = 50, float $seconds = 20.0): array
    {
        $tid   = Tenancy::require();
        $start = microtime(true);
        $out   = ['sent' => 0, 'failed' => 0, 'retry' => 0];
        // a run that died while sending: give its rows back after 10 minutes
        Db::exec("UPDATE {message_outbox} SET status = 'queued', claim = NULL WHERE tenant_id = ? AND status = 'sending' AND claimed_at < ?", [$tid, gmdate('Y-m-d H:i:s', time() - 600)]);
        $claim = bin2hex(random_bytes(8));
        Db::exec("UPDATE {message_outbox} SET status = 'sending', claim = ?, claimed_at = ?, attempts = attempts + 1
                   WHERE tenant_id = ? AND status = 'queued' AND next_attempt_at <= ? ORDER BY id LIMIT " . (int) $limit, [$claim, Db::now(), $tid, Db::now()]);
        $rows = Db::rows('SELECT * FROM {message_outbox} WHERE tenant_id = ? AND claim = ? AND status = \'sending\' ORDER BY id', [$tid, $claim]);
        $wa    = Senders::whatsapp();
        $mail  = Senders::email();
        foreach ($rows as $r) {
            if (microtime(true) - $start > $seconds) {
                // out of time: back in the queue, the try not counted
                Db::exec("UPDATE {message_outbox} SET status = 'queued', claim = NULL, attempts = attempts - 1 WHERE tenant_id = ? AND id = ? AND claim = ?", [$tid, $r['id'], $claim]);
                continue;
            }
            // core 1.4: no sender of the organisation's own yet: the message waits, the try not counted
            if (($r['channel'] === 'email' && ! $mail) || ($r['channel'] === 'whatsapp' && ! $wa)) {
                Db::exec("UPDATE {message_outbox} SET status = 'queued', claim = NULL, attempts = attempts - 1, error = ? WHERE tenant_id = ? AND id = ? AND claim = ?",
                    [$r['channel'] === 'email' ? 'Waiting for your e-mail settings (Administration > Messages).' : 'Waiting for your WhatsApp number (Administration > Messages).', $tid, $r['id'], $claim]);
                $out['waiting'] = ($out['waiting'] ?? 0) + 1;
                continue;
            }
            $body = json_decode(Senders::dec($r['body_enc']), true);
            if (! is_array($body)) {
                $res = ['ok' => false, 'id' => null, 'permanent' => true, 'error' => 'The message text cannot be read (installation key changed?).'];
            } elseif ($r['channel'] === 'whatsapp') {
                $res = Transport::whatsapp($wa, $r['recipient'], ['template' => $r['template'] ?: $wa['template'], 'lang' => $wa['lang'], 'params' => $body['params'] ?? []]);
            } else {
                $res = Transport::email($mail, $r['recipient'], $body);
            }
            self::settle($r, $res, $claim);
            $out[$res['ok'] ? 'sent' : (($res['permanent'] || (int) $r['attempts'] >= self::MAX_ATTEMPTS) ? 'failed' : 'retry')]++;
        }
        return $out;
    }

    private static function settle(array $r, array $res, string $claim): void
    {
        $tid = (int) $r['tenant_id'];
        if ($res['ok']) {
            // a delivery report can arrive before this update: keep the higher state
            Db::exec("UPDATE {message_outbox} SET status = IF(status IN ('delivered','read'), status, 'sent'), provider_id = ?, sent_at = ?, body_enc = NULL, error = NULL, claim = NULL
                       WHERE tenant_id = ? AND id = ? AND claim = ?", [$res['id'], Db::now(), $tid, $r['id'], $claim]);
            return;
        }
        if ($res['permanent'] || (int) $r['attempts'] >= self::MAX_ATTEMPTS) {
            Db::exec("UPDATE {message_outbox} SET status = 'failed', failed_at = ?, error = ?, claim = NULL WHERE tenant_id = ? AND id = ? AND claim = ?",
                [Db::now(), mb_substr((string) $res['error'], 0, 255), $tid, $r['id'], $claim]);
            return;
        }
        $wait = self::BACKOFF_MINUTES[min((int) $r['attempts'], count(self::BACKOFF_MINUTES)) - 1] ?? 60;
        Db::exec("UPDATE {message_outbox} SET status = 'queued', next_attempt_at = ?, error = ?, claim = NULL WHERE tenant_id = ? AND id = ? AND claim = ?",
            [gmdate('Y-m-d H:i:s', time() + $wait * 60), mb_substr((string) $res['error'], 0, 255), $tid, $r['id'], $claim]);
    }

    /**
     * A delivery report (WhatsApp webhook): sent, delivered, read or failed, by WhatsApp message id. Works across
     * organisations (the report names no organisation). A report never moves a message backwards. Returns true when a
     * message was found.
     */
    public static function report(string $providerId, string $status, int $at, ?string $error = null): bool
    {
        return Db::unscoped(static function () use ($providerId, $status, $at, $error) {
            $r = Db::row('SELECT id, status FROM {message_outbox} WHERE provider_id = ?', [$providerId]);
            if (! $r) {
                return false;
            }
            $when = gmdate('Y-m-d H:i:s', $at ?: time());
            if ($status === 'failed') {
                if ($r['status'] !== 'read') {
                    Db::exec("UPDATE {message_outbox} SET status = 'failed', failed_at = ?, error = ?, body_enc = NULL WHERE id = ?", [$when, mb_substr((string) $error, 0, 255), $r['id']]);
                }
                return true;
            }
            if (! isset(self::RANK[$status]) || $r['status'] === 'failed' || self::RANK[$status] <= (self::RANK[$r['status']] ?? 9)) {
                return true;
            }
            $col = ['sent' => 'sent_at', 'delivered' => 'delivered_at', 'read' => 'read_at'][$status];
            Db::exec("UPDATE {message_outbox} SET status = ?, {$col} = COALESCE({$col}, ?) WHERE id = ?", [$status, $when, $r['id']]);
            if ($status === 'read') {
                Db::exec('UPDATE {message_outbox} SET delivered_at = COALESCE(delivered_at, ?) WHERE id = ?', [$when, $r['id']]);
            }
            return true;
        });
    }

    /** Try a failed message again (its text is kept for a week after failing). */
    public static function retry(int $id): void
    {
        Access::require('admin.settings');
        $r = self::get($id);
        if ($r['status'] !== 'failed') {
            throw new BizException('Only a message that was not delivered can be tried again.');
        }
        if (! $r['body_enc']) {
            throw new BizException('This message is too old to try again.');
        }
        Db::exec("UPDATE {message_outbox} SET status = 'queued', attempts = 0, next_attempt_at = ?, error = NULL, failed_at = NULL WHERE tenant_id = ? AND id = ? AND status = 'failed'",
            [Db::now(), Tenancy::require(), $id]);
        Audit::log('message.retry', 'message', $id);
    }

    public static function cancel(int $id): void
    {
        Access::require('admin.settings');
        $r = self::get($id);
        if ($r['status'] !== 'queued') {
            throw new BizException('Only a message still waiting to be sent can be cancelled.');
        }
        Db::exec("UPDATE {message_outbox} SET status = 'cancelled', body_enc = NULL WHERE tenant_id = ? AND id = ? AND status = 'queued'", [Tenancy::require(), $id]);
        Audit::log('message.cancel', 'message', $id);
    }

    public static function get(int $id): array
    {
        $r = Db::row('SELECT * FROM {message_outbox} WHERE tenant_id = ? AND id = ?', [Tenancy::require(), $id]);
        if (! $r) {
            throw new NotFoundException('Message not found.');
        }
        return $r;
    }

    /** Messages about one record (a shipment), newest first. */
    public static function forObject(string $type, int $id): array
    {
        return Db::rows('SELECT * FROM {message_outbox} WHERE tenant_id = ? AND object_type = ? AND object_id = ? ORDER BY id DESC LIMIT 50', [Tenancy::require(), $type, $id]);
    }

    /** The outbox page: $f status, channel, q (recipient). */
    public static function list(array $f = [], int $limit = 300): array
    {
        $sql  = 'SELECT o.*, u.name created_by_name FROM {message_outbox} o LEFT JOIN {users} u ON u.id = o.created_by WHERE o.tenant_id = ?';
        $args = [Tenancy::require()];
        if (! empty($f['status']) && isset(self::LABELS[$f['status']])) {
            $sql   .= ' AND o.status = ?';
            $args[] = $f['status'];
        }
        if (! empty($f['channel']) && in_array($f['channel'], ['whatsapp', 'email'], true)) {
            $sql   .= ' AND o.channel = ?';
            $args[] = $f['channel'];
        }
        if (trim((string) ($f['q'] ?? '')) !== '') {
            $sql   .= ' AND o.recipient LIKE ?';
            $args[] = '%' . str_replace(['%', '_'], ['\%', '\_'], trim($f['q'])) . '%';
        }
        return Db::rows($sql . ' ORDER BY o.id DESC LIMIT ' . (int) $limit, $args);
    }

    /** Messages of the current organisation waiting because it has no sender for their channel. */
    public static function waiting(): array
    {
        $n = array_column(Db::rows("SELECT channel, COUNT(*) n FROM {message_outbox} WHERE tenant_id = ? AND status = 'queued' GROUP BY channel", [Tenancy::require()]), 'n', 'channel');
        return ['email' => Senders::email() ? 0 : (int) ($n['email'] ?? 0), 'whatsapp' => Senders::whatsapp() ? 0 : (int) ($n['whatsapp'] ?? 0)];
    }

    /** Counts by status for the outbox page. */
    public static function counts(): array
    {
        return array_column(Db::rows('SELECT status, COUNT(*) n FROM {message_outbox} WHERE tenant_id = ? AND created_at > ? GROUP BY status', [Tenancy::require(), gmdate('Y-m-d H:i:s', time() - 30 * 86400)]), 'n', 'status');
    }

    /** WhatsApp messages sent in a calendar month (YYYY-MM, UTC), by organisation: for the platform owner's figures. */
    public static function monthly(string $month): array
    {
        $from = $month . '-01 00:00:00';
        $to   = date('Y-m-d H:i:s', strtotime($from . ' +1 month'));
        return Db::unscoped(static fn () => Db::rows("SELECT tenant_id, sender, COUNT(*) n FROM {message_outbox}
                                                        WHERE channel = 'whatsapp' AND sent_at >= ? AND sent_at < ? GROUP BY tenant_id, sender", [$from, $to]));
    }

    /** Housekeeping for the current organisation: wipe the text of messages that failed a week ago; delete old rows. */
    public static function housekeeping(): void
    {
        $tid = Tenancy::require();
        Db::exec("UPDATE {message_outbox} SET body_enc = NULL WHERE tenant_id = ? AND status = 'failed' AND body_enc IS NOT NULL AND failed_at < ?", [$tid, gmdate('Y-m-d H:i:s', time() - 7 * 86400)]);
        $months = 12;
        Db::exec('DELETE FROM {message_outbox} WHERE tenant_id = ? AND created_at < ?', [$tid, gmdate('Y-m-d H:i:s', strtotime('-' . $months . ' months'))]);
    }
}
