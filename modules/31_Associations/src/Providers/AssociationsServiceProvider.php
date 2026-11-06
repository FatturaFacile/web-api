<?php

declare(strict_types=1);

namespace Modules\Associations\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Modules\Associations\Contracts\BoardDecisionGateway;
use Modules\Associations\Contracts\FeePaymentGateway;
use Modules\Associations\Gateways\LocalFeePaymentGateway;
use Modules\Associations\Models\AccessArea;
use Modules\Associations\Models\AccessDevice;
use Modules\Associations\Models\AssociativeYear;
use Modules\Associations\Models\Authorization;
use Modules\Associations\Models\BookableResource;
use Modules\Associations\Models\Course;
use Modules\Associations\Models\CourseEnrolment;
use Modules\Associations\Models\CustomField;
use Modules\Associations\Models\Department;
use Modules\Associations\Models\Discipline;
use Modules\Associations\Models\EnrolmentApplication;
use Modules\Associations\Models\EnrolmentForm;
use Modules\Associations\Models\EventRegistration;
use Modules\Associations\Models\Family;
use Modules\Associations\Models\FamilyDiscount;
use Modules\Associations\Models\FeeAssignment;
use Modules\Associations\Models\FeeInstalment;
use Modules\Associations\Models\FeePayment;
use Modules\Associations\Models\FeePlan;
use Modules\Associations\Models\ImportBatch;
use Modules\Associations\Models\MedicalCertificate;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\MemberDocument;
use Modules\Associations\Models\Membership;
use Modules\Associations\Models\MembershipType;
use Modules\Associations\Models\NetworkLink;
use Modules\Associations\Models\Receipt;
use Modules\Associations\Models\RegistryType;
use Modules\Associations\Models\ResourceBooking;
use Modules\Associations\Models\SocialRole;
use Modules\Associations\Models\TicketType;
use Modules\Associations\Policies\AssociationRecordPolicy;
use Modules\Associations\Services\AssociationAccess;
use Modules\Associations\Services\BoardDecisionsByStaff;
use Modules\Associations\Support\AssociationCapability;

final class AssociationsServiceProvider extends ServiceProvider
{
    /**
     * The contract of the governance module and the online payment driver.
     */
    public function register(): void
    {
        $this->app->bindIf(BoardDecisionGateway::class, BoardDecisionsByStaff::class);

        $this->app->bind(FeePaymentGateway::class, static function (): FeePaymentGateway {
            $driver = (string) config('associations.payment_driver');

            return match ($driver) {
                'local' => new LocalFeePaymentGateway,
                default => throw new InvalidArgumentException("Unknown association payment driver [{$driver}]."),
            };
        });
    }

    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        });

        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'associations');

        foreach ([
            AccessArea::class, AccessDevice::class, AssociativeYear::class, Authorization::class, BookableResource::class,
            Course::class, CourseEnrolment::class, CustomField::class, Department::class, Discipline::class,
            EnrolmentApplication::class, EnrolmentForm::class, EventRegistration::class, Family::class, FamilyDiscount::class, FeeAssignment::class,
            FeeInstalment::class, FeePayment::class, FeePlan::class, ImportBatch::class, MedicalCertificate::class,
            Member::class, MemberDocument::class, Membership::class, MembershipType::class, NetworkLink::class,
            Receipt::class, RegistryType::class, ResourceBooking::class, SocialRole::class, TicketType::class,
        ] as $model) {
            Gate::policy($model, AssociationRecordPolicy::class);
        }

        foreach (AssociationCapability::cases() as $capability) {
            Gate::define($capability->value, static fn (User $user): bool => app(AssociationAccess::class)->allows($user, $capability));
        }

        RateLimiter::for('association-public', static fn (Request $request): Limit => Limit::perMinute(30)->by($request->ip()));
        RateLimiter::for('association-enrolment', static fn (Request $request): array => [
            Limit::perMinute(5)->by($request->ip()),
            Limit::perHour(30)->by($request->ip()),
        ]);
        RateLimiter::for('association-area', static fn (Request $request): Limit => Limit::perMinute(60)->by(hash('sha256', (string) $request->header('X-Area-Token')).$request->ip()));
        RateLimiter::for('association-reader', static fn (Request $request): Limit => Limit::perMinute(240)->by((string) $request->header('X-Device-Key') ?: $request->ip()));
        RateLimiter::for('association-payment', static fn (Request $request): Limit => Limit::perMinute(60)->by($request->ip()));
    }
}
