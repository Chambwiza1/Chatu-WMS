<?php

namespace App\Libraries\Core;

/**
 * The permissions of an organisation in one place (core 1.1, as ChatuPOS beta12), as an owner thinks of them: by
 * module, with Select · View · Create · Edit · Delete where a module keeps records, the module's own actions, and its
 * supervisor rights (approvals, never of your own work).
 *
 * - Select: pick a record of the module elsewhere (an item on a count, a supplier on a purchase order).
 * - View: open the module's own pages. Create: add records. Edit: change them. Delete: switch them off (nothing is
 *   ever removed, so old documents keep their names).
 *
 * A code belongs to one module and one column. The organisation's roles and the permissions given to a person
 * directly are both sets of these codes; what a person may do is the union, at the warehouses they are given.
 */
final class Rights
{
    public const COLUMNS = ['select' => 'Select', 'view' => 'View', 'create' => 'Create', 'update' => 'Edit', 'delete' => 'Delete', 'do' => 'Actions', 'supervise' => 'Supervisor'];

    /**
     * module => [title, platform module key (Platform\Product::MODULES) or null, [column => [code => [short label, description]]]].
     * Order is the order on the page.
     */
    public const MODULES = [
        'stock' => ['Stock', null, [
            'view' => ['stock.view' => ['View', 'See stock, documents and movements at the warehouse']],
            'do'   => [
                'stock.view_cost' => ['See costs', 'See costs and stock values'],
                'inventory.move'  => ['Move stock', 'Put away and move stock; put stock on hold or in quarantine'],
                'stock.opening'   => ['Opening stock', 'Enter opening stock balances'],
                'lot.hold'        => ['Hold batches', 'Hold, recall and release batches'],
            ],
            'supervise' => ['stock.approve' => ['Approve', 'Approve adjustments and count results; release stock from quarantine (never your own)']],
        ]],
        'items' => ['Items', null, [
            'select' => ['item.select' => ['Select', 'Pick items on orders, receipts, transfers, counts, recipes and labels']],
            'view'   => ['item.view' => ['View', 'See the item list, categories and pack sizes']],
            'create' => ['item.create' => ['Create', 'Add items and categories']],
            'update' => ['item.update' => ['Edit', 'Change items, pack sizes, barcodes and reorder levels']],
            'delete' => ['item.delete' => ['Delete', 'Switch items off']],
        ]],
        'suppliers' => ['Suppliers and purchasing', null, [
            'select' => ['supplier.select' => ['Select', 'Pick a supplier on a purchase order, a receipt or a return']],
            'view'   => ['supplier.view' => ['View', 'See suppliers']],
            'create' => ['supplier.create' => ['Create', 'Add suppliers']],
            'update' => ['supplier.update' => ['Edit', 'Change suppliers and their terms']],
            'delete' => ['supplier.delete' => ['Delete', 'Switch suppliers off']],
            'do'     => [
                'purchase.manage' => ['Purchase orders', 'Raise, issue and close purchase orders'],
                'receive.post'    => ['Receive', 'Receive goods and return goods to suppliers'],
            ],
        ]],
        'customers' => ['Customers and orders', null, [
            'select' => ['customer.select' => ['Select', 'Pick a customer on an order or a return']],
            'view'   => ['customer.view' => ['View', 'See customers']],
            'create' => ['customer.create' => ['Create', 'Add customers']],
            'update' => ['customer.update' => ['Edit', 'Change customers, their addresses and terms']],
            'delete' => ['customer.delete' => ['Delete', 'Switch customers off']],
            'do'     => [
                'sales.manage'    => ['Sales orders', 'Take, confirm and cancel sales orders'],
                'dispatch.manage' => ['Pick, pack, dispatch', 'Pick, pack and dispatch orders; record deliveries and returns'],
            ],
        ]],
        'transfers' => ['Transfers', null, [
            'do' => [
                'transfer.manage'  => ['Send', 'Raise, allocate and dispatch transfers from the warehouse'],
                'transfer.receive' => ['Receive', 'Receive transfers into the warehouse'],
            ],
        ]],
        'control' => ['Adjustments and counts', null, [
            'do' => [
                'adjust.request' => ['Adjustments', 'Raise stock adjustments for someone else to approve'],
                'count.manage'   => ['Run counts', 'Start, submit and cancel stock counts'],
                'count.enter'    => ['Enter counts', 'Enter counted quantities'],
            ],
        ]],
        'production' => ['Production', 'production', [
            'do' => ['production.manage' => ['Production', 'Post and void production runs; keep recipes']],
        ]],
        'fleet' => ['Fleet and trips', 'fleet', [
            'do' => ['fleet.manage' => ['Fleet', 'Keep vehicles and drivers; plan and close trips']],
        ]],
        'reports' => ['Reports', null, [
            'view' => ['report.view' => ['View', 'Run reports']],
            'do'   => [
                'data.export' => ['Export CSV', 'Export data to CSV'],
                'audit.view'  => ['Audit log', 'See the organisation\'s audit log'],
            ],
        ]],
        'warehouses' => ['Warehouses and bins', null, [
            'do' => ['warehouse.setup' => ['Set up', 'Keep warehouses, zones and bins']],
        ]],
        'people' => ['People', null, [
            'select' => ['user.select' => ['Select', 'Pick a person: a driver\'s login, filters in reports']],
            'view'   => ['user.view' => ['View', 'See the people of the organisation and their access']],
            'create' => ['user.create' => ['Create', 'Add people']],
            'update' => ['user.update' => ['Edit', 'Change people\'s details, passwords, roles, warehouses and permissions']],
            'delete' => ['user.delete' => ['Delete', 'Switch people off in this organisation']],
        ]],
        'roles' => ['Roles', null, [
            'view'   => ['role.view' => ['View', 'See the roles and what each may do']],
            'create' => ['role.create' => ['Create', 'Add roles, or copy one']],
            'update' => ['role.update' => ['Edit', 'Change what a role may do (never a role you hold yourself)']],
            'delete' => ['role.delete' => ['Delete', 'Delete a role nobody holds']],
        ]],
        'settings' => ['Settings', null, [
            'do' => [
                'admin.settings' => ['Settings and logo', 'Keep the organisation\'s settings, reason codes and logo'],
                'billing.manage' => ['Plan and billing', 'Change the plan, switch modules and pay invoices'],
            ],
        ]],
    ];

