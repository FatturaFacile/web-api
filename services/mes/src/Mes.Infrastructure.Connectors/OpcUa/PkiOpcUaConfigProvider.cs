using Opc.Ua;

namespace Mes.Infrastructure.Connectors.OpcUa;

/// <summary>
/// Client configuration with an application certificate in a directory PKI store. Untrusted server certificates are
/// REJECTED by default: an operator must drop the server certificate into <c>{pkiRoot}/trusted/certs</c>.
/// Auto-accept is an explicit opt-in for lab use only. SDK specifics live in <see cref="OpcUaCertificateSetup"/>.
/// </summary>
public sealed class PkiOpcUaConfigProvider(string applicationName, string pkiRoot, bool autoAcceptUntrusted = false) : IOpcUaApplicationConfigurationProvider
{
    public Task<ApplicationConfiguration> GetAsync(CancellationToken ct) =>
        OpcUaCertificateSetup.BuildClientAsync(applicationName, $"urn:{Environment.MachineName}:{applicationName}", pkiRoot, autoAcceptUntrusted, ct);
}
