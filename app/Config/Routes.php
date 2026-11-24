<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->setAutoRoute(false);

// public
$routes->get('update', 'Update::index');     // core 1.1 (A9): the in-app database update; every page comes here while steps wait
$routes->post('update', 'Update::run');
$routes->get('login', 'AuthController::login');
$routes->post('login', 'AuthController::doLogin');
$routes->get('install', 'Install::index');
$routes->post('install', 'Install::run');
$routes->get('cron/run', 'Cron::run');
$routes->get('health', 'Cron::health'); // core 1.1
$routes->get('api/ping', 'Home::ping');

// core 1.5b: payment gateways call these with no sign-in (signature checked, or DPO asked)
$routes->post('pay/lenco/(:segment)', 'PayPublic::lenco/$1');
$routes->get('pay/return', 'PayPublic::back');

// signed in, any organisation or none
$routes->group('', ['filter' => 'auth'], static function ($routes) {
    $routes->post('logout', 'AuthController::logout');
    $routes->get('account', 'AuthController::account');
    $routes->post('account/password', 'AuthController::password');
    $routes->get('org', 'AuthController::org');
    $routes->post('org', 'AuthController::switchOrg');
    $routes->get('notifications', 'Home::notifications');
    $routes->get('live', 'Home::live');                                 // core 1.1 (E2): new messages for the pop-ups
    $routes->post('notifications/read', 'Home::readAll');
    $routes->get('notifications/(:num)', 'Home::openMessage/$1');
    $routes->post('notifications/(:num)/read', 'Home::readOne/$1');
});

