using System.Globalization;
using System.Net;
using System.Net.Http.Headers;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;
using Mes.Application.Contracts;
using Microsoft.Extensions.Options;

namespace Edge.Gateway.Publishing;

/// <summary>The five states accepted by <c>POST /api/v1/manufacturing/edge/telemetry</c>.</summary>
public static class EdgeStates
{
    public const string Running = "RUNNING";
    public const string Setup = "SETUP";
    public const string Idle = "IDLE";
    public const string Fault = "FAULT";
    public const string Maintenance = "MAINTENANCE";

    public static readonly IReadOnlySet<string> All = new HashSet<string>(StringComparer.Ordinal) { Running, Setup, Idle, Fault, Maintenance };
}

/// <summary>One event of the request body. Property order is fixed so the serialised bytes are reproducible.</summary>
public sealed record EdgeEvent(
    [property: JsonPropertyName("event_uuid")] Guid EventUuid,
    [property: JsonPropertyName("machine_id")] Guid MachineId,
    [property: JsonPropertyName("state")] string State,
    [property: JsonPropertyName("occurred_at")] string OccurredAt,
    [property: JsonPropertyName("payload")] IReadOnlyDictionary<string, object?>? Payload);

public sealed record EdgeRequest([property: JsonPropertyName("events")] IReadOnlyList<EdgeEvent> Events);

/// <summary>HMAC-SHA256 of the exact raw body, lowercase hex — what the backend recomputes from <c>getContent()</c>.</summary>
public static class EdgeSignature
{
    public static string Sign(string secret, ReadOnlySpan<byte> body)
    {
        var key = Encoding.UTF8.GetBytes(secret);
        var mac = HMACSHA256.HashData(key, body);
        return Convert.ToHexStringLower(mac);
    }
}

public static class EdgeEventId
{
    /// <summary>
    /// Stable id of a state sample: SHA-256 of (gateway, machine, tag, event time, state), formatted as a version-5 style
    /// UUID. The same WAL record always yields the same id — also after the WAL downsampled the batch or the process
    /// restarted — so a resend is a duplicate for the backend and never a new event.
    /// </summary>
    public static Guid For(string gatewayUuid, Guid machineId, string tag, long eventMs, string state)
    {
        var name = string.Create(CultureInfo.InvariantCulture, $"{gatewayUuid.ToLowerInvariant()}|{machineId:D}|{tag}|{eventMs}|{state}");
        var hash = SHA256.HashData(Encoding.UTF8.GetBytes(name));
        Span<byte> b = stackalloc byte[16];
        hash.AsSpan(0, 16).CopyTo(b);
        b[6] = (byte)((b[6] & 0x0F) | 0x50);
        b[8] = (byte)((b[8] & 0x3F) | 0x80);
        return new Guid(b, bigEndian: true);
    }
}

/// <summary>Raw machine-state value → one of the five backend states, per connector (<see cref="ConnectorEntry.StateMap"/>).</summary>
public static class StateMapper
{
    private static readonly Dictionary<string, string> TextFallback = new(StringComparer.OrdinalIgnoreCase)
    {
        ["run"] = EdgeStates.Running, ["running"] = EdgeStates.Running, ["active"] = EdgeStates.Running, ["automatic"] = EdgeStates.Running,
        ["auto"] = EdgeStates.Running, ["executing"] = EdgeStates.Running, ["production"] = EdgeStates.Running, ["cycle"] = EdgeStates.Running,
        ["setup"] = EdgeStates.Setup, ["set-up"] = EdgeStates.Setup, ["changeover"] = EdgeStates.Setup, ["manual"] = EdgeStates.Setup,
        ["idle"] = EdgeStates.Idle, ["stopped"] = EdgeStates.Idle, ["stop"] = EdgeStates.Idle, ["standby"] = EdgeStates.Idle,
        ["ready"] = EdgeStates.Idle, ["interrupted"] = EdgeStates.Idle, ["off"] = EdgeStates.Idle,
        ["fault"] = EdgeStates.Fault, ["alarm"] = EdgeStates.Fault, ["error"] = EdgeStates.Fault, ["emergency"] = EdgeStates.Fault,
        ["estop"] = EdgeStates.Fault, ["failure"] = EdgeStates.Fault,
        ["maintenance"] = EdgeStates.Maintenance, ["service"] = EdgeStates.Maintenance,
    };

