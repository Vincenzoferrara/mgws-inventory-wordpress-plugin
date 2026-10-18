<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Report engine.
 *
 * A report is a question made of a measure (what to count), an optional
 * dimension (what to split by) and filters. The answer is aggregated in SQL,
 * so the app never downloads every order.
 *
 * Sales come from two channels, merged into one line-level fact:
 * - store: MGWS POS receipts and their lines. Line amounts include VAT; the
 *   document-level discount is spread over the lines so that the lines of a
 *   receipt always add up to the receipt total.
 * - online: WooCommerce analytics lookup tables, excluding the orders created
 *   by the POS (those are already counted as store receipts).
 *
 * Shift closings are a second source with their own measures.
 */
class MGWS_Reports {
    public const CACHE_TTL = 120;
    private const MAX_LIMIT = 500;
    private const ONLINE_STATUSES = array('wc-completed', 'wc-processing', 'wc-on-hold');

    /** Measures: key => [source, format]. */
    public static function measures() {
        return array(
            'revenue' => array('sales', 'currency'),
            'net_revenue' => array('sales', 'currency'),
            'tax' => array('sales', 'currency'),
            'discount' => array('sales', 'currency'),
            'discount_rate' => array('sales', 'percent'),
            'returns_value' => array('sales', 'currency'),
            'returns_rate' => array('sales', 'percent'),
            'quantity' => array('sales', 'count'),
            'quantity_sold' => array('sales', 'count'),
            'quantity_returned' => array('sales', 'count'),
            'documents' => array('sales', 'count'),
            'average_ticket' => array('sales', 'currency'),
            'units_per_document' => array('sales', 'decimal'),
            'average_unit_price' => array('sales', 'currency'),
            'distinct_products' => array('sales', 'count'),
            'customers' => array('sales', 'count'),
            'new_customers' => array('sales', 'count'),
            'returning_customers' => array('sales', 'count'),
            'revenue_per_customer' => array('sales', 'currency'),
            'documents_per_customer' => array('sales', 'decimal'),
            'first_purchase' => array('sales', 'date'),
            'last_purchase' => array('sales', 'date'),
            'days_since_last_purchase' => array('sales', 'days'),
            'closings' => array('closings', 'count'),
            'cash_collected' => array('closings', 'currency'),
            'card_collected' => array('closings', 'currency'),
            'other_collected' => array('closings', 'currency'),
            'collected_total' => array('closings', 'currency'),
            'refunds_paid' => array('closings', 'currency'),
            'manual_in' => array('closings', 'currency'),
            'manual_out' => array('closings', 'currency'),
            'cash_expected' => array('closings', 'currency'),
            'cash_counted' => array('closings', 'currency'),
            'cash_difference' => array('closings', 'currency'),
            'card_difference' => array('closings', 'currency'),
        );
    }

    /** Dimensions: key => [kind, sources]. */
    public static function dimensions() {
        $both = array('sales', 'closings');
        return array(
            'hour' => array('time', $both),
            'weekday' => array('time', $both),
            'day' => array('time', $both),
            'week' => array('time', $both),
            'month' => array('time', $both),
            'quarter' => array('time', $both),
            'year' => array('time', $both),
            'product' => array('entity', array('sales')),
            'variation' => array('entity', array('sales')),
            'category' => array('entity', array('sales')),
            'brand' => array('entity', array('sales')),
            'customer' => array('entity', array('sales')),
            'channel' => array('entity', array('sales')),
            'register' => array('entity', $both),
            'operator' => array('entity', $both),
            'payment_method' => array('entity', array('sales')),
        );
    }

    /** Filters: key => [type, sources]. */
    public static function filters() {
        $both = array('sales', 'closings');
        return array(
            'product_ids' => array('ints', array('sales')),
            'variation_ids' => array('ints', array('sales')),
            'category_ids' => array('ints', array('sales')),
            'brand_ids' => array('ints', array('sales')),
            'customer_keys' => array('customer_keys', array('sales')),
            'channels' => array('channels', array('sales')),
            'registers' => array('strings', $both),
            'operator_ids' => array('ints', $both),
            'payment_methods' => array('strings', array('sales')),
        );
    }

    public static function online_available() {
        global $wpdb;
        static $available = null;
        if ($available !== null) {
            return $available;
        }
        $table = $wpdb->prefix . 'wc_order_product_lookup';
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        $available = is_string($found) && $found === $table;
        return $available;
    }

