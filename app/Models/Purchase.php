<?php

namespace App\Models;

use App\Core\Model;

/**
 * An import purchase — one supplier invoice and everything that follows it
 * (clearance assignments, parcels, arrival verification, inventory update).
 */
class Purchase extends Model
{
    protected string $table = 'purchases';

    /** Lifecycle order. Index position is used to compare progress. */
    public const STATUSES = [
        'draft',
        'awaiting_clearance',
        'assigned',
        'in_transit',
        'arrived',
        'verification_pending',
        'completed',
    ];

    public const STATUS_LABELS = [
        'draft'                => 'Draft',
        'awaiting_clearance'   => 'Awaiting Clearance Assignment',
        'assigned'             => 'Assigned to Clearance',
        'in_transit'           => 'In Transit',
        'arrived'              => 'Arrived',
        'verification_pending' => 'Verification Pending',
        'completed'            => 'Completed',
    ];

    public const INVOICE_TYPE_LABELS = [
        'pdf'         => 'Printed PDF',
        'image'       => 'Printed Image',
        'handwritten' => 'Handwritten Invoice',
        'manual'      => 'Manual Entry',
    ];

    public static function statusLabel(?string $status): string
    {
        return self::STATUS_LABELS[$status] ?? ucfirst((string) $status);
    }

    /** True when $status is at or past $target in the lifecycle. */
    public static function statusAtLeast(?string $status, string $target): bool
    {
        $a = array_search($status, self::STATUSES, true);
        $b = array_search($target, self::STATUSES, true);
        return $a !== false && $b !== false && $a >= $b;
    }

    /** Next purchase number, e.g. PUR-2026-000001. */
    public function nextNumber(): string
    {
        $year = date('Y');
        $max  = (int) $this->db()->scalar(
            "SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(purchase_number, '-', -1) AS UNSIGNED)), 0)
               FROM purchases
              WHERE purchase_number LIKE ?",
            ["PUR-{$year}-%"]
        );
        return sprintf('PUR-%s-%06d', $year, $max + 1);
    }

    public function findWithRelations(int $id): ?array
    {
        $purchase = $this->db()->first(
            'SELECT p.*, u.name AS created_by_name
               FROM purchases p
          LEFT JOIN users u ON u.id = p.created_by
              WHERE p.id = ?',
            [$id]
        );
        if (!$purchase) {
            return null;
        }
        $purchase['items']       = (new PurchaseItem())->byPurchase($id);
        $purchase['assignments'] = (new PurchaseClearanceAssignment())->byPurchase($id);
        $purchase['parcels']     = (new Parcel())->byPurchase($id);
        $purchase['attachments'] = (new PurchaseAttachment())->byPurchase($id);
        $purchase['weights']     = $this->weightSummary($id);
        return $purchase;
    }

    /**
     * Weight reconciliation for one purchase.
     *
     * @return array{total:float,cleared:float,remaining:float,arrived:float,verified:float,balanced:bool}
     */
    public function weightSummary(int $id): array
    {
        $row = $this->db()->first(
            "SELECT
                p.total_weight_kg AS total,
                COALESCE((SELECT SUM(a.assigned_weight_kg)
                            FROM purchase_clearance_assignments a
                           WHERE a.purchase_id = p.id AND a.status <> 'cancelled'), 0) AS cleared,
                COALESCE((SELECT SUM(pr.weight_kg)
                            FROM parcels pr
                           WHERE pr.purchase_id = p.id AND pr.status = 'received'), 0) AS arrived,
                COALESCE((SELECT ga.weight_received_kg
                            FROM goods_arrivals ga
                           WHERE ga.purchase_id = p.id), 0) AS verified
               FROM purchases p
              WHERE p.id = ?",
            [$id]
        ) ?: ['total' => 0, 'cleared' => 0, 'arrived' => 0, 'verified' => 0];

        $total   = (float) $row['total'];
        $cleared = (float) $row['cleared'];

        return [
            'total'     => $total,
            'cleared'   => $cleared,
            'remaining' => round($total - $cleared, 2),
            'arrived'   => (float) $row['arrived'],
            'verified'  => (float) $row['verified'],
            // Tolerance of 10g absorbs decimal rounding on split assignments.
            'balanced'  => abs($total - $cleared) < 0.01,
        ];
    }