    /// <summary>The backend state, or null when the value matches nothing (the caller counts and logs it; it never guesses).</summary>
    public static string? Map(ConnectorEntry entry, WireSample sample)
    {
        foreach (var m in entry.StateMap)
        {
            var target = m.State.Trim().ToUpperInvariant();
            if (!EdgeStates.All.Contains(target))
            {
                continue;
            }

            if (sample.V is { } v && double.TryParse(m.Match, NumberStyles.Float, CultureInfo.InvariantCulture, out var n) && v == n)
            {
                return target;
            }

            if (sample.S is { } s && string.Equals(s.Trim(), m.Match.Trim(), StringComparison.OrdinalIgnoreCase))
            {
                return target;
            }
        }

        return sample.S is { } text && TextFallback.TryGetValue(text.Trim(), out var fallback) ? fallback : null;
    }
}

/// <summary>Result of turning one WAL batch into backend events.</summary>
public sealed record EdgeEventSet(IReadOnlyList<EdgeEvent> Events, int Unmapped, int Skipped);

public static class EdgeEventBuilder
{
    /// <summary>
    /// State samples (<see cref="ConnectorEntry.StateTag"/>) of connectors configured for this batch → events, oldest first.
    /// Repeats of the previous state are dropped (within the batch and against <paramref name="lastSent"/>), because the
    /// backend only needs transitions. Samples with bad quality are skipped. Pure: nothing is committed to
    /// <paramref name="lastSent"/> here — the caller does that only after the backend accepted the batch.
    /// </summary>
    public static EdgeEventSet Build(WireBatch batch, GatewayConfig cfg, IReadOnlyDictionary<Guid, string> lastSent)
    {
        var byMachine = cfg.Connectors.Where(c => c.StateTag is not null).GroupBy(c => c.MachineId).ToDictionary(g => g.Key, g => g.First());
        var events = new List<EdgeEvent>();
        var last = new Dictionary<Guid, string>(lastSent);
        int unmapped = 0, skipped = 0;

        foreach (var s in batch.Samples.OrderBy(x => x.EventMs).ThenBy(x => x.Tag, StringComparer.Ordinal))
        {
            if (!byMachine.TryGetValue(s.MachineId, out var entry) || !string.Equals(s.Tag, entry.StateTag, StringComparison.Ordinal))
            {
                continue;
            }

            if (s.Q == (byte)Mes.Domain.Telemetry.SampleQuality.Bad)
            {
                skipped++;
                continue;
            }

            var state = StateMapper.Map(entry, s);
            if (state is null)
            {
                unmapped++;
                continue;
            }

            var backendMachine = entry.BackendMachineId ?? entry.MachineId;
            if (last.TryGetValue(backendMachine, out var prev) && prev == state)
            {
                skipped++;
                continue;
            }

            last[backendMachine] = state;
            events.Add(new EdgeEvent(
                EdgeEventId.For(cfg.Laravel.GatewayUuid, backendMachine, s.Tag, s.EventMs, state),
                backendMachine,
                state,
                DateTimeOffset.FromUnixTimeMilliseconds(s.EventMs).UtcDateTime.ToString("yyyy-MM-dd'T'HH:mm:ss.fff'Z'", CultureInfo.InvariantCulture),
                new Dictionary<string, object?> { ["tag"] = s.Tag, ["raw"] = (object?)s.S ?? s.V, ["connector"] = entry.Name }));
        }

        return new EdgeEventSet(events, unmapped, skipped);
    }
}

public enum EdgeOutcome
{
    /// <summary>200: <c>accepted</c> + <c>duplicates</c> are stored; <c>rejected</c> ids were refused for good.</summary>
    Delivered,

    /// <summary>401/403: wrong gateway id/secret/signature. Retrying the same request cannot succeed.</summary>
    AuthFailed,

    /// <summary>422 or another permanent 4xx: the body is wrong; resending the same bytes cannot succeed.</summary>
    Invalid,

    /// <summary>5xx, 408, 429, network error, timeout: try again later with the same data.</summary>
    Transient,
}

public sealed record EdgeSendResult(EdgeOutcome Outcome, int Accepted = 0, int Duplicates = 0, IReadOnlyList<Guid>? Rejected = null, string? Error = null);

/// <summary>
/// Sends events to the Laravel edge telemetry endpoint. The body is serialised once to bytes; the HMAC is computed over
/// those very bytes and the same buffer is sent (<see cref="ByteArrayContent"/>), so a resilience-handler retry resends
/// identical bytes with an identical signature. Neither the secret nor the signature is ever logged.
/// </summary>
public sealed class LaravelTelemetryPublisher(HttpClient http, IOptionsMonitor<GatewayConfig> options)
{
    public const string Route = "/api/v1/manufacturing/edge/telemetry";

