<?php

namespace App\Libraries\Core;

/**
 * Saved filters (core 1.9, D6). A person saves the filters of a list or report under a name and picks them again later.
 * A filter can be shared with a role: everyone holding that role in the organisation sees it. Sharing needs the right to
 * manage users or settings; only the person who saved a filter (or such an administrator, for shared ones) removes it.
 */
final class Filters
{
    /** Query keys that are never saved: exports, paging, the form token. */
    private const DROP = ['csv', 'as', 'export', 'page', 'csrf_token', 'csrf_test_name'];

    public static function canShare(): bool
    {
        return Access::canAny(['admin.users', 'admin.settings']);
    }

    /** The page key: the address without the site root, query or trailing slash. */
    public static function page(string $path): string
    {
        $p = trim(parse_url($path, PHP_URL_PATH) ?? $path, '/');
        $p = preg_replace('#^index\.php/?#', '', $p);
        return mb_substr(preg_replace('#[^a-z0-9/_.-]#i', '', $p), 0, 120);
    }

    /** The query string to keep, from the request's GET values. */
    public static function clean(array $get): string
    {
        $keep = [];
        foreach ($get as $k => $v) {
            if (in_array($k, self::DROP, true) || $v === '' || $v === null || $v === []) {
                continue;
            }
            $keep[$k] = $v;
        }
        ksort($keep);
        return http_build_query($keep);
    }

    /** Filters on a page that the current person may use: their own and those shared with a role they hold. */
    public static function forPage(string $page, ?int $userId = null): array
    {
        $uid = $userId ?? (int) Auth::id();
        return Db::rows("SELECT f.id, f.name, f.query, f.role_id, f.user_id, r.name role_name, u.name owner
                           FROM {saved_filters} f LEFT JOIN {roles} r ON r.id = f.role_id AND r.tenant_id = f.tenant_id
                           LEFT JOIN {users} u ON u.id = f.user_id
                          WHERE f.tenant_id = {tid} AND f.page = ?
                            AND (f.user_id = ? OR f.role_id IN (SELECT ur.role_id FROM {user_roles} ur WHERE ur.tenant_id = {tid} AND ur.user_id = ?))
                          ORDER BY f.role_id IS NOT NULL, f.name", [$page, $uid, $uid]);
    }

    /** Saves (or replaces, by name) a filter; returns its id. */
    public static function save(string $page, string $name, string $query, ?int $roleId = null): int
    {
        $uid  = (int) Auth::id();
        $name = trim(mb_substr($name, 0, 80));
        $page = self::page($page);
        if ($name === '' || $page === '') {
            throw new BizException('Give the filter a name.');
        }
        parse_str($query, $q);
        $query = self::clean($q);
        if ($query === '') {
            throw new BizException('Set some filters first, then save them.');
        }
        if (strlen($query) > 1000) {
            throw new BizException('These filters are too long to save.');
        }
        if ($roleId) {
            if (! self::canShare()) {
                throw new DeniedException('Only an administrator can share a filter with a role.');
            }
            if (! Db::value('SELECT 1 FROM {roles} WHERE id = ? AND tenant_id = {tid}', [$roleId])) {
                throw new BizException('Choose one of this organisation\'s roles.');
            }
        }
        $old = Db::row('SELECT id FROM {saved_filters} WHERE tenant_id = {tid} AND user_id = ? AND page = ? AND name = ?', [$uid, $page, $name]);
        if ($old) {
            Db::exec('UPDATE {saved_filters} SET query = ?, role_id = ? WHERE id = ? AND tenant_id = {tid}', [$query, $roleId ?: null, $old['id']]);
            $id = (int) $old['id'];
        } else {
            $id = Db::insert('saved_filters', ['user_id' => $uid, 'page' => $page, 'name' => $name, 'query' => $query, 'role_id' => $roleId ?: null, 'created_at' => Db::now()]);
        }
        Audit::log('filter.save', 'saved_filter', $id, null, ['page' => $page, 'name' => $name, 'role_id' => $roleId ?: null]);
        return $id;
    }

    public static function delete(int $id): void
    {
        $f = Db::row('SELECT * FROM {saved_filters} WHERE id = ? AND tenant_id = {tid}', [$id]);
        if (! $f) {
            throw new BizException('That saved filter is not there any more.');
        }
        if ((int) $f['user_id'] !== (int) Auth::id() && ! ($f['role_id'] && self::canShare())) {
            throw new DeniedException('Only the person who saved this filter can remove it.');
        }
        Db::exec('DELETE FROM {saved_filters} WHERE id = ? AND tenant_id = {tid}', [$id]);
        Audit::log('filter.delete', 'saved_filter', $id, ['page' => $f['page'], 'name' => $f['name']], null);
    }
}
