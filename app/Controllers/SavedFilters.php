<?php

namespace App\Controllers;

use App\Libraries\Core\Filters;

/** core 1.9 (D6): save the filters of a list or report, and remove saved ones. */
class SavedFilters extends BaseController
{
    public function save()
    {
        $page  = Filters::page((string) $this->in('page', ''));
        $query = (string) $this->in('query', '');
        $back  = site_url($page) . ($query !== '' ? '?' . $query : '');
        return $this->act(function () use ($page, $query) {
            $role = (int) $this->in('role_id', 0);
            Filters::save($page, (string) $this->in('name', ''), $query, $role ?: null);
            parse_str($query, $q);
            $clean = Filters::clean($q);
            return $this->ok($role ? 'Filter saved and shared with the role.' : 'Filter saved. Pick it from Saved filters next time.', $page . ($clean !== '' ? '?' . $clean : ''));
        }, $back);
    }

    public function delete(int $id)
    {
        $page = Filters::page((string) $this->in('page', ''));
        return $this->act(function () use ($id, $page) {
            Filters::delete($id);
            return $this->ok('Saved filter removed.', $page ?: '/');
        }, site_url($page));
    }
}
