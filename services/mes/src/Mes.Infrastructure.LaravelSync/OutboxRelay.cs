using System.Net;
using System.Net.Http.Json;
using System.Text.Json;
using System.Text.Json.Serialization;
using Mes.Application.Abstractions;
using Mes.Domain.Common;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;

namespace Mes.Infrastructure.LaravelSync;

public sealed class OutboxRelayOptions
{
    public int BatchSize { get; set; } = 50;

    public TimeSpan Lease { get; set; } = TimeSpan.FromMinutes(2);

    public TimeSpan IdlePoll { get; set; } = TimeSpan.FromSeconds(2);

    /// <summary>Dead-letter when the claim count reaches this value: 1 first try + 11 retries = 12 attempts.</summary>
    public int MaxAttempts { get; set; } = 12;

    public TimeSpan BackoffBase { get; set; } = TimeSpan.FromSeconds(5);

    public TimeSpan BackoffCap { get; set; } = TimeSpan.FromMinutes(15);
}

public static class RetryPolicy
{
    /// <summary>Exponential backoff with full jitter: uniform in [0, min(cap, base * 2^(attempt-1))], at least 1 s.</summary>
    public static TimeSpan Delay(int attempt, TimeSpan @base, TimeSpan cap, Random rng)
    {
        var ceiling = Math.Min(cap.TotalSeconds, @base.TotalSeconds * Math.Pow(2, Math.Min(Math.Max(attempt - 1, 0), 20)));
        return TimeSpan.FromSeconds(Math.Max(1, rng.NextDouble() * ceiling));
    }
}

internal sealed record RelayResult(
    [property: JsonPropertyName("id")] Guid Id,
    [property: JsonPropertyName("status")] string Status,
    [property: JsonPropertyName("message")] string? Message);

internal sealed record RelayResponse([property: JsonPropertyName("results")] List<RelayResult> Results);

