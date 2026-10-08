<?php

namespace App\Services;

use App\Core\Database;

/** Authoritative, date-aware customer statement data and dependency-free PDF output. */
class CustomerStatementService
{
    private const CREDIT_TYPES = ['payment', 'credit_memo'];

    public function period(string $preset, ?string $from, ?string $to): array
    {
        $today = new \DateTimeImmutable('today');
        if ($preset === 'this_month') {
            return [$today->modify('first day of this month')->format('Y-m-d'), $today->format('Y-m-d')];
        }
        if ($preset === 'last_month') {
            $last = $today->modify('first day of last month');
            return [$last->format('Y-m-d'), $last->modify('last day of this month')->format('Y-m-d')];
        }
        if ($preset === 'custom') {
            $from = $this->validDate($from);
            $to = $this->validDate($to);
            if (!$from || !$to || $from > $to) {
                throw new \InvalidArgumentException('Choose a valid date range.');
            }
            return [$from, $to];
        }
        return [null, null];
    }

    public function data(array $customer, ?string $from, ?string $to): array
    {
        $db = Database::instance();
        $dateSql = 'COALESCE(ct.transaction_date, DATE(ct.created_at))';
        $opening = 0.0;
        if ($from) {
            $prior = $db->all(
                "SELECT transaction_type, amount FROM customer_transactions ct
                  WHERE customer_id = ? AND {$dateSql} < ?
               ORDER BY {$dateSql}, ct.created_at, ct.id",
                [(int) $customer['id'], $from]
            );
            foreach ($prior as $row) {
                $opening = $this->apply($opening, (string) $row['transaction_type'], (float) $row['amount']);
            }
        }

        $where = ['ct.customer_id = ?'];
        $params = [(int) $customer['id']];
        if ($from) { $where[] = "{$dateSql} >= ?"; $params[] = $from; }
        if ($to) { $where[] = "{$dateSql} <= ?"; $params[] = $to; }
        $rows = $db->all(
            "SELECT ct.*, pay.reference AS payment_reference, pay.payment_method,
                    ch.cheque_number, ch.status AS cheque_status
               FROM customer_transactions ct
          LEFT JOIN payments pay ON ct.reference_type = 'payment' AND pay.id = ct.reference_id
          LEFT JOIN cheques ch ON ch.payment_id = pay.id
              WHERE " . implode(' AND ', $where) . "
           ORDER BY {$dateSql}, ct.created_at, ct.id",
            $params
        );

        $balance = $opening;
        $bills = 0.0;
        $payments = 0.0;
        foreach ($rows as &$row) {
            $type = (string) $row['transaction_type'];
            $amount = (float) $row['amount'];
            $balance = $this->apply($balance, $type, $amount);
            $row['statement_date'] = $row['transaction_date'] ?: substr((string) $row['created_at'], 0, 10);
            $row['statement_balance'] = $balance;
            $row['debit'] = in_array($type, self::CREDIT_TYPES, true) ? 0.0 : $amount;
            $row['credit'] = in_array($type, self::CREDIT_TYPES, true) ? $amount : 0.0;
            $row['reference'] = $row['bill_number'] ?: ($row['cheque_number'] ?: ($row['payment_reference'] ?: $this->typeLabel($type)));
            $row['statement_type'] = ($row['reference_type'] ?? '') === 'customer_return' ? 'Return' : $this->typeLabel($type);
            if ($type === 'sale') $bills += $amount;
            if ($type === 'payment') $payments += $amount;
        }
        unset($row);

        return [
            'customer' => $customer,
            'from' => $from,
            'to' => $to,
            'opening_balance' => round($opening, 2),
            'transactions' => $rows,
            'total_bills' => round($bills, 2),
            'total_payments' => round($payments, 2),
            'closing_balance' => round($balance, 2),
            'generated_at' => date('Y-m-d H:i'),
        ];
    }

    public function pdf(array $statement, string $businessName): string
    {
        $pages = [];
        $rows = array_chunk($statement['transactions'], 30);
        if (!$rows) $rows = [[]];
        $pageCount = count($rows);
        foreach ($rows as $index => $pageRows) {
            $pages[] = $this->page($statement, $businessName, $pageRows, $index + 1, $pageCount);
        }
        return $this->document($pages);
    }

    public function filename(array $customer): string
    {
        $name = preg_replace('/[^A-Za-z0-9]+/', '-', (string) ($customer['name'] ?? 'customer'));
        return trim($name, '-') . '-statement-' . date('Y-m-d') . '.pdf';
    }

