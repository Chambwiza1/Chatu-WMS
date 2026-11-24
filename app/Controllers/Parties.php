<?php

namespace App\Controllers;

use App\Libraries\Core\Access;
use App\Libraries\Core\Db;
use App\Libraries\Master\Parties as P;

/** Suppliers and customers share one screen design. */
class Parties extends BaseController
{
    /** supplier or customer: the module of the list (core 1.1 rights). */
    private function mod(string $t): string
    {
        return $t === 'suppliers' ? 'supplier' : 'customer';
    }

    private function perm(string $t): string
    {
        return $this->mod($t) . '.view';
    }

    private function canSee(string $t): bool
    {
        return Access::can($this->perm($t)) || Access::can($t === 'suppliers' ? 'purchase.manage' : 'sales.manage');
    }

    public function index(string $t)
    {
        if (! $this->canSee($t)) {
            return $this->need($this->perm($t));
        }
        $q = trim((string) $this->request->getGet('q'));
        $w = '1=1';
        $b = [];
        if ($q !== '') {
            $w = '(code LIKE ? OR name LIKE ? OR phone LIKE ?)';
            $b = ['%' . $q . '%', '%' . $q . '%', '%' . $q . '%'];
        }
        $extra = $t === 'suppliers'
            ? ", (SELECT COUNT(*) FROM {purchase_orders} p WHERE p.tenant_id = {tid} AND p.supplier_id = x.id AND p.status IN ('open','partly_received')) open_docs"
            : ", (SELECT COUNT(*) FROM {sales_orders} s WHERE s.tenant_id = {tid} AND s.customer_id = x.id AND s.status IN ('confirmed','in_progress','dispatched')) open_docs";
        $rows = Db::rows("SELECT x.* $extra FROM {{$t}} x WHERE x.tenant_id = {tid} AND $w ORDER BY x.active DESC, x.name LIMIT 500", $b);
        return $this->render('parties/index', ['t' => $t, 'rows' => $rows, 'q' => $q], ucfirst($t));
    }

    public function form(string $t, ?int $id = null)
    {
        if ($r = $this->need($this->mod($t) . ($id ? '.update' : '.create'))) {
            return $r;
        }
        $row = $id ? Db::row("SELECT * FROM {{$t}} WHERE tenant_id = {tid} AND id = ?", [$id]) : null;
        if ($id && ! $row) {
            return $this->notFound(ucfirst(rtrim($t, 's')));
        }
        return $this->render('parties/form', ['t' => $t, 'row' => $row], $row ? $row['name'] : 'New ' . rtrim($t, 's'));
    }

    public function save(string $t, ?int $id = null)
    {
        return $this->act(function () use ($t, $id) {
            $d = (array) $this->request->getPost();
            $d['active'] = $id ? ! empty($d['active']) : true;
            P::save($t, $d, $id);
            return $this->ok(ucfirst(rtrim($t, 's')) . ' saved.', $t);
        }, $id ? site_url($t . '/' . $id) : site_url($t . '/new'));
    }
}
