<?php

use App\Commands\TestKit;

/**
 * SQL-1 (0.6.0): a statement's organisation condition can't be undone by an OR beside it. The query guard only checks
 * that "tenant_id" is named; "WHERE tenant_id = {tid} AND a = ? OR b = ?" names it and still reads every organisation.
 * This reads the code itself: in every Db:: call, a WHERE that names an organisation's tenant_id may not have an OR at
 * its own level (inside brackets is fine).
 */
function sqlscan_calls(string $src): array
{
    $out = [];
    if (! preg_match_all('/Db::(?:rows|row|value|exec|col|column)\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
        return $out;
    }
    foreach ($m[0] as [$hit, $at]) {
        $i = $at + strlen($hit);
        $depth = 1;
        $q = null;
        for ($j = $i, $n = strlen($src); $j < $n && $depth > 0; $j++) {
            $c = $src[$j];
            if ($q !== null) {
                if ($c === '\\') {
                    $j++;
                } elseif ($c === $q) {
                    $q = null;
                }
                continue;
            }
            if ($c === '"' || $c === "'") {
                $q = $c;
            } elseif ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
            }
        }
        $call = substr($src, $i, $j - $i);
        preg_match_all('/"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'/s', $call, $lits);
        $sql = implode(' ', array_map(static fn ($l) => substr($l, 1, -1), $lits[0]));
        $out[] = [substr_count(substr($src, 0, $at), "\n") + 1, $sql];
    }
    return $out;
}

function sqlscan_topOr(string $sql): bool
{
    $u = strtoupper($sql);
    if (! preg_match_all('/\bWHERE\b/', $u, $w, PREG_OFFSET_CAPTURE)) {
        return false;
    }
    foreach ($w[0] as [$kw, $pos]) {
        $after = substr($u, $pos + 5);
        $d = 0;
        for ($k = 0, $n = strlen($after); $k < $n; $k++) {
            $c = $after[$k];
            if ($c === '(') {
                $d++;
            } elseif ($c === ')') {
                if (--$d < 0) {
                    break;
                }
            } elseif ($d === 0) {
                $rest = substr($after, $k, 12);
                if (preg_match('/^(ORDER BY|GROUP BY|LIMIT|HAVING|UNION)\b/', $rest)) {
                    break;
                }
                if (preg_match('/^OR\b/', $rest) && ($k === 0 || ! ctype_alnum($after[$k - 1]) && $after[$k - 1] !== '_')) {
                    // only a WHERE that limits to an organisation matters
                    $where = substr($after, 0, $k);
                    if (str_contains($where, 'TENANT_ID')) {
                        return true;
                    }
                    break;
                }
            }
        }
    }
    return false;
}

return [
    'SQL-1 no statement lets an OR undo its organisation condition' => static function (TestKit $t) {
        $bad = [];
        $it  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APPPATH, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() !== 'php') {
                continue;
            }
            foreach (sqlscan_calls((string) file_get_contents($f->getPathname())) as [$line, $sql]) {
                if (sqlscan_topOr($sql)) {
                    $bad[] = str_replace(APPPATH, 'app/', $f->getPathname()) . ':' . $line;
                }
            }
        }
        $t->eq([], $bad, 'statements to put in brackets');
        $t->true(sqlscan_topOr("SELECT 1 FROM {items} WHERE tenant_id = {tid} AND sku = ? OR barcode = ?"), 'the scan finds the fault it is for');
        $t->true(! sqlscan_topOr("SELECT 1 FROM {items} WHERE tenant_id = {tid} AND (sku = ? OR barcode = ?)"), 'and lets the bracketed one pass');
    },
];
