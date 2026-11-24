<?php

namespace App\Filters;

use App\Libraries\Core\Audit;
use App\Libraries\Core\Settings;
use App\Libraries\Setup\Upgrade;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * core 1.1 (A9, as ChatuPOS beta19 and Chatu Assets 2.0): new program files on an old database. The database's version (setting app_version,
 * written by every update) is compared with the program's on each request; when they differ the migrations are looked
 * at once, and when steps are waiting every page goes to /update. There the platform owner (online service, every
 * organisation at once) or the administrator (single company) presses Update the database; everyone else sees that
 * Chatu Warehouse is being updated, so
 * nobody meets "Whoops!" and no terminal is needed. Signing in and out still work; scripts, the scheduled job, the
 * API and guests' pages answer 503 meanwhile. Once the versions agree the answer is kept in the cache, keyed by the
 * program version, so an up-to-date site does no extra work.
 */
class UpdateFilter implements FilterInterface
{
    /** Pages that must work while the database waits. */
    private const OPEN = ['update', 'login', 'logout', 'install', 'sw.js', 'manifest.webmanifest', 'offline.html', 'favicon.ico', 'health'];

    public static function cacheKey(): string
    {
        return 'chatu_dbver_' . preg_replace('/[^A-Za-z0-9_]/', '_', Audit::APP_VERSION);
    }

    /** Steps waiting, or [] when the database matches the program. */
    public static function waiting(): array
    {
        if (cache(self::cacheKey())) {
            return [];
        }
        try {
            if ((string) Settings::get('app_version') !== Audit::APP_VERSION) {
                $todo = Upgrade::pending();
                if ($todo) {
                    return $todo;
                }
                Settings::set('app_version', Audit::APP_VERSION);     // files and database agree (e.g. migrated on the server)
            }
            cache()->save(self::cacheKey(), 1, 3600);
        } catch (Throwable $e) {
            log_message('error', 'Update check: ' . $e->getMessage());
        }
        return [];
    }

    public function before(RequestInterface $request, $arguments = null)
    {
        $path  = trim(uri_string(), '/');
        $first = explode('/', $path)[0];
        if (in_array($first, self::OPEN, true) || str_starts_with($path, 'static/')) {
            return null;
        }
        if (! self::waiting()) {
            return null;
        }
        $json = in_array($first, ['api', 'cron', 'live', 'offline', 'health'], true)
            || str_contains($request->getHeaderLine('Accept'), 'application/json') || $request->getHeaderLine('X-Chatu-Popup') !== '';
        if ($json) {
            return service('response')->setStatusCode(503)->setHeader('Retry-After', '120')
                ->setJSON(['ok' => false, 'error' => 'Chatu Warehouse is being updated. Try again in a few minutes.', 'message' => 'Chatu Warehouse is being updated.']);
        }
        return redirect()->to(site_url('update'));
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
