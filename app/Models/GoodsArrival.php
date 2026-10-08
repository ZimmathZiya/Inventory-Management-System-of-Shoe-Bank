<?php

namespace App\Models;

use App\Core\Model;

/**
 * The arrival/verification session for a purchase.
 *
 * Inventory is deliberately NOT touched when a purchase is created. Stock is
 * only written by confirm(), and only once all three gates are satisfied:
 *   1. every expected parcel received (or the owner accepted a partial receipt)
 *   2. every line's quantity verified
 *   3. the owner confirmed the shipment
 */
class GoodsArrival extends Model
{
    protected string $table = 'goods_arrivals';

    public function byPurchase(int $purchaseId): ?array
    {
        return $this->db()->first('SELECT * FROM goods_arrivals WHERE purchase_id = ?', [$purchaseId]);
    }

    /**
     * Open the verification session for a purchase, seeding one arrival_item per
     * invoice line with the invoiced quantity as "expected". Idempotent.
     */
    public function openFor(int $purchaseId, array $data = []): array
    {
        $existing = $this->byPurchase($purchaseId);
        if ($existing) {
            return $existing;
        }

        $purchase = (new Purchase())->find($purchaseId);
        $parcels  = (new Parcel())->summary($purchaseId);

        $arrivalId = $this->create([
            'purchase_id'        => $purchaseId,
            'arrival_date'       => $data['arrival_date'] ?? date('Y-m-d'),
            'parcels_expected'   => (int) ($purchase['expected_parcels'] ?? 0),
            'parcels_received'   => $parcels['received'],
            'weight_expected_kg' => (float) ($purchase['total_weight_kg'] ?? 0),
            'weight_received_kg' => $parcels['weight'],
            'counting_mode'      => $data['counting_mode'] ?? 'final',
            'status'             => 'pending',
        ]);

        $items = new ArrivalItem();
        foreach ((new PurchaseItem())->byPurchase($purchaseId) as $line) {
            $items->create([
                'arrival_id'       => $arrivalId,
                'purchase_item_id' => $line['id'],
                'product_id'       => $line['product_id'] ?: null,
                'expected_pairs'   => (int) $line['quantity_pairs'],
                'received_pairs'   => 0,
                'status'           => 'pending',
            ]);
        }

        return $this->find($arrivalId);
    }

    /** Refresh the parcel/weight roll-up from the parcels table. */
    public function syncParcelTotals(int $arrivalId): void
    {
        $arrival = $this->find($arrivalId);
        if (!$arrival) {
            return;
        }
        $summary = (new Parcel())->summary((int) $arrival['purchase_id']);
        $this->update($arrivalId, [
            'parcels_received'   => $summary['received'],
            'weight_received_kg' => $summary['weight'],
        ]);
    }

    /**
     * Whether confirm() would be allowed, and why not.
     *
     * @return array{ok:bool,reasons:string[]}
     */
    public function canConfirm(int $arrivalId): array
    {
        $arrival = $this->find($arrivalId);
        if (!$arrival) {
            return ['ok' => false, 'reasons' => ['Arrival not found.']];
        }

        $reasons = [];

        if ((int) $arrival['inventory_updated'] === 1) {
            $reasons[] = 'Inventory has already been updated for this shipment.';
        }

        if ((float) ($arrival['weight_expected_kg'] ?? 0) <= 0) {
            $reasons[] = 'Save the client-supplied total shipment weight.';
        }

        $expected = (int) $arrival['parcels_expected'];
        $received = (int) $arrival['parcels_received'];
        if ($expected > 0 && $received < $expected && (int) $arrival['partial_receipt'] !== 1) {
            $reasons[] = "Only {$received} of {$expected} parcels received. "
                       . 'Log the remaining parcels or accept a partial receipt.';
        }

        $totals = (new ArrivalItem())->totals($arrivalId);
        if ((int) ($totals['line_count'] ?? 0) === 0) {
            $reasons[] = 'This purchase has no invoice lines to verify.';
        } elseif ((int) ($totals['pending'] ?? 0) > 0) {
            $reasons[] = $totals['pending'] . ' product line(s) still need a received quantity.';
        }

        $parcelSummary = (new Parcel())->summary((int) $arrival['purchase_id']);
        if ((int) $parcelSummary['received'] === 0) {
            $reasons[] = 'Log each arrived parcel and its weight before confirming.';
        }

        $setupMissing = ['brand' => 0, 'category' => 0, 'size set' => 0];
        foreach ((new ArrivalItem())->byArrival($arrivalId) as $item) {
            if (empty($item['purchase_brand_id'])) $setupMissing['brand']++;
            if (empty($item['purchase_category_id'])) $setupMissing['category']++;
            if (empty($item['purchase_size_set_id'])) $setupMissing['size set']++;
        }
        foreach ($setupMissing as $field => $count) {
            if ($count > 0) {
                $reasons[] = "{$count} product line(s) still need a {$field}.";
            }
        }

        return ['ok' => $reasons === [], 'reasons' => $reasons];
    }

