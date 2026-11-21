using System.Text;
using System.Text.Json;
using Mes.Application;
using Mes.Application.Abstractions;
using Mes.Application.Commands;
using Mes.Application.Contracts;
using Mes.Domain.Common;
using Mes.Domain.State;
using Mes.Infrastructure.LaravelSync;
using Mes.Infrastructure.Persistence;
using Mes.Infrastructure.Persistence.Pipeline;
using Mes.Presentation;
using Microsoft.AspNetCore.Authentication.JwtBearer;
using Microsoft.AspNetCore.Mvc;
using Microsoft.IdentityModel.Tokens;

const string GatewayPolicy = "Gateway";
var builder = WebApplication.CreateBuilder(args);
var cfg = builder.Configuration;

builder.Services.AddMesApplication();
builder.Services.AddMesPersistence(
    cfg.GetConnectionString("Mes") ?? throw new InvalidOperationException("ConnectionStrings:Mes missing"),
    cfg.GetConnectionString("MesSystem") ?? throw new InvalidOperationException("ConnectionStrings:MesSystem missing (BYPASSRLS role for the outbox relay)"),
    cfg.GetConnectionString("Redis") ?? "localhost:6379",
    new PipelineOptions(cfg.GetValue("Mes:Partitions", 8)));
builder.Services.AddLaravelSync(o => cfg.GetSection("LaravelSync").Bind(o));

var gatewayMode = cfg.GetValue("Auth:Mode", GatewayAuthMode.Jwt);
var certOptions = cfg.GetSection("Auth:Certificate").Get<CertificateAuthOptions>() ?? new CertificateAuthOptions();
if (gatewayMode != GatewayAuthMode.Jwt && certOptions.Gateways.Count == 0)
{
    throw new InvalidOperationException("Auth:Mode requires client certificates but Auth:Certificate:Gateways is empty: no gateway could ever authenticate.");
}

var authBuilder = builder.Services.AddAuthentication(JwtBearerDefaults.AuthenticationScheme);
if (gatewayMode != GatewayAuthMode.Jwt)
{
    authBuilder.AddCertificate(Microsoft.AspNetCore.Authentication.Certificate.CertificateAuthenticationDefaults.AuthenticationScheme, o =>
    {
        o.AllowedCertificateTypes = Microsoft.AspNetCore.Authentication.Certificate.CertificateTypes.Chained;
        o.RevocationMode = Enum.Parse<System.Security.Cryptography.X509Certificates.X509RevocationMode>(certOptions.RevocationMode, true);
        o.Events = new Microsoft.AspNetCore.Authentication.Certificate.CertificateAuthenticationEvents
        {
            OnCertificateValidated = ctx => CertificateTenantMap.OnValidated(ctx, certOptions.Gateways),
        };
    });
}

authBuilder.AddJwtBearer(o =>
{
    o.Authority = cfg["Auth:Authority"];
    o.Audience = cfg["Auth:Audience"];
    if (cfg["Auth:DevSigningKey"] is { Length: >= 32 } dev)
    {
        o.TokenValidationParameters.IssuerSigningKey = new SymmetricSecurityKey(Encoding.UTF8.GetBytes(dev)); // local dev only
        o.TokenValidationParameters.ValidateIssuer = false;
    }

    // SignalR sends the token in the query string for WebSockets.
    o.Events = new JwtBearerEvents
    {
        OnMessageReceived = ctx =>
        {
            if (ctx.Request.Path.StartsWithSegments("/hubs", StringComparison.Ordinal) && ctx.Request.Query["access_token"] is { Count: > 0 } t)
            {
                ctx.Token = t;
            }

            return Task.CompletedTask;
        },
    };
});
builder.Services.AddAuthorization(o =>
{
    // The gateway ingest endpoint accepts the schemes selected by Auth:Mode; everything else stays on JWT (UI users).
    var schemes = gatewayMode switch
    {
        GatewayAuthMode.Certificate => new[] { Microsoft.AspNetCore.Authentication.Certificate.CertificateAuthenticationDefaults.AuthenticationScheme },
        GatewayAuthMode.Both => [Microsoft.AspNetCore.Authentication.Certificate.CertificateAuthenticationDefaults.AuthenticationScheme, JwtBearerDefaults.AuthenticationScheme],
        _ => [JwtBearerDefaults.AuthenticationScheme],
    };
    o.AddPolicy(GatewayPolicy, p => p.AddAuthenticationSchemes(schemes).RequireAuthenticatedUser().RequireClaim(TenantAccess.ClaimType));
});
builder.Services.AddSignalR();
builder.Services.AddHostedService<OeeBroadcaster>();
builder.Services.AddHealthChecks();

var app = builder.Build();

if (cfg.GetValue("Mes:MigrateOnStartup", false))
{
    await app.Services.GetRequiredService<SchemaMigrator>().MigrateAsync(CancellationToken.None);
}

app.UseAuthentication();
app.UseAuthorization();

