<?php

namespace App\Services;

use App\Core\Database;
use App\Models\DiscountRule;
use App\Models\Purchase;
use App\Models\PurchaseItem;

/**
 * Landed cost for a confirmed shipment.
 *
 * Wraps CostCalculator (which owns the per-pair formula) and feeds it the real
 * quantities from the arrival. Nothing here invents a rate.
 *
 * IMPORTANT — two different per-kilo numbers exist in this system and they must
 * not be mixed:
 *
 *   clearance rate (Rs/kg)  the pricing input on the cost formula, from
 *                           settings. This is what a pair is *costed* at.
 *   agent wage (Rs/kg)      what the clearance agent is actually paid for
 *                           carrying the shipment. An expense, reported here for
 *                           context only, and deliberately NOT fed into pricing.
 */
class PurchaseCosting
{
    /**
     * Rate inputs for a purchase: the snapshot if it has already been costed,
     * otherwise current settings, with any caller overrides applied last.
     */
    public function rates(array $purchase, array $overrides = []): array
    {
        $rates = [
            'lkr_rate'           => (float) ($purchase['lkr_rate_used']        ?? 0) ?: (float) setting('lkr_rate', 3.6),
            'per_kilo_clearance' => (float) ($purchase['clearance_rate_used']  ?? 0) ?: (float) setting('per_kilo_clearance', 3000),
            'handling_charge'    => (float) ($purchase['handling_charge_used'] ?? 0) ?: (float) setting('handling_charge', 25),
            'rounding_step'      => (int)   ($purchase['rounding_step_used']   ?? 0) ?: (int)   setting('cost_rounding_step', 25),
        ];

        foreach ($rates as $key => $default) {
            if (isset($overrides[$key]) && $overrides[$key] !== '') {
                $rates[$key] = $key === 'rounding_step'
                    ? max(0, (int) $overrides[$key])
                    : max(0.0, (float) $overrides[$key]);
            }
        }

        return $rates;
    }

    /**
     * What the clearance agents are actually being paid on this shipment.
     * Reported alongside the costing so the two per-kilo figures stay visibly
     * distinct — never used as the pricing rate.
     *
     * @return array{cost:float,weight:float,per_kg:float}
     */
    public function agentWage(int $purchaseId): array
    {
        $row = Database::instance()->first(
            "SELECT COALESCE(SUM(clearance_cost), 0) AS cost,
                    COALESCE(SUM(assigned_weight_kg), 0) AS weight
               FROM purchase_clearance_assignments
              WHERE purchase_id = ? AND status <> 'cancelled'",
            [$purchaseId]
        ) ?: ['cost' => 0, 'weight' => 0];

        $cost   = (float) $row['cost'];
        $weight = (float) $row['weight'];

        return [
            'cost'   => $cost,
            'weight' => $weight,
            'per_kg' => $weight > 0 ? round($cost / $weight, 2) : 0.0,
        ];
    }

    /**
     * Per-line landed cost breakdown.
     *
     * $lineInput lets the screen feed back edited values, keyed by purchase_item id:
     *   ['set_weight_grams' => .., 'indian_price' => .., 'discount_percent' => ..]
     */
    public function breakdown(int $purchaseId, array $lineInput = []): array
    {
        $purchase = (new Purchase())->find($purchaseId);
        if (!$purchase) {
            return [];
        }

        $discounts = new DiscountRule();
        $lines     = [];

        foreach ($this->costableLines($purchaseId) as $line) {
            $id    = (int) $line['id'];
            $given = $lineInput[$id] ?? [];

            // Weight: what was typed, else what the product already knows.
            $weight = $this->firstNumeric([
                $given['set_weight_grams']  ?? null,
                $line['set_weight_grams']   ?? null,
                $line['product_set_weight'] ?? null,
            ]);

            // Indian price: what was typed, else the invoice rate.
            $indianPrice = $this->firstNumeric([
                $given['indian_price'] ?? null,
                $line['unit_price']    ?? null,
            ]);

            $discount = isset($given['discount_percent']) && $given['discount_percent'] !== ''
                ? max(0.0, (float) $given['discount_percent'])
                : $discounts->forLine(
                    $line['brand_id'] !== null ? (int) $line['brand_id'] : null,
                    $line['art_no']
                );

            $pairsInSet = (int) ($line['pairs_per_set'] ?: $line['product_pairs_in_set'] ?: 0);

            $rates  = $this->rates($purchase, []);
            $result = CostCalculator::calculate([
                'indian_price'       => $indianPrice,
                'discount_percent'   => $discount,
                'lkr_rate'           => $rates['lkr_rate'],
                'per_kilo_clearance' => $rates['per_kilo_clearance'],
                'set_weight_grams'   => $weight,
                'pairs_in_set'       => $pairsInSet,
                'handling_charge'    => $rates['handling_charge'],
                'rounding_step'      => $rates['rounding_step'],
            ]);

            // A pair cannot be costed without a set weight and a pairs-per-set,
            // because the clearance share is derived from weight.
            $ready = $weight > 0 && $pairsInSet > 0 && $indianPrice > 0;

            $lines[] = [
                'id'               => $id,
                'product_id'       => $line['product_id'] !== null ? (int) $line['product_id'] : null,
                'label'            => trim(($line['brand_name'] ?? '') . ' ' . ($line['art_no'] ?? '')) ?: 'Unnamed line',
                'colour'           => $line['colour'],
                'size_set_label'   => $line['size_set_label'],
                'product_thumb'    => $line['product_thumb'] ?? null,
                'pairs_in_set'     => $pairsInSet,
                'set_weight_grams' => $weight,
                'indian_price'     => $indianPrice,
                'discount_percent' => $discount,
                'received_pairs'   => (int) ($line['received_pairs'] ?? 0),
                'current_cost'     => $line['product_final_cost'] !== null ? (float) $line['product_final_cost'] : null,
                'ready'            => $ready,
                'calc'             => $result,
            ];
        }

        return $lines;
    }