    /** Catalog for the app: measures, dimensions, filters and known values. */
    public static function catalog() {
        global $wpdb;
        $receipts = MGWS_DB::table_pos_receipts();
        $registers = $wpdb->get_col("SELECT DISTINCT register_name FROM {$receipts} WHERE register_name <> '' ORDER BY register_name");
        $payments = $wpdb->get_col("SELECT DISTINCT payment_method FROM {$receipts} WHERE payment_method <> '' ORDER BY payment_method");
        $operator_ids = $wpdb->get_col("SELECT DISTINCT created_by FROM {$receipts} WHERE created_by > 0");
        $operators = array();
        foreach ((array) $operator_ids as $operator_id) {
            $operators[] = array('id' => (int) $operator_id, 'name' => self::user_label((int) $operator_id));
        }

        $measures = array();
        foreach (self::measures() as $key => $definition) {
            $measures[] = array('key' => $key, 'source' => $definition[0], 'format' => $definition[1]);
        }
        $dimensions = array();
        foreach (self::dimensions() as $key => $definition) {
            $dimensions[] = array('key' => $key, 'kind' => $definition[0], 'sources' => $definition[1]);
        }
        $filters = array();
        foreach (self::filters() as $key => $definition) {
            $filters[] = array('key' => $key, 'type' => $definition[0], 'sources' => $definition[1]);
        }

        return array(
            'ok' => true,
            'version' => 1,
            'online_available' => self::online_available(),
            'measures' => $measures,
            'dimensions' => $dimensions,
            'filters' => $filters,
            'values' => array(
                'registers' => array_values(array_map('strval', (array) $registers)),
                'payment_methods' => array_values(array_map('strval', (array) $payments)),
                'operators' => $operators,
                'channels' => array('store', 'online'),
            ),
        );
    }

    // =====================================================================
    // Request
    // =====================================================================

    /**
     * Validates a query request.
     *
     * @return array|WP_Error
     */
    public static function normalize(array $body) {
        $all_measures = self::measures();
        $measures = $body['measures'] ?? (isset($body['measure']) ? array($body['measure']) : array());
        if (!is_array($measures) || empty($measures)) {
            return self::bad_request('measures is required');
        }
        $measures = array_values(array_unique(array_map('strval', $measures)));
        if (count($measures) > 24) {
            return self::bad_request('at most 24 measures per query');
        }
        $source = null;
        foreach ($measures as $measure) {
            if (!isset($all_measures[$measure])) {
                return self::bad_request('unknown measure: ' . $measure);
            }
            $measure_source = $all_measures[$measure][0];
            if ($source !== null && $source !== $measure_source) {
                return self::bad_request('measures must come from the same source');
            }
            $source = $measure_source;
        }

        $dimension = isset($body['dimension']) && $body['dimension'] !== '' ? (string) $body['dimension'] : null;
        if ($dimension !== null) {
            $dimensions = self::dimensions();
            if (!isset($dimensions[$dimension])) {
                return self::bad_request('unknown dimension: ' . $dimension);
            }
            if (!in_array($source, $dimensions[$dimension][1], true)) {
                return self::bad_request('dimension ' . $dimension . ' is not available for these measures');
            }
        }

        $timezone = wp_timezone();
        $date_from = self::parse_date($body['date_from'] ?? '', $timezone);
        $date_to = self::parse_date($body['date_to'] ?? '', $timezone);
        if ($date_from === null || $date_to === null) {
            return self::bad_request('date_from and date_to must be YYYY-MM-DD');
        }
        if ($date_to < $date_from) {
            return self::bad_request('date_to must not be before date_from');
        }
        if ($date_from->diff($date_to)->days > 3700) {
            return self::bad_request('the period can cover at most ten years');
        }

        $filters = array();
        $raw_filters = isset($body['filters']) && is_array($body['filters']) ? $body['filters'] : array();
        foreach (self::filters() as $key => $definition) {
            if (!isset($raw_filters[$key])) {
                continue;
            }
            if (!in_array($source, $definition[1], true)) {
                return self::bad_request('filter ' . $key . ' is not available for these measures');
            }
            $values = self::filter_values($raw_filters[$key], $definition[0]);
            if (is_wp_error($values)) {
                return $values;
            }
            if (!empty($values)) {
                $filters[$key] = $values;
            }
        }

        $compare = isset($body['compare']) ? (string) $body['compare'] : 'none';
        if (!in_array($compare, array('none', 'previous_period', 'previous_year'), true)) {
            return self::bad_request('compare must be none, previous_period or previous_year');
        }
        $sort = isset($body['sort']) ? (string) $body['sort'] : '';
        if (!in_array($sort, array('', 'value_desc', 'value_asc', 'key_asc', 'key_desc'), true)) {
            return self::bad_request('sort must be value_desc, value_asc, key_asc or key_desc');
        }
        if ($sort === '') {
            $sort = $dimension !== null && self::dimensions()[$dimension][0] === 'time' ? 'key_asc' : 'value_desc';
        }
        $sort_measure = isset($body['sort_measure']) ? (string) $body['sort_measure'] : $measures[0];
        if (!in_array($sort_measure, $measures, true)) {
            return self::bad_request('sort_measure must be one of the requested measures');
        }
        $is_time = $dimension !== null && self::dimensions()[$dimension][0] === 'time';
        $limit = isset($body['limit']) ? (int) $body['limit'] : ($is_time ? self::MAX_LIMIT : 50);
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            return self::bad_request('limit must be between 1 and ' . self::MAX_LIMIT);
        }

