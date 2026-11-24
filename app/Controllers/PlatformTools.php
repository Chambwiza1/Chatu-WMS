<?php

namespace App\Controllers;

use App\Libraries\Core\Audit;
use App\Libraries\Core\BizException;
use App\Libraries\Core\Settings;
use App\Libraries\Setup\Upgrade;

/** Installation-level actions shared by the platform owner (SaaS) and the Administrator (single-company). Not a route. */
final class PlatformTools
{
    /** Run pending database updates (each is safe to run again). */
    public static function upgrade(BaseController $c, string $back)
    {
        $todo = Upgrade::pending();
        if (! $todo) {
            throw new BizException('The database is already up to date.');
        }
        $runner = service('migrations');
        $runner->setNamespace('App');
        $runner->latest();
        \App\Libraries\Core\Db::reset();
        \App\Libraries\Core\Audit::flush();
        Settings::flush();
        Settings::set('app_version', Audit::APP_VERSION);
        cache()->delete(\App\Filters\UpdateFilter::cacheKey());
        Audit::log('system.upgrade', 'system', null, null, ['steps' => $todo, 'version' => Audit::APP_VERSION]);
        session()->setFlashdata('ok', 'Database updated: ' . implode(', ', $todo) . '.');
        return redirect()->to(site_url($back));
    }

    /** @return array{0:array,1:array} [before, after] of the changed installation settings */
    public static function savePlatformSettings(BaseController $c, array $fields): array
    {
        $req    = service('request');
        $before = $after = [];
        foreach (array_keys($fields) as $k) {
            $v = trim((string) $req->getPost($k));
            if ($k === 'smtp_pass' && $v === '') {
                continue;
            }
            if ($k === 'web_cron' || $k === 'single_session') {
                $v = $v === '1' ? '1' : '0';
            }
            if (in_array($k, ['session_idle_minutes', 'max_upload_mb', 'smtp_port'], true) && $v !== '' && ! ctype_digit($v)) {
                throw new BizException($fields[$k][0] . ': give a whole number.');
            }
            if ($k === 'mail_from' && $v !== '' && ! filter_var($v, FILTER_VALIDATE_EMAIL)) {
                throw new BizException('Send e-mail from: that address is not valid.');
            }
            if ((string) Settings::get($k) !== $v) {
                $before[$k] = $k === 'smtp_pass' ? '***' : Settings::get($k);
                $after[$k]  = $k === 'smtp_pass' ? '***' : $v;
                Settings::set($k, $v);
            }
        }
        return [$before, $after];
    }
}