// inside an organisation
$routes->group('', ['filter' => ['auth', 'tenant']], static function ($routes) {
    $routes->post('warehouse/switch', 'Home::switchWarehouse');

    $routes->get('/', 'Home::index');
    $routes->get('find', 'Home::find');
    $routes->get('offline/snapshot', 'Offline::snapshot');

    // stock
    $routes->get('stock', 'Stock::index');
    $routes->get('stock/item/(:num)', 'Stock::item/$1');
    $routes->get('putaway', 'Stock::putaway');
    $routes->get('moves/new', 'Stock::moveForm');
    $routes->post('moves', 'Stock::move');
    $routes->get('lots', 'Stock::lots');
    $routes->get('lots/(:num)', 'Stock::lot/$1');
    $routes->post('lots/(:num)/status', 'Stock::lotStatus/$1');
    $routes->get('serials/(:num)', 'Stock::serial/$1');
    $routes->get('bins/(:num)', 'Stock::bin/$1');

    // barcode labels
    $routes->get('labels/items', 'Labels::items');
    $routes->get('labels/bins/(:num)', 'Labels::bins/$1');
    $routes->get('labels/lot/(:num)', 'Labels::lot/$1');
    $routes->get('labels/receipt/(:num)', 'Labels::receipt/$1');
    $routes->get('labels/run/(:num)', 'Labels::run/$1');

    // lists
    $routes->get('items', 'Items::index');
    $routes->get('items/new', 'Items::form');
    $routes->post('items', 'Items::save');
    $routes->get('items/(:num)', 'Items::form/$1');
    $routes->post('items/(:num)', 'Items::save/$1');
    $routes->post('categories', 'Items::category');
    $routes->get('(suppliers|customers)', 'Parties::index/$1');
    $routes->get('(suppliers|customers)/new', 'Parties::form/$1');
    $routes->post('(suppliers|customers)', 'Parties::save/$1');
    $routes->get('(suppliers|customers)/(:num)', 'Parties::form/$1/$2');
    $routes->post('(suppliers|customers)/(:num)', 'Parties::save/$1/$2');

    // purchasing and receiving
    $routes->get('purchase-orders', 'Purchasing::index');
    $routes->get('purchase-orders/new', 'Purchasing::form');
    $routes->post('purchase-orders', 'Purchasing::save');
    $routes->get('purchase-orders/(:num)', 'Purchasing::show/$1');
    $routes->get('purchase-orders/(:num)/edit', 'Purchasing::form/$1');
    $routes->post('purchase-orders/(:num)', 'Purchasing::save/$1');
    $routes->post('purchase-orders/(:num)/issue', 'Purchasing::issue/$1');
    $routes->post('purchase-orders/(:num)/close', 'Purchasing::close/$1');
    $routes->get('receipts', 'Receiving::index');
    $routes->get('receipts/new', 'Receiving::form');
    $routes->post('receipts', 'Receiving::post');
    $routes->get('receipts/(:num)', 'Receiving::show/$1');
    $routes->get('supplier-returns/new', 'Receiving::returnForm');
    $routes->post('supplier-returns', 'Receiving::returnPost');

    // transfers
    $routes->get('transfers', 'Transfers::index');
    $routes->get('transfers/new', 'Transfers::form');
    $routes->post('transfers', 'Transfers::create');
    $routes->get('transfers/(:num)', 'Transfers::show/$1');
    $routes->post('transfers/(:num)/(allocate|unallocate|dispatch|cancel|receive)', 'Transfers::step/$1/$2');

    // sales and distribution
    $routes->get('orders', 'Orders::index');
    $routes->get('orders/new', 'Orders::form');
    $routes->post('orders', 'Orders::save');
    $routes->get('orders/(:num)', 'Orders::show/$1');
    $routes->get('orders/(:num)/edit', 'Orders::form/$1');
    $routes->post('orders/(:num)', 'Orders::save/$1');
    $routes->post('orders/(:num)/(confirm|cancel|ship)', 'Orders::step/$1/$2');
    $routes->get('shipments', 'Orders::shipments');
    $routes->get('shipments/(:num)', 'Orders::shipment/$1');
    $routes->get('shipments/(:num)/note', 'Orders::deliveryNote/$1');
    $routes->post('shipments/(:num)/(pick|pack|dispatch|deliver|fail|return|cancel)', 'Orders::shipmentAct/$1/$2');
    $routes->get('files/(:num)', 'Orders::file/$1');
    $routes->get('waves', 'Waves::index');
    $routes->post('waves', 'Waves::create');
    $routes->get('waves/(:num)', 'Waves::show/$1');
    $routes->post('waves/(:num)/(confirm|cancel)', 'Waves::step/$1/$2');

    // production
    $routes->get('production', 'Production::index');
    $routes->get('production/recipes/new', 'Production::recipeForm');
    $routes->post('production/recipes', 'Production::recipeSave');
    $routes->get('production/recipes/(:num)', 'Production::recipeForm/$1');
    $routes->post('production/recipes/(:num)', 'Production::recipeSave/$1');
    $routes->get('production/runs/new', 'Production::runForm');
    $routes->post('production/runs', 'Production::runPost');
    $routes->get('production/runs/(:num)', 'Production::run/$1');
    $routes->post('production/runs/(:num)/void', 'Production::void/$1');

    // fleet
    $routes->get('fleet', 'Fleet::index');
    $routes->get('fleet/vehicles/new', 'Fleet::vehicleForm');
    $routes->get('fleet/import', 'Fleet::importForm');
    $routes->post('fleet/import', 'Fleet::importPost');
    $routes->post('fleet/vehicles', 'Fleet::vehicleSave');
    $routes->get('fleet/vehicles/(:num)', 'Fleet::vehicleForm/$1');
    $routes->post('fleet/vehicles/(:num)', 'Fleet::vehicleSave/$1');
    $routes->post('fleet/vehicles/(:num)/log', 'Fleet::vehicleLog/$1');
    $routes->get('fleet/drivers/new', 'Fleet::driverForm');
    $routes->post('fleet/drivers', 'Fleet::driverSave');
    $routes->get('fleet/drivers/(:num)', 'Fleet::driverForm/$1');
    $routes->post('fleet/drivers/(:num)', 'Fleet::driverSave/$1');
    $routes->get('fleet/trips', 'Fleet::trips');
    $routes->get('fleet/trips/new', 'Fleet::tripForm');
    $routes->post('fleet/trips', 'Fleet::tripPlan');
    $routes->get('fleet/trips/(:num)', 'Fleet::trip/$1');
    $routes->post('fleet/trips/(:num)/(depart|complete|cancel|stop|unstop)', 'Fleet::tripAct/$1/$2');

    // control
    $routes->get('adjustments', 'Control::adjustments');
    $routes->get('adjustments/new', 'Control::adjustmentForm');
    $routes->post('adjustments', 'Control::adjustmentPost');
    $routes->get('adjustments/(:num)', 'Control::adjustment/$1');
    $routes->post('adjustments/(:num)/(approve|reject)', 'Control::adjustmentAct/$1/$2');
    $routes->get('counts', 'Control::counts');
    $routes->post('counts', 'Control::countStart');
    $routes->get('counts/(:num)', 'Control::count/$1');
    $routes->get('counts/(:num)/sheet', 'Control::countSheet/$1');
    $routes->post('counts/(:num)/(enter|submit|approve|reopen|cancel)', 'Control::countAct/$1/$2');

    // reports
    $routes->get('reports', 'Reports::index');
    $routes->get('reports/trace', 'Reports::trace');
    $routes->get('reports/(:segment)', 'Reports::show/$1');

    // set-up and administration
    $routes->get('warehouses', 'Setup::index');
    $routes->post('warehouses', 'Setup::create');
    $routes->get('warehouses/(:num)', 'Setup::show/$1');
    $routes->post('warehouses/(:num)', 'Setup::update/$1');
    $routes->post('warehouses/(:num)/zones', 'Setup::zone/$1');
    $routes->post('warehouses/(:num)/bins', 'Setup::bins/$1');
    $routes->post('bins/(:num)/status', 'Setup::binStatus/$1');
    $routes->get('admin', 'Admin::index');
    $routes->post('admin/upgrade', 'Admin::upgrade');
    $routes->get('admin/users', 'Admin::users');
    $routes->post('admin/users', 'Admin::createUser');
    $routes->post('admin/users/existing', 'Admin::addExisting');
    $routes->get('admin/users/(:num)', 'Admin::user/$1');
    $routes->post('admin/users/(:num)', 'Admin::saveUser/$1');
    $routes->post('admin/users/(:num)/access', 'Admin::saveAccess/$1');
    $routes->get('admin/roles', 'Admin::roles');
    $routes->get('admin/roles/new', 'Admin::role');
    $routes->post('admin/roles', 'Admin::saveRole');
    $routes->get('admin/roles/(:num)', 'Admin::role/$1');
    $routes->post('admin/roles/(:num)', 'Admin::saveRole/$1');
    $routes->post('admin/roles/(:num)/copy', 'Admin::copyRole/$1');
    $routes->post('admin/roles/(:num)/delete', 'Admin::deleteRole/$1');
    $routes->get('admin/settings', 'Admin::settings');
    $routes->post('admin/settings', 'Admin::saveSettings');
    $routes->post('admin/reasons', 'Admin::saveReason');
    $routes->post('admin/logo', 'Admin::logo');
    $routes->get('brand/logo', 'Admin::logoFile');
    $routes->get('admin/reconcile', 'Admin::reconcile');
    $routes->post('admin/reconcile', 'Admin::runReconcile');
    $routes->get('audit', 'AuditLog::index');

    // core 1.5: the organisation's plan and invoices, and the modules it may switch
    $routes->get('billing', 'Billing::index');
    $routes->get('billing/invoices/(:num)', 'Billing::invoice/$1');
    $routes->post('billing/invoices/(:num)/pay', 'Billing::pay/$1');
    $routes->post('billing/payments/(:num)/check', 'Billing::check/$1');
    $routes->get('admin/modules', 'Billing::modules');
    $routes->post('admin/modules', 'Billing::saveModule');
});