    /** @param array{status?:string,search?:string,supplier?:string,from?:string,to?:string} $filters */
    public function search(array $filters = [], int $limit = 100): array
    {
        $conditions = ['1=1'];
        $params     = [];

        if (!empty($filters['status'])) {
            if ($filters['status'] === 'in_progress') {
                $conditions[] = "p.status <> 'completed'";
            } else {
                $conditions[] = 'p.status = ?';
                $params[]     = $filters['status'];
            }
        }
        if (!empty($filters['search'])) {
            $conditions[] = '(p.purchase_number LIKE ? OR p.supplier_name LIKE ? OR p.supplier_invoice_no LIKE ?)';
            $like         = '%' . $filters['search'] . '%';
            array_push($params, $like, $like, $like);
        }
        if (!empty($filters['clearance_person_id'])) {
            $conditions[] = 'EXISTS (SELECT 1 FROM purchase_clearance_assignments a
                                      WHERE a.purchase_id = p.id AND a.clearance_person_id = ?)';
            $params[]     = (int) $filters['clearance_person_id'];
        }
        if (!empty($filters['from'])) {
            $conditions[] = 'p.purchase_date >= ?';
            $params[]     = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $conditions[] = 'p.purchase_date <= ?';
            $params[]     = $filters['to'];
        }

        $where = implode(' AND ', $conditions);
        $limit = max(1, min(500, $limit));

