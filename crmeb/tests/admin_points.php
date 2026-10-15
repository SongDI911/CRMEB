<?php
/** Run: php -d extension=pdo_sqlite -d extension=mbstring tests/admin_points.php */
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
    return false;
});
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/vendor/topthink/framework/src/helper.php';

// Keep the test independent of translations, Redis, WeChat and production data.
function getLang($message, $replace = []) { return $message; }
$app = new \think\App(dirname(__DIR__));
\think\Container::setInstance($app);
$app->instance('think\\DbManager', new \think\DbManager());

use think\facade\Db;
use app\services\user\AdminPointsServices;
use crmeb\exceptions\AdminException;

// SQLite transactions cover rollback tests; MySQL row locking needs deployment testing.
class PointsSqliteBuilder extends \think\db\builder\Sqlite
{
    protected function parseLock(\think\db\Query $query, $lock = false): string { return ''; }
    public function parseKey(\think\db\Query $query, $key, bool $strict = false): string
    {
        return $key === 'unique' ? '"unique"' : parent::parseKey($query, $key, $strict);
    }
}
Db::setConfig(['default' => 'test', 'connections' => ['test' => [
    'type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'eb_', 'fields_strict' => false,
    'builder' => PointsSqliteBuilder::class,
]]]);
Db::execute('CREATE TABLE eb_user (uid INTEGER PRIMARY KEY, nickname TEXT, avatar TEXT, integral NUMERIC DEFAULT 0, status INTEGER DEFAULT 1, is_del INTEGER DEFAULT 0)');
Db::execute('CREATE TABLE eb_user_bill (id INTEGER PRIMARY KEY AUTOINCREMENT, uid INTEGER, link_id INTEGER, category TEXT, type TEXT, title TEXT, number NUMERIC, balance NUMERIC, pm INTEGER, mark TEXT, status INTEGER, add_time INTEGER, frozen_time INTEGER DEFAULT 0)');
Db::name('user')->insertAll([
    ['uid' => 1, 'nickname' => '甲', 'avatar' => '', 'status' => 1],
    ['uid' => 2, 'nickname' => '乙', 'avatar' => '', 'status' => 1],
    ['uid' => 3, 'nickname' => '丙', 'avatar' => '', 'status' => 1],
    ['uid' => 4, 'nickname' => '停用用户', 'avatar' => '', 'status' => 0],
]);
$checks = 0;
function check($condition, $message) {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    ++$checks;
}
function rejected(callable $fn) {
    try { $fn(); } catch (AdminException $e) { return true; }
    return false;
}
$points = new AdminPointsServices();
Db::name('user')->insert(['uid' => 5, 'nickname' => '注销用户', 'avatar' => '', 'status' => 1, 'is_del' => 1]);
check(rejected(function () use ($points) { $points->adjust(5, 10, 1, 9, '测试'); }), 'deleted user rejected');
check($points->total(1) === 0, 'new account total');
$points->adjust(1, '1000', 1, 9, '项目奖励');
$points->adjust(2, '1000', 1, 9, '项目奖励');
check($points->total(1) === 1000, 'award increments cumulative total');
check((int)Db::name('user')->where('uid', 1)->value('integral') === 1000, 'award increments balance');
check((int)Db::name('user_bill')->where('uid', 1)->value('link_id') === 9, 'operator is recorded');
check($points->today(1) === 1000, 'today includes administrator awards');
$rank = $points->ranking(1);
check($rank['count'] === 3 && $rank['my']['rank'] === 1, 'inactive users excluded and ties deterministic');
check($rank['list'][0]['is_me'] && !isset($rank['list'][0]['uid']), 'self identified without exposing ids');
check($points->ranking(2, 2, 1)['list'][0]['rank'] === 2, 'pagination retains absolute rank');

// Exercise the existing exchange debit service (the create-order path additionally locks the user).
$orderService = $app->make(\app\services\activity\integral\StoreIntegralOrderServices::class);
Db::transaction(function () use ($orderService) {
    $orderService->deductIntegral(['uid' => 1, 'integral' => 1000], 300, 1, '101');
});
check($points->total(1) === 1000, 'exchange must not change earned points');
check($points->ranking(1)['my']['available_integral'] === 700, 'exchange deducts available balance');
check($points->ranking(1)['my']['rank'] === 1, 'exchange does not change rank');

// Refund entry must not become a second award.
Db::name('user')->where('uid', 1)->inc('integral', 300)->update();
Db::name('user_bill')->insert(['uid' => 1, 'category' => 'integral', 'type' => 'pay_product_integral_back', 'number' => 300, 'pm' => 1, 'status' => 1]);
check($points->total(1) === 1000, 'refund excluded from cumulative points');
$points->adjust(1, 100, 2, 9, '撤销错发');
check($points->total(1) === 900 && $points->ranking(1)['my']['rank'] === 2, 'revoke corrects total and rank');
check($points->ranking(1)['my']['available_integral'] === 900, 'revoke corrects balance');