// the platform owner (online service edition only)
$routes->group('platform', ['filter' => ['auth', 'platform']], static function ($routes) {
    $routes->get('/', 'Platform::index');
    $routes->get('tenants/new', 'Platform::newTenant');
    $routes->post('tenants', 'Platform::createTenant');
    $routes->get('tenants/(:num)', 'Platform::tenant/$1');
    $routes->post('tenants/(:num)/status', 'Platform::status/$1');
    $routes->get('settings', 'Platform::settings');
    $routes->post('settings', 'Platform::saveSettings');
    $routes->get('integrity', 'Platform::integrity');
    $routes->post('integrity', 'Platform::runIntegrity');
    $routes->post('upgrade', 'Platform::upgrade');
    $routes->get('audit', 'AuditLog::platform');
    // core 1.5: plans, invoices, online payments
    $routes->get('plans', 'PlatformBilling::plans');
    $routes->post('plans', 'PlatformBilling::savePlan');
    $routes->get('invoices', 'PlatformBilling::invoices');
    $routes->get('invoices/(:num)', 'PlatformBilling::invoice/$1');
    $routes->post('invoices/(:num)/pay', 'PlatformBilling::pay/$1');
    $routes->post('invoices/(:num)/void', 'PlatformBilling::void/$1');
    $routes->get('payments', 'PlatformBilling::payments');
    $routes->post('payments/(:num)/check', 'PlatformBilling::checkPayment/$1');
    $routes->get('billing', 'PlatformBilling::settings');
    $routes->post('billing', 'PlatformBilling::saveSettings');
    $routes->post('tenants/(:num)/plan', 'PlatformBilling::setPlan/$1');
    $routes->post('tenants/(:num)/invoice', 'PlatformBilling::issue/$1');
    $routes->post('tenants/(:num)/trial', 'PlatformBilling::extendTrial/$1');
    $routes->post('tenants/(:num)/modules', 'PlatformBilling::modules/$1');
});