        return array(
            'source' => $source,
            'measures' => $measures,
            'dimension' => $dimension,
            'date_from' => $date_from,
            'date_to' => $date_to,
            'filters' => $filters,
            'compare' => $compare,
            'sort' => $sort,
            'sort_measure' => $sort_measure,
            'limit' => $limit,
        );
    }

    private static function bad_request($message) {
        return new WP_Error('mgws_bad_request', $message, array('status' => 400));
    }

    private static function parse_date($raw, DateTimeZone $timezone) {
        $raw = (string) $raw;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw, $timezone);
        return $date instanceof DateTimeImmutable ? $date : null;
    }

    private static function filter_values($raw, $type) {
        if (!is_array($raw)) {
            $raw = array($raw);
        }
        if (count($raw) > 200) {
            return self::bad_request('too many filter values');
        }
        $out = array();
        foreach ($raw as $value) {
            switch ($type) {
                case 'ints':
                    if (!is_numeric($value) || (int) $value < 0) {
                        return self::bad_request('filter values must be non-negative integers');
                    }
                    $out[] = (int) $value;
                    break;
                case 'customer_keys':
                    $value = (string) $value;
                    if (!preg_match('/^[ug]\d{1,19}$/', $value)) {
                        return self::bad_request('customer keys look like u123 or g45');
                    }
                    $out[] = $value;
                    break;
                case 'channels':
                    $value = (string) $value;
                    if (!in_array($value, array('store', 'online'), true)) {
                        return self::bad_request('channels must be store or online');
                    }
                    $out[] = $value;
                    break;
                default:
                    $value = sanitize_text_field((string) $value);
                    if ($value !== '') {
                        $out[] = $value;
                    }
            }
        }
        return array_values(array_unique($out));
    }

    // =====================================================================
    // Query
    // =====================================================================

    /**
     * Runs a normalized request, with a short cache.
     */
    public static function run(array $request, $refresh = false) {
        $cache_key = 'mgws_report_' . md5(wp_json_encode(array(
            $request['source'],
            $request['measures'],
            $request['dimension'],
            $request['date_from']->format('Y-m-d'),
            $request['date_to']->format('Y-m-d'),
            $request['filters'],
            $request['compare'],
            $request['sort'],
            $request['sort_measure'],
            $request['limit'],
            wp_timezone_string(),
        )));
        if (!$refresh) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                $cached['cached'] = true;
                return $cached;
            }
        }

        $from = $request['date_from'];
        $to = $request['date_to'];
        $result = array(
            'ok' => true,
            'source' => $request['source'],
            'measures' => $request['measures'],
            'dimension' => $request['dimension'],
            'date_from' => $from->format('Y-m-d'),
            'date_to' => $to->format('Y-m-d'),
            'online_included' => $request['source'] === 'sales' && self::online_available(),
            'totals' => self::aggregate($request, $from, $to, null)[0] ?? self::empty_values($request['measures']),
            'rows' => $request['dimension'] !== null ? self::aggregate($request, $from, $to, $request['dimension']) : array(),
            'generated_at_gmt' => gmdate('Y-m-d H:i:s'),
            'cached' => false,
        );

        if ($request['compare'] !== 'none') {
            list($compare_from, $compare_to) = self::compare_range($from, $to, $request['compare']);
            $result['compare'] = array(
                'date_from' => $compare_from->format('Y-m-d'),
                'date_to' => $compare_to->format('Y-m-d'),
                'totals' => self::aggregate($request, $compare_from, $compare_to, null)[0] ?? self::empty_values($request['measures']),
                'rows' => $request['dimension'] !== null ? self::aggregate($request, $compare_from, $compare_to, $request['dimension']) : array(),
            );
        }

        set_transient($cache_key, $result, self::CACHE_TTL);
        return $result;
    }

    private static function compare_range(DateTimeImmutable $from, DateTimeImmutable $to, $mode) {
        if ($mode === 'previous_year') {
            return array($from->modify('-1 year'), $to->modify('-1 year'));
        }
        $days = (int) $from->diff($to)->days + 1;
        return array($from->modify('-' . $days . ' days'), $from->modify('-1 day'));
    }

    private static function empty_values(array $measures) {
        $values = array();
        foreach ($measures as $measure) {
            $values[$measure] = null;
        }
        return array('key' => '', 'label' => '', 'values' => $values);
    }

    /**
     * Aggregates one period, split by $dimension when given.
     */
    private static function aggregate(array $request, DateTimeImmutable $from, DateTimeImmutable $to, $dimension) {
        $from_gmt = $from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $to_gmt = $to->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $rows = $request['source'] === 'closings'
            ? self::query_closings($request, $from_gmt, $to_gmt, $dimension)
            : self::query_sales($request, $from_gmt, $to_gmt, $dimension);

        $out = array();
        foreach ($rows as $row) {
            $out[] = array(
                'key' => $dimension === null ? '' : (string) $row['dim_key'],
                'label' => '',
                'values' => self::derive($request['measures'], $row),
            );
        }
        if ($dimension === null) {
            return $out;
        }
        $out = self::sort_rows($out, $request['sort'], $request['sort_measure']);
        $out = array_slice($out, 0, $request['limit']);
        return self::label_rows($out, $dimension);
    }

    private static function query_sales(array $request, $from_gmt, $to_gmt, $dimension) {
        global $wpdb;
        $params = array();
        $fact = self::sales_fact_sql($from_gmt, $to_gmt, $params);
        $fact_param_count = count($params);

        $joins = '';
        $where = array('1=1');
        $dim_expr = "''";
        if ($dimension !== null) {
            list($dim_expr, $dim_join, $dim_where) = self::sales_dimension_sql($dimension, $from_gmt, $to_gmt);
            $joins .= $dim_join;
            if ($dim_where !== '') {
                $where[] = $dim_where;
            }
        }
        self::sales_filter_sql($request['filters'], $where, $params);

        $needs_first = (bool) array_intersect($request['measures'], array('new_customers', 'returning_customers'));
        $new_customers_sql = '0';
        if ($needs_first) {
            $first_params = array();
            $all_fact = self::sales_fact_sql('1970-01-01 00:00:00', $to_gmt, $first_params);
            $joins .= " LEFT JOIN (SELECT ff.customer_key, MIN(ff.ts) AS first_ts FROM ({$all_fact}) ff"
                . " WHERE ff.customer_key <> '' GROUP BY ff.customer_key) fp ON fp.customer_key = f.customer_key";
            // The subquery placeholders come before the WHERE placeholders.
            $params = array_merge(array_slice($params, 0, $fact_param_count), $first_params, array_slice($params, $fact_param_count));
            $new_customers_sql = $wpdb->prepare(
                "COUNT(DISTINCT CASE WHEN f.customer_key <> '' AND fp.first_ts >= %s THEN f.customer_key END)",
                $from_gmt
            );
        }

        $sql = "SELECT {$dim_expr} AS dim_key,"
            . ' SUM(f.gross) AS gross, SUM(f.net) AS net, SUM(f.gross_sales) AS gross_sales,'
            . ' SUM(f.list_value) AS list_value, SUM(f.returns_value) AS returns_value,'
            . ' SUM(f.qty_sold) AS qty_sold, SUM(f.qty_returned) AS qty_returned,'
            . ' COUNT(DISTINCT CASE WHEN f.qty_sold > 0 THEN f.doc_key END) AS documents,'
            . " COUNT(DISTINCT CASE WHEN f.qty_sold > 0 AND f.customer_key <> '' THEN f.doc_key END) AS identified_documents,"
            . " SUM(CASE WHEN f.customer_key <> '' THEN f.gross ELSE 0 END) AS identified_gross,"
            . " COUNT(DISTINCT NULLIF(f.customer_key, '')) AS customers,"
            . ' COUNT(DISTINCT CASE WHEN f.qty_sold > 0 THEN f.product_id END) AS distinct_products,'
            . " {$new_customers_sql} AS new_customers,"
            . ' MIN(f.ts) AS first_ts, MAX(f.ts) AS last_ts'
            . " FROM ({$fact}) f{$joins}"
            . ' WHERE ' . implode(' AND ', $where)
            . ($dimension !== null ? ' GROUP BY dim_key' : '');

        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    /**
     * Line-level sales of both channels in [from, to).
     */
    private static function sales_fact_sql($from_gmt, $to_gmt, array &$params) {
        global $wpdb;
        $receipts = MGWS_DB::table_pos_receipts();
        $lines = MGWS_DB::table_pos_receipt_lines();

        // Global discounts only apply to a positive balance, so the factor
        // is total / subtotal there and 1 otherwise: the signed lines of a
        // receipt then add up to its total.
        $factor = '(CASE WHEN r.subtotal > 0 AND r.total >= 0 THEN r.total / r.subtotal ELSE 1 END)';
        $is_return = "(l.movement_type = 'return')";
        $line_gross = "ABS(l.subtotal) * {$factor}";
        $store = "SELECT 'store' AS channel, CONCAT('r', r.id) AS doc_key, r.issued_at_gmt AS ts,"
            . ' r.register_name AS register_name, r.created_by AS operator_id, r.payment_method AS payment_method,'
            . " CASE WHEN r.customer_id > 0 THEN CONCAT('u', r.customer_id) ELSE '' END AS customer_key,"
            . ' l.product_id AS product_id, l.variation_id AS variation_id,'
            . " CASE WHEN {$is_return} THEN 0 ELSE ABS(l.quantity) END AS qty_sold,"
            . " CASE WHEN {$is_return} THEN ABS(l.quantity) ELSE 0 END AS qty_returned,"
            . " (CASE WHEN {$is_return} THEN -1 ELSE 1 END) * {$line_gross} AS gross,"
            . " (CASE WHEN {$is_return} THEN -1 ELSE 1 END) * {$line_gross} / (1 + l.vat_rate / 100) AS net,"
            . " CASE WHEN {$is_return} THEN 0 ELSE {$line_gross} END AS gross_sales,"
            . " CASE WHEN {$is_return} THEN 0 ELSE ABS(l.quantity) * l.unit_price END AS list_value,"
            . " CASE WHEN {$is_return} THEN {$line_gross} ELSE 0 END AS returns_value"
            . " FROM {$receipts} r INNER JOIN {$lines} l ON l.receipt_id = r.id"
            . " WHERE r.status <> 'cancelled' AND r.issued_at_gmt >= %s AND r.issued_at_gmt < %s";
        $params[] = $from_gmt;
        $params[] = $to_gmt;

        $sql = $store;
        if (self::online_available()) {
            $product_lookup = $wpdb->prefix . 'wc_order_product_lookup';
            $order_stats = $wpdb->prefix . 'wc_order_stats';
            $customer_lookup = $wpdb->prefix . 'wc_customer_lookup';
            $statuses = "'" . implode("','", self::ONLINE_STATUSES) . "'";
            $is_refund = '(s.parent_id > 0)';
            $online_gross = '(p.product_net_revenue + p.tax_amount)';
            $sql .= " UNION ALL SELECT 'online', CONCAT('o', s.order_id), s.date_created_gmt,"
                . " '', 0, '',"
                . " CASE WHEN c.user_id > 0 THEN CONCAT('u', c.user_id) WHEN p.customer_id > 0 THEN CONCAT('g', p.customer_id) ELSE '' END,"
                . ' p.product_id, p.variation_id,'
                . " CASE WHEN {$is_refund} THEN 0 ELSE GREATEST(p.product_qty, 0) END,"
                . " CASE WHEN {$is_refund} THEN ABS(p.product_qty) ELSE 0 END,"
                . " (CASE WHEN {$is_refund} THEN -ABS({$online_gross}) ELSE {$online_gross} END),"
                . " (CASE WHEN {$is_refund} THEN -ABS(p.product_net_revenue) ELSE p.product_net_revenue END),"
                . " CASE WHEN {$is_refund} THEN 0 ELSE {$online_gross} END,"
                . " CASE WHEN {$is_refund} THEN 0 ELSE {$online_gross} + p.coupon_amount END,"
                . " CASE WHEN {$is_refund} THEN ABS({$online_gross}) ELSE 0 END"
                . " FROM {$product_lookup} p"
                . " INNER JOIN {$order_stats} s ON s.order_id = p.order_id"
                . " LEFT JOIN {$customer_lookup} c ON c.customer_id = p.customer_id"
                . ' WHERE s.date_created_gmt >= %s AND s.date_created_gmt < %s'
                . " AND ((s.parent_id = 0 AND s.status IN ({$statuses})) OR s.parent_id > 0)"
                . " AND NOT EXISTS (SELECT 1 FROM {$receipts} rx WHERE rx.order_id > 0"
                . ' AND rx.order_id = (CASE WHEN s.parent_id > 0 THEN s.parent_id ELSE s.order_id END))';
            $params[] = $from_gmt;
            $params[] = $to_gmt;
        }
        return $sql;
    }

    /**
     * SQL that turns a GMT column into shop-local time, exact across DST
     * changes inside the period.
     */
    private static function local_time_sql($column, $from_gmt, $to_gmt) {
        $timezone = wp_timezone();
        $begin = strtotime($from_gmt . ' UTC') - DAY_IN_SECONDS;
        $end = strtotime($to_gmt . ' UTC') + DAY_IN_SECONDS;
        $transitions = $timezone->getTransitions($begin, $end);
        if (!is_array($transitions) || empty($transitions)) {
            $offset = (int) $timezone->getOffset(new DateTimeImmutable('@' . $begin));
            return "DATE_ADD({$column}, INTERVAL {$offset} SECOND)";
        }
        $case = 'CASE';
        $count = count($transitions);
        for ($index = 1; $index < $count; $index++) {
            $switch_at = gmdate('Y-m-d H:i:s', (int) $transitions[$index]['ts']);
            $case .= " WHEN {$column} < '{$switch_at}' THEN " . (int) $transitions[$index - 1]['offset'];
        }
        $case .= ' ELSE ' . (int) $transitions[$count - 1]['offset'] . ' END';
        return "DATE_ADD({$column}, INTERVAL ({$case}) SECOND)";
    }

    private static function time_dimension_sql($dimension, $local) {
        switch ($dimension) {
            case 'hour':
                return "LPAD(HOUR({$local}), 2, '0')";
            case 'weekday':
                // 1 = Monday ... 7 = Sunday.
                return "(WEEKDAY({$local}) + 1)";
            case 'day':
                return "DATE_FORMAT({$local}, '%Y-%m-%d')";
            case 'week':
                return "DATE_FORMAT(DATE_SUB(DATE({$local}), INTERVAL WEEKDAY({$local}) DAY), '%Y-%m-%d')";
            case 'month':
                return "DATE_FORMAT({$local}, '%Y-%m')";
            case 'quarter':
                return "CONCAT(YEAR({$local}), '-Q', QUARTER({$local}))";
            case 'year':
                return "CAST(YEAR({$local}) AS CHAR)";
        }
        return "''";
    }

    /** @return array [expression, join, where] */
    private static function sales_dimension_sql($dimension, $from_gmt, $to_gmt) {
        global $wpdb;
        if (self::dimensions()[$dimension][0] === 'time') {
            // The `%` of DATE_FORMAT must survive $wpdb->prepare.
            return array(str_replace('%', '%%', self::time_dimension_sql($dimension, self::local_time_sql('f.ts', $from_gmt, $to_gmt))), '', '');
        }
        $term_join = function ($taxonomy) use ($wpdb) {
            return " INNER JOIN {$wpdb->term_relationships} dtr ON dtr.object_id = f.product_id"
                . " INNER JOIN {$wpdb->term_taxonomy} dtt ON dtt.term_taxonomy_id = dtr.term_taxonomy_id AND dtt.taxonomy = '" . esc_sql($taxonomy) . "'";
        };
        switch ($dimension) {
            case 'product':
                return array('f.product_id', '', 'f.product_id > 0');
            case 'variation':
                return array('(CASE WHEN f.variation_id > 0 THEN f.variation_id ELSE f.product_id END)', '', 'f.product_id > 0');
            case 'category':
                return array('dtt.term_id', $term_join('product_cat'), '');
            case 'brand':
                return array('dtt.term_id', $term_join('product_brand'), '');
            case 'customer':
                return array('f.customer_key', '', "f.customer_key <> ''");
            case 'channel':
                return array('f.channel', '', '');
            case 'register':
                return array('f.register_name', '', "f.channel = 'store'");
            case 'operator':
                return array('f.operator_id', '', "f.channel = 'store'");
            case 'payment_method':
                return array('f.payment_method', '', "f.channel = 'store'");
        }
        return array("''", '', '');
    }

    private static function sales_filter_sql(array $filters, array &$where, array &$params) {
        global $wpdb;
        $in = function ($column, array $values, $format) use (&$where, &$params) {
            $where[] = $column . ' IN (' . implode(',', array_fill(0, count($values), $format)) . ')';
            foreach ($values as $value) {
                $params[] = $value;
            }
        };
        if (!empty($filters['product_ids'])) {
            $in('f.product_id', $filters['product_ids'], '%d');
        }
        if (!empty($filters['variation_ids'])) {
            $in('f.variation_id', $filters['variation_ids'], '%d');
        }
        foreach (array('category_ids' => 'product_cat', 'brand_ids' => 'product_brand') as $key => $taxonomy) {
            if (empty($filters[$key])) {
                continue;
            }
            $term_ids = self::with_child_terms($filters[$key], $taxonomy);
            $where[] = 'f.product_id IN (SELECT ftr.object_id FROM ' . $wpdb->term_relationships . ' ftr'
                . ' INNER JOIN ' . $wpdb->term_taxonomy . ' ftt ON ftt.term_taxonomy_id = ftr.term_taxonomy_id'
                . " AND ftt.taxonomy = '" . esc_sql($taxonomy) . "'"
                . ' WHERE ftt.term_id IN (' . implode(',', array_fill(0, count($term_ids), '%d')) . '))';
            foreach ($term_ids as $term_id) {
                $params[] = $term_id;
            }
        }
        if (!empty($filters['customer_keys'])) {
            $in('f.customer_key', $filters['customer_keys'], '%s');
        }
        if (!empty($filters['channels'])) {
            $in('f.channel', $filters['channels'], '%s');
        }
        if (!empty($filters['registers'])) {
            $in('f.register_name', $filters['registers'], '%s');
        }
        if (!empty($filters['operator_ids'])) {
            $in('f.operator_id', $filters['operator_ids'], '%d');
        }
        if (!empty($filters['payment_methods'])) {
            $in('f.payment_method', $filters['payment_methods'], '%s');
        }
    }

    private static function with_child_terms(array $term_ids, $taxonomy) {
        $all = array();
        foreach ($term_ids as $term_id) {
            $all[] = (int) $term_id;
            if (function_exists('get_term_children')) {
                $children = get_term_children((int) $term_id, $taxonomy);
                if (is_array($children)) {
                    foreach ($children as $child) {
                        $all[] = (int) $child;
                    }
                }
            }
        }
        return array_values(array_unique($all));
    }

    private static function query_closings(array $request, $from_gmt, $to_gmt, $dimension) {
        global $wpdb;
        $table = MGWS_DB::table_pos_closings();
        $params = array($from_gmt, $to_gmt);
        $where = array('c.closed_at_gmt >= %s', 'c.closed_at_gmt < %s');
        $dim_expr = "''";
        if ($dimension !== null) {
            if (self::dimensions()[$dimension][0] === 'time') {
                $dim_expr = str_replace('%', '%%', self::time_dimension_sql($dimension, self::local_time_sql('c.closed_at_gmt', $from_gmt, $to_gmt)));
            } elseif ($dimension === 'register') {
                $dim_expr = 'c.register_name';
            } elseif ($dimension === 'operator') {
                $dim_expr = 'c.operator_id';
            }
        }
        $filters = $request['filters'];
        if (!empty($filters['registers'])) {
            $where[] = 'c.register_name IN (' . implode(',', array_fill(0, count($filters['registers']), '%s')) . ')';
            $params = array_merge($params, $filters['registers']);
        }
        if (!empty($filters['operator_ids'])) {
            $where[] = 'c.operator_id IN (' . implode(',', array_fill(0, count($filters['operator_ids']), '%d')) . ')';
            $params = array_merge($params, $filters['operator_ids']);
        }
        $sql = "SELECT {$dim_expr} AS dim_key, COUNT(*) AS closings,"
            . ' SUM(c.cash_collected) AS cash_collected, SUM(c.card_collected) AS card_collected,'
            . ' SUM(c.other_collected) AS other_collected, SUM(c.refunds) AS refunds_paid,'
            . ' SUM(c.manual_in) AS manual_in, SUM(c.manual_out) AS manual_out,'
            . ' SUM(c.opening_float + c.cash_collected + c.manual_in - c.refunds - c.manual_out) AS cash_expected,'
            . ' SUM(c.cash_counted) AS cash_counted, SUM(c.card_counted) AS card_counted'
            . " FROM {$table} c WHERE " . implode(' AND ', $where)
            . ($dimension !== null ? ' GROUP BY dim_key' : '');
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    // =====================================================================
    // Measures and labels
    // =====================================================================

    private static function derive(array $measures, array $row) {
        $number = function ($key) use ($row) {
            return isset($row[$key]) && $row[$key] !== null ? (float) $row[$key] : 0.0;
        };
        $ratio = function ($numerator, $denominator, $scale = 1) {
            return $denominator > 0 ? round($numerator / $denominator * $scale, 4) : null;
        };
        $iso = function ($key) use ($row) {
            return !empty($row[$key]) ? gmdate('c', strtotime($row[$key] . ' UTC')) : null;
        };
        $values = array();
        foreach ($measures as $measure) {
            switch ($measure) {
                case 'revenue':
                    $value = round($number('gross'), 2);
                    break;
                case 'net_revenue':
                    $value = round($number('net'), 2);
                    break;
                case 'tax':
                    $value = round($number('gross') - $number('net'), 2);
                    break;
                case 'discount':
                    $value = round(max(0, $number('list_value') - $number('gross_sales')), 2);
                    break;
                case 'discount_rate':
                    $value = $ratio(max(0, $number('list_value') - $number('gross_sales')), $number('list_value'), 100);
                    break;
                case 'returns_value':
                    $value = round($number('returns_value'), 2);
                    break;
                case 'returns_rate':
                    $value = $ratio($number('returns_value'), $number('gross_sales'), 100);
                    break;
                case 'quantity':
                    $value = (int) ($number('qty_sold') - $number('qty_returned'));
                    break;
                case 'quantity_sold':
                    $value = (int) $number('qty_sold');
                    break;
                case 'quantity_returned':
                    $value = (int) $number('qty_returned');
                    break;
                case 'documents':
                    $value = (int) $number('documents');
                    break;
                case 'average_ticket':
                    $value = $ratio($number('gross_sales'), $number('documents'));
                    break;
                case 'units_per_document':
                    $value = $ratio($number('qty_sold'), $number('documents'));
                    break;
                case 'average_unit_price':
                    $value = $ratio($number('gross_sales'), $number('qty_sold'));
                    break;
                case 'distinct_products':
                    $value = (int) $number('distinct_products');
                    break;
                case 'customers':
                    $value = (int) $number('customers');
                    break;
                case 'new_customers':
                    $value = (int) $number('new_customers');
                    break;
                case 'returning_customers':
                    $value = (int) max(0, $number('customers') - $number('new_customers'));
                    break;
                case 'revenue_per_customer':
                    $value = $ratio($number('identified_gross'), $number('customers'));
                    break;
                case 'documents_per_customer':
                    $value = $ratio($number('identified_documents'), $number('customers'));
                    break;
                case 'first_purchase':
                    $value = $iso('first_ts');
                    break;
                case 'last_purchase':
                    $value = $iso('last_ts');
                    break;
                case 'days_since_last_purchase':
                    $value = !empty($row['last_ts'])
                        ? (int) floor((time() - strtotime($row['last_ts'] . ' UTC')) / DAY_IN_SECONDS)
                        : null;
                    break;
                case 'closings':
                    $value = (int) $number('closings');
                    break;
                case 'collected_total':
                    $value = round($number('cash_collected') + $number('card_collected') + $number('other_collected'), 2);
                    break;
                case 'cash_difference':
                    $value = round($number('cash_counted') - $number('cash_expected'), 2);
                    break;
                case 'card_difference':
                    $value = round($number('card_counted') - $number('card_collected'), 2);
                    break;
                default:
                    $value = round($number($measure), 2);
            }
            $values[$measure] = $value;
        }
        return $values;
    }

    private static function sort_rows(array $rows, $sort, $measure) {
        usort($rows, function ($a, $b) use ($sort, $measure) {
            if ($sort === 'key_asc' || $sort === 'key_desc') {
                $cmp = is_numeric($a['key']) && is_numeric($b['key'])
                    ? ((float) $a['key'] <=> (float) $b['key'])
                    : strcmp((string) $a['key'], (string) $b['key']);
                return $sort === 'key_asc' ? $cmp : -$cmp;
            }
            $left = $a['values'][$measure];
            $right = $b['values'][$measure];
            if ($left === $right) {
                return strcmp((string) $a['key'], (string) $b['key']);
            }
            // Empty values always go last.
            if ($left === null) {
                return 1;
            }
            if ($right === null) {
                return -1;
            }
            $cmp = $left <=> $right;
            return $sort === 'value_asc' ? $cmp : -$cmp;
        });
        return $rows;
    }

    private static function label_rows(array $rows, $dimension) {
        foreach ($rows as $index => $row) {
            $rows[$index]['label'] = self::label($dimension, (string) $row['key']);
        }
        return $rows;
    }

    private static function label($dimension, $key) {
        switch ($dimension) {
            case 'product':
            case 'variation':
                $id = (int) $key;
                if ($id <= 0) {
                    return '';
                }
                $title = function_exists('get_the_title') ? (string) get_the_title($id) : '';
                return $title !== '' ? html_entity_decode($title, ENT_QUOTES, 'UTF-8') : '#' . $id;
            case 'category':
            case 'brand':
                $term = function_exists('get_term') ? get_term((int) $key) : null;
                return is_object($term) && !is_wp_error($term) ? html_entity_decode((string) $term->name, ENT_QUOTES, 'UTF-8') : '#' . $key;
            case 'customer':
                return self::customer_label($key);
            case 'operator':
                return self::user_label((int) $key);
        }
        return $key;
    }

    private static function user_label($user_id) {
        if ($user_id <= 0 || !function_exists('get_userdata')) {
            return '';
        }
        $user = get_userdata($user_id);
        if (!$user) {
            return '#' . $user_id;
        }
        $name = trim((string) get_user_meta($user_id, 'billing_first_name', true) . ' ' . (string) get_user_meta($user_id, 'billing_last_name', true));
        if ($name === '') {
            $name = trim((string) $user->first_name . ' ' . (string) $user->last_name);
        }
        return $name !== '' ? $name : (string) $user->display_name;
    }

    private static function customer_label($key) {
        global $wpdb;
        if (strpos($key, 'u') === 0) {
            return self::user_label((int) substr($key, 1));
        }
        if (strpos($key, 'g') === 0 && self::online_available()) {
            $row = $wpdb->get_row($wpdb->prepare(
                'SELECT first_name, last_name, email FROM ' . $wpdb->prefix . 'wc_customer_lookup WHERE customer_id = %d',
                (int) substr($key, 1)
            ), ARRAY_A);
            if (is_array($row)) {
                $name = trim((string) $row['first_name'] . ' ' . (string) $row['last_name']);
                return $name !== '' ? $name : (string) $row['email'];
            }
        }
        return $key;
    }
}
