import fs from 'fs';
function edit(file, pairs){
  let s = fs.readFileSync(file,'utf8'); const crlf = s.includes('\r\n'); s = s.replace(/\r\n/g,'\n');
  for (const [a,b] of pairs){ if(!s.includes(a)) throw new Error('missing in '+file+': '+a.slice(0,50)); s = s.replace(a,b); }
  if (crlf) s = s.replace(/\n/g,'\r\n'); fs.writeFileSync(file,s);
}
edit('app/Models/LegalCase.php', [[`    protected function casts(): array`, `    /**
     * Calculated columns of the grid: the logged time and the fees of the case, summed over its billable activities.
     *
     * @return array<string, string>
     */
    public function gridColumns(): array
    {
        return [
            'logged_minutes' => 'COALESCE((SELECT SUM(billable_activities.duration_minutes) FROM billable_activities WHERE billable_activities.legal_case_id = legal_cases.id), 0)',
            'fee_total' => 'COALESCE((SELECT SUM(billable_activities.fee_amount) FROM billable_activities WHERE billable_activities.legal_case_id = legal_cases.id), 0)',
        ];
    }

    protected function casts(): array`]]);
edit('app/Models/BillableActivity.php', [[`    protected function casts(): array`, `    /**
     * Calculated columns of the grid: the billing status the grid shows.
     *
     * @return array<string, string>
     */
    public function gridColumns(): array
    {
        return [
            'billing_status' => "CASE WHEN billable_activities.is_billed THEN 'billed' ELSE 'unbilled' END",
        ];
    }

    protected function casts(): array`]]);
edit('modules/36_PracticeManagement/src/Models/Retainer.php', [[`    protected function casts(): array`, `    /**
     * Calculated columns of the grid: the balance still available.
     *
     * @return array<string, string>
     */
    public function gridColumns(): array
    {
        return [
            'balance' => 'practice_retainers.amount - practice_retainers.applied_amount',
        ];
    }

    protected function casts(): array`],
[`    /**
     * @return HasMany<RetainerApplication, $this>`, `    /**
     * @return BelongsTo<LegalCase, $this>
     */
    public function legalCase(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class, 'legal_case_id');
    }

    /**
     * @return HasMany<RetainerApplication, $this>`],
[`use App\Models\Contact;`, `use App\Models\Contact;\nuse App\Models\LegalCase;`]]);
edit('modules/36_PracticeManagement/src/Http/Controllers/RetainerController.php', [[`        return PracticeResource::collection(Retainer::query()
            ->when(`, `        return PracticeResource::collection(Retainer::query()
            ->with(['client', 'legalCase'])
            ->when(`],[`->latest('received_on')->get());`, `->latest('received_on')->gridPaginate(RequestedPerPage::resolve(25)));`],
[`use Modules\PracticeManagement\Http\Requests`, `use App\Support\Http\RequestedPerPage;\nuse Modules\PracticeManagement\Http\Requests`]]);
edit('app/Http/Controllers/Api/V1/ProfessionalsController.php', [
[`LegalCase::latest()->gridPaginate`, `LegalCase::with('client')->latest()->gridPaginate`],
[`        $logs = BillableActivity::latest('date')->gridPaginate(`, `        $logs = BillableActivity::with('legalCase.client')
            ->when($request->filled('from'), fn ($query) => $query->whereDate('date', '>=', (string) $request->query('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('date', '<=', (string) $request->query('to')))
            ->when($request->filled('is_billed'), fn ($query) => $query->where('is_billed', $request->boolean('is_billed')))
            ->latest('date')
            ->gridPaginate(`],
[`FeeNote::latest('date')->gridPaginate`, `FeeNote::with('client')->latest('date')->gridPaginate`],
[`        responses: [
            new OA\Response(response: 200, description: 'Billable activities retrieved'`, `        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: false, description: 'First day of the period (Y-m-d)', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, description: 'Last day of the period (Y-m-d)', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'is_billed', in: 'query', required: false, description: 'Only billed (true) or unbilled (false) activities', schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Billable activities retrieved'`]]);
