<?php

declare(strict_types=1);

namespace Modules\Payroll\Services\Extras;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Mail\PayslipAvailableMail;
use Modules\Payroll\Models\PayrollRun;
use Modules\Payroll\Models\Payslip;
use Modules\Payroll\Models\PayslipDelivery;
use Modules\Payroll\Services\PayrollDocumentService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Tells the employees of a closed run that their payslip is ready. The mail holds no salary figure and no attachment: only
 * a personal link, valid for a few days, stored as a hash. Opening it also asks for the date of birth, and a few wrong
 * answers lock the link. The employee portal of /payroll/payslips stays the way for whoever has an account.
 */
final class PayslipDeliveryService
{
    public const int ValidityDays = 14;

    public const int MaximumAttempts = 5;

    public function __construct(
        private readonly PayrollDocumentService $documents,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Sending again replaces the earlier links of each payslip: they are revoked, and only the newest mail opens it.
     *
     * @return array{sent: int, skipped: list<array{employee_id: string, reason: string}>}
     */
    public function deliver(PayrollRun $run): array
    {
        if (! $run->isFinal()) {
            throw new PayrollException("Le buste paga si inviano quando l'elaborazione è chiusa.");
        }

        $sent = 0;
        $skipped = [];
        $company = (string) ($this->tenantContext->getTenant()?->legal_name ?? '');

        foreach (Payslip::query()->with('employee.user')->where('payroll_run_id', $run->id)->get() as $payslip) {
            $employee = $payslip->employee;
            $address = $employee?->work_email ?: $employee?->user?->email;

            if ($address === null || $address === '' || $employee?->birth_date === null) {
                $skipped[] = ['employee_id' => (string) $payslip->employee_id, 'reason' => $employee?->birth_date === null ? 'Data di nascita mancante: il link non sarebbe protetto.' : 'Indirizzo e-mail mancante.'];

                continue;
            }

            $token = Str::random(64);
            $now = CarbonImmutable::now();

            PayslipDelivery::query()
                ->where('payslip_id', $payslip->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => $now]);

            PayslipDelivery::query()->create([
                'payslip_id' => $payslip->id,
                'employee_id' => $payslip->employee_id,
                'token_hash' => hash('sha256', $token),
                'expires_at' => $now->addDays(self::ValidityDays),
            ]);

            Mail::to($address)
                ->locale($employee->user?->locale ?? 'it')
                ->queue(new PayslipAvailableMail(
                    (string) $employee->full_name,
                    $company,
                    sprintf('%02d/%d', $payslip->month, $payslip->year),
                    rtrim((string) config('app.frontend_url'), '/').'/payslip-access/'.$token,
                    self::ValidityDays,
                ));
            $sent++;
        }

        return ['sent' => $sent, 'skipped' => $skipped];
    }

    /**
     * The PDF of a link, for whoever knows the date of birth. Every failure answers the same, so a link cannot be probed.
     * Opening records the attempt, a write: while a restore replaces the data of the tenant it answers 503.
     *
     * @return array{content: string, file_name: string}
     */
    public function open(string $token, string $birthDate): array
    {
        $delivery = PayslipDelivery::query()->withoutGlobalScopes()->where('token_hash', hash('sha256', $token))->first();

        if ($delivery === null || $delivery->revoked_at !== null || $delivery->expires_at->isPast() || $delivery->failed_attempts >= self::MaximumAttempts) {
            throw new NotFoundHttpException;
        }

        $tenant = Tenant::query()->findOrFail($delivery->tenant_id);

        if ($tenant->is_maintenance) {
            throw new ServiceUnavailableHttpException(60, __('backup.maintenance.detail'));
        }

        return $this->tenantContext->runWithTenant($tenant, function () use ($delivery, $birthDate, $tenant): array {
            $payslip = Payslip::query()->with('employee')->findOrFail($delivery->payslip_id);

            if (! $this->reserveAttempt($delivery)) {
                throw new NotFoundHttpException;
            }

            if ($payslip->employee?->birth_date?->format('Y-m-d') !== $birthDate) {
                throw new NotFoundHttpException;
            }

            PayslipDelivery::query()->withoutGlobalScopes()->whereKey($delivery->id)->where('failed_attempts', '>', 0)->decrement('failed_attempts');

            $delivery->update(['opened_at' => $delivery->opened_at ?? CarbonImmutable::now()]);

            return [
                'content' => $this->documents->payslip($payslip, $tenant),
                'file_name' => sprintf('Busta_paga_%d-%02d.pdf', $payslip->year, $payslip->month),
            ];
        });
    }

    /**
     * Counts the attempt as failed before the answer is compared, in one conditional UPDATE: parallel requests cannot all
     * read the same counter and slip past the limit. A right answer gives the attempt back.
     */
    private function reserveAttempt(PayslipDelivery $delivery): bool
    {
        return PayslipDelivery::query()->withoutGlobalScopes()
            ->whereKey($delivery->id)
            ->where('failed_attempts', '<', self::MaximumAttempts)
            ->increment('failed_attempts') === 1;
    }
}
