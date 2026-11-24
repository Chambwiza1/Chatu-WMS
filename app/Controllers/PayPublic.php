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
}
