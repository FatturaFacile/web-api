using System.Security.Claims;
using Microsoft.AspNetCore.Authentication.Certificate;

namespace Mes.Presentation;

/// <summary>Auth:Mode — which credentials the gateway ingest endpoint accepts.</summary>
public enum GatewayAuthMode
{
    /// <summary>Bearer token only (local/dev default).</summary>
    Jwt,

    /// <summary>Client certificate only (production mTLS).</summary>
    Certificate,

    /// <summary>Either (migration period).</summary>
    Both,
}

/// <summary>One enrolled gateway: the certificate thumbprint it connects with and the tenant it may write for.</summary>
public sealed class GatewayCertificateEntry
{
    public string Thumbprint { get; set; } = "";

    public Guid TenantId { get; set; }

    public string? GatewayId { get; set; }
}

public sealed class CertificateAuthOptions
{
    /// <summary>Enrolled gateways. A valid-but-unlisted certificate is rejected: possession of a CA-signed cert is not enough.</summary>
    public List<GatewayCertificateEntry> Gateways { get; set; } = [];

    /// <summary>Revocation checking (Online | Offline | NoCheck). Use NoCheck only with a private CA that publishes no CRL.</summary>
    public string RevocationMode { get; set; } = "Online";
}

public static class CertificateTenantMap
{
    /// <summary>
    /// Maps a certificate thumbprint to its tenant. The tenant comes ONLY from this server-side allow-list, never from
    /// certificate content that a client could influence beyond what the CA signed, and never from request data.
    /// </summary>
    public static bool TryMap(string? thumbprint, IReadOnlyList<GatewayCertificateEntry> entries, out GatewayCertificateEntry? entry)
    {
        entry = null;
        if (string.IsNullOrWhiteSpace(thumbprint))
        {
            return false;
        }

        var norm = Normalize(thumbprint);
        entry = entries.FirstOrDefault(e => e.TenantId != Guid.Empty && string.Equals(Normalize(e.Thumbprint), norm, StringComparison.OrdinalIgnoreCase));
        return entry is not null;
    }

    private static string Normalize(string t) => t.Replace(":", "", StringComparison.Ordinal).Replace(" ", "", StringComparison.Ordinal);

    internal static Task OnValidated(CertificateValidatedContext ctx, IReadOnlyList<GatewayCertificateEntry> entries)
    {
        if (!TryMap(ctx.ClientCertificate.Thumbprint, entries, out var entry) || entry is null)
        {
            ctx.Fail("Client certificate is valid but not enrolled for any tenant.");
            return Task.CompletedTask;
        }

        ctx.Principal = new ClaimsPrincipal(new ClaimsIdentity(
            [
                new Claim(TenantAccess.ClaimType, entry.TenantId.ToString()),
                new Claim(ClaimTypes.Name, entry.GatewayId ?? ctx.ClientCertificate.Subject),
                new Claim("auth_method", "mtls"),
            ],
            CertificateAuthenticationDefaults.AuthenticationScheme));
        ctx.Success();
        return Task.CompletedTask;
    }
}
