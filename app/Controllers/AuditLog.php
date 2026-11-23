<?php

namespace App\Controllers;

use App\Libraries\Core\Audit;
use App\Libraries\Core\Db;

class AuditLog extends BaseController
{
    public function index()
    {
        if ($r = $this->need('audit.view')) {
            return $r;
        }
        $f = [
            'q'       => trim((string) $this->request->getGet('q')),
            'outcome' => (string) $this->request->getGet('outcome'),
            'from'    => (string) ($this->request->getGet('from') ?: date('Y-m-d', strtotime('-30 days'))),
            'to'      => (string) ($this->request->getGet('to') ?: date('Y-m-d')),
        ];
        $where = ['e.at BETWEEN ? AND ?'];
        $b     = [$f['from'] . ' 00:00:00', $f['to'] . ' 23:59:59'];
        if ($f['q'] !== '') {
            $where[] = '(e.action LIKE ? OR e.username LIKE ? OR e.object_id = ? OR e.detail LIKE ?)';
            array_push($b, '%' . $f['q'] . '%', '%' . $f['q'] . '%', $f['q'], '%' . $f['q'] . '%');
        }
        if (in_array($f['outcome'], ['success', 'denied', 'failed'], true)) {
            $where[] = 'e.outcome = ?';
            $b[]     = $f['outcome'];
        }
        $limit = $this->request->getGet('export') === 'csv' ? 100000 : 300;
        $rows  = Db::rows('SELECT e.* FROM {audit_events} e WHERE ' . implode(' AND ', $where) . ' ORDER BY e.id DESC LIMIT ' . $limit, $b);
        if ($this->request->getGet('export') === 'csv') {
            return $this->csv('audit-log', ['Id', 'When (UTC)', 'User', 'On behalf of', 'IP', 'Device', 'Action', 'Object', 'Object id', 'Request', 'Outcome', 'Detail', 'Before', 'After', 'Hash'],
                array_map(static fn ($e) => [$e['id'], $e['at'], $e['username'], $e['on_behalf_of'], $e['ip'], $e['device_id'], $e['action'], $e['object_type'], $e['object_id'], $e['request_id'], $e['outcome'], $e['detail'], $e['before_json'], $e['after_json'], $e['hash']], $rows));
        }
        $verify = null;
        if ($this->request->getGet('verify') === '1') {
            $bad    = Audit::verify();
            $verify = $bad === null ? 'The chain is intact: no record has been changed or removed.' : 'The chain breaks at event #' . $bad . '. A record was changed or removed outside the application.';
            Audit::log('audit.verify', null, null, null, null, $bad === null ? 'success' : 'failed', $verify);
        }
        return $this->render('audit/index', ['rows' => $rows, 'f' => $f, 'verify' => $verify], 'Audit log');
    }
}
