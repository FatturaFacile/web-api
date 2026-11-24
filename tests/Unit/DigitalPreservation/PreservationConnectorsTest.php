<?php

declare(strict_types=1);

use App\Contracts\Security\HostResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Modules\DigitalPreservation\Domain\Enums\BatchStatus;
use Modules\DigitalPreservation\Domain\Enums\DocumentCategory;
use Modules\DigitalPreservation\Domain\Enums\JurisdictionMode;
use Modules\DigitalPreservation\Domain\Enums\PreservationProvider;
use Modules\DigitalPreservation\Domain\Exceptions\ConnectorException;
use Modules\DigitalPreservation\Infrastructure\Connectors\ArubaPecConnector;
use Modules\DigitalPreservation\Infrastructure\Connectors\InfoCertConnector;
use Modules\DigitalPreservation\Infrastructure\Connectors\LocalWormVaultConnector;
use Modules\DigitalPreservation\Infrastructure\Connectors\NamirialConnector;
use Modules\DigitalPreservation\Infrastructure\Connectors\PreservationConnectorManager;
use Modules\DigitalPreservation\Infrastructure\Connectors\ZucchettiConnector;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationBatch;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationConfiguration;
use Tests\Support\StaticHostResolver;

function connectorBatch(): PreservationBatch
{
    return new PreservationBatch(['batch_number' => '2026-000001', 'jurisdiction' => JurisdictionMode::It, 'status' => BatchStatus::Timestamped, 'document_category' => DocumentCategory::ActiveInvoices]);
}

function connectorConfig(PreservationProvider $provider, array $credentials): PreservationConfiguration
{
    return new PreservationConfiguration(['provider' => $provider, 'credentials' => $credentials]);
}

beforeEach(function (): void {
    app()->instance(HostResolver::class, new StaticHostResolver);
    Http::preventStrayRequests();
    config(['digital_preservation.connector_retry_wait_ms' => 1]);
});

dataset('conservators', [
    'aruba' => [ArubaPecConnector::class, PreservationProvider::ArubaPec, ['api_key' => 'k', 'customer_code' => 'c'], 'api.conservazione.aruba.it/v1/deposits', ['depositId' => 'AR-1', 'receiptXml' => '<RdV>aruba</RdV>'], 'X-Api-Key', 'k', '<RdV>aruba</RdV>'],
    'infocert' => [InfoCertConnector::class, PreservationProvider::InfoCert, ['api_key' => 'tok'], 'legaldoc.infocert.it/api/archive/packages', ['packageId' => 'IC-1', 'rdv' => '<RdV>ic</RdV>'], 'Authorization', 'Bearer tok', '<RdV>ic</RdV>'],
    'namirial' => [NamirialConnector::class, PreservationProvider::Namirial, ['api_key' => 'sub'], 'archive.namirial.com/api/v1/submissions', ['submissionId' => 'NA-1', 'receipt' => '<RdV>na</RdV>'], 'Ocp-Apim-Subscription-Key', 'sub', '<RdV>na</RdV>'],
    'zucchetti' => [ZucchettiConnector::class, PreservationProvider::Zucchetti, ['username' => 'u', 'api_key' => 'p'], 'digitalhub.zucchetti.it/api/conservation/volumes', ['volumeId' => 'ZU-1', 'confirmed' => true], 'Authorization', 'Basic '.base64_encode('u:p'), null],
]);

test('each accredited conservator adapter authenticates, sends the package and returns the receipt', function (string $class, PreservationProvider $provider, array $credentials, string $url, array $response, string $header, string $headerValue, ?string $rdv): void {
    Http::fake(['*' => Http::response($response, 200)]);
    $connector = app($class);

    $receipt = $connector->dispatch(connectorBatch(), '<pdv/>', ['documents/a.xml' => '<a/>'], connectorConfig($provider, $credentials));

    expect($connector->provider())->toBe($provider)
        ->and($receipt->externalId)->toBe(array_values($response)[0])
        ->and($receipt->rdvXml)->toContain($rdv ?? $provider->pdvCode());

    Http::assertSent(function (Request $request) use ($url, $header, $headerValue): bool {
        $body = json_encode($request->data());

        return str_contains($request->url(), $url) && $request->method() === 'POST' && $request->header($header)[0] === $headerValue
            && str_contains($body, hash('sha256', '<pdv/>')) && str_contains($body, hash('sha256', '<a/>'));
    });
})->with('conservators');