    /** Old codes (0.2.x) => the codes that replace them. */
    public const RENAMED = [
        'item.manage'     => ['item.select', 'item.view', 'item.create', 'item.update', 'item.delete'],
        'supplier.manage' => ['supplier.select', 'supplier.view', 'supplier.create', 'supplier.update', 'supplier.delete'],
        'customer.manage' => ['customer.select', 'customer.view', 'customer.create', 'customer.update', 'customer.delete'],
        'admin.users'     => ['user.select', 'user.view', 'user.create', 'user.update', 'user.delete', 'role.view', 'role.create', 'role.update', 'role.delete'],
    ];

    /** Codes that came with an old code without replacing it: holders of the key also get these (the pickers their work needs). */
    public const ADDED = [
        'stock.view'      => ['item.select', 'item.view'],
        'purchase.manage' => ['supplier.select', 'supplier.view', 'item.select'],
        'receive.post'    => ['supplier.select', 'item.select'],
        'sales.manage'    => ['customer.select', 'customer.view', 'item.select'],
        'dispatch.manage' => ['customer.select'],
        'transfer.manage' => ['item.select'],
        'adjust.request'  => ['item.select'],
        'count.manage'    => ['item.select'],
        'production.manage' => ['item.select'],
        'fleet.manage'    => ['user.select'],
        'report.view'     => ['item.select', 'supplier.select', 'customer.select'],
        'admin.settings'  => ['billing.manage'],
    ];

    /** code => [module, column, label, description] */
    public static function all(): array
    {
        static $all = null;
        if ($all === null) {
            $all = [];
            foreach (self::MODULES as $mod => [, , $cols]) {
                foreach ($cols as $col => $codes) {
                    foreach ($codes as $code => [$label, $desc]) {
                        $all[$code] = [$mod, $col, $label, $desc];
                    }
                }
            }
        }
        return $all;
    }

    public static function exists(string $code): bool
    {
        return isset(self::all()[$code]);
    }

    /** The modules shown for the current organisation: those of a platform module it does not have are left out. */
    public static function modules(): array
    {
        return array_filter(self::MODULES, static fn ($m) => $m[1] === null || Tenancy::id() === null || \App\Libraries\Platform\Modules::on($m[1]));
    }

    /** "Module, Box" for a code, as refusals name it. */
    public static function label(string $code): ?string
    {
        $r = self::all()[$code] ?? null;
        return $r ? self::MODULES[$r[0]][0] . ', ' . $r[2] : null;
    }

    /** What old grants become: the renamed codes in, the old ones out, the added ones next to their key. */
    public static function upgrade(array $codes): array
    {
        $out = [];
        foreach ($codes as $c) {
            foreach (self::RENAMED[$c] ?? [$c] as $n) {
                $out[$n] = true;
            }
            foreach (self::ADDED[$c] ?? [] as $n) {
                $out[$n] = true;
            }
        }
        $out = array_keys(array_filter($out, static fn ($v, $k) => self::exists($k), ARRAY_FILTER_USE_BOTH));
        sort($out);
        return $out;
    }
}