// NTP-style clock probe support: every response carries the server's receive and send instants (Unix ms).
app.Use(async (ctx, next) =>
{
    var rx = DateTimeOffset.UtcNow.ToUnixTimeMilliseconds();
    ctx.Response.OnStarting(() =>
    {
        ctx.Response.Headers["X-Mes-Rx-Ms"] = rx.ToString(System.Globalization.CultureInfo.InvariantCulture);
        ctx.Response.Headers["X-Mes-Tx-Ms"] = DateTimeOffset.UtcNow.ToUnixTimeMilliseconds().ToString(System.Globalization.CultureInfo.InvariantCulture);
        return Task.CompletedTask;
    });
    await next();
});

app.MapHealthChecks("/healthz");
app.MapHub<LineHub>("/hubs/line");

var api = app.MapGroup("/api/v1/mes");

// ── edge → core ────────────────────────────────────────────────────────────────
api.MapPost("/ingest/batches", async (WireBatch batch, HttpContext ctx, IngestService ingest, CancellationToken ct) =>
{
    if (!TenantAccess.TryGet(ctx.User, out var tenant))
    {
        return Results.Forbid();
    }

    try
    {
        return Results.Ok(await ingest.IngestAsync(tenant, batch, ct));
    }
    catch (UnauthorizedAccessException)
    {
        return Results.Forbid();
    }
}).RequireAuthorization(GatewayPolicy);

// ── Laravel → core push notifications (HMAC, not JWT) ───────────────────────────
api.MapPost("/ref-events", async (HttpRequest req, IManufacturingReferenceSync sync, Microsoft.Extensions.Options.IOptions<LaravelSyncOptions> opts, TimeProvider time, CancellationToken ct) =>
{
    using var ms = new MemoryStream();
    await req.Body.CopyToAsync(ms, ct); // body must be the exact bytes that were signed
    var body = ms.ToArray();

    var keys = opts.Value.InboundKeys;
    var failure = Hmac.Verify(req.Headers[Hmac.Header], body, kid => keys.TryGetValue(kid, out var s) ? Encoding.UTF8.GetBytes(s) : null, time.GetUtcNow());
    if (failure != SignatureFailure.None)
    {
        return Results.Json(new { error = "invalid_signature", reason = failure.ToString() }, statusCode: StatusCodes.Status401Unauthorized);
    }

    RefEventDto? dto;
    try
    {
        dto = JsonSerializer.Deserialize<RefEventDto>(body, new JsonSerializerOptions(JsonSerializerDefaults.Web));
    }
    catch (JsonException)
    {
        return Results.BadRequest(new { error = "invalid_json" });
    }

    if (dto is null || dto.TenantId == Guid.Empty)
    {
        return Results.BadRequest(new { error = "invalid_event" });
    }

    // Tenant-scope check: only tenants this deployment serves, and the header (if present) must agree with the body.
    if (!opts.Value.Tenants.Contains(dto.TenantId) || (req.Headers.TryGetValue("X-Tenant-Id", out var h) && !string.Equals(h, dto.TenantId.ToString(), StringComparison.OrdinalIgnoreCase)))
    {
        return Results.Json(new { error = "tenant_not_allowed" }, statusCode: StatusCodes.Status403Forbidden);
    }

    await sync.ApplyPushAsync(
        new RefEvent(new TenantId(dto.TenantId), RefResourceExtensions.FromSlug(dto.Resource), dto.Id, dto.Seq, dto.Revision, dto.Deleted, dto.Payload?.GetRawText()),
        ct);
    return Results.Accepted();
}).AllowAnonymous();

// ── commands & queries ──────────────────────────────────────────────────────────
api.MapPost("/commands/classify-downtime", async ([FromBody] ClassifyDowntimeRequest r, HttpContext ctx, ClassifyDowntimeHandler handler, CancellationToken ct) =>
{
    if (!TenantAccess.TryGet(ctx.User, out var tenant))
    {
        return Results.Forbid();
    }

    if (r.To <= r.From || string.IsNullOrWhiteSpace(r.ReasonCode))
    {
        return Results.UnprocessableEntity(new { error = "invalid_window_or_reason" });
    }

    try
    {
        await handler.HandleAsync(
            new ClassifyDowntime(tenant, new MachineId(r.MachineId), new Interval(r.From, r.To), new ReasonCode(r.ReasonCode), r.ReclassifyAs, ctx.User.Identity?.Name),
            ct);
        return Results.NoContent();
    }
    catch (MesNotFoundException e)
    {
        return Results.NotFound(new { error = e.Message });
    }
    catch (MesValidationException e)
    {
        return Results.UnprocessableEntity(new { error = e.Message });
    }
}).RequireAuthorization();

api.MapGet("/oee/live/{machineId:guid}", async (Guid machineId, string? scope, HttpContext ctx, IOeeLiveStore live, CancellationToken ct) =>
{
    if (!TenantAccess.TryGet(ctx.User, out var tenant))
    {
        return Results.Forbid();
    }

    var view = await live.GetAsync(tenant, new MachineId(machineId), scope ?? "shift", ct);
    return view is null ? Results.NotFound() : Results.Ok(LiveViewDto.From(view));
}).RequireAuthorization();

app.Run();

namespace Mes.Presentation
{
    public sealed record RefEventDto(Guid TenantId, string Resource, Guid Id, long Seq, long Revision, bool Deleted, JsonElement? Payload);

    public sealed record ClassifyDowntimeRequest(Guid MachineId, DateTimeOffset From, DateTimeOffset To, string ReasonCode, MachineState? ReclassifyAs);

    public partial class Program;
}
