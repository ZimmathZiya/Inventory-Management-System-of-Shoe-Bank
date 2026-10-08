<?php

namespace App\Models;

use App\Core\Model;

/**
 * Junction between purchases and clearance agents.
 *
 * Normal case: one agent takes the whole shipment (one row).
 * Rare case:   several agents split it by weight (one row each), and the sum of
 *              assigned_weight_kg must equal the purchase's total_weight_kg.
 */
class PurchaseClearanceAssignment extends Model
{
    protected string $table = 'purchase_clearance_assignments';

    public function byPurchase(int $purchaseId): array
    {
        return $this->db()->all(
            "SELECT a.*, cp.name AS clearance_person_name, cp.phone AS clearance_person_phone,
                    (SELECT COUNT(*) FROM parcels pr WHERE pr.assignment_id = a.id) AS parcels_logged,
                    (SELECT COUNT(*) FROM parcels pr WHERE pr.assignment_id = a.id AND pr.status = 'received') AS parcels_received
               FROM purchase_clearance_assignments a
               JOIN clearance_persons cp ON cp.id = a.clearance_person_id
              WHERE a.purchase_id = ?
           ORDER BY a.id",
            [$purchaseId]
        );
    }

    public function findForPurchase(int $purchaseId, int $personId): ?array
    {
        return $this->db()->first(
            'SELECT * FROM purchase_clearance_assignments
              WHERE purchase_id = ? AND clearance_person_id = ?',
            [$purchaseId, $personId]
        );
    }

    /** Total weight already assigned on a purchase, optionally ignoring one row. */
    public function assignedWeight(int $purchaseId, ?int $excludeId = null): float
    {
        $sql    = "SELECT COALESCE(SUM(assigned_weight_kg), 0)
                     FROM purchase_clearance_assignments
                    WHERE purchase_id = ? AND status <> 'cancelled'";
        $params = [$purchaseId];

        if ($excludeId !== null) {
            $sql     .= ' AND id <> ?';
            $params[] = $excludeId;
        }

        return (float) $this->db()->scalar($sql, $params);
    }

    /**
     * Create or update the assignment for one agent on one purchase, snapshotting
     * the agent's rate so later rate changes don't rewrite shipment history.
     */
    public function assign(int $purchaseId, int $personId, array $data): int
    {
        $person = (new ClearancePerson())->find($personId);
        $rate   = $data['rate_per_kg'] ?? ($person['wage_per_kilo'] ?? 0);
        $weight = (float) ($data['assigned_weight_kg'] ?? 0);

        $payload = [
            'assigned_weight_kg' => $weight,
            'parcel_count'       => (int) ($data['parcel_count'] ?? 0),
            'assignment_date'    => $data['assignment_date'] ?? date('Y-m-d'),
            'rate_per_kg'        => $rate,
            'clearance_cost'     => round($weight * (float) $rate, 2),
            'status'             => $data['status'] ?? 'assigned',
            'notes'              => $data['notes'] ?? null,
        ];

        $existing = $this->findForPurchase($purchaseId, $personId);
        if ($existing) {
            $this->update((int) $existing['id'], $payload);
            return (int) $existing['id'];
        }

        return $this->create($payload + [
            'purchase_id'         => $purchaseId,
            'clearance_person_id' => $personId,
        ]);
    }

    public function updateStatusForPurchase(int $purchaseId, string $status): void
    {
        $this->db()->query(
            "UPDATE purchase_clearance_assignments SET status = ?
              WHERE purchase_id = ? AND status <> 'cancelled'",
            [$status, $purchaseId]
        );
    }

    public function setPaymentStatus(int $id, string $status): void
    {
        if (!in_array($status, ['pending', 'paid'], true)) {
            throw new \InvalidArgumentException('Invalid clearance payment status.');
        }
        $this->update($id, [
            'payment_status' => $status,
            'paid_at'        => $status === 'paid' ? date('Y-m-d H:i:s') : null,
        ]);
    }

    /**
     * Payment is due only for the weight the assigned person actually delivered.
     * Returns the recalculated payable weight and amount without inventing a
     * payment when no received parcel has been logged yet.
     *
     * @return array{weight:float,amount:float}
     */
    public function syncPaymentToReceivedWeight(int $id): array
    {
        $assignment = $this->find($id);
        if (!$assignment) {
            return ['weight' => 0.0, 'amount' => 0.0];
        }

        $weight = (float) $this->db()->scalar(
            "SELECT COALESCE(SUM(COALESCE(arrived_weight_kg, weight_kg)), 0)
               FROM parcels
              WHERE assignment_id = ? AND status = 'received'",
            [$id]
        );
        $amount = round($weight * (float) ($assignment['rate_per_kg'] ?? 0), 2);

        if ($weight > 0) {
            $this->update($id, ['clearance_cost' => $amount]);
        }

        return ['weight' => $weight, 'amount' => $amount];
    }
}
