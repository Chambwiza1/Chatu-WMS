<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/** Sends every request to the installer until installation has finished. */
class InstalledFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (! is_file(WRITEPATH . 'installed.lock')) {
            return redirect()->to(site_url('install'));
        }
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
