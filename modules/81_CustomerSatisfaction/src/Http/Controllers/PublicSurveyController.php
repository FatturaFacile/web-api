<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\CustomerSatisfaction\Domain\Enums\SubmittedVia;
use Modules\CustomerSatisfaction\Domain\Support\SurveyLocale;
use Modules\CustomerSatisfaction\Exceptions\SatisfactionException;
use Modules\CustomerSatisfaction\Http\Requests\NoInputRequest;
use Modules\CustomerSatisfaction\Http\Requests\SubmitFeedbackRequest;
use Modules\CustomerSatisfaction\Http\Requests\SubmitScoreRequest;
use Modules\CustomerSatisfaction\Http\Resources\SurveyResponseResource;
use Modules\CustomerSatisfaction\Models\SurveyResponse;
use Modules\CustomerSatisfaction\Services\SurveyLinkBuilder;
use Modules\CustomerSatisfaction\Services\SurveyResponseService;
use OpenApi\Attributes as OA;

/**
 * Public, login-free side of a survey. The 48 character token of the dispatch is the only credential: the one-click score link of
 * an email or SMS, the WhatsApp interactive reply, the mobile landing page and its text / voice follow-up all address the same answer.
 */
final class PublicSurveyController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly SurveyResponseService $surveys, private readonly SurveyLinkBuilder $links) {}

    #[OA\Get(path: '/api/v1/public/surveys/{token}', operationId: 'customerSatisfactionPublicShowSurvey', summary: 'Landing page data of a survey: scale, localized follow-up prompts and the score already given', tags: ['Customer Satisfaction Public'], parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'Survey state')])]
    public function show(string $token): JsonResponse
    {
        $dispatch = $this->surveys->resolve($token);
        $template = $dispatch->template;
        $response = SurveyResponse::query()->where('survey_dispatch_id', $dispatch->id)->first();

        $prompts = [];
        foreach (['low', 'mid', 'high'] as $key) {
            /** @var string|null $prompt */
            $prompt = SurveyLocale::pick($template->follow_up_config[$key] ?? null, $dispatch->locale);
            $prompts[$key] = $prompt;
        }

        return $this->successResponse([
            'survey_type' => $template->survey_type->value,
            'scale' => ['min' => $template->survey_type->min(), 'max' => $template->survey_type->max()],
            'locale' => $dispatch->locale,
            'answered' => $response !== null,
            'score' => $response?->score,
            'band' => $response?->normalized_band->value,
            'prompt' => $response === null ? null : $prompts[$response->normalized_band->promptKey()],
            'prompts' => $prompts,
            'allow_voice_comment' => $template->allow_voice_comment,
            'has_comment' => $response !== null && ($response->comment_text !== null || $response->voice_attachment_path !== null),
            'voice_max_kb' => (int) config('customer_satisfaction.voice_max_kb'),
        ], __('customer_satisfaction.messages.loaded'));
    }

    /**
     * The click on a score of an email or SMS: the answer is stored at once and the browser continues on the landing page.
     */
    #[OA\Get(path: '/api/v1/public/surveys/{token}/rate/{score}', operationId: 'customerSatisfactionPublicOneClick', summary: 'One-click score capture: stores the answer and redirects to the mobile landing page', tags: ['Customer Satisfaction Public'], parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'score', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 302, description: 'Redirect to the landing page'), new OA\Response(response: 200, description: 'Answer stored (JSON clients)')])]
    public function rate(Request $request, string $token, int $score): JsonResponse|RedirectResponse
    {
        try {
            [$response] = $this->surveys->capture($token, $score, SubmittedVia::OneClickLink, $this->metadata($request));
        } catch (SatisfactionException $e) {
            if ($request->expectsJson()) {
                throw $e;
            }

            return redirect()->away($this->links->landingUrl($token).'?error='.$e->errorCode());
        }

        if ($request->expectsJson()) {
            return $this->successResponse(new SurveyResponseResource($response), __('customer_satisfaction.messages.score_recorded'));
        }

        return redirect()->away($this->links->landingUrl($token));
    }

    #[OA\Post(path: '/api/v1/public/surveys/{token}/respond', operationId: 'customerSatisfactionPublicRespond', summary: 'Record the score from the landing page', tags: ['Customer Satisfaction Public'], parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'Answer stored (or the one already stored)')])]
    public function respond(SubmitScoreRequest $request, string $token): JsonResponse
    {
        [$response, $created] = $this->surveys->capture(
            $token,
            (int) $request->validated('score'),
            SubmittedVia::from((string) ($request->validated('submitted_via') ?? SubmittedVia::LandingPage->value)),
            $this->metadata($request),
        );

        return $this->successResponse(new SurveyResponseResource($response), __('customer_satisfaction.messages.score_recorded'), $created ? 201 : 200);
    }

    #[OA\Post(path: '/api/v1/public/surveys/{token}/whatsapp-reply', operationId: 'customerSatisfactionPublicWhatsappReply', summary: 'Score of a WhatsApp interactive reply, forwarded by the provider webhook', tags: ['Customer Satisfaction Public'], parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'Answer stored (or the one already stored)')])]
    public function whatsappReply(SubmitScoreRequest $request, string $token): JsonResponse
    {
        [$response, $created] = $this->surveys->capture($token, (int) $request->validated('score'), SubmittedVia::WhatsappReply, $this->metadata($request));

        return $this->successResponse(new SurveyResponseResource($response), __('customer_satisfaction.messages.score_recorded'), $created ? 201 : 200);
    }

    #[OA\Post(path: '/api/v1/public/surveys/{token}/feedback', operationId: 'customerSatisfactionPublicFeedback', summary: 'Add the text or voice comment to the answer already recorded', tags: ['Customer Satisfaction Public'], parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'Answer enriched')])]
    public function feedback(SubmitFeedbackRequest $request, string $token): JsonResponse
    {
        $response = $this->surveys->enrich(
            $token,
            $request->validated('comment_text'),
            $request->file('voice'),
            $request->validated('voice_duration_seconds') === null ? null : (int) $request->validated('voice_duration_seconds'),
            $request->validated('voice_transcript'),
        );

        return $this->successResponse(new SurveyResponseResource($response), __('customer_satisfaction.messages.feedback_recorded'));
    }

    #[OA\Post(path: '/api/v1/public/surveys/{token}/opt-out', operationId: 'customerSatisfactionPublicOptOut', summary: 'Stop receiving satisfaction surveys', tags: ['Customer Satisfaction Public'], parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'Opted out')])]
    public function optOut(NoInputRequest $request, string $token): JsonResponse
    {
        $this->surveys->optOut($token);

        return $this->successResponse(null, __('customer_satisfaction.messages.opted_out'));
    }

    /**
     * @return array<string, string|null>
     */
    private function metadata(Request $request): array
    {
        return ['ip' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 255)];
    }
}