test('a conservator error is mapped to a structured connector exception', function (string $class, PreservationProvider $provider, array $credentials): void {
    Http::fake(['*' => Http::sequence()->push(['message' => 'bad key'], 401)->push(['message' => 'dup'], 409)->push('', 200)]);

    expect(fn () => app($class)->dispatch(connectorBatch(), '<pdv/>', [], connectorConfig($provider, $credentials)))
        ->toThrow(ConnectorException::class, 'authentication refused (HTTP 401): bad key');

    expect(fn () => app($class)->dispatch(connectorBatch(), '<pdv/>', [], connectorConfig($provider, $credentials)))->toThrow(ConnectorException::class, 'duplicate package');

    expect(fn () => app($class)->dispatch(connectorBatch(), '<pdv/>', [], connectorConfig($provider, $credentials)))->toThrow(ConnectorException::class, 'no document reference');
})->with([
    'aruba' => [ArubaPecConnector::class, PreservationProvider::ArubaPec, ['api_key' => 'k']],
    'infocert' => [InfoCertConnector::class, PreservationProvider::InfoCert, ['api_key' => 'k']],
    'namirial' => [NamirialConnector::class, PreservationProvider::Namirial, ['api_key' => 'k']],
    'zucchetti' => [ZucchettiConnector::class, PreservationProvider::Zucchetti, ['username' => 'u', 'api_key' => 'k']],
]);

test('a conservator without credentials or on a private address is refused before any request', function (): void {
    $connector = app(ArubaPecConnector::class);

    expect(fn () => $connector->dispatch(connectorBatch(), '<pdv/>', [], connectorConfig(PreservationProvider::ArubaPec, [])))->toThrow(ConnectorException::class, 'no credentials')
        ->and(fn () => $connector->dispatch(connectorBatch(), '<pdv/>', [], connectorConfig(PreservationProvider::ArubaPec, ['api_key' => 'k', 'base_url' => 'https://10.0.0.5'])))->toThrow(ConnectorException::class, 'not an allowed https address')
        ->and(fn () => $connector->dispatch(connectorBatch(), '<pdv/>', [], connectorConfig(PreservationProvider::ArubaPec, ['api_key' => 'k', 'base_url' => 'http://api.example.org'])))->toThrow(ConnectorException::class);

    Http::assertNothingSent();
});

test('the connection test reports reachability and the provider paths can be overridden per tenant', function (): void {
    Http::fake(['custom.example.org/*' => Http::response(['ok' => true]), '*' => Http::response('', 500)]);
    $connector = app(NamirialConnector::class);

    expect($connector->testConnection(connectorConfig(PreservationProvider::Namirial, ['api_key' => 'k', 'base_url' => 'https://custom.example.org/v2', 'paths' => ['/health' => '/ping']])))->toBeTrue()
        ->and($connector->testConnection(connectorConfig(PreservationProvider::Namirial, ['api_key' => 'k'])))->toBeFalse()
        ->and($connector->testConnection(connectorConfig(PreservationProvider::Namirial, [])))->toBeFalse();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://custom.example.org/v2/ping' && $request->method() === 'GET');
});

test('the local WORM connector issues a receipt without any network call and the manager resolves every provider', function (): void {
    Storage::fake('preservation_worm');
    Storage::disk('preservation_worm')->put('documents/a.xml', '<a/>');
    $connector = app(LocalWormVaultConnector::class);

    $receipt = $connector->dispatch(connectorBatch(), '<pdv/>', ['documents/a.xml' => '<a/>', 'documents/missing.xml' => 'x'], connectorConfig(PreservationProvider::LocalWorm, []));

    expect($receipt->externalId)->toBe('LOCAL-2026-000001')->and($receipt->confirmed)->toBeTrue()
        ->and($receipt->rdvXml)->toContain('<DocumentiAcquisiti>1</DocumentiAcquisiti>')->toContain(hash('sha256', '<pdv/>'))
        ->and($connector->testConnection(connectorConfig(PreservationProvider::LocalWorm, [])))->toBeTrue();

    $manager = app(PreservationConnectorManager::class);
    expect($manager->for(PreservationProvider::LocalWorm))->toBeInstanceOf(LocalWormVaultConnector::class)
        ->and($manager->for(PreservationProvider::ArubaPec))->toBeInstanceOf(ArubaPecConnector::class)
        ->and($manager->for(PreservationProvider::InfoCert))->toBeInstanceOf(InfoCertConnector::class)
        ->and($manager->for(PreservationProvider::Namirial))->toBeInstanceOf(NamirialConnector::class)
        ->and($manager->for(PreservationProvider::Zucchetti))->toBeInstanceOf(ZucchettiConnector::class);
    Http::assertNothingSent();
});