    /**
     * Write the computed costs onto the products and snapshot the rates.
     *
     * @return array{ok:bool,updated:int,skipped:int,reason?:string}
     */
    public function apply(int $purchaseId, array $lineInput, array $rateOverrides, ?int $userId): array
    {
        $purchaseModel = new Purchase();
        $purchase      = $purchaseModel->find($purchaseId);
        if (!$purchase) {
            return ['ok' => false, 'updated' => 0, 'skipped' => 0, 'reason' => 'Purchase not found.'];
        }
        if ($purchase['status'] !== 'completed') {
            return [
                'ok' => false, 'updated' => 0, 'skipped' => 0,
                'reason' => 'Cost the shipment only after its arrival has been confirmed — '
                          . 'the received quantities are what get costed.',
            ];
        }

        $rates    = $this->rates($purchase, $rateOverrides);
        $products = new \App\Models\Product();
        $items    = new PurchaseItem();
        $db       = Database::instance();

        // Recompute under the caller's rates rather than trusting posted totals.
        $purchaseModel->update($purchaseId, [
            'lkr_rate_used'        => $rates['lkr_rate'],
            'clearance_rate_used'  => $rates['per_kilo_clearance'],
            'handling_charge_used' => $rates['handling_charge'],
            'rounding_step_used'   => $rates['rounding_step'],
        ]);

        $updated = 0;
        $skipped = 0;

        $db->beginTransaction();
        try {
            foreach ($this->breakdown($purchaseId, $lineInput) as $line) {
                if (!$line['ready'] || $line['product_id'] === null) {
                    $skipped++;
                    continue;
                }

                $productId = $line['product_id'];
                $newCost   = (float) $line['calc']['final_cost'];
                $product   = $products->find($productId);
                $oldCost   = $product['final_cost'] ?? null;

                $items->update($line['id'], [
                    'set_weight_grams'     => $line['set_weight_grams'] ?: null,
                    'landed_cost_per_pair' => $newCost,
                ]);

                $products->update($productId, [
                    'set_weight_grams'    => $line['set_weight_grams'] ?: null,
                    'pairs_in_set'        => $line['pairs_in_set'] ?: null,
                    'indian_price'        => $line['indian_price'],
                    'discount_percent'    => $line['discount_percent'],
                    'lkr_rate_used'       => $rates['lkr_rate'],
                    'clearance_rate_used' => $rates['per_kilo_clearance'],
                    'final_cost'          => $newCost,
                ]);

                // Append-only price history, so a cost change is always traceable.
                $products->recordPriceChange($productId, 'final_cost', $oldCost, $newCost, $userId);

                $updated++;
            }

            $purchaseModel->update($purchaseId, ['costed_at' => date('Y-m-d H:i:s')]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return ['ok' => true, 'updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * Invoice lines joined to their product and to what actually arrived.
     * Only lines mapped to a product can be costed.
     */
    private function costableLines(int $purchaseId): array
    {
        return Database::instance()->all(
            'SELECT pi.id, pi.product_id, pi.brand_id, pi.brand_name, pi.art_no, pi.colour,
                    pi.size_set_label, pi.pairs_per_set, pi.set_weight_grams, pi.unit_price,
                    pr.pairs_in_set     AS product_pairs_in_set,
                    pr.set_weight_grams AS product_set_weight,
                    pr.final_cost       AS product_final_cost,
                    (SELECT img.thumb_path FROM product_images img
                      WHERE img.product_id = pr.id
                        AND (TRIM(COALESCE(pi.colour, "")) = ""
                             OR REPLACE(REPLACE(LOWER(TRIM(COALESCE(img.colour, ""))), "-", ""), " ", "")
                                = REPLACE(REPLACE(LOWER(TRIM(pi.colour)), "-", ""), " ", ""))
                   ORDER BY img.is_main DESC, img.sort_order, img.id LIMIT 1) AS product_thumb,
                    ai.received_pairs
               FROM purchase_items pi
          LEFT JOIN products pr      ON pr.id = pi.product_id
          LEFT JOIN goods_arrivals g ON g.purchase_id = pi.purchase_id
          LEFT JOIN arrival_items ai ON ai.arrival_id = g.id AND ai.purchase_item_id = pi.id
              WHERE pi.purchase_id = ?
           ORDER BY pi.sort_order, pi.id',
            [$purchaseId]
        );
    }

    /** First value in the list that is a positive number, else 0.0. */
    private function firstNumeric(array $candidates): float
    {
        foreach ($candidates as $value) {
            if ($value !== null && $value !== '' && (float) $value > 0) {
                return (float) $value;
            }
        }
        return 0.0;
    }
}
