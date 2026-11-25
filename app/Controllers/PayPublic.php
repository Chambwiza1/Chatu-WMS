<?php

namespace App\Controllers;

use App\Libraries\Core\BizException;
use App\Libraries\Core\Db;
use App\Libraries\Platform\Pay;
use CodeIgniter\Controller;
use Throwable;

/**
 * core 1.5b: addresses the payment gateways call, with no sign-in.
 *   POST /pay/lenco/platform     Lenco webhook for Chatu's own account (platform invoices)
 *   POST /pay/lenco/{org code}   Lenco webhook for an organisation's own account (its sales)
 *   GET  /pay/go/{reference} the step from our Pay by card button to DPO's page
 *   GET  /pay/return             where DPO sends the customer back (TransactionToken, CompanyRef)
 * Nothing is trusted from the request but the reference: the webhook's signature is checked, and a return asks
 * DPO whether it was paid before anything is marked paid.
 */
class PayPublic extends Controller
{
    public function lenco(string $owner)
    {
        $keyOwner = null;
        if ($owner !== 'platform') {
            $keyOwner = Db::value('SELECT id FROM {tenants} WHERE slug = ?', [$owner]);
            if ($keyOwner === null) {
                return $this->response->setStatusCode(404)->setBody('unknown');
            }
            $keyOwner = (int) $keyOwner;
        }
        try {
            $out = Pay::lencoWebhook((string) $this->request->getBody(), (string) $this->request->getHeaderLine('X-Lenco-Signature'), $keyOwner);
            return $this->response->setStatusCode(200)->setBody($out);
        } catch (BizException $e) {
            return $this->response->setStatusCode(401)->setBody('bad signature');
        } catch (Throwable $e) {
            log_message('error', 'Lenco webhook: ' . $e->getMessage());
            return $this->response->setStatusCode(500)->setBody('error');   // Lenco tries again later
        }
    }

    public function back()
    {
        $ref = (string) ($this->request->getGet('CompanyRef') ?? '');
        $p = $ref !== '' ? Pay::byReference($ref) : null;
        if (! $p) {
            return $this->response->setStatusCode(404)->setBody('Payment not found.');
        }
        $tok = (string) ($this->request->getGet('TransactionToken') ?? $this->request->getGet('TransID') ?? '');
        if ($p['gateway'] !== 'dpo' || ($tok !== '' && $p['gateway_ref'] && ! hash_equals((string) $p['gateway_ref'], $tok))) {
            return $this->response->setStatusCode(400)->setBody('That does not match the payment.');
        }
        $p = Pay::check((int) $p['id']);
        $msg = ['paid' => 'Paid. Thank you.', 'pending' => 'DPO has not confirmed it yet. The invoice shows it as paid once it is through.', 'failed' => 'The payment did not go through.', 'cancelled' => 'The payment was cancelled.'][$p['status']];
        session()->setFlashdata($p['status'] === 'paid' ? 'ok' : 'error', $msg);
        return redirect()->to(site_url(Pay::returnPath($p)));
    }

    /**
     * The step between our "Pay by card" button and DPO's page (GET /pay/go/{reference}). Our pages only let forms
     * send people to this site (form-action 'self'), and browsers apply that to a redirect after a form too, so
     * the form comes here first and this page, an ordinary page with a link, passes the customer on to DPO.
     */
    public function go(string $ref)
    {
        $p = Pay::cardPage($ref);
        if (! $p) {
            $was = preg_match('/^[A-Za-z0-9._-]{6,60}$/', $ref) ? Pay::byReference($ref) : null;
            session()->setFlashdata('error', 'That payment is no longer waiting. Start it again from the invoice.');
            return redirect()->to($was ? site_url(Pay::returnPath($was)) : site_url('/'));
        }
        $url = esc((string) $p['pay_url'], 'attr');
        $css = esc(base_url('static/app.css'), 'attr');
        $amt = esc($p['currency'] . ' ' . number_format((float) $p['amount'], 2));
        return $this->response->setHeader('Cache-Control', 'no-store')->setBody(
            '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex"><meta http-equiv="refresh" content="1;url=' . $url . '"><title>Going to DPO</title>'
            . '<link rel="stylesheet" href="' . $css . '"></head><body><div class="login-wrap"><div class="login panel">'
            . '<h1>Taking you to DPO\'s secure page</h1><p>To pay ' . $amt . ' by card or mobile money. You come back here when it is done.</p>'
            . '<p><a class="btn" href="' . $url . '">Continue to payment</a></p><p class="muted small">Reference ' . esc($p['reference']) . '</p>'
            . '</div></div></body></html>'
        );
    }
}
