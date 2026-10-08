<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Models\ClearancePerson;
use App\Models\Purchase;
use App\Models\PurchaseClearanceAssignment;

/**
 * Hands a purchase to one or more clearance agents.
 *
 * The common case is one agent taking the whole shipment. The rare case — several
 * agents splitting it — is supported by adding one assignment each; the sum of
 * their weights should equal the shipment weight, and a mismatch is surfaced as a
 * warning rather than blocked, because the true split is sometimes only known once
 * the goods are weighed.
 */
class ClearanceAssignmentController extends Controller
{
    private Purchase $purchases;
    private PurchaseClearanceAssignment $assignments;

    public function __construct()
    {
        $this->purchases   = new Purchase();
        $this->assignments = new PurchaseClearanceAssignment();
    }

    public function create(Request $request, array $params): void
    {
        $purchase = $this->purchases->findWithRelations((int) $params['id']);
        if (!$purchase) {
            $this->abort(404, 'Purchase not found.');
        }
        $this->rejectCompletedPurchase($purchase);

        $this->view('clearance/assign', [
            'title'    => 'Assign Clearance — ' . $purchase['purchase_number'],
            'purchase' => $purchase,
            'people'   => (new ClearancePerson())->active(),
        ]);
    }

    public function store(Request $request, array $params): void
    {
        $purchaseId = (int) $params['id'];
        $purchase   = $this->purchases->find($purchaseId);
        if (!$purchase) {
            $this->abort(404, 'Purchase not found.');
        }
        $this->rejectCompletedPurchase($purchase);

        $input    = $request->all();
        $personChoice = (string) ($input['clearance_person_id'] ?? '');
        $personId = ctype_digit($personChoice) ? (int) $personChoice : 0;
        $weight   = (float) ($input['assigned_weight_kg'] ?? 0);
        if ($weight <= 0) {
            $this->withErrors(['assigned_weight_kg' => ['Enter the weight this person is clearing.']], $input);
        }

        if ($personChoice === '__new__') {
            $name = trim((string) ($input['new_person_name'] ?? ''));
            $rawPhone = trim((string) ($input['new_person_phone'] ?? ''));
            $phone = sri_lankan_phone($rawPhone);
            if ($name === '') {
                $this->withErrors(['clearance_person_id' => ['Enter the new clearance person name.']], $input);
            }
            if ($rawPhone !== '' && $phone === null) {
                $this->withErrors(['clearance_person_id' => ['Enter a valid Sri Lankan mobile number.']], $input);
            }
            $personId = (new ClearancePerson())->create([
                'name' => $name,
                'phone' => $phone,
                'address' => null,
                'wage_per_kilo' => max(0, (float) ($input['new_person_rate'] ?? 0)),
                'notes' => 'Created while assigning purchase ' . $purchase['purchase_number'],
                'is_active' => 1,
            ]);
            $this->log('clearance_person.create', 'clearance_person', $personId, ['source' => 'purchase_assignment']);
        }

        if ($personId <= 0) {
            $this->withErrors(['clearance_person_id' => ['Choose a clearance person.']], $input);
        }
        $selectedPerson = (new ClearancePerson())->find($personId);
        if (!$selectedPerson || !(int) $selectedPerson['is_active']) {
            $this->withErrors(['clearance_person_id' => ['Choose an active clearance person.']], $input);
        }
        $this->assignments->assign($purchaseId, $personId, [
            'assigned_weight_kg' => $weight,
            'parcel_count'       => max(0, (int) ($input['parcel_count'] ?? 0)),
            'assignment_date'    => $this->dateOrToday($input['assignment_date'] ?? ''),
            'notes'              => trim((string) ($input['notes'] ?? '')) ?: null,
        ]);

        // Assigning is what moves a purchase out of "awaiting clearance".
        $this->purchases->advanceStatus($purchaseId, 'assigned');

        $this->log('clearance.assign', 'purchase', $purchaseId, ['person' => $personId, 'kg' => $weight]);

        $summary = $this->purchases->weightSummary($purchaseId);
        if ($summary['balanced']) {
            Session::flash('success', 'Clearance assigned — assigned weight matches the shipment.');
        } elseif ($summary['remaining'] > 0) {
            Session::flash('error', sprintf(
                'Assigned, but %.2f kg of %.2f kg is still unassigned.',
                $summary['remaining'],
                $summary['total']
            ));
        } else {
            Session::flash('error', sprintf(
                'Assigned, but the total assigned weight exceeds the shipment by %.2f kg.',
                abs($summary['remaining'])
            ));
        }

        $this->redirect('purchases/' . $purchaseId);
    }

