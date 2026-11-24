<?php

namespace App\Libraries;

use App\Libraries\Core\Access;
use App\Libraries\Core\Db;
use App\Libraries\Core\Settings;
use App\Libraries\Fleet\Fleet;
use App\Libraries\Reports\Reconcile;
use App\Libraries\Workflow\Notify;
use Throwable;

/**
 * Everything that runs on a schedule. Safe to call every few minutes from cron (Linux or cPanel), from the
 * cron URL, or on page visits when no cron is available.
 */
final class Jobs
{
    public static function run(): array
    {
        $got = (int) Db::value('SELECT GET_LOCK(?, 0)', [Db::t('cron')]);
        if (! $got) {
            return ['skipped' => 'Another run is in progress.'];
        }
        $logId = Db::insert('jobs_log', ['job' => 'cron', 'started_at' => Db::now()]);
        $out   = [];
        try {
            $out['emails'] = Notify::sendEmails();
            Db::exec('DELETE FROM {op_tokens} WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
            $last = json_decode((string) Settings::get('last_reconcile'), true);
            if (! $last || strtotime($last['at'] . ' UTC') < time() - 20 * 3600) {
                $res      = Reconcile::run();
                $problems = array_sum(array_map('count', $res));
                $out['reconcile'] = $problems;
                if ($problems) {
                    Notify::holders('admin.settings', null, 'integrity', 'The integrity check found ' . $problems . ' problem(s)', null, '/admin/reconcile');
                }
            }
            if (Settings::get('last_daily') !== date('Y-m-d')) {
                $out['daily'] = self::daily();
                Settings::set('last_daily', date('Y-m-d'));
            }
            Settings::set('last_cron_at', (string) time());
            Db::exec('UPDATE {jobs_log} SET finished_at = ?, outcome = ? WHERE id = ?', [Db::now(), mb_substr(json_encode($out), 0, 255), $logId]);
        } catch (Throwable $e) {
            log_message('error', 'Scheduled job failed: ' . $e->getMessage());
            Db::exec('UPDATE {jobs_log} SET finished_at = ?, outcome = ? WHERE id = ?', [Db::now(), mb_substr('error: ' . $e->getMessage(), 0, 255), $logId]);
            $out['error'] = $e->getMessage();
        } finally {
            Db::value('SELECT RELEASE_LOCK(?)', [Db::t('cron')]);
        }
        return $out;
    }

    /** Once a day: expired and expiring batches per warehouse, fleet papers, overdue transfers. */
    public static function daily(): array
    {
        $n    = 0;
        $days = (int) Settings::get('expiry_warn_days');
        foreach (Db::rows('SELECT id, code FROM {warehouses} WHERE tenant_id = {tid} AND active = 1') as $w) {
            $expired = (int) Db::value('SELECT COUNT(DISTINCT s.lot_id) FROM {stock} s JOIN {lots} l ON l.id = s.lot_id WHERE s.tenant_id = {tid} AND s.warehouse_id = ? AND l.expiry_date < CURDATE()', [$w['id']]);
            $soon    = (int) Db::value("SELECT COUNT(DISTINCT s.lot_id) FROM {stock} s JOIN {lots} l ON l.id = s.lot_id WHERE s.tenant_id = {tid} AND s.warehouse_id = ? AND l.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL $days DAY)", [$w['id']]);
            if ($expired || $soon) {
                $n += Notify::holders('inventory.move', (int) $w['id'], 'expiry', $w['code'] . ': ' . $expired . ' expired batch(es), ' . $soon . ' expiring within ' . $days . ' days', null, '/reports/expiry?warehouse_id=' . $w['id']);
            }
            $late = (int) Db::value("SELECT COUNT(*) FROM {transfers} WHERE tenant_id = {tid} AND to_warehouse_id = ? AND status IN ('in_transit','partly_received') AND dispatched_at < ?", [$w['id'], gmdate('Y-m-d H:i:s', time() - 3 * 86400)]);
            if ($late) {
                $n += Notify::holders('transfer.receive', (int) $w['id'], 'transfer_late', $w['code'] . ': ' . $late . ' transfer(s) on the way for more than 3 days', 'Receive them, or record what is missing.', '/transfers?view=incoming');
            }
        }
        $exp = array_filter(Fleet::expiring(), static fn ($e) => $e['overdue'] || $e['date'] === date('Y-m-d', strtotime('+7 days')) || $e['date'] === date('Y-m-d', strtotime('+' . Settings::get('fleet_warn_days') . ' days')));
        if ($exp) {
            $n += Notify::holders('fleet.manage', null, 'fleet', count($exp) . ' vehicle or driver paper(s) need attention', implode('; ', array_map(static fn ($e) => $e['what'] . ': ' . $e['label'] . ($e['date'] ? ' ' . $e['date'] : ''), array_slice($exp, 0, 8))), '/fleet');
        }
        return ['notified' => $n];
    }

    /** Fallback for hosts without cron: run at most every 5 minutes on page visits. */
    public static function maybeRunOnVisit(): void
    {
        if (Settings::get('web_cron') !== '1') {
            return;
        }
        if ((int) Settings::get('last_cron_at', '0') > time() - 300) {
            return;
        }
        Settings::set('last_cron_at', (string) time());
        try {
            self::run();
        } catch (Throwable $e) {
            log_message('error', 'Web cron failed: ' . $e->getMessage());
        }
    }
}
