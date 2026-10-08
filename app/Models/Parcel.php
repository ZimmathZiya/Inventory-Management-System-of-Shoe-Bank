<?php

namespace App\Models;

use App\Core\Model;

/**
 * A physical parcel belonging to a purchase, carried by one clearance assignment.
 */
class Parcel extends Model
{
    protected string $table = 'parcels';

    public function byPurchase(int $purchaseId): array
    {
        return $this->db()->all(
            'SELECT pr.*, cp.name AS clearance_person_name
               FROM parcels pr
          LEFT JOIN purchase_clearance_assignments a ON a.id = pr.assignment_id
          LEFT JOIN clearance_persons cp ON cp.id = a.clearance_person_id
              WHERE pr.purchase_id = ?
           ORDER BY pr.id',
            [$purchaseId]
        );
    }

    public function nextNumber(): string
    {
        $year = date('Y');
        $max  = (int) $this->db()->scalar(
            "SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(parcel_number, '-', -1) AS UNSIGNED)), 0)
               FROM parcels WHERE parcel_number LIKE ?",
            ["PARCEL-{$year}-%"]
        );
        return sprintf('PARCEL-%s-%06d', $year, $max + 1);
    }

    /** Expected vs received parcels for a purchase. */
    public function summary(int $purchaseId): array
    {
        $row = $this->db()->first(
            "SELECT
                (SELECT expected_parcels FROM purchases WHERE id = ?) AS expected,
                COUNT(*) AS logged,
                COALESCE(SUM(status = 'received'), 0) AS received,
                COALESCE(SUM(CASE WHEN status = 'received'
                    THEN COALESCE(arrived_weight_kg, weight_kg) END), 0) AS weight
               FROM parcels WHERE purchase_id = ?",
            [$purchaseId, $purchaseId]
        ) ?: [];

        $expected = (int) ($row['expected'] ?? 0);
        $received = (int) ($row['received'] ?? 0);

        return [
            'expected' => $expected,
            'logged'   => (int) ($row['logged'] ?? 0),
            'received' => $received,
            'weight'   => (float) ($row['weight'] ?? 0),
            // New purchases do not ask the client for a parcel count. When the
            // expected count is unknown, at least one measured parcel means the
            // parcel-weight step has started/completed for confirmation gating.
            'matches'  => $expected > 0 ? $expected === $received : $received > 0,
        ];
    }

    /** Shipments in transit or arrived whose parcels are not all logged in. */
    public function pendingVerification(int $limit = 10): array
    {
        return $this->db()->all(
            "SELECT p.id, p.purchase_number, p.supplier_name, p.expected_parcels,
                    p.expected_arrival_date,
                    COALESCE(SUM(pr.status = 'received'), 0) AS parcels_received
               FROM purchases p
          LEFT JOIN parcels pr ON pr.purchase_id = p.id
              WHERE p.status IN ('assigned','in_transit','arrived')
           GROUP BY p.id, p.purchase_number, p.supplier_name, p.expected_parcels,
                    p.expected_arrival_date
             HAVING parcels_received < p.expected_parcels
           ORDER BY p.expected_arrival_date IS NULL, p.expected_arrival_date
              LIMIT {$limit}"
        );
    }
}