    private static readonly JsonSerializerOptions Json = new() { DefaultIgnoreCondition = JsonIgnoreCondition.Never };

    public static byte[] Serialize(IReadOnlyList<EdgeEvent> events) => JsonSerializer.SerializeToUtf8Bytes(new EdgeRequest(events), Json);

    public async Task<EdgeSendResult> SendAsync(IReadOnlyList<EdgeEvent> events, CancellationToken ct)
    {
        var cfg = options.CurrentValue.Laravel;
        if (string.IsNullOrWhiteSpace(cfg.Secret) || !Guid.TryParse(cfg.GatewayUuid, out _))
        {
            return new EdgeSendResult(EdgeOutcome.AuthFailed, Error: "Gateway:Laravel:GatewayUuid / Secret are not configured.");
        }

        var body = Serialize(events);
        using var req = new HttpRequestMessage(HttpMethod.Post, Route);
        req.Headers.TryAddWithoutValidation("X-Gateway-Id", cfg.GatewayUuid);
        req.Headers.TryAddWithoutValidation("X-Signature", EdgeSignature.Sign(cfg.Secret, body));
        req.Headers.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
        req.Content = new ByteArrayContent(body);
        req.Content.Headers.ContentType = new MediaTypeHeaderValue("application/json");

        HttpResponseMessage resp;
        try
        {
            resp = await http.SendAsync(req, ct).ConfigureAwait(false);
        }
        catch (Exception ex) when (ex is HttpRequestException or TaskCanceledException or Polly.CircuitBreaker.BrokenCircuitException or Polly.Timeout.TimeoutRejectedException && !ct.IsCancellationRequested)
        {
            return new EdgeSendResult(EdgeOutcome.Transient, Error: ex.GetType().Name);
        }

        using (resp)
        {
            var status = (int)resp.StatusCode;
            if (resp.IsSuccessStatusCode)
            {
                return await ParseAsync(resp, ct).ConfigureAwait(false);
            }

            var outcome = resp.StatusCode switch
            {
                HttpStatusCode.Unauthorized or HttpStatusCode.Forbidden => EdgeOutcome.AuthFailed,
                HttpStatusCode.RequestTimeout or HttpStatusCode.TooManyRequests => EdgeOutcome.Transient,
                _ when status >= 500 => EdgeOutcome.Transient,
                _ => EdgeOutcome.Invalid,
            };
            var detail = outcome == EdgeOutcome.Invalid ? await Truncated(resp, ct).ConfigureAwait(false) : null;
            return new EdgeSendResult(outcome, Error: $"HTTP {status}{(detail is null ? "" : ": " + detail)}");
        }
    }

    private static async Task<string> Truncated(HttpResponseMessage resp, CancellationToken ct)
    {
        var text = await resp.Content.ReadAsStringAsync(ct).ConfigureAwait(false);
        return text.Length <= 500 ? text : text[..500];
    }

    private static async Task<EdgeSendResult> ParseAsync(HttpResponseMessage resp, CancellationToken ct)
    {
        try
        {
            using var doc = await JsonDocument.ParseAsync(await resp.Content.ReadAsStreamAsync(ct).ConfigureAwait(false), cancellationToken: ct).ConfigureAwait(false);
            var data = doc.RootElement.GetProperty("data");
            var rejected = new List<Guid>();
            if (data.TryGetProperty("rejected", out var r) && r.ValueKind == JsonValueKind.Array)
            {
                foreach (var item in r.EnumerateArray())
                {
                    if (Guid.TryParse(item.GetString(), out var g))
                    {
                        rejected.Add(g);
                    }
                }
            }

            return new EdgeSendResult(EdgeOutcome.Delivered, data.GetProperty("accepted").GetInt32(), data.GetProperty("duplicates").GetInt32(), rejected);
        }
        catch (Exception ex) when (ex is JsonException or KeyNotFoundException or InvalidOperationException)
        {
            // A 2xx we cannot read: the events may or may not be stored. Resending is safe (idempotent), so treat as transient.
            return new EdgeSendResult(EdgeOutcome.Transient, Error: "Unreadable 200 response body.");
        }
    }
}

public enum DrainStatus
{
    Idle,
    Progress,
    Backoff,
    AuthBlocked,
}

