<?php

require_once dirname(__DIR__) . '/includes/class-mgws-documents.php';

function mgws_reset_documents() {
    $GLOBALS['mgws_test_tables'] = array();
    $GLOBALS['wpdb'] = new wpdb();
}

mgws_test('create: requires document_type', function () {
    mgws_reset_documents();
    $r = MGWS_Documents::create('', 'N1', 'order', 5, '', 1);
    mgws_assert_true($r instanceof WP_Error);
});

mgws_test('create: defaults to draft', function () {
    mgws_reset_documents();
    $d = MGWS_Documents::create('ddt', 'N1', 'order', 5, 'note', 1);
    mgws_assert_true(is_array($d));
    mgws_assert_same('draft', $d['status']);
    mgws_assert_same('ddt', $d['document_type']);
});

mgws_test('update_status: validates allowed values', function () {
    mgws_reset_documents();
    $d = MGWS_Documents::create('ddt', 'N1', 'order', 5, '', 1);
    $id = (int) $d['id'];
    $bad = MGWS_Documents::update_status($id, 'bogus');
    mgws_assert_true($bad instanceof WP_Error);
    $ok = MGWS_Documents::update_status($id, 'issued', '2026-01-01 10:00:00', '/tmp/x.pdf');
    mgws_assert_same('issued', $ok['status']);
});

mgws_test('update_status: missing => WP_Error', function () {
    mgws_reset_documents();
    $r = MGWS_Documents::update_status(999, 'issued');
    mgws_assert_true($r instanceof WP_Error);
});

mgws_test('list: filters by type/reference/status', function () {
    mgws_reset_documents();
    MGWS_Documents::create('ddt', 'N1', 'order', 5, '', 1);
    MGWS_Documents::create('invoice', 'N2', 'order', 7, '', 1);
    mgws_assert_same(2, count(MGWS_Documents::list()));
    mgws_assert_same(1, count(MGWS_Documents::list('ddt')));
    mgws_assert_same(1, count(MGWS_Documents::list('', 'order', 7)));
});

mgws_test('delete: missing => WP_Error; present => true', function () {
    mgws_reset_documents();
    $d = MGWS_Documents::create('ddt', 'N1', 'order', 5, '', 1);
    $id = (int) $d['id'];
    mgws_assert_same(true, MGWS_Documents::delete($id));
    mgws_assert_null(MGWS_Documents::get($id));
    mgws_assert_true(MGWS_Documents::delete(999) instanceof WP_Error);
});
mgws_test('delete: an issued document cannot be erased', function () {
    mgws_reset_documents();
    $d = MGWS_Documents::create('invoice', 'F1', 'order', 5, '', 1);
    $id = (int) $d['id'];
    MGWS_Documents::update_status($id, 'issued');
    mgws_assert_true(MGWS_Documents::delete($id) instanceof WP_Error);
    mgws_assert_true(is_array(MGWS_Documents::get($id)));
});

mgws_test('update_status: issued never goes back to draft, cancelled is final', function () {
    mgws_reset_documents();
    $d = MGWS_Documents::create('invoice', 'F1', 'order', 5, '', 1);
    $id = (int) $d['id'];
    MGWS_Documents::update_status($id, 'issued');
    mgws_assert_true(MGWS_Documents::update_status($id, 'draft') instanceof WP_Error);
    mgws_assert_same('cancelled', MGWS_Documents::update_status($id, 'cancelled')['status']);
    mgws_assert_true(MGWS_Documents::update_status($id, 'sent') instanceof WP_Error);
});
