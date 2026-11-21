using System.Globalization;
using System.Security.Cryptography;
using System.Text;

namespace Mes.Infrastructure.LaravelSync;

public enum SignatureFailure
{
    None,
    Malformed,
    UnknownKey,
    Expired,
    Mismatch,
}

/// <summary>
/// <c>X-Mes-Signature: t=&lt;unix&gt;,kid=&lt;id&gt;,v1=&lt;hex&gt;</c> where <c>v1 = HMAC_SHA256(secret[kid], "{t}.{body}")</c>
/// (lower-case hex). The timestamp bounds replay; <c>kid</c> allows key rotation (both keys valid during the overlap).
/// </summary>
public static class Hmac
{
    public const string Header = "X-Mes-Signature";
    public static readonly TimeSpan DefaultTolerance = TimeSpan.FromMinutes(5);

    public static string Sign(string keyId, byte[] secret, ReadOnlySpan<byte> body, DateTimeOffset now)
    {
        var t = now.ToUnixTimeSeconds().ToString(CultureInfo.InvariantCulture);
        return $"t={t},kid={keyId},v1={Compute(secret, t, body)}";
    }

    public static SignatureFailure Verify(string? header, ReadOnlySpan<byte> body, Func<string, byte[]?> keyLookup, DateTimeOffset now, TimeSpan? tolerance = null)
    {
        if (string.IsNullOrWhiteSpace(header))
        {
            return SignatureFailure.Malformed;
        }

        string? t = null, kid = null, v1 = null;
        foreach (var part in header.Split(','))
        {
            var kv = part.Split('=', 2);
            if (kv.Length != 2)
            {
                return SignatureFailure.Malformed;
            }

            switch (kv[0].Trim())
            {
                case "t": t = kv[1].Trim(); break;
                case "kid": kid = kv[1].Trim(); break;
                case "v1": v1 = kv[1].Trim(); break;
            }
        }

        if (t is null || kid is null || v1 is null || !long.TryParse(t, NumberStyles.None, CultureInfo.InvariantCulture, out var ts))
        {
            return SignatureFailure.Malformed;
        }

        if ((now - DateTimeOffset.FromUnixTimeSeconds(ts)).Duration() > (tolerance ?? DefaultTolerance))
        {
            return SignatureFailure.Expired;
        }

        var secret = keyLookup(kid);
        if (secret is null)
        {
            return SignatureFailure.UnknownKey;
        }

        var expected = Compute(secret, t, body);
        // Constant-time comparison: never short-circuit on the first differing character.
        return CryptographicOperations.FixedTimeEquals(Encoding.ASCII.GetBytes(expected), Encoding.ASCII.GetBytes(v1.ToLowerInvariant()))
            ? SignatureFailure.None
            : SignatureFailure.Mismatch;
    }

    private static string Compute(byte[] secret, string t, ReadOnlySpan<byte> body)
    {
        using var h = new HMACSHA256(secret);
        h.TransformBlock(Encoding.ASCII.GetBytes(t + "."), 0, t.Length + 1, null, 0);
        var buf = body.ToArray();
        h.TransformFinalBlock(buf, 0, buf.Length);
        return Convert.ToHexStringLower(h.Hash!);
    }
}

/// <summary>Signs every outbound request body with the active key. Registered INSIDE the resilience handler so each retry gets a fresh timestamp.</summary>
public sealed class HmacSigningHandler(LaravelSyncOptions options, TimeProvider time) : DelegatingHandler
{
    protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken ct)
    {
        var body = request.Content is null ? [] : await request.Content.ReadAsByteArrayAsync(ct).ConfigureAwait(false);
        request.Headers.Remove(Hmac.Header);
        request.Headers.Add(Hmac.Header, Hmac.Sign(options.KeyId, Encoding.UTF8.GetBytes(options.Secret), body, time.GetUtcNow()));
        return await base.SendAsync(request, ct).ConfigureAwait(false);
    }
}

public sealed class LaravelSyncOptions
{
    public string BaseUrl { get; set; } = "https://erp.example.invalid";

    /// <summary>Key id sent in <c>kid</c>; Laravel looks the secret up by it.</summary>
    public string KeyId { get; set; } = "k1";

    public string Secret { get; set; } = "";

    /// <summary>Keys accepted for INBOUND pushes (kid → secret). Include old + new during rotation.</summary>
    public Dictionary<string, string> InboundKeys { get; set; } = [];

    public int PageSize { get; set; } = 500;

    public List<Guid> Tenants { get; set; } = [];

    public TimeSpan PullInterval { get; set; } = TimeSpan.FromMinutes(5);

    public TimeSpan ReconcileInterval { get; set; } = TimeSpan.FromHours(6);
}