    /** Mark every open assignment on a purchase as in transit. */
    public function markInTransit(Request $request, array $params): void
    {
        $purchaseId = (int) $params['id'];
        $purchase = $this->purchases->find($purchaseId);
        if (!$purchase) {
            $this->abort(404, 'Purchase not found.');
        }
        $this->rejectCompletedPurchase($purchase);

        $assigned = $this->assignments->byPurchase($purchaseId);
        if (!$assigned) {
            Session::flash('error', 'Assign at least one clearance person before marking this shipment in transit.');
            $this->redirect('purchases/' . $purchaseId);
        }
        $this->assignments->updateStatusForPurchase($purchaseId, 'in_transit');
        $this->purchases->advanceStatus($purchaseId, 'in_transit');

        Session::flash('success', 'Shipment marked as in transit.');
        $this->redirect('purchases/' . $purchaseId);
    }

    public function destroy(Request $request, array $params): void
    {
        $purchaseId   = (int) $params['id'];
        $assignmentId = (int) $params['assignmentId'];

        $assignment = $this->assignments->find($assignmentId);
        if (!$assignment || (int) $assignment['purchase_id'] !== $purchaseId) {
            $this->abort(404, 'Assignment not found.');
        }

        $purchase = $this->purchases->find($purchaseId);
        if (!$purchase) {
            $this->abort(404, 'Purchase not found.');
        }
        $this->rejectCompletedPurchase($purchase);

        $this->assignments->delete($assignmentId);

        $this->log('clearance.unassign', 'purchase', $purchaseId, ['assignment' => $assignmentId]);
        Session::flash('success', 'Assignment removed.');
        $this->redirect('purchases/' . $purchaseId);
    }

    /** Repair older completed shipments whose received parcels were saved without an agent link. */
    public function linkReceivedParcels(Request $request, array $params): void
    {
        $purchaseId = (int) $params['id'];
        $personId = (int) $request->input('clearance_person_id');
        $rate = max(0.0, (float) $request->input('rate_per_kg', 0));
        $purchase = $this->purchases->find($purchaseId);
        $person = (new ClearancePerson())->find($personId);
        if (!$purchase || !$person) {
            $this->abort(404, 'Purchase or clearance person not found.');
        }

        $weight = (float) Database::instance()->scalar(
            "SELECT COALESCE(SUM(COALESCE(arrived_weight_kg, weight_kg)), 0)
               FROM parcels WHERE purchase_id = ? AND assignment_id IS NULL AND status = 'received'",
            [$purchaseId]
        );
        if ($weight <= 0) {
            Session::flash('error', 'There are no unassigned received parcels to link.');
            $this->redirect('purchases/' . $purchaseId);
        }

        Database::instance()->transaction(function () use ($purchaseId, $personId, $weight, $rate): void {
            $assignmentId = $this->assignments->assign($purchaseId, $personId, [
                'assigned_weight_kg' => $weight,
                'parcel_count' => 0,
                'assignment_date' => date('Y-m-d'),
                'status' => 'delivered',
                'notes' => 'Linked to parcels after delivery.',
                'rate_per_kg' => $rate,
            ]);
            Database::instance()->query(
                "UPDATE parcels SET assignment_id = ? WHERE purchase_id = ? AND assignment_id IS NULL AND status = 'received'",
                [$assignmentId, $purchaseId]
            );
            $this->assignments->syncPaymentToReceivedWeight($assignmentId);
        });

        $this->log('clearance.parcels_linked', 'purchase', $purchaseId, ['person' => $personId, 'weight' => $weight]);
        Session::flash('success', sprintf('Linked %.2f kg of received parcels to the clearance person. Payment is now calculated from that delivered weight.', $weight));
        $this->redirect('purchases/' . $purchaseId);
    }

    /** Set the actual per-kg clearance payment for delivered parcels. */
    public function updateReceivedPaymentRate(Request $request, array $params): void
    {
        $purchaseId = (int) $params['id'];
        $assignmentId = (int) $params['assignmentId'];
        $assignment = $this->assignments->find($assignmentId);
        if (!$assignment || (int) $assignment['purchase_id'] !== $purchaseId) {
            $this->abort(404, 'Clearance assignment not found.');
        }

        $rate = max(0.0, (float) $request->input('rate_per_kg', 0));
        $this->assignments->update($assignmentId, ['rate_per_kg' => $rate]);
        $payable = $this->assignments->syncPaymentToReceivedWeight($assignmentId);
        $this->log('clearance.payment_rate', 'clearance_assignment', $assignmentId, ['rate_per_kg' => $rate, 'amount' => $payable['amount']]);
        Session::flash('success', sprintf('Clearance payment updated: %.2f kg × %s/kg = %s.', $payable['weight'], money($rate), money($payable['amount'])));
        $this->redirect('purchases/' . $purchaseId);
    }

    private function dateOrToday($value): string
    {
        $value = trim((string) $value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : date('Y-m-d');
    }

    /** Completed purchases are immutable accounting and inventory history. */
    private function rejectCompletedPurchase(array $purchase): void
    {
        if (Purchase::statusAtLeast((string) $purchase['status'], 'completed')) {
            Session::flash('error', 'This purchase is completed. Its clearance history can no longer be changed.');
            $this->redirect('purchases/' . (int) $purchase['id']);
        }
    }
}
