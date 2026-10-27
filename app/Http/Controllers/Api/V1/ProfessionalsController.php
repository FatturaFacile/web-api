<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\BillableActivity;
use App\Models\FeeNote;
use App\Models\LegalCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProfessionalsController extends Controller
{
    use ApiResponse;

    public function cases(): JsonResponse
    {
        $cases = LegalCase::latest()->paginate(25);

        return $this->successResponse(data: $cases, message: 'Legal cases (Fascicoli) retrieved');
    }

    public function timeLogs(Request $request): JsonResponse
    {
        $logs = BillableActivity::latest('date')->paginate(25);

        return $this->successResponse(data: $logs, message: 'Billable activities retrieved');
    }

    public function logActivity(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'legal_case_id' => ['required', 'uuid'],
            'date' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'min:1'],
            'hourly_rate' => ['required', 'numeric', 'min:0'],
            'description' => ['required', 'string'],
        ]);

        $fee = round(($validated['duration_minutes'] / 60.0) * (float) $validated['hourly_rate'], 2);

        $activity = BillableActivity::create([
            'legal_case_id' => $validated['legal_case_id'],
            'user_id' => $request->user()->id,
            'date' => $validated['date'],
            'duration_minutes' => $validated['duration_minutes'],
            'hourly_rate' => $validated['hourly_rate'],
            'fee_amount' => $fee,
            'description' => $validated['description'],
            'is_billed' => false,
        ]);

        return $this->successResponse(
            data: ['id' => $activity->id, 'fee_amount' => $fee],
            message: 'Billable time logged successfully',
            statusCode: Response::HTTP_CREATED
        );
    }

    public function feeNotes(): JsonResponse
    {
        $notes = FeeNote::latest('date')->paginate(25);

        return $this->successResponse(data: $notes, message: 'Professional fee notes (Parcelle) retrieved');
    }

    public function storeFeeNote(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'legal_case_id' => ['nullable', 'uuid'],
            'client_id' => ['required', 'uuid'],
            'note_number' => ['required', 'string'],
            'date' => ['required', 'date'],
            'taxable_fees' => ['required', 'numeric', 'min:0'],
            'cpa_rate' => ['nullable', 'numeric', 'min:0', 'max:10'], // Cassa Previdenza (default 4% IT)
            'ritenuta_rate' => ['nullable', 'numeric', 'min:0', 'max:30'], // Ritenuta d'acconto (default 20% IT)
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:25'],
        ]);

        $taxable = (float) $validated['taxable_fees'];
        $cpaRate = (float) ($validated['cpa_rate'] ?? 4.0);
        $ritenutaRate = (float) ($validated['ritenuta_rate'] ?? 20.0);
        $vatRate = (float) ($validated['vat_rate'] ?? 22.0);

        $cpa = round($taxable * ($cpaRate / 100.0), 2);
        $imponibileIva = $taxable + $cpa;
        $vat = round($imponibileIva * ($vatRate / 100.0), 2);
        $ritenuta = round($taxable * ($ritenutaRate / 100.0), 2);
        $netPayable = ($imponibileIva + $vat) - $ritenuta;

        $feeNote = FeeNote::create([
            'legal_case_id' => $validated['legal_case_id'] ?? null,
            'client_id' => $validated['client_id'],
            'note_number' => $validated['note_number'],
            'date' => $validated['date'],
            'taxable_fees' => $taxable,
            'cassa_previdenza_amount' => $cpa,
            'ritenuta_acconto_amount' => $ritenuta,
            'vat_amount' => $vat,
            'net_payable' => $netPayable,
            'status' => 'draft',
        ]);

        return $this->successResponse(
            data: [
                'id' => $feeNote->id,
                'taxable_fees' => $taxable,
                'cassa_previdenza' => $cpa,
                'vat' => $vat,
                'ritenuta_acconto' => $ritenuta,
                'net_payable' => $netPayable,
            ],
            message: 'Fee note (Parcella) created',
            statusCode: Response::HTTP_CREATED
        );
    }
}