/// <summary>
/// Drains the WAL to the Laravel endpoint, oldest batch first.
/// <list type="bullet">
/// <item>200: batch acked (duplicates count as delivered); <c>rejected</c> ids are written to <c>wal_quarantine</c> and the batch is acked — they are never retried.</item>
/// <item>422 / other permanent 4xx: the whole batch goes to quarantine (so the queue does not stall) and the error is logged.</item>
/// <item>401/403: nothing is acked, the error is logged loudly, the caller backs off long. 5xx/network: backoff, buffering continues.</item>
/// </list>
/// </summary>
public sealed class LaravelForwarder(Wal.WalStore wal, LaravelTelemetryPublisher publisher, IOptionsMonitor<GatewayConfig> options, ILogger<LaravelForwarder> logger)
{
    private readonly Dictionary<Guid, string> _lastSent = [];

    public async Task<DrainStatus> DrainOnceAsync(CancellationToken ct)
    {
        var pending = wal.PeekOldest(10);
        if (pending.Count == 0)
        {
            return DrainStatus.Idle;
        }

        foreach (var item in pending)
        {
            var cfg = options.CurrentValue;
            var set = EdgeEventBuilder.Build(item.Batch, cfg, _lastSent);
            if (set.Unmapped > 0)
            {
                logger.LogWarning("Batch {BatchId}: {Count} state sample(s) matched no state mapping and were dropped", item.Batch.BatchId, set.Unmapped);
            }

            var max = Math.Clamp(cfg.Laravel.MaxEventsPerRequest, 1, 500);
            var rejectedAll = new List<Guid>();
            foreach (var chunk in set.Events.Chunk(max))
            {
                var res = await publisher.SendAsync(chunk, ct).ConfigureAwait(false);
                switch (res.Outcome)
                {
                    case EdgeOutcome.Delivered:
                        rejectedAll.AddRange(res.Rejected ?? []);
                        break;
                    case EdgeOutcome.Invalid:
                        logger.LogError("Batch {BatchId} refused permanently by the backend ({Error}); quarantined", item.Batch.BatchId, res.Error);
                        wal.Quarantine(item.Id, item.Batch.BatchId, "invalid: " + res.Error, JsonSerializer.Serialize(item.Batch));
                        return DrainStatus.Progress;
                    case EdgeOutcome.AuthFailed:
                        logger.LogError("Backend rejected the gateway credentials ({Error}); not retrying blindly — check Gateway:Laravel settings. WAL pending {Pending}", res.Error, wal.Pending);
                        return DrainStatus.AuthBlocked;
                    default:
                        logger.LogWarning("Backend unavailable ({Error}); WAL pending {Pending}", res.Error, wal.Pending);
                        return DrainStatus.Backoff;
                }
            }

            foreach (var e in set.Events)
            {
                if (!rejectedAll.Contains(e.EventUuid))
                {
                    _lastSent[e.MachineId] = e.State;
                }
            }

            if (rejectedAll.Count > 0)
            {
                logger.LogError("Batch {BatchId}: backend rejected {Count} event(s) (unknown machine?); quarantined, not retried", item.Batch.BatchId, rejectedAll.Count);
                wal.Quarantine(item.Id, item.Batch.BatchId, "rejected by backend", JsonSerializer.Serialize(rejectedAll));
            }
            else
            {
                wal.Ack([item.Id]);
            }
        }

        return DrainStatus.Progress;
    }
}

public sealed class LaravelPublisherService(LaravelForwarder forwarder, ILogger<LaravelPublisherService> logger) : BackgroundService
{
    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        var rng = new Random();
        var failures = 0;
        while (!stoppingToken.IsCancellationRequested)
        {
            try
            {
                var status = await forwarder.DrainOnceAsync(stoppingToken).ConfigureAwait(false);
                TimeSpan delay;
                switch (status)
                {
                    case DrainStatus.Progress:
                        failures = 0;
                        continue;
                    case DrainStatus.Idle:
                        failures = 0;
                        delay = TimeSpan.FromMilliseconds(250);
                        break;
                    case DrainStatus.AuthBlocked:
                        failures++;
                        delay = TimeSpan.FromMinutes(1); // credentials need an operator; do not hammer the endpoint
                        break;
                    default:
                        failures++;
                        delay = Mes.Infrastructure.Connectors.Backoff.Delay(failures, Mes.Infrastructure.Connectors.BackoffOptions.Default, rng);
                        break;
                }

                await Task.Delay(delay, stoppingToken).ConfigureAwait(false);
            }
            catch (OperationCanceledException) when (stoppingToken.IsCancellationRequested)
            {
                break;
            }
            catch (Exception ex)
            {
                failures++;
                logger.LogError(ex, "Laravel forwarding loop failed ({Failures} in a row)", failures);
                await Task.Delay(Mes.Infrastructure.Connectors.Backoff.Delay(failures, Mes.Infrastructure.Connectors.BackoffOptions.Default, rng), stoppingToken).ConfigureAwait(false);
            }
        }
    }
}
