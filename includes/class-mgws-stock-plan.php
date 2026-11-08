<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The decisions behind a reconciliation, with no WordPress in them.
 *
 * The reconciler does two things: it decides *what* has to move, and then it
 * writes it. The writing needs a database, a product, a transaction and a
 * warehouse; the deciding needs only numbers and a list of levels. Keeping the
 * two apart is what makes the interesting half testable: the rules below can be
 * checked without WooCommerce, without MySQL and without a WordPress bootstrap,
 * which is exactly the environment a unit test has.
 *
 * Nothing here touches the database, a global, or a WordPress function. If a
 * change to this file needs one of those, the change belongs in the reconciler.
 */
final class MGWS_Stock_Plan {
    /**
     * Sum of the quantities recorded on a set of levels.
     *
     * Levels carry their quantity as a string more often than not, because that
     * is what the database hands back, so the cast is part of the contract.
     *
     * @param array<int,array<string,mixed>> $levels
     */
    public static function sum_levels(array $levels): int {
        $total = 0;
        foreach ($levels as $level) {
            $total += (int) ($level['qty'] ?? 0);
        }
        return $total;
    }

    /**
     * The difference to close, in WooCommerce's direction.
     *
     * Positive means WooCommerce holds more than the ledger and the pieces have
     * to be added somewhere; negative means the ledger holds more and they have
     * to be taken from somewhere. A null current total means WooCommerce has no
     * number to compare (the product does not manage stock), and the honest
     * answer is "no difference to close", not "the ledger is wrong".
     */
    public static function delta(?int $woo_total, int $mgws_total): ?int {
        if ($woo_total === null) {
            return null;
        }
        return $woo_total - $mgws_total;
    }

    /**
     * The delta to write to WooCommerce so that it ends up at the counted total.
     *
     * A physical count is absolute, but WooCommerce is written as a delta: a
     * sale that happens while the operator is counting must not be erased by
     * writing the counted number as a total. The delta is therefore measured
     * against the total WooCommerce holds right now, not against the ledger:
     * those two numbers are only the same when nothing has diverged yet, and
     * assuming they are is what makes a count undo a sale.
     *
     * A null current total means there is no number to move, so there is
     * nothing to write.
     */
    public static function delta_for_target(int $target, ?int $current): ?int {
        if ($current === null) {
            return null;
        }
        return $target - $current;
    }

    /**
     * Which delta to write to WooCommerce for a counted total, and what it was
     * measured on.
     *
     * A count on a single-site shop is the store total, so the delta is the
     * distance between the counted number and what WooCommerce holds right now:
     * that closes a divergence instead of adding it back. A count scoped to one
     * site on a multi-site shop is not the store total, and subtracting it from
     * the store-wide number would wipe every other site; there WooCommerce has
     * to move by exactly the change of that site, which is the ledger delta the
     * write already computed. A divergence that spans the store is the
     * reconciler's job, not a site count's.
     *
     * The basis is returned with the delta so the caller can report which rule
     * was used instead of leaving it invisible.
     *
     * @return array{delta:?int,basis:string} basis is `store_total`, `site_change` or `none`.
     */
    public static function woo_delta(int $target, ?int $current, bool $scope_is_store, int $ledger_delta): array {
        if ($current === null) {
            return array('delta' => null, 'basis' => 'none');
        }
        if ($scope_is_store) {
            return array('delta' => self::delta_for_target($target, $current), 'basis' => 'store_total');
        }
        return array('delta' => $ledger_delta, 'basis' => 'site_change');
    }

    /**
     * Where a shortage comes from, in the order it should be taken.
     *
     * The "Unassigned" warehouse goes first: it is the sink for differences that
     * were never assigned to a shelf, so it is the honest place to take an
     * unexplained shortage from before touching stock that someone located. The
     * rest keeps the order it arrives in, which is the database order — site,
     * warehouse, room, rack, shelf — so two runs of the same shortage take the
     * same pieces from the same shelves.
     *
     * The result is a plan, not a write: each entry names a level and how much
     * to take from it, clamped to what that level actually holds. A level with
     * no warehouse is left out: there is nowhere to record the movement. When
     * the levels cannot cover the shortage, `remaining` says how much is left
     * and the caller decides whether that is a failure.
     *
     * @param array<int,array<string,mixed>> $levels
     * @param array<int,int|string>          $system_warehouse_ids
     * @return array{moves:array<int,array{level:array<string,mixed>,take:int}>,taken:int,remaining:int}
     */
    public static function shortfall_moves(int $missing, array $levels, array $system_warehouse_ids = array()): array {
        if ($missing <= 0) {
            return array('moves' => array(), 'taken' => 0, 'remaining' => max(0, $missing));
        }

        $system_ids = self::int_list($system_warehouse_ids);
        $system = array();
        $regular = array();
        foreach ($levels as $level) {
            $warehouse_id = (int) ($level['warehouse_id'] ?? 0);
            if ($warehouse_id <= 0) {
                // A level with no warehouse is not a location: a movement taken
                // from it would point nowhere, so it is not part of the plan.
                continue;
            }
            if (in_array($warehouse_id, $system_ids, true)) {
                $system[] = $level;
            } else {
                $regular[] = $level;
            }
        }

        $moves = array();
        $remaining = $missing;
        $taken = 0;

        foreach (array_merge($system, $regular) as $level) {
            if ($remaining <= 0) {
                break;
            }
            $available = (int) ($level['qty'] ?? 0);
            if ($available <= 0) {
                continue;
            }
            $take = min($available, $remaining);
            $moves[] = array('level' => $level, 'take' => $take);
            $remaining -= $take;
            $taken += $take;
        }

        return array('moves' => $moves, 'taken' => $taken, 'remaining' => $remaining);
    }

    /**
     * @param array<int,int|string> $values
     * @return array<int,int>
     */
    private static function int_list(array $values): array {
        $out = array();
        foreach ($values as $value) {
            $out[] = (int) $value;
        }
        return $out;
    }
}
