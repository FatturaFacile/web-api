<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Controllers\Recruiting;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\HumanResources\Contracts\RecruitmentServiceInterface;
use Modules\HumanResources\Http\Requests\Recruiting\UpdateRecruitingSettingsRequest;
use Modules\HumanResources\Http\Resources\RecruitingSettingsResource;

final class RecruitingSettingsController extends Controller
{
    public function __construct(
        private readonly RecruitmentServiceInterface $recruitment
    ) {}

    public function show(Request $request): JsonResponse
    {
        $settings = $this->recruitment->settings();

        return response()->json([
            'data' => (new RecruitingSettingsResource($settings, $this->recruitment->companyName($settings)))->resolve($request),
        ]);
    }

    public function update(UpdateRecruitingSettingsRequest $request): JsonResponse
    {
        /** @var array{careers?: array<string, mixed>, retention?: array{default_months?: int}, pipeline?: array{stages?: list<array<string, mixed>>}} $data */
        $data = $request->validated();
        $settings = $this->recruitment->updateSettings($data);

        return response()->json([
            'data' => (new RecruitingSettingsResource($settings, $this->recruitment->companyName($settings)))->resolve($request),
        ]);
    }
}
