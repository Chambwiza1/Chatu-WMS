<?php

namespace App\Controllers;

use App\Libraries\Core\Access;
use App\Libraries\Core\Audit;
use App\Libraries\Core\Auth;
use App\Libraries\Core\BizException;
use App\Libraries\Core\Db;
use App\Libraries\Core\DeniedException;
use App\Libraries\Master\Items;
use App\Libraries\Workflow\Notify;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\CLIRequest;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;

abstract class BaseController extends Controller
{
    /** @var CLIRequest|IncomingRequest */
    protected $request;
    protected $helpers = ['url', 'form', 'app'];

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
    }

    /**
     * The warehouse the user is working in (chosen in the header), among those where they hold $perm.
     * A ?warehouse_id= in the address overrides it for that page.
     */
    protected function wh(string $perm = 'stock.view'): int
    {
        $ids = Access::warehouses($perm);
        $q   = (int) $this->request->getGet('warehouse_id');
        if ($q && in_array($q, $ids, true)) {
            return $q;
        }
        $cur = (int) session('wh');
        if (in_array($cur, $ids, true)) {
            return $cur;
        }
        return $ids[0] ?? 0;
    }

    protected function render(string $view, array $data = [], ?string $title = null): string
    {
        $uid  = Auth::id();
        $mine = [];
        if ($uid) {
            $ids  = array_values(array_unique(array_merge(...array_map(static fn ($p) => Access::warehouses($p), ['stock.view', 'receive.post', 'dispatch.manage', 'warehouse.setup']))));
            $mine = $ids ? Db::rows('SELECT id, code, name FROM {warehouses} WHERE tenant_id = {tid} AND active = 1 AND id IN (' . implode(',', $ids) . ') ORDER BY code') : [];
        }
        $data += [
            'title'      => $title,
            'me'         => Auth::user(),
            'unread'     => $uid ? Notify::unreadCount($uid) : 0,
            'warehouses' => $mine,
            'whId'       => $uid ? ((int) session('wh') ?: (int) ($mine[0]['id'] ?? 0)) : 0,
            'path'       => '/' . trim($this->request->getUri()->getPath(), '/'),
        ];
        return view($view, $data);
    }

    /**
     * Run a write action. Business refusals become a message on the form; permission refusals are logged and
     * shown as 403. Unexpected errors are logged and shown without detail.
     */
    protected function act(callable $fn, ?string $back = null)
    {
        try {
            return $fn();
        } catch (DeniedException $e) {
            Audit::denied('http.' . strtolower($this->request->getMethod()) . ' ' . $this->request->getUri()->getPath(), $e->getMessage());
            return $this->forbidden($e->getMessage());
        } catch (BizException $e) {
            session()->setFlashdata('error', $e->getMessage());
            return redirect()->to($back ?? previous_url())->withInput();
        } catch (Throwable $e) {
            log_message('error', $e->getMessage() . "\n" . $e->getTraceAsString());
            session()->setFlashdata('error', 'Something went wrong and nothing was saved. The error has been logged.' . (ENVIRONMENT !== 'production' ? ' (' . $e->getMessage() . ')' : ''));
            return redirect()->to($back ?? previous_url())->withInput();
        }
    }

    protected function forbidden(string $message): ResponseInterface
    {
        return $this->response->setStatusCode(403)->setBody($this->render('errors/forbidden', ['message' => $message], 'Not allowed'));
    }

    protected function notFound(string $what = 'Page'): ResponseInterface
    {
        return $this->response->setStatusCode(404)->setBody($this->render('errors/notfound', ['message' => $what . ' not found.'], 'Not found'));
    }

    /** Check a permission inside a GET page and render 403 when missing. */
    protected function need(string $perm, ?int $warehouseId = null): ?ResponseInterface
    {
        $ok = $warehouseId ? Access::canAt($warehouseId, $perm) : Access::can($perm);
        if (! $ok) {
            Audit::denied('http.get ' . $this->request->getUri()->getPath(), 'Missing permission ' . $perm);
            return $this->forbidden('You do not have permission to open this page.');
        }
        return null;
    }

    /** Like need(), for pages that any of several duties may open (read-only for the others). */
    protected function needAny(array $perms, ?int $warehouseId = null): ?ResponseInterface
    {
        foreach ($perms as $p) {
            if ($warehouseId ? Access::canAt($warehouseId, $p) : Access::can($p)) {
                return null;
            }
        }
        return $this->need($perms[0], $warehouseId);
    }

    protected function ok(string $message, string $to): RedirectResponse
    {
        session()->setFlashdata('ok', $message);
        return redirect()->to(site_url(ltrim($to, '/')));
    }

    protected function in(string $key, $default = null)
    {
        $v = $this->request->getPost($key);
        return $v === null ? $default : $v;
    }

    /** Posted line rows (lines[n][field]) with the item code resolved to an item id; blank rows dropped. */
    protected function lines(string $key = 'lines'): array
    {
        $out = [];
        foreach ((array) $this->request->getPost($key) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $sku = trim((string) ($row['sku'] ?? ''));
            if ($sku !== '') {
                $code = trim(explode(' — ', $sku)[0]);
                [$item, $pack] = Items::byCode($code);
                if (! $item) {
                    throw new BizException('Unknown item code ' . $code . '.');
                }
                $row['item_id'] = (int) $item['id'];
                if ($pack && empty($row['unit'])) {
                    $row['unit'] = $pack; // a carton barcode was scanned
                }
            }
            if (empty($row['item_id']) && empty($row['stock_id']) && implode('', array_map('strval', array_filter($row, 'is_scalar'))) === '') {
                continue;
            }
            $out[] = Items::convertLine($row);
        }
        return $out;
    }

    /** CSV download. */
    protected function csv(string $name, array $header, array $rows): ResponseInterface
    {
        if (! Access::can('data.export')) {
            Audit::denied('report.export', 'Missing permission data.export', 'report', $name);
            return $this->forbidden('Your role cannot export data.');
        }
        Audit::log('report.export', 'report', $name, null, ['rows' => count($rows)]);
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, $header);
        foreach ($rows as $r) {
            fputcsv($fh, array_map(static function ($v) {
                $v = (string) $v;
                return preg_match('/^[=+\-@\t\r]/', $v) && ! is_numeric($v) ? "'" . $v : $v; // no formula injection
            }, $r));
        }
        rewind($fh);
        $body = stream_get_contents($fh);
        return $this->response->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . preg_replace('/[^a-z0-9_-]/i', '_', $name) . '-' . date('Ymd') . '.csv"')
            ->setBody($body);
    }
}
