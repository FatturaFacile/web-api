<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Modules\ElectronicSignature\Enums\EnvelopeStatus;
use Modules\ElectronicSignature\Exceptions\SignatureException;
use Modules\ElectronicSignature\Http\Requests\SaveProviderConfigRequest;
use Modules\ElectronicSignature\Http\Resources\ProviderConfigResource;
use Modules\ElectronicSignature\Models\SignatureEnvelope;
use Modules\ElectronicSignature\Models\SignatureProviderConfig;
use Modules\ElectronicSignature\Services\EnvelopeStorage;
use Modules\ElectronicSignature\Services\PlatformSealService;
use Modules\ElectronicSignature\Services\ProviderRegistry;
use OpenApi\Attributes as OA;

/**
 * Settings of the signature providers (Swisscom Trust Services, InfoCert, Namirial and the built-in engine): credentials
 * stored encrypted per company, the PKCS#12 certificate of the native seal, a default, and a connection test.
 */
final class ProviderConfigController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly PlatformSealService $seal,
        private readonly EnvelopeStorage $storage,
        private readonly TenantContext $tenantContext,
    ) {}

    #[OA\Get(path: '/api/v1/signatures/providers', operationId: 'signaturesListProviders', summary: 'List the signature provider configurations', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Electronic Signatures'], responses: [new OA\Response(response: 200, description: 'Provider configurations')])]
    public function index(): JsonResponse
    {
        Gate::authorize('signatures.configure');

        return $this->successResponse(ProviderConfigResource::collection(SignatureProviderConfig::query()->orderByDesc('is_default')->orderBy('name')->get())->resolve());
    }

    #[OA\Post(path: '/api/v1/signatures/providers', operationId: 'signaturesCreateProvider', summary: 'Create a signature provider configuration', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Electronic Signatures'], responses: [new OA\Response(response: 201, description: 'Provider configuration created')])]
    public function store(SaveProviderConfigRequest $request): JsonResponse
    {
        $config = DB::transaction(function () use ($request): SignatureProviderConfig {
            $config = new SignatureProviderConfig;
            $this->fill($config, $request);
            $config->save();
            $this->settleDefault($config);

            return $config;
        });

        return $this->successResponse(new ProviderConfigResource($config), 'signatures.message.saved', 201);
    }

    #[OA\Put(path: '/api/v1/signatures/providers/{id}', operationId: 'signaturesUpdateProvider', summary: 'Update a signature provider configuration', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Electronic Signatures'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Provider configuration updated')])]
    public function update(SaveProviderConfigRequest $request, string $id): JsonResponse
    {
        $config = SignatureProviderConfig::query()->findOrFail($id);

        DB::transaction(function () use ($config, $request): void {
            $this->fill($config, $request);
            $config->save();
            $this->settleDefault($config);
        });

        return $this->successResponse(new ProviderConfigResource($config->refresh()), 'signatures.message.saved');
    }

    #[OA\Delete(path: '/api/v1/signatures/providers/{id}', operationId: 'signaturesDeleteProvider', summary: 'Delete a signature provider configuration', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Electronic Signatures'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Provider configuration deleted')])]
    public function destroy(string $id): JsonResponse
    {
        Gate::authorize('signatures.configure');
        $config = SignatureProviderConfig::query()->findOrFail($id);
        $inUse = SignatureEnvelope::query()->where('provider_config_id', $config->id)->whereIn('status', [EnvelopeStatus::Draft->value, EnvelopeStatus::Pending->value, EnvelopeStatus::PartiallySigned->value])->exists();

        if ($inUse) {
            throw new SignatureException('provider_in_use', 409);
        }

        $this->storage->delete($config->certificate_path);
        $config->delete();

        return $this->successResponse(null, 'signatures.message.deleted');
    }

    #[OA\Post(path: '/api/v1/signatures/providers/{id}/test', operationId: 'signaturesTestProvider', summary: 'Test the connection of a provider configuration', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Electronic Signatures'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Test result')])]
    public function test(string $id): JsonResponse
    {
        Gate::authorize('signatures.configure');
        $config = SignatureProviderConfig::query()->findOrFail($id);
        $result = $this->providers->provider($config->provider)->testConnection($config);

        $config->forceFill([
            'last_tested_at' => now(),
            'last_test_status' => $result->ok ? 'ok' : 'failed',
            'last_test_message' => $result->code,
        ])->save();

        return $this->successResponse($result->toArray() + ['config' => new ProviderConfigResource($config)], $result->ok ? 'signatures.message.testOk' : 'signatures.message.testFailed');
    }

    private function fill(SignatureProviderConfig $config, SaveProviderConfigRequest $request): void
    {
        $data = $request->validated();

        if (! $config->exists) {
            $config->provider = $data['provider'];
        }

        $config->jurisdiction = $data['jurisdiction'];
        $config->name = $data['name'];
        $config->is_sandbox = (bool) $data['is_sandbox'];
        $config->is_active = (bool) $data['is_active'];
        $config->is_default = (bool) $data['is_default'];
        $config->settings = $data['settings'] ?? $config->settings;

        // A credential left out (or empty) keeps its stored value; the values themselves are never sent back to the client.
        $credentials = $config->credentials ?? [];

        foreach ((array) ($data['credentials'] ?? []) as $key => $value) {
            if (is_string($value) && $value !== '') {
                $credentials[$key] = $value;
            }
        }

        $file = $request->file('certificate');

        if ($file instanceof UploadedFile) {
            $contents = (string) file_get_contents((string) $file->getRealPath());
            $this->seal->assertReadable($contents, (string) $data['certificate_password']);
            $this->storage->delete($config->certificate_path);
            $path = 'signatures/'.$this->tenantContext->getTenantId().'/certificates/'.Str::uuid().'.p12';
            $this->storage->put($path, $contents);
            $config->certificate_path = $path;
            $credentials['certificate_password'] = (string) $data['certificate_password'];
        }

        $config->credentials = $credentials === [] ? null : $credentials;
    }

    /**
     * Only one configuration is the default.
     */
    private function settleDefault(SignatureProviderConfig $config): void
    {
        if ($config->is_default) {
            SignatureProviderConfig::query()->where('id', '!=', $config->id)->where('is_default', true)->update(['is_default' => false]);
        }
    }
}
