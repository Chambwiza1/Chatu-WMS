<?php

namespace App\Controllers;

use App\Libraries\Core\Access;
use App\Libraries\Core\Audit;
use App\Libraries\Core\Db;
use App\Libraries\Core\Tenancy;

/** The audit log: an organisation's own chain (audit.view), or the platform's chain (platform owner). */
class AuditLog extends BaseController
{
    public function index()
    {
        if ($r = $this->need('audit.view')) {
            return $r;
        }
        return $this->show(Tenancy::require(), 'audit');
    }

    public function platform()
    {
        if (! Access::platform('platform.audit')) {
            return $this->forbidden('This is for the platform owner only.');
        }
        return $this->show(null, 'platform/audit');
    }

    private function show(?int $tenantId, string $route)
    {
        $f = [
            'q'       => trim((string) $this->request->getGet('q')),
            'outcome' => (string) $this->request->getGet('outcome'),
            'from'    => (string) ($this->request->getGet('from') ?: date('Y-m-d', strtotime('-30 days'))),
            'to'      => (string) ($this->request->getGet('to') ?: date('Y-m-d')),
        ];
        $where = [$tenantId === null ? 'e.tenant_id IS NULL' : 'e.tenant_id = ?', 'e.at BETWEEN ? AND ?'];
        $b     = $tenantId === null ? [] : [$tenantId];
        array_push($b, $f['from'] . ' 00:00:00', $f['to'] . ' 23:59:59');
        if ($f['q'] !== '') {
            $where[] = '(e.action LIKE ? OR e.username LIKE ? OR e.object_id = ? OR e.detail LIKE ?)';
            array_push($b, '%' . $f['q'] . '%', '%' . $f['q'] . '%', $f['q'], '%' . $f['q'] . '%');
        }
        if (in_array($f['outcome'], ['success', 'denied', 'failed'], true)) {
            $where[] = 'e.outcome = ?';
            $b[]     = $f['outcome'];
        }
        $csv   = $this->request->getGet('export') === 'csv';
        $rows  = Db::rows('SELECT e.* FROM {audit_events} e WHERE ' . implode(' AND ', $where) . ' ORDER BY e.id DESC LIMIT ' . ($csv ? 100000 : 300), $b);
        if ($csv) {
            return $this->csv('audit-log', ['Id', 'When (UTC)', 'User', 'IP', 'Device', 'Action', 'Object', 'Object id', 'Outcome', 'Detail', 'Before', 'After', 'Hash'],
                array_map(static fn ($e) => [$e['id'], $e['at'], $e['username'], $e['ip'], $e['device_id'], $e['action'], $e['object_type'], $e['object_id'], $e['outcome'], $e['detail'], $e['before_json'], $e['after_json'], $e['hash']], $rows));
        }
        $verify = null;
        if ($this->request->getGet('verify') === '1') {
            $bad    = Audit::verify($tenantId);
            $verify = $bad === null ? 'The chain is intact: no record has been changed or removed.' : 'The chain breaks at event #' . $bad . '. A record was changed or removed outside the application.';
            Audit::log('audit.verify', null, null, null, null, $bad === null ? 'success' : 'failed', $verify);
        }
        return $this->render('audit/index', ['rows' => $rows, 'f' => $f, 'verify' => $verify, 'platform' => $tenantId === null, 'route' => $route], 'Audit log');
    }
}
