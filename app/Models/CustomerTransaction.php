<?php

namespace App\Models;

use App\Core\Model;

class CustomerTransaction extends Model
{
    protected string $table = 'customer_transactions';

    private const MANUAL_BILL_REF = 'manual_bill';

    public function create(array $data): int
    {
        return $this->db()->insert('customer_transactions', $data);
    }

    public function byCustomer(int $customerId, int $limit = 100): array
    {
        return $this->db()->all(
            "SELECT ct.*, u.name AS created_by_name,
                    pay.payment_method, pay.payment_date,
                    ch.id AS cheque_id, ch.cheque_number, ch.cheque_date,
                    ch.image_path AS cheque_image_path, ch.thumb_path AS cheque_thumb_path
             FROM customer_transactions ct
             LEFT JOIN users u ON ct.created_by = u.id
             LEFT JOIN payments pay
                    ON ct.reference_type = 'payment' AND pay.id = ct.reference_id
             LEFT JOIN cheques ch ON ch.payment_id = pay.id
             WHERE ct.customer_id = ?
             ORDER BY COALESCE(ct.transaction_date, DATE(ct.created_at)) DESC, ct.created_at DESC, ct.id DESC
             LIMIT ?",
            [$customerId, $limit]
        );
    }

    public function manualBillReceipt(int $id): ?array
    {
        return $this->db()->first(
            'SELECT ct.*, c.name AS customer_name, c.phone AS customer_phone
               FROM customer_transactions ct
               JOIN customers c ON c.id = ct.customer_id
              WHERE ct.id = ? AND ct.reference_type = ?',
            [$id, self::MANUAL_BILL_REF]
        );
    }

    public function paymentLedgerEntry(int $paymentId): ?array
    {
        return $this->db()->first(
            "SELECT * FROM customer_transactions
              WHERE reference_type = 'payment' AND reference_id = ?
           ORDER BY id DESC LIMIT 1",
            [$paymentId]
        );
    }

    public function attachBillImage(int $id, string $path, ?string $thumbPath): void
    {
        $this->db()->update('customer_transactions', ['image_path' => $path, 'thumb_path' => $thumbPath], ['id' => $id]);
    }

    public function currentBalance(int $customerId): float
    {
        $result = $this->db()->first(
            'SELECT running_balance FROM customer_transactions WHERE customer_id = ? ORDER BY COALESCE(transaction_date, DATE(created_at)) DESC, created_at DESC, id DESC LIMIT 1',
            [$customerId]
        );
        return (float)($result['running_balance'] ?? 0);
    }

    public function manualBillExists(int $customerId, string $billNumber, ?int $excludeId = null): bool
    {
        $sql =
            'SELECT COUNT(*) FROM customer_transactions
              WHERE customer_id = ?
                AND reference_type = ?
                AND bill_number = ?';
        $params = [$customerId, self::MANUAL_BILL_REF, $billNumber];
        if ($excludeId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }
        return (int) $this->db()->scalar($sql, $params) > 0;
    }

    public function postManualBill(
        int $customerId,
        string $billNumber,
        string $billDate,
        float $amount,
        string $dueDate,
        ?int $userId,
        ?string $notes = null
    ): int {
        $balance = round($this->currentBalance($customerId) + $amount, 2);
        $description = 'Manual bill #' . $billNumber;
        if ($notes !== null && trim($notes) !== '') {
            $description .= ' - ' . trim($notes);
        }

        return $this->create([
            'customer_id'      => $customerId,
            'transaction_type' => 'sale',
            'amount'           => round($amount, 2),
            'running_balance'  => $balance,
            'transaction_date' => $billDate,
            'reference_type'   => self::MANUAL_BILL_REF,
            'reference_id'     => null,
            'bill_number'      => $billNumber,
            'due_date'         => $dueDate,
            'description'      => $description,
            'created_by'       => $userId,
        ]);
    }

    public function summarizeByType(int $customerId): array
    {
        return $this->db()->all(
            'SELECT transaction_type, COUNT(*) AS count, SUM(amount) AS total
             FROM customer_transactions
             WHERE customer_id = ?
             GROUP BY transaction_type
             ORDER BY transaction_type',
            [$customerId]
        );
    }

    public function totalSales(int $customerId): float
    {
        $result = $this->db()->first(
            'SELECT COALESCE(SUM(amount), 0) AS total FROM customer_transactions WHERE customer_id = ? AND transaction_type = ?',
            [$customerId, 'sale']
        );
        return (float)($result['total'] ?? 0);
    }

    public function totalPayments(int $customerId): float
    {
        $result = $this->db()->first(
            'SELECT COALESCE(SUM(amount), 0) AS total FROM customer_transactions WHERE customer_id = ? AND transaction_type = ?',
            [$customerId, 'payment']
        );
        return (float)($result['total'] ?? 0);
    }

    /** Customer ledger bills recorded today (the app's day-to-day sales figure). */
    public function billsRecordedOn(string $date): array
    {
        return $this->db()->first(
            'SELECT COUNT(*) AS count, COALESCE(SUM(amount), 0) AS total
               FROM customer_transactions
              WHERE transaction_type = ?
                AND transaction_date = ?',
            ['sale', $date]
        ) ?? ['count' => 0, 'total' => 0];
    }
}
