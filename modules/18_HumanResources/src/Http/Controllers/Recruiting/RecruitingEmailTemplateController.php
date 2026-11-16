<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Controllers\Recruiting;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\HumanResources\Contracts\RecruitmentServiceInterface;
use Modules\HumanResources\Enums\ApplicationStage;
use Modules\HumanResources\Enums\RecruitingLocale;
use Modules\HumanResources\Http\Requests\Recruiting\UpsertEmailTemplateRequest;

final class RecruitingEmailTemplateController extends Controller
{
    public function __construct(
        private readonly RecruitmentServiceInterface $recruitment
    ) {}

    public function index(Request $request): JsonResponse
    {
        $stage = $request->filled('stage') ? ApplicationStage::tryFrom((string) $request->query('stage')) : null;
        $locale = $request->filled('locale') ? RecruitingLocale::tryFrom((string) $request->query('locale')) : null;

        return response()->json(['data' => $this->recruitment->emailTemplates($stage, $locale)]);
    }

    public function upsert(UpsertEmailTemplateRequest $request, string $stage, string $locale): JsonResponse
    {
        $stageEnum = ApplicationStage::tryFrom($stage);
        $localeEnum = RecruitingLocale::tryFrom($locale);

        if ($stageEnum === null || $localeEnum === null) {
            abort(404);
        }

        return response()->json(['data' => $this->recruitment->upsertEmailTemplate(
            $stageEnum,
            $localeEnum,
            (string) $request->input('subject'),
            (string) $request->input('body'),
            $request->boolean('is_enabled')
        )]);
    }
}