foreach ([0, -1, '1.5', '1e3', '1000000000'] as $invalid) {
    check(rejected(function () use ($points, $invalid) { $points->adjust(1, $invalid, 1, 9, '测试'); }), 'invalid points rejected');
}
check(rejected(function () use ($points) { $points->adjust(1, 10, 1, 0, '测试'); }), 'missing administrator rejected');
check(rejected(function () use ($points) { $points->adjust(1, 10, 1, 9, ' '); }), 'reason required');
check(rejected(function () use ($points) { $points->adjust(4, 10, 1, 9, '测试'); }), 'disabled user rejected');
check(rejected(function () use ($points) { $points->adjust(1, 901, 2, 9, '测试'); }), 'overdraw rejected');
Db::name('user_bill')->insert(['uid' => 1, 'category' => 'integral', 'type' => 'gain', 'number' => 850, 'frozen_time' => time() + 3600, 'pm' => 1, 'status' => 1]);
check(rejected(function () use ($points) { $points->adjust(1, 100, 2, 9, '测试'); }), 'frozen balance cannot be revoked');
check($points->ranking(1)['my']['available_integral'] === 50, 'frozen points excluded from available balance');

Db::execute("CREATE TRIGGER fail_bill BEFORE INSERT ON eb_user_bill BEGIN SELECT RAISE(ABORT, 'test failure'); END");
$before = Db::name('user')->where('uid', 2)->value('integral');
try { $points->adjust(2, 20, 1, 9, '测试回滚'); throw new RuntimeException('expected insert failure'); }
catch (\think\db\exception\PDOException $e) { /* expected */ }
check(Db::name('user')->where('uid', 2)->value('integral') == $before, 'ledger failure rolls back balance');
check($points->total(2) === 1000, 'ledger failure leaves total unchanged');
Db::execute('DROP TRIGGER fail_bill');

$bills = $app->make(\app\services\user\UserBillServices::class);
check(rejected(function () use ($bills) { $bills->incomeIntegral(1, 'system_add', ['number' => 10]); }), 'old direct award entry blocked');
foreach (['pay_give_integral', 'order_give_integral', 'lottery_give_integral'] as $type) {
    check(rejected(function () use ($bills, $type) { $bills->income($type, 1, 10, 910, 1); }), 'automatic income blocked');
}
try { $app->make(\app\services\user\UserSignServices::class)->sign(1); throw new RuntimeException('sign should fail'); }
catch (\crmeb\exceptions\ApiException $e) { check(true, 'sign-in blocked'); }
check($app->make(\app\services\order\StoreOrderTakeServices::class)->gainUserIntegral([], null, '') === true, 'order rewards disabled without blocking delivery');

