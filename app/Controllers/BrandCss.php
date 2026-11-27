<?php

namespace App\Controllers;

use App\Libraries\Admin\Theme;
use App\Libraries\Core\Db;
use App\Libraries\Core\Tenancy;

/** /brand.css (core 1.3, C2): an organisation's colours, as ChatuPOS. Public, so the sign-in page can use them too. */
class BrandCss extends BaseController
{
    public function css()
    {
        $o   = (int) ($this->request->getGet('o') ?? 0);
        $tid = Tenancy::id();
        if ($o && $o !== $tid && Db::unscoped(static fn () => Db::value('SELECT 1 FROM {tenants} WHERE id = ?', [$o]))) {
            $css = Tenancy::run($o, static fn () => Theme::css());
        } elseif ($tid) {
            $css = Theme::css();
        } else {
            $css = "/* Chatu green */\n";
        }
        return $this->response->setHeader('Content-Type', 'text/css; charset=utf-8')
            ->setHeader('Cache-Control', 'public, max-age=86400')->setBody($css);
    }
}
