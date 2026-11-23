<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Strict Content-Security-Policy and related headers. No inline scripts or styles anywhere,
 * no third-party hosts: every asset is served from this site (also what the offline cache needs).
 */
class CspFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        if (! $response->hasHeader('Content-Security-Policy')) { // served files set a stricter, sandboxed one
            $response->setHeader('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; "
                . "connect-src 'self'; font-src 'self'; manifest-src 'self'; worker-src 'self'; media-src 'self' blob:; object-src 'none'; "
                . "frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
        }
        $response->setHeader('Referrer-Policy', 'same-origin');
        $response->setHeader('Permissions-Policy', 'camera=(self), geolocation=(), microphone=()');
        if ($request->isSecure()) {
            $response->setHeader('Strict-Transport-Security', 'max-age=31536000');
        }
        if (! $response->hasHeader('Cache-Control') || str_contains($response->getHeaderLine('Content-Type'), 'html')) {
            $response->setHeader('Cache-Control', 'no-store, private');
        }
        return $response;
    }
}