    /** Mark quantity verification finished (not yet confirmed). */
    public function markVerified(int $arrivalId, ?int $userId): void
    {
        $this->update($arrivalId, [
            'status'      => 'verified',
            'verified_by' => $userId,
            'verified_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Final step: write the verified quantities into inventory, in one transaction.
     *
     * @return array{ok:bool,reasons?:string[],products_created:int,sets_added:int}
     */
    public function confirm(int $arrivalId, ?int $userId): array
    {
        $gate = $this->canConfirm($arrivalId);
        if (!$gate['ok']) {
            return ['ok' => false, 'reasons' => $gate['reasons'], 'products_created' => 0, 'sets_added' => 0];
        }

        $arrival    = $this->find($arrivalId);
        $purchaseId = (int) $arrival['purchase_id'];
        $items      = (new ArrivalItem())->byArrival($arrivalId);

        $productsCreated = 0;
        $setsAdded       = 0;

        $this->db()->beginTransaction();
        try {
            foreach ($items as $item) {
                $received = (int) $item['received_pairs'];
                $productId = $item['product_id'] ? (int) $item['product_id'] : null;

                if ($productId === null) {
                    // A product is identified by Art Number + Category. Invoice
                    // colours remain variants of that product, not duplicate stock cards.
                    $productId = $this->findBaseProductFromLine($item);
                    if ($productId === null) {
                        $productId = $this->createProductFromLine($item, $userId);
                        $productsCreated++;
                    }
                    $this->db()->query(
                        'UPDATE arrival_items SET product_id = ? WHERE id = ?',
                        [$productId, $item['id']]
                    );
                    $this->db()->query(
                        "UPDATE purchase_items SET product_id = ?, match_status = 'matched' WHERE id = ?",
                        [$productId, $item['purchase_item_id']]
                    );
                }

                // Stock is held in SETS; invoices are written in pairs.
                $pairsPerSet = (int) ($item['pairs_per_set'] ?: $item['product_pairs_in_set'] ?: 0);
                $sets = $pairsPerSet > 0 ? intdiv($received, $pairsPerSet) : $received;

                $this->db()->query(
                    'UPDATE arrival_items SET received_sets = ? WHERE id = ?',
                    [$sets, $item['id']]
                );

                if ($sets === 0) {
                    continue;
                }

                $balance = (int) $this->db()->scalar(
                    'SELECT stock_sets FROM products WHERE id = ? FOR UPDATE',
                    [$productId]
                ) + $sets;

                $this->db()->query(
                    'UPDATE products SET stock_sets = ? WHERE id = ?',
                    [$balance, $productId]
                );
                $this->db()->query(
                    'INSERT INTO stock_history
                        (product_id, change_qty, balance_after, reason, ref_type, ref_id, note, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $productId,
                        $sets,
                        $balance,
                        'arrival',
                        'purchase',
                        $purchaseId,
                        sprintf('Arrival %s — %d pairs received', $arrival['arrival_date'], $received),
                        $userId,
                    ]
                );

                $setsAdded += $sets;
            }

            $this->db()->query(
                "UPDATE goods_arrivals
                    SET status = 'confirmed', confirmed_by = ?, confirmed_at = NOW(), inventory_updated = 1
                  WHERE id = ?",
                [$userId, $arrivalId]
            );
            $this->db()->query(
                "UPDATE purchases SET status = 'completed' WHERE id = ?",
                [$purchaseId]
            );

            $this->db()->commit();
        } catch (\Throwable $e) {
            $this->db()->rollBack();
            throw $e;
        }

        return ['ok' => true, 'products_created' => $productsCreated, 'sets_added' => $setsAdded];
    }

    /**
     * Create an inventory product from an invoice line when the art number
     * matched nothing existing.
     */
    private function createProductFromLine(array $item, ?int $userId): int
    {
        if (empty($item['purchase_brand_id']) || empty($item['purchase_category_id']) || empty($item['purchase_size_set_id'])) {
            throw new \RuntimeException('A product cannot be created without a brand, category and size set.');
        }
        $brandId = null;
        $brandName = trim((string) ($item['mapped_brand_name'] ?? $item['brand_name'] ?? ''));
        $brandId = (int) $item['purchase_brand_id'];

        $categoryId = $this->categoryIdForLine($item);
        $sizeSetId = $this->sizeSetIdForLine($item, $categoryId);

        $name = trim(implode(' ', array_filter([
            $brandName,
            $item['art_no'] ?? '',
        ])));

        $this->db()->query(
            'INSERT INTO products
                (type, brand_id, art_no, name, category_id, size_set_id, pairs_in_set, indian_price, stock_sets, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)',
            [
                'imported',
                $brandId,
                $item['art_no'] ?: null,
                $name !== '' ? $name : ($item['art_no'] ?: 'Imported product'),
                $categoryId,
                $sizeSetId,
                $item['pairs_per_set'] ?: null,
                $item['unit_price'] ?: null,
                'Created automatically from an import arrival.',
                $userId,
            ]
        );

        return $this->db()->lastInsertId();
    }

    /** Find a product already created for this Art Number + Category. */
    private function findBaseProductFromLine(array $item): ?int
    {
        $artNo = strtolower(preg_replace('/[^a-z0-9]/i', '', trim((string) ($item['art_no'] ?? ''))));
        if ($artNo === '') {
            return null;
        }
        $categoryId = $this->categoryIdForLine($item);
        $row = $this->db()->first(
            "SELECT id FROM products
              WHERE deleted_at IS NULL
                AND REGEXP_REPLACE(LOWER(art_no), '[^a-z0-9]', '') = ?
                AND brand_id = ?
                AND category_id <=> ?
           ORDER BY id LIMIT 1",
            [$artNo, (int) $item['purchase_brand_id'], $categoryId]
        );
        return $row ? (int) $row['id'] : null;
    }

    private function categoryIdForLine(array $item): ?int
    {
        if (!empty($item['purchase_category_id'])) {
            return (int) $item['purchase_category_id'];
        }
        $name = trim((string) ($item['category_name'] ?? ''));
        return $name !== '' ? ((new Category())->findOrCreate($name) ?: null) : null;
    }

    private function sizeSetIdForLine(array $item, ?int $categoryId): ?int
    {
        if (!empty($item['purchase_size_set_id'])) {
            return (int) $item['purchase_size_set_id'];
        }
        $label = trim((string) ($item['size_set_label'] ?? ''));
        if ($label === '') {
            return null;
        }
        $row = $this->db()->first(
            'SELECT id FROM size_sets WHERE label = ? AND category_id <=> ? ORDER BY id LIMIT 1',
            [$label, $categoryId]
        );
        return $row ? (int) $row['id'] : null;
    }

    /** Shipments arrived but not yet fully counted — dashboard widget. */
    public function pendingQuantityVerification(int $limit = 10): array
    {
        return $this->db()->all(
            "SELECT ga.id AS arrival_id, ga.arrival_date, ga.status,
                    p.id AS purchase_id, p.purchase_number, p.supplier_name,
                    COALESCE(SUM(ai.status = 'pending'), 0) AS pending_lines,
                    COUNT(ai.id) AS total_lines
               FROM goods_arrivals ga
               JOIN purchases p ON p.id = ga.purchase_id
          LEFT JOIN arrival_items ai ON ai.arrival_id = ga.id
              WHERE ga.inventory_updated = 0
           GROUP BY ga.id, ga.arrival_date, ga.status, p.id, p.purchase_number, p.supplier_name
           ORDER BY ga.arrival_date DESC
              LIMIT {$limit}"
        );
    }
}