// Exercise real conditional stock updates, including failure after an earlier update succeeded.
Db::execute('CREATE TABLE eb_store_integral (id INTEGER PRIMARY KEY, product_id INTEGER, stock INTEGER, quota INTEGER, sales INTEGER DEFAULT 0, is_show INTEGER DEFAULT 1, is_del INTEGER DEFAULT 0, num INTEGER DEFAULT -1, once_num INTEGER DEFAULT -1, add_time INTEGER DEFAULT 0)');
Db::execute('CREATE TABLE eb_store_product (id INTEGER PRIMARY KEY, stock INTEGER, sales INTEGER DEFAULT 0)');
Db::execute('CREATE TABLE eb_store_product_attr_value (id INTEGER PRIMARY KEY, product_id INTEGER, type INTEGER, "unique" TEXT, suk TEXT, stock INTEGER, quota INTEGER, sales INTEGER DEFAULT 0)');
Db::name('store_integral')->insert(['id' => 10, 'product_id' => 20, 'stock' => 3, 'quota' => 3]);
Db::name('store_product')->insert(['id' => 20, 'stock' => 3]);
Db::name('store_product_attr_value')->insertAll([
    ['id' => 1, 'product_id' => 10, 'type' => 4, 'unique' => 'gift', 'suk' => 'blue', 'stock' => 3, 'quota' => 3],
    ['id' => 2, 'product_id' => 20, 'type' => 0, 'unique' => 'base', 'suk' => 'blue', 'stock' => 3, 'quota' => 0],
]);
$products = $app->make(\app\services\activity\integral\StoreIntegralServices::class);
check($products->decIntegralStock(1, 10, 'gift') === true, 'exchange stock updates succeed');
check((int)Db::name('store_integral')->value('quota') === 2 && (int)Db::name('store_product')->value('stock') === 2, 'exchange updates activity and underlying stock');
check($products->decIntegralStock(0, 10, 'gift') === false, 'zero quantity rejected');
Db::name('store_product_attr_value')->where('id', 2)->update(['stock' => 0]);
try { $products->decIntegralStock(1, 10, 'gift'); throw new RuntimeException('expected stock failure'); }
catch (\crmeb\exceptions\ApiException $e) { check(true, 'insufficient underlying stock rejected'); }
check((int)Db::name('store_integral')->value('quota') === 2 && (int)Db::name('store_integral')->value('sales') === 1, 'stock failure rolls back prior quota and sales updates');
check((int)Db::name('store_product_attr_value')->where('id', 1)->value('stock') === 2, 'stock failure rolls back activity SKU');
Db::name('store_product_attr_value')->where('id', 2)->update(['stock' => 2]);
try {
    Db::transaction(function () use ($products, $orderService) {
        $products->decIntegralStock(1, 10, 'gift');
        $orderService->deductIntegral(['uid' => 2, 'integral' => 1000], 100, 2, '102');
        throw new RuntimeException('simulate order failure');
    });
} catch (RuntimeException $e) { /* expected */ }
check((int)Db::name('store_integral')->value('quota') === 2, 'outer order failure rolls back nested stock transaction');
check((int)Db::name('user')->where('uid', 2)->value('integral') === 1000 && $points->total(2) === 1000, 'outer order failure rolls back balance and preserves ranking');
function apiRejected(callable $fn) {
    try { $fn(); } catch (\crmeb\exceptions\ApiException $e) { return true; }
    return false;
}
check(apiRejected(function () use ($app) {
    $app->make(\app\services\user\OutUserServices::class)->changeUserData(2, 999999, 'integral');
}), 'open API cannot replace points balance');
check(apiRejected(function () use ($app) {
    $app->make(\app\services\user\LoginServices::class)->remoteRegister(base64_encode(json_encode(['uid' => 2, 'integral' => 999999])));
}), 'unsigned remote login cannot impersonate a user or sync points');
check((int)Db::name('user')->where('uid', 2)->value('integral') === 1000, 'blocked external entries leave balance unchanged');

// Use the real backend user-edit service, not just the isolated points service.
$userServices = $app->make(\app\services\user\UserServices::class);
$adjustData = ['money_status' => 0, 'integration_status' => 1, 'integration' => '20', 'adminId' => 9, 'mark' => '后台发放测试', 'is_other' => true];
check($userServices->updateInfo(2, $adjustData) === true, 'backend user edit accepts administrator awards');
check($points->total(2) === 1020 && (int)Db::name('user')->where('uid', 2)->value('integral') === 1020, 'backend edit does not overwrite awarded balance');
$adjustData['adminId'] = 0;
check(rejected(function () use ($userServices, $adjustData) { $userServices->updateInfo(2, $adjustData); }), 'backend edit rejects missing trusted administrator');

// Generate SQLite table columns from the shipped installer to catch schema drift.
function installTestTable($table) {
    $sql = file_get_contents(dirname(__DIR__) . '/public/install/crmeb.sql');
    if (!preg_match('/CREATE TABLE IF NOT EXISTS `eb_' . preg_quote($table, '/') . '` \((.*?)\) ENGINE=/s', $sql, $match)) throw new RuntimeException('table not found');
    preg_match_all('/^\s*`([^`]+)`\s+([^\r\n]+)/m', $match[1], $columns, PREG_SET_ORDER);
    $fields = [];
    foreach ($columns as $column) {
        $definition = $column[2];
        $type = preg_match('/^(tinyint|smallint|mediumint|bigint|int)/i', $definition) ? 'INTEGER' : (preg_match('/^(decimal|float|double)/i', $definition) ? 'NUMERIC' : 'TEXT');
        $field = '"' . $column[1] . '" ' . $type;
        if (strpos($definition, 'AUTO_INCREMENT') !== false) $field = '"' . $column[1] . '" INTEGER PRIMARY KEY AUTOINCREMENT';
        elseif (preg_match("/DEFAULT ('[^']*'|NULL|[0-9]+)/i", $definition, $default)) $field .= ' DEFAULT ' . $default[1];
        $fields[] = $field;
    }
    Db::execute('CREATE TABLE eb_' . $table . ' (' . implode(',', $fields) . ')');
}
foreach (['user_address', 'store_integral_order', 'store_integral_order_status'] as $table) installTestTable($table);
Db::name('user_address')->insert(['id' => 1, 'uid' => 2, 'real_name' => '测试用户', 'phone' => '13800000000', 'province' => '测试省', 'city' => '测试市', 'district' => '测试区', 'detail' => '测试地址']);
$productInfo = ['product_id' => 10, 'unique' => 'gift', 'price' => 100, 'image' => '', 'store_name' => '测试礼品', 'suk' => 'blue'];
$userInfo = ['uid' => 2, 'integral' => 1020, 'user_type' => 'routine'];
$order = $orderService->createOrder(2, 1, '', $userInfo, 1, $productInfo);
check((int)$order->total_price === 100 && (int)$order->uid === 2, 'full exchange creates order for authenticated user');
check((int)Db::name('user')->where('uid', 2)->value('integral') === 920 && $points->total(2) === 1020, 'full exchange deducts balance but preserves cumulative points');
check((int)Db::name('store_integral')->value('quota') === 1 && Db::name('store_integral_order_status')->count() === 1, 'full exchange saves stock and status');
check(apiRejected(function () use ($orderService, $userInfo, $productInfo) { $orderService->createOrder(3, 1, '', $userInfo, 1, $productInfo); }), 'another user cannot use this delivery address');
check(apiRejected(function () use ($products) { $products->checkoutProductStock(2, 10, 1, 'missing'); }), 'unknown SKU returns business error');
check(apiRejected(function () use ($orderService, $userInfo, $productInfo) { $orderService->createOrder(2, 1, '', $userInfo, 0, $productInfo); }), 'full exchange rejects zero quantity');
Db::name('user')->where('uid', 2)->update(['integral' => 50]);
check(apiRejected(function () use ($orderService, $userInfo, $productInfo) { $orderService->createOrder(2, 1, '', $userInfo, 1, $productInfo); }), 'full exchange rechecks stale request balance');
Db::name('user')->where('uid', 2)->update(['integral' => 920]);