/// <summary>
/// Drains <c>mes_outbox</c> to Laravel (<c>POST /api/v1/manufacturing/mes-events</c>), one request per tenant batch.
/// Response contract: <c>207 Multi-Status</c> with a per-event status:
/// <c>applied</c> | <c>duplicate</c> | <c>stale</c> → done (stale: Laravel already holds a newer snapshot);
/// <c>rejected</c> → permanent validation failure, dead-lettered at once (retrying cannot fix it).
/// Anything else (timeout, 5xx, 429) → every event of the request is rescheduled with exponential backoff + full jitter,
/// and dead-lettered once <see cref="OutboxRelayOptions.MaxAttempts"/> claims have been used.
/// Several relay instances may run: the claim uses <c>FOR UPDATE SKIP LOCKED</c> plus a lease (see <c>PgOutboxStore</c>).
/// </summary>
public sealed class OutboxRelay(
    IOutboxStore store,
    HttpClient http,
    IOptions<OutboxRelayOptions> options,
    ILogger<OutboxRelay> logger) : BackgroundService
{
    private readonly Random _rng = new();

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        var o = options.Value;
        while (!stoppingToken.IsCancellationRequested)
        {
            try
            {
                var claimed = await store.ClaimAsync(o.BatchSize, o.Lease, stoppingToken).ConfigureAwait(false);
                if (claimed.Count == 0)
                {
                    await Task.Delay(o.IdlePoll, stoppingToken).ConfigureAwait(false);
                    continue;
                }

                foreach (var tenantGroup in claimed.GroupBy(c => c.Message.TenantId))
                {
                    await DeliverAsync(tenantGroup.Key, tenantGroup.ToList(), o, stoppingToken).ConfigureAwait(false);
                }
            }
            catch (OperationCanceledException) when (stoppingToken.IsCancellationRequested)
            {
                break;
            }
            catch (Exception ex)
            {
                logger.LogError(ex, "Outbox relay loop error");
                await Task.Delay(TimeSpan.FromSeconds(5), stoppingToken).ConfigureAwait(false);
            }
        }
    }

    internal async Task DeliverAsync(TenantId tenant, IReadOnlyList<ClaimedOutbox> items, OutboxRelayOptions o, CancellationToken ct)
    {
        try
        {
            var body = new
            {
                events = items.Select(i => new
                {
                    id = i.Message.Id,
                    type = i.Message.Type,
                    dedupe_key = i.Message.DedupeKey,
                    occurred_at = i.Message.CreatedAt,
                    payload = JsonDocument.Parse(i.Message.PayloadJson).RootElement,
                }),
            };
            using var req = new HttpRequestMessage(HttpMethod.Post, "/api/v1/manufacturing/mes-events") { Content = JsonContent.Create(body) };
            req.Headers.Add("X-Tenant-Id", tenant.ToString());
            using var resp = await http.SendAsync(req, ct).ConfigureAwait(false);

            if (resp.StatusCode != HttpStatusCode.MultiStatus && !resp.IsSuccessStatusCode)
            {
                throw new HttpRequestException($"Laravel answered {(int)resp.StatusCode}", null, resp.StatusCode);
            }

            var parsed = await resp.Content.ReadFromJsonAsync<RelayResponse>(ct).ConfigureAwait(false);
            var byId = parsed?.Results.ToDictionary(r => r.Id) ?? [];

            foreach (var item in items)
            {
                if (!byId.TryGetValue(item.Message.Id, out var r))
                {
                    await FailAsync(item, "event missing from 207 response", o, ct).ConfigureAwait(false);
                    continue;
                }

                switch (r.Status)
                {
                    case "applied" or "duplicate" or "stale":
                        await store.CompleteAsync(item.Message.Id, r.Status, ct).ConfigureAwait(false);
                        break;
                    case "rejected":
                        logger.LogError("Outbox event {Id} ({Type}) rejected by Laravel: {Message}", item.Message.Id, item.Message.Type, r.Message);
                        await store.DeadLetterAsync(item.Message.Id, "rejected: " + r.Message, ct).ConfigureAwait(false);
                        break;
                    default:
                        await FailAsync(item, $"unknown status '{r.Status}'", o, ct).ConfigureAwait(false);
                        break;
                }
            }
        }
        catch (Exception ex) when (ex is HttpRequestException or TaskCanceledException or JsonException && !ct.IsCancellationRequested)
        {
            logger.LogWarning(ex, "Outbox delivery failed for tenant {Tenant} ({Count} events)", tenant, items.Count);
            foreach (var item in items)
            {
                await FailAsync(item, ex.Message, o, ct).ConfigureAwait(false);
            }
        }
    }

    private async Task FailAsync(ClaimedOutbox item, string error, OutboxRelayOptions o, CancellationToken ct)
    {
        if (item.Attempts >= o.MaxAttempts)
        {
            logger.LogError("Outbox event {Id} dead-lettered after {Attempts} attempts: {Error}", item.Message.Id, item.Attempts, error);
            await store.DeadLetterAsync(item.Message.Id, $"max attempts: {error}", ct).ConfigureAwait(false);
            return;
        }

        var delay = RetryPolicy.Delay(item.Attempts, o.BackoffBase, o.BackoffCap, _rng);
        await store.RescheduleAsync(item.Message.Id, DateTimeOffset.UtcNow + delay, error, ct).ConfigureAwait(false);
    }
}

/// <summary>Periodic pull for every configured tenant + resource, and a slower reconcile pass to repair drift.</summary>
public sealed class ReferenceSyncWorker(
    IManufacturingReferenceSync sync,
    IOptions<LaravelSyncOptions> options,
    ILogger<ReferenceSyncWorker> logger) : BackgroundService
{
    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        var o = options.Value;
        var nextReconcile = DateTimeOffset.UtcNow;
        while (!stoppingToken.IsCancellationRequested)
        {
            var reconcile = DateTimeOffset.UtcNow >= nextReconcile;
            foreach (var tenantGuid in o.Tenants)
            {
                var tenant = new TenantId(tenantGuid);
                foreach (var resource in Enum.GetValues<RefResource>())
                {
                    try
                    {
                        await sync.PullAsync(tenant, resource, stoppingToken).ConfigureAwait(false);
                        if (reconcile)
                        {
                            await sync.ReconcileAsync(tenant, resource, stoppingToken).ConfigureAwait(false);
                        }
                    }
                    catch (OperationCanceledException) when (stoppingToken.IsCancellationRequested)
                    {
                        return;
                    }
                    catch (Exception ex)
                    {
                        logger.LogError(ex, "Reference sync failed for tenant {Tenant} resource {Resource}", tenant, resource);
                    }
                }
            }

            if (reconcile)
            {
                nextReconcile = DateTimeOffset.UtcNow + o.ReconcileInterval;
            }

            await Task.Delay(o.PullInterval, stoppingToken).ConfigureAwait(false);
        }
    }
}