        return $this->db()->all(
            "SELECT p.*,
                    (SELECT COUNT(*) FROM purchase_items pi WHERE pi.purchase_id = p.id) AS item_count,
                    (SELECT COUNT(*) FROM parcels pr WHERE pr.purchase_id = p.id AND pr.status = 'received') AS parcels_received,
                    COALESCE((SELECT SUM(a.assigned_weight_kg)
                                FROM purchase_clearance_assignments a
                               WHERE a.purchase_id = p.id AND a.status <> 'cancelled'), 0) AS assigned_weight_kg,
                    (SELECT GROUP_CONCAT(cp.name ORDER BY cp.name SEPARATOR ', ')
                       FROM purchase_clearance_assignments a
                       JOIN clearance_persons cp ON cp.id = a.clearance_person_id
                      WHERE a.purchase_id = p.id AND a.status <> 'cancelled') AS clearance_names
               FROM purchases p
              WHERE {$where}
           ORDER BY p.purchase_date DESC, p.id DESC
              LIMIT {$limit}",
            $params
        );
    }

    public function updateStatus(int $id, string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            return;
        }
        $this->update($id, ['status' => $status]);
    }

    /**
     * Move a purchase forward to $target, but never backwards — a shipment that
     * already arrived must not drop back to 'in_transit' when an assignment is
     * edited.
     */
    public function advanceStatus(int $id, string $target): void
    {
        $current = $this->db()->scalar('SELECT status FROM purchases WHERE id = ?', [$id]);
        if ($current && !self::statusAtLeast($current, $target)) {
            $this->updateStatus($id, $target);
        }
    }

    // --- Dashboard aggregates ------------------------------------------------

    /** Headline counters and weight totals for the dashboard. */
    public function stats(): array
    {
        $row = $this->db()->first(
            "SELECT
                COUNT(*) AS total,
                SUM(status = 'draft') AS draft,
                SUM(status = 'awaiting_clearance') AS awaiting_clearance,
                SUM(status = 'assigned') AS assigned,
                SUM(status = 'in_transit') AS in_transit,
                SUM(status = 'arrived') AS arrived,
                SUM(status = 'verification_pending') AS verification_pending,
                SUM(status = 'completed') AS completed,
                COALESCE(SUM(CASE WHEN status IN ('assigned','in_transit') THEN total_weight_kg END), 0) AS weight_in_transit
               FROM purchases"
        ) ?: [];

        $row['weight_cleared'] = (float) $this->db()->scalar(
            "SELECT COALESCE(SUM(assigned_weight_kg), 0)
               FROM purchase_clearance_assignments WHERE status <> 'cancelled'"
        );
        $row['weight_received'] = (float) $this->db()->scalar(
            "SELECT COALESCE(SUM(weight_kg), 0) FROM parcels WHERE status = 'received'"
        );

        return $row;
    }

    /** Purchases created but not yet handed to any clearance agent. */
    public function awaitingClearance(int $limit = 10): array
    {
        return $this->db()->all(
            "SELECT p.* FROM purchases p
              WHERE p.status IN ('draft','awaiting_clearance')
                AND NOT EXISTS (SELECT 1 FROM purchase_clearance_assignments a
                                 WHERE a.purchase_id = p.id AND a.status <> 'cancelled')
           ORDER BY p.purchase_date DESC
              LIMIT {$limit}"
        );
    }

    public function inTransit(int $limit = 10): array
    {
        return $this->db()->all(
            "SELECT p.*,
                    (SELECT GROUP_CONCAT(cp.name ORDER BY cp.name SEPARATOR ', ')
                       FROM purchase_clearance_assignments a
                       JOIN clearance_persons cp ON cp.id = a.clearance_person_id
                      WHERE a.purchase_id = p.id AND a.status <> 'cancelled') AS clearance_names
               FROM purchases p
              WHERE p.status IN ('assigned','in_transit')
           ORDER BY p.expected_arrival_date IS NULL, p.expected_arrival_date
              LIMIT {$limit}"
        );
    }

    public function recentlyArrived(int $limit = 10): array
    {
        return $this->db()->all(
            "SELECT p.*, ga.arrival_date, ga.status AS arrival_status
               FROM purchases p
               JOIN goods_arrivals ga ON ga.purchase_id = p.id
           ORDER BY ga.arrival_date DESC, ga.id DESC
              LIMIT {$limit}"
        );
    }

    public function recent(int $limit = 10): array
    {
        return $this->db()->all(
            "SELECT * FROM purchases ORDER BY created_at DESC LIMIT {$limit}"
        );
    }

    /** Other invoices recorded under the same supplier name. */
    public function billsForSupplier(string $supplierName, int $excludeId = 0, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        return $this->db()->all(
            "SELECT id, purchase_number, supplier_invoice_no, invoice_date,
                    total_invoice_value, status
               FROM purchases
              WHERE LOWER(TRIM(supplier_name)) = LOWER(TRIM(?))
                AND id <> ?
           ORDER BY COALESCE(invoice_date, purchase_date) DESC, id DESC
              LIMIT {$limit}",
            [$supplierName, $excludeId]
        );
    }

    /** Supplier + invoice number is the business identity of an imported invoice. */
    public function findDuplicateInvoice(string $supplierName, string $invoiceNumber, int $excludeId = 0): ?array
    {
        $supplierName = trim($supplierName);
        $invoiceNumber = trim($invoiceNumber);
        if ($supplierName === '' || $invoiceNumber === '') {
            return null;
        }

        return $this->db()->first(
            "SELECT id, purchase_number, supplier_name, supplier_invoice_no,
                    invoice_date, total_invoice_value, status
               FROM purchases
              WHERE source = 'import'
                AND LOWER(TRIM(supplier_name)) = LOWER(TRIM(?))
                AND LOWER(TRIM(supplier_invoice_no)) = LOWER(TRIM(?))
                AND id <> ?
              LIMIT 1",
            [$supplierName, $invoiceNumber, $excludeId]
        );
    }
}
