<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-mgws-cost-plan.php';

/**
 * The decisions behind editing an "Aggiungi" movement, with no WordPress in them.
 *
 * The REST route owns the database, the transaction and WooCommerce. This class
 * owns the three rules that decide whether an edit is allowed and what it means:
 * only a quick-load row can be reopened, only the newest operation can be
 * touched, and changing a quantity moves the difference between the audited
 * old and new values, not a second independent movement.
 */
final class MGWS_Movement_Edit_Plan {
    /**
     * Solo le righe scritte da "Aggiungi" hanno una quantita' che l'operatore
     * puo' rivedere. Le altre operazioni hanno un loro modulo e un loro motivo:
     * rieditarle qui cancellerebbe la distinzione fra carico e rettifica.
     *
     * @param mixed $row
     * @return bool
     */
    public static function can_edit($row) {
        return is_array($row) && (string) ($row['source_type'] ?? '') === 'quick_load';
    }

    /**
     * L'ultima operazione e' l'unica modificabile.
     *
     * `$newer_rows` contiene le righe che il ledger mette dopo il bersaglio,
     * per timestamp o per id. Se una di esse appartiene a un'altra operazione,
     * qualcosa e' successo dopo quel carico e il suo stock non e' piu' una
     * funzione della sola quantita' registrata: modificarlo calpesterebbe il
     * movimento successivo.
     *
     * Un'operazione senza chiave (movement_id = 0) non ha un gruppo a cui
     * appartenere, quindi qualsiasi riga successiva la blocca.
     *
     * @param mixed                       $target
     * @param array<int,array<string,mixed>> $newer_rows
     * @return bool
     */
    public static function is_last_operation($target, array $newer_rows) {
        $operation = is_array($target) ? (int) ($target['movement_id'] ?? 0) : 0;
        foreach ($newer_rows as $row) {
            $row_operation = is_array($row) ? (int) ($row['movement_id'] ?? 0) : -1;
            if ($operation <= 0 || $row_operation !== $operation) {
                return false;
            }
        }
        return true;
    }

    /**
     * Trasforma la nuova quantita' in un delta auditato.
     *
     * La quantita' 0 e' lecita e significa "il prodotto resta nel carico ma non
     * ha piu' pezzi": per questo la riga resta nel ledger e lo stock torna a
     * quello che aveva prima del carico.
     *
     * @param mixed $row     Riga del ledger, con qty e stock_after auditati.
     * @param mixed $new_qty Quantita' assoluta richiesta, non un delta.
     * @return array<string,mixed>|null Null quando i dati auditati non bastano.
     */
    public static function build($row, $new_qty) {
        if (!self::can_edit($row)) {
            return null;
        }
        $new_qty = (int) $new_qty;
        if ($new_qty < 0 || !array_key_exists('stock_after', $row) || $row['stock_after'] === null) {
            return null;
        }

        $old_qty = (int) ($row['qty'] ?? 0);
        $delta = $new_qty - $old_qty;
        $value_before = array_key_exists('value_delta_cents', $row) && $row['value_delta_cents'] !== null
            ? (int) $row['value_delta_cents']
            : null;
        $unit_cost = array_key_exists('unit_cost_cents', $row) && $row['unit_cost_cents'] !== null
            ? (int) $row['unit_cost_cents']
            : null;

        return array(
            'old_qty' => $old_qty,
            'new_qty' => $new_qty,
            'delta' => $delta,
            'stock_after' => (int) $row['stock_after'] + $delta,
            'unit_cost_cents' => $unit_cost,
            'value_delta_cents' => MGWS_Cost_Plan::value_for_quantity($value_before, $old_qty, $new_qty, $unit_cost),
            'noop' => $delta === 0,
        );
    }
}
