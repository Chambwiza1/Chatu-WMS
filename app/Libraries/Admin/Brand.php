<?php

namespace App\Libraries\Admin;

use App\Libraries\Core\Access;
use App\Libraries\Core\Audit;
use App\Libraries\Core\BizException;
use App\Libraries\Core\Settings;

/** The client's own logo, printed on delivery notes, purchase orders, receipts and every printed page. */
final class Brand
{
    private const TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'];

    public static function path(): ?string
    {
        $f = (string) Settings::get('company_logo');
        $p = WRITEPATH . 'uploads/brand/' . basename($f);
        return $f !== '' && is_file($p) ? $p : null;
    }

    public static function hasLogo(): bool
    {
        return self::path() !== null;
    }

    public static function mime(): string
    {
        $ext = strtolower(pathinfo((string) self::path(), PATHINFO_EXTENSION));
        return self::TYPES[$ext] ?? 'application/octet-stream';
    }

    /** @param \CodeIgniter\HTTP\Files\UploadedFile $f */
    public static function upload($f): void
    {
        Access::require('admin.settings');
        if (! $f->isValid()) {
            throw new BizException('The logo did not upload: ' . $f->getErrorString());
        }
        if ($f->getSize() > 1024 * 1024) {
            throw new BizException('Use a logo file smaller than 1 MB.');
        }
        $mime = $f->getMimeType();
        $ext  = array_search($mime, self::TYPES, true);
        if ($ext === false || ! @getimagesize($f->getTempName())) {
            throw new BizException('The logo must be a PNG, JPG or WEBP picture.');
        }
        $dir = WRITEPATH . 'uploads/brand';
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if ($old = self::path()) {
            @unlink($old);
        }
        $name = 'logo-' . bin2hex(random_bytes(6)) . '.' . $ext;
        $f->move($dir, $name);
        Settings::set('company_logo', $name);
        Audit::log('brand.logo', 'settings', null, null, ['file' => $name]);
    }

    public static function remove(): void
    {
        Access::require('admin.settings');
        if ($old = self::path()) {
            @unlink($old);
        }
        Settings::set('company_logo', '');
        Audit::log('brand.logo_removed', 'settings');
    }
}
