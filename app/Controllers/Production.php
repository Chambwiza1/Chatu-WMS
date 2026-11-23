<?php

namespace App\Controllers;

use App\Libraries\Core\Access;
use App\Libraries\Core\Db;
use App\Libraries\Docs\Production as P;
use App\Libraries\Master\Items;
use App\Libraries\Master\Warehouses;
use App\Libraries\Stock\Alloc;

class Production extends BaseController
{
    public function index()
    {
        if ($r = $this->need('production.manage')) {
            return $r;
        }
        [$w, $b] = Access::whereWarehouse('production.manage', 'p.warehouse_id');
        return $this->render('production/index', [
            'recipes' => Db::rows('SELECT r.*, i.sku, i.name item_name, i.uom, (SELECT COUNT(*) FROM {recipe_lines} l WHERE l.recipe_id = r.id) materials FROM {recipes} r JOIN {items} i ON i.id = r.output_item_id ORDER BY r.active DESC, r.code'),
            'runs'    => Db::rows("SELECT p.*, r.code recipe, i.sku, i.uom, l.lot_no, w.code wh FROM {production_runs} p JOIN {recipes} r ON r.id = p.recipe_id JOIN {items} i ON i.id = p.output_item_id
                                    JOIN {warehouses} w ON w.id = p.warehouse_id LEFT JOIN {lots} l ON l.id = p.lot_id WHERE $w ORDER BY p.id DESC LIMIT 50", $b),
        ], 'Production');
    }

    public function recipeForm(?int $id = null)
    {
        if ($r = $this->need('production.manage')) {
            return $r;
        }
        $rec = $id ? P::recipe($id) : null;
        return $this->render('production/recipe', ['rec' => $rec, 'outputs' => Items::options(['finished_good', 'goods', 'packaging'])], $rec ? 'Recipe ' . $rec['code'] : 'New recipe');
    }

    public function recipeSave(?int $id = null)
    {
        return $this->act(function () use ($id) {
            $d          = (array) $this->request->getPost();
            $d['lines'] = $this->lines();
            $d['active'] = $id ? ! empty($d['active']) : true;
            P::saveRecipe($d, $id);
            return $this->ok('Recipe saved.', 'production');
        }, $id ? site_url('production/recipes/' . $id) : site_url('production/recipes/new'));
    }

    public function runForm()
    {
        $wh = $this->wh('production.manage');
        if ($r = $this->need('production.manage', $wh ?: null)) {
            return $r;
        }
        $rec  = ($rid = (int) $this->request->getGet('recipe_id')) ? P::recipe($rid) : null;
        $batches = max(0.001, (float) ($this->request->getGet('batches') ?: 1));
        $avail = [];
        if ($rec) {
            foreach ($rec['lines'] as $l) {
                $avail[$l['item_id']] = Alloc::free($wh, (int) $l['item_id'], ['bin_types' => ['production', 'storage', 'picking']]);
            }
        }
        return $this->render('production/run_form', ['rec' => $rec, 'recipes' => Db::rows('SELECT id, code, name FROM {recipes} WHERE active = 1 ORDER BY code'), 'wh' => $wh, 'w' => Warehouses::get($wh),
            'batches' => $batches, 'avail' => $avail, 'warehouses' => Warehouses::forUser('production.manage'), 'bins' => Warehouses::bins($wh, ['storage', 'picking', 'production'])], 'Production run');
    }

    public function runPost()
    {
        $back = previous_url();
        return $this->act(function () {
            $d   = (array) $this->request->getPost();
            $id  = P::post($d, $this->in('op_token'));
            $run = P::run($id);
            return $this->ok('Posted: ' . qty($run['actual_qty']) . ' ' . $run['uom'] . ' made' . ($run['lot_no'] ? ' as batch ' . $run['lot_no'] : '') . '.', 'production/runs/' . $id);
        }, $back);
    }

    public function run(int $id)
    {
        try {
            $run = P::run($id);
        } catch (\Throwable $e) {
            return $this->notFound('Run');
        }
        if ($r = $this->needAny(['production.manage', 'stock.view'], (int) $run['warehouse_id'])) {
            return $r;
        }
        return $this->render('production/run', ['run' => $run], $run['number']);
    }

    public function void(int $id)
    {
        return $this->act(function () use ($id) {
            P::void($id, (string) $this->in('reason', ''), $this->in('op_token'));
            return $this->ok('Run voided: the output is out of stock and the materials are back.', 'production/runs/' . $id);
        }, site_url('production/runs/' . $id));
    }
}
