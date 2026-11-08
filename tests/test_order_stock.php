<?php

if (!class_exists('WC_Order')) {
    class WC_Order {
        private int $id;
        private array $meta = array();
        private string $created_via = '';
        private array $items = array();

        public function __construct(int $id = 0) {
            $this->id = $id;
        }

        public function get_id(): int {
            return $this->id;
        }

        public function get_meta(string $key, bool $single = true) {
            return $this->meta[$key] ?? '';
        }

        public function set_meta(string $key, $value): void {
            $this->meta[$key] = $value;
        }

        public function get_created_via(): string {
            return $this->created_via;
        }

        public function set_created_via(string $v): void {
            $this->created_via = $v;
        }

        public function get_items(string $type = 'line_item'): array {
            return $this->items;
        }

        public function set_items(array $items): void {
            $this->items = $items;
        }
    }
}

require_once dirname(__DIR__) . '/includes/class-mgws-order-stock.php';

function mgws_reset_order_stock() {
    $GLOBALS['mgws_test_tables'] = array();
    $GLOBALS['wpdb'] = new wpdb();
    MGWS_Order_Stock::$refund_in_progress = false;
}

if (!function_exists('wc_get_order')) {
    function wc_get_order($id) {
        return $GLOBALS['mgws_test_orders'][(int) $id] ?? null;
    }
}

mgws_test('raise_refund_flag: sets the static flag', function () {
    mgws_reset_order_stock();
    mgws_assert_false(MGWS_Order_Stock::$refund_in_progress);
    MGWS_Order_Stock::raise_refund_flag(true, null, array());
    mgws_assert_true(MGWS_Order_Stock::$refund_in_progress);
});

mgws_test('is_accepted: reads order meta and post meta', function () {
    mgws_reset_order_stock();
    $o = new WC_Order(5);
    mgws_assert_false(MGWS_Order_Stock::is_accepted($o));
    $o->set_meta('_mgws_accept_committed', 1);
    mgws_assert_true(MGWS_Order_Stock::is_accepted($o));
});

mgws_test('already_taken: false when no moves', function () {
    mgws_reset_order_stock();
    mgws_assert_false(MGWS_Order_Stock::already_taken(7));
});

mgws_test('already_taken: true when an out move exists', function () {
    mgws_reset_order_stock();
    $table = MGWS_DB::table_moves();
    $GLOBALS['mgws_test_tables'][$table] = array(
        array('id' => 1, 'ref_order_id' => 7, 'type' => 'out', 'qty' => 2, 'warehouse_id' => 1, 'site_id' => 1, 'product_id' => 10, 'variation_id' => 0, 'room' => '', 'rack' => '', 'shelf' => ''),
    );
    mgws_assert_true(MGWS_Order_Stock::already_taken(7));
});

mgws_test('on_order_paid: skips POS-created orders', function () {
    mgws_reset_order_stock();
    $o = new WC_Order(9);
    $o->set_created_via('mgws-pos');
    $GLOBALS['mgws_test_orders'][9] = $o;
    // should not throw and should not write moves
    MGWS_Order_Stock::on_order_paid(9, $o);
    $table = MGWS_DB::table_moves();
    $moves = $GLOBALS['mgws_test_tables'][$table] ?? array();
    mgws_assert_same(0, count($moves));
});

mgws_test('on_order_paid: skips already-accepted orders', function () {
    mgws_reset_order_stock();
    $o = new WC_Order(10);
    $o->set_meta('_mgws_accept_committed', 1);
    $GLOBALS['mgws_test_orders'][10] = $o;
    MGWS_Order_Stock::on_order_paid(10, $o);
    $table = MGWS_DB::table_moves();
    $moves = $GLOBALS['mgws_test_tables'][$table] ?? array();
    mgws_assert_same(0, count($moves));
});

mgws_test('on_order_paid: skips when no order resolves', function () {
    mgws_reset_order_stock();
    // no order registered => resolve_order returns null => early return
    MGWS_Order_Stock::on_order_paid(999, null);
    $table = MGWS_DB::table_moves();
    $moves = $GLOBALS['mgws_test_tables'][$table] ?? array();
    mgws_assert_same(0, count($moves));
});

mgws_test('on_order_paid: skips already-taken (out move present)', function () {
    mgws_reset_order_stock();
    $o = new WC_Order(11);
    $GLOBALS['mgws_test_orders'][11] = $o;
    $table = MGWS_DB::table_moves();
    $GLOBALS['mgws_test_tables'][$table] = array(
        array('id' => 1, 'ref_order_id' => 11, 'type' => 'out', 'qty' => 2, 'warehouse_id' => 1, 'site_id' => 1, 'product_id' => 10, 'variation_id' => 0, 'room' => '', 'rack' => '', 'shelf' => ''),
    );
    MGWS_Order_Stock::on_order_paid(11, $o);
    // no new moves written
    mgws_assert_same(1, count($GLOBALS['mgws_test_tables'][$table]));
});

mgws_test('on_order_paid: skips non-managing-stock products', function () {
    mgws_reset_order_stock();
    $o = new WC_Order(12);
    $item = new stdClass();
    $item->product_id = 10;
    $item->variation_id = 0;
    $item->quantity = 2;
    // emulate get_items returning an array of item objects with methods
    $orderItem = new class {
        public function get_product_id() { return 10; }
        public function get_variation_id() { return 0; }
        public function get_quantity() { return 2; }
    };
    $o->set_items(array($orderItem));
    $GLOBALS['mgws_test_orders'][12] = $o;
    $GLOBALS['mgws_test_products'][10] = new MGWS_Test_Product(10, false, 5); // not managing stock
    $GLOBALS['mgws_test_warehouse_sites'][1] = 1;
    MGWS_Order_Stock::on_order_paid(12, $o);
    $table = MGWS_DB::table_moves();
    $moves = $GLOBALS['mgws_test_tables'][$table] ?? array();
    mgws_assert_same(0, count($moves));
});

mgws_test('on_order_released: does nothing when no out move', function () {
    mgws_reset_order_stock();
    $o = new WC_Order(13);
    $GLOBALS['mgws_test_orders'][13] = $o;
    MGWS_Order_Stock::on_order_released(13, $o);
    $table = MGWS_DB::table_moves();
    $moves = $GLOBALS['mgws_test_tables'][$table] ?? array();
    mgws_assert_same(0, count($moves));
});