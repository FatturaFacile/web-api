<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services;

use App\Services\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;
use Modules\HumanResources\Contracts\RecruitmentServiceInterface;
use Modules\HumanResources\Enums\ApplicationStage;
use Modules\HumanResources\Enums\JobOpeningStatus;
use Modules\HumanResources\Enums\RecruitingLocale;
use Modules\HumanResources\Models\CandidateApplication;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\JobOpening;
use Modules\HumanResources\Models\Position;
use Modules\HumanResources\Models\RecruitingEmailTemplate;
use Modules\HumanResources\Models\RecruitingSettings;
use Modules\HumanResources\Support\RecruitingDefaults;

final readonly class RecruitmentService implements RecruitmentServiceInterface
{
    public function __construct(
        private TenantContext $tenantContext
    ) {}

    public function settings(): RecruitingSettings
    {
        $settings = RecruitingSettings::query()->first();

        if ($settings !== null) {
            return $settings;
        }

        $tenant = $this->tenantContext->getTenant();

        return RecruitingSettings::defaults($tenant?->id, $tenant?->name);
    }

    public function updateSettings(array $data): RecruitingSettings
    {
        $settings = $this->settings();

        if (isset($data['careers'])) {
            $careers = $data['careers'];
            $mapping = [
                'enabled' => 'careers_enabled',
                'slug' => 'careers_slug',
                'company_name' => 'company_name',
                'privacy_policy_url' => 'privacy_policy_url',
                'consent_text_version' => 'consent_text_version',
            ];

            foreach ($mapping as $input => $column) {
                if (array_key_exists($input, $careers)) {
                    $value = $careers[$input];
                    $settings->{$column} = match ($input) {
                        'enabled' => (bool) $value,
                        'slug' => $value === null || $value === '' ? null : strtolower((string) $value),
                        'consent_text_version' => $value === null || $value === '' ? $settings->consent_text_version : (string) $value,
                        default => $value === null ? null : (string) $value,
                    };
                }
            }

            foreach (['intro', 'privacy_notice'] as $field) {
                if (array_key_exists($field, $careers)) {
                    $settings->{$field} = $this->onlyLocales((array) ($careers[$field] ?? []));
                }
            }
        }

        if (isset($data['retention']['default_months'])) {
            $settings->retention_default_months = (int) $data['retention']['default_months'];
        }

        if (isset($data['pipeline']['stages'])) {
            $pipeline = (array) ($settings->pipeline ?? []);

            foreach ($data['pipeline']['stages'] as $stageInput) {
                $stage = ApplicationStage::from((string) $stageInput['stage']);
                $config = is_array($pipeline[$stage->value] ?? null) ? $pipeline[$stage->value] : [];

                if (array_key_exists('enabled', $stageInput)) {
                    $config['enabled'] = $stage->canBeDisabled() ? (bool) $stageInput['enabled'] : true;
                }

                if (array_key_exists('auto_email', $stageInput)) {
                    $config['auto_email'] = (bool) $stageInput['auto_email'];
                }

                if (array_key_exists('labels', $stageInput)) {
                    $config['labels'] = $this->onlyLocales((array) ($stageInput['labels'] ?? []));
                }

                $pipeline[$stage->value] = $config;
            }

            $settings->pipeline = $pipeline;
        }

        if ($settings->careers_enabled && ($settings->careers_slug === null || $settings->careers_slug === '')) {
            throw ValidationException::withMessages([
                'careers.slug' => ['A careers slug is required to enable the public careers page.'],
            ]);
        }

        $settings->save();

        return $settings->refresh();
    }

    public function companyName(?RecruitingSettings $settings = null): string
    {
        $settings ??= $this->settings();
        $name = trim((string) $settings->company_name);

        if ($name !== '') {
            return $name;
        }

        return (string) ($this->tenantContext->getTenant()->name ?? config('app.name'));
    }

    public function availableVacancy(Position $position, ?JobOpening $excluding = null): int
    {
        $activeIncumbents = Employee::query()
            ->where('position_id', $position->id)
            ->where('employment_status', '!=', 'terminated')
            ->count();

        $reserved = JobOpening::query()
            ->where('position_id', $position->id)
            ->whereIn('status', [JobOpeningStatus::Published->value, JobOpeningStatus::InternalOnly->value])
            ->when($excluding !== null, fn ($query) => $query->whereKeyNot($excluding?->id))
            ->get(['id', 'openings_count', 'hires_count'])
            ->sum(static fn (JobOpening $opening): int => $opening->remainingOpenings());

        return max(0, (int) $position->headcount_budget - $activeIncumbents - (int) $reserved);
    }

    public function emailTemplate(ApplicationStage $stage, RecruitingLocale $locale): array
    {
        $stored = RecruitingEmailTemplate::query()
            ->where('stage', $stage->value)
            ->where('locale', $locale->value)
            ->first();

        return $stored !== null ? $this->storedTemplatePayload($stored) : $this->defaultTemplatePayload($stage, $locale);
    }

    public function emailTemplates(?ApplicationStage $stage = null, ?RecruitingLocale $locale = null): array
    {
        $settings = $this->settings();
        $stored = RecruitingEmailTemplate::query()
            ->when($stage !== null, fn ($query) => $query->where('stage', $stage?->value))
            ->when($locale !== null, fn ($query) => $query->where('locale', $locale?->value))
            ->get()
            ->keyBy(static fn (RecruitingEmailTemplate $template): string => $template->stage.'|'.$template->locale);

        $templates = [];
        foreach (ApplicationStage::cases() as $candidateStage) {
            if (($stage !== null && $candidateStage !== $stage) || ! $settings->stageEnabled($candidateStage)) {
                continue;
            }

            foreach (RecruitingLocale::cases() as $candidateLocale) {
                if ($locale !== null && $candidateLocale !== $locale) {
                    continue;
                }

                $template = $stored->get($candidateStage->value.'|'.$candidateLocale->value);
                $templates[] = $template instanceof RecruitingEmailTemplate
                    ? $this->storedTemplatePayload($template)
                    : $this->defaultTemplatePayload($candidateStage, $candidateLocale);
            }
        }

        return $templates;
    }

    public function upsertEmailTemplate(ApplicationStage $stage, RecruitingLocale $locale, string $subject, string $body, bool $isEnabled): array
    {
        $template = RecruitingEmailTemplate::query()
            ->where('stage', $stage->value)
            ->where('locale', $locale->value)
            ->first() ?? new RecruitingEmailTemplate(['stage' => $stage->value, 'locale' => $locale->value]);

        $template->fill([
            'subject' => $subject,
            'body' => $body,
            'is_enabled' => $isEnabled,
        ]);
        $template->save();

        return $this->storedTemplatePayload($template);
    }

    public function nextBoardPosition(string $jobOpeningId, ApplicationStage $stage): int
    {
        $max = CandidateApplication::query()
            ->where('job_opening_id', $jobOpeningId)
            ->where('stage', $stage->value)
            ->max('board_position');

        return $max === null ? 0 : ((int) $max) + 1;
    }

    /**
     * @param  array<mixed>  $texts
     * @return array<string, string>
     */
    private function onlyLocales(array $texts): array
    {
        $result = [];
        foreach (RecruitingLocale::values() as $locale) {
            if (isset($texts[$locale]) && is_string($texts[$locale])) {
                $result[$locale] = $texts[$locale];
            }
        }

        return $result;
    }

    /**
     * @return array{stage: string, locale: string, subject: string, body: string, is_enabled: bool, is_default: bool}
     */
    private function storedTemplatePayload(RecruitingEmailTemplate $template): array
    {
        return [
            'stage' => (string) $template->stage,
            'locale' => (string) $template->locale,
            'subject' => (string) $template->subject,
            'body' => (string) $template->body,
            'is_enabled' => (bool) $template->is_enabled,
            'is_default' => false,
        ];
    }

    /**
     * @return array{stage: string, locale: string, subject: string, body: string, is_enabled: bool, is_default: bool}
     */
    private function defaultTemplatePayload(ApplicationStage $stage, RecruitingLocale $locale): array
    {
        $default = RecruitingDefaults::emailTemplate($stage, $locale);

        return [
            'stage' => $stage->value,
            'locale' => $locale->value,
            'subject' => $default['subject'],
            'body' => $default['body'],
            'is_enabled' => true,
            'is_default' => true,
        ];
    }
}
