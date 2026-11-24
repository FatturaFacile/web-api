using Opc.Ua;
using Opc.Ua.Configuration;

namespace Mes.Infrastructure.Connectors.OpcUa;

/// <summary>
/// The ONE place that touches the OPC UA SDK's certificate/security-configuration API, which is obsolete in
/// 1.5.378.x and renamed in 2.x. Everything else depends on <see cref="IOpcUaApplicationConfigurationProvider"/>, so an
/// SDK upgrade only has to change this adapter.
/// </summary>
internal static class OpcUaCertificateSetup
{
    internal static ITelemetryContext Telemetry { get; } = DefaultTelemetry.Create(_ => { });

    /// <summary>Builds a client configuration with an application certificate in a directory PKI store under <paramref name="pkiRoot"/>.</summary>
    internal static async Task<ApplicationConfiguration> BuildClientAsync(string applicationName, string applicationUri, string pkiRoot, bool autoAcceptUntrusted, CancellationToken ct)
    {
        var app = new ApplicationInstance(Telemetry) { ApplicationName = applicationName, ApplicationType = ApplicationType.Client };
#pragma warning disable CS0618 // SDK 1.5.378: replaced by the certificate-collection overload in 2.x
        var config = await app.Build(applicationUri, "urn:fatturafacile:mes:edge")
            .AsClient()
            .AddSecurityConfiguration($"CN={applicationName}, O=FatturaFacile", pkiRoot)
#pragma warning restore CS0618
            .SetAutoAcceptUntrustedCertificates(autoAcceptUntrusted)
            .CreateAsync()
            .ConfigureAwait(false);
        await app.CheckApplicationInstanceCertificatesAsync(false, ct: ct).ConfigureAwait(false);
        return config;
    }
}