    private function page(array $s, string $business, array $rows, int $page, int $pages): string
    {
        $out = "0.08 0.18 0.38 rg 0 770 595 72 re f\n";
        $out .= $this->text(36, 812, 18, $business, true, [1,1,1]);
        $out .= $this->text(36, 790, 11, 'CUSTOMER STATEMENT', true, [0.78,0.86,1]);
        $out .= $this->text(400, 798, 9, 'Page ' . $page . ' of ' . $pages, false, [1,1,1]);
        $out .= $this->text(36, 744, 12, 'Customer: ' . $s['customer']['name'], true);
        $out .= $this->text(36, 727, 9, 'Phone: ' . ($s['customer']['phone'] ?: 'Not provided'));
        $period = $s['from'] ? $s['from'] . ' to ' . $s['to'] : 'All time';
        $out .= $this->text(330, 744, 9, 'Period: ' . $period);
        $out .= $this->text(330, 727, 9, 'Opening balance: Rs. ' . number_format($s['opening_balance'], 2));

        $out .= "0.94 0.96 0.99 rg 30 684 535 25 re f\n";
        $headers = [['DATE',36],['TYPE',94],['REFERENCE',160],['DEBIT',330],['CREDIT',405],['BALANCE',480]];
        foreach ($headers as [$label,$x]) $out .= $this->text($x, 693, 8, $label, true);
        $y = 672;
        foreach ($rows as $row) {
            $out .= "0.86 0.89 0.93 RG 0.4 w 30 " . ($y - 5) . " m 565 " . ($y - 5) . " l S\n";
            $out .= $this->text(36, $y, 8, date('d M Y', strtotime($row['statement_date'])));
            $out .= $this->text(94, $y, 8, (string) ($row['statement_type'] ?? $this->typeLabel((string) $row['transaction_type'])));
            $out .= $this->text(160, $y, 8, $this->short((string) $row['reference'], 28));
            $out .= $this->text(330, $y, 8, $row['debit'] ? number_format($row['debit'], 2) : '-');
            $out .= $this->text(405, $y, 8, $row['credit'] ? number_format($row['credit'], 2) : '-');
            $out .= $this->text(480, $y, 8, number_format($row['statement_balance'], 2), true);
            $y -= 20;
        }
        if ($page === $pages) {
            $boxY = max(60, $y - 95);
            $out .= "0.96 0.98 1 rg 325 {$boxY} 240 82 re f\n";
            $out .= $this->text(340, $boxY + 60, 9, 'Total bills', false, [0.25,0.3,0.4]);
            $out .= $this->text(465, $boxY + 60, 9, 'Rs. ' . number_format($s['total_bills'], 2), true);
            $out .= $this->text(340, $boxY + 40, 9, 'Total payments', false, [0.25,0.3,0.4]);
            $out .= $this->text(465, $boxY + 40, 9, 'Rs. ' . number_format($s['total_payments'], 2), true);
            $out .= $this->text(340, $boxY + 17, 10, 'Outstanding', true, [0.55,0.12,0.12]);
            $out .= $this->text(465, $boxY + 17, 10, 'Rs. ' . number_format($s['closing_balance'], 2), true, [0.55,0.12,0.12]);
        }
        $out .= $this->text(36, 25, 7, 'Generated ' . $s['generated_at'] . ' - This statement is generated from the customer ledger.', false, [0.4,0.45,0.52]);
        return $out;
    }

    private function document(array $streams): string
    {
        $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>', 4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>'];
        $kids = [];
        foreach ($streams as $i => $stream) {
            $pageId = 5 + ($i * 2); $contentId = $pageId + 1; $kids[] = "{$pageId} 0 R";
            $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents {$contentId} 0 R >>";
            $objects[$contentId] = "<< /Length " . strlen($stream) . " >>\nstream\n{$stream}endstream";
        }
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $offsets = [0];
        foreach ($objects as $id => $body) { $offsets[$id] = strlen($pdf); $pdf .= "{$id} 0 obj\n{$body}\nendobj\n"; }
        $xref = strlen($pdf); $max = max(array_keys($objects));
        $pdf .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i=1; $i<=$max; $i++) $pdf .= sprintf('%010d 00000 n ', $offsets[$i]) . "\n";
        return $pdf . "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }

    private function text(float $x, float $y, int $size, string $value, bool $bold = false, array $rgb = [0.12,0.16,0.23]): string
    {
        $value = $this->ascii($value);
        $value = str_replace(['\\','(',')'], ['\\\\','\\(','\\)'], $value);
        return implode(' ', $rgb) . " rg BT /F" . ($bold ? '2' : '1') . " {$size} Tf {$x} {$y} Td ({$value}) Tj ET\n";
    }

    private function apply(float $balance, string $type, float $amount): float
    {
        return round($balance + (in_array($type, self::CREDIT_TYPES, true) ? -$amount : $amount), 2);
    }

    private function typeLabel(string $type): string
    {
        return match ($type) { 'sale' => 'Bill', 'payment' => 'Payment', 'credit_memo' => 'Credit', 'opening_balance' => 'Opening', 'adjustment' => 'Adjustment', default => ucwords(str_replace('_',' ',$type)) };
    }

    private function validDate(?string $date): ?string
    {
        return $date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && checkdate((int) substr($date,5,2), (int) substr($date,8,2), (int) substr($date,0,4)) ? $date : null;
    }

    private function short(string $text, int $max): string { return strlen($text) > $max ? substr($text, 0, $max - 3) . '...' : $text; }
    private function ascii(string $text): string { return iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: preg_replace('/[^\x20-\x7E]/', '', $text); }
}