// A status failure must roll back the actual createOrder transaction as well.
Db::execute("CREATE TRIGGER fail_status BEFORE INSERT ON eb_store_integral_order_status BEGIN SELECT RAISE(ABORT, 'test failure'); END");
try { $orderService->createOrder(2, 1, '', $userInfo, 1, $productInfo); throw new RuntimeException('expected status failure'); }
catch (\think\db\exception\PDOException $e) { /* expected */ }
check(Db::name('store_integral_order')->count() === 1 && (int)Db::name('store_integral')->value('quota') === 1, 'status failure rolls back order and stock');
check((int)Db::name('user')->where('uid', 2)->value('integral') === 920 && $points->total(2) === 1020, 'status failure rolls back balance and leaves ranking unchanged');
Db::execute('DROP TRIGGER fail_status');
Db::name('store_integral')->where('id', 10)->update(['num' => 1]);
Db::name('store_integral_order')->where('id', $order->id)->update(['is_del' => 1, 'status' => 3]);
check(apiRejected(function () use ($products) { $products->checkoutProductStock(2, 10, 1, 'gift'); }), 'deleting completed order cannot bypass lifetime exchange quota');
$cashOrders = $app->make(\app\services\order\StoreOrderCreateServices::class);
$cashPrice = ['usedIntegral' => 100, 'SurplusIntegral' => 0, 'deduction_price' => '1.00'];
$cashOrders->deductIntegral(['uid' => 2, 'integral' => 100], true, $cashPrice, 2, '201');
check((int)Db::name('user')->where('uid', 2)->value('integral') === 820, 'ordinary checkout must not erase awards made after its balance snapshot');
check($points->total(2) === 1020, 'ordinary checkout preserves cumulative rank');
$cashPrice['usedIntegral'] = 900;
check(apiRejected(function () use ($cashOrders, $cashPrice) { $cashOrders->deductIntegral(['uid' => 2, 'integral' => 1020], true, $cashPrice, 2, '202'); }), 'ordinary checkout rechecks current balance');
$cashPrice['usedIntegral'] = -100;
check(apiRejected(function () use ($cashOrders, $cashPrice) { $cashOrders->deductIntegral(['uid' => 2, 'integral' => 820], true, $cashPrice, 2, '203'); }), 'negative ordinary checkout debit cannot add points');
// Exercise the real order-detail controller with only its JSON response wrapper replaced.
$app->instance('json', new class {
    public function success($data) { return ['ok' => true, 'data' => $data]; }
    public function fail($message) { return ['ok' => false, 'message' => $message]; }
});
Db::name('store_integral_order')->where('id', $order->id)->update(['is_del' => 0]);
$controller = new \app\api\controller\v1\order\StoreIntegralOrderController($orderService);
$request = new \app\Request();
$request->macro('uid', function () { return 2; });
check($controller->detail($request, $order->order_id)['ok'], 'owner can read exchange order detail');
$request->macro('uid', function () { return 3; });
check(!$controller->detail($request, $order->order_id)['ok'], 'another user cannot read exchange order detail');
$request->macro('uid', function () { return 0; });
check(!$controller->detail($request, $order->order_id)['ok'], 'anonymous user cannot read exchange order detail');
echo "PASS: $checks points checks\n";
