using System.Collections.Concurrent;
using System.Security.Claims;
using Mes.Application.Abstractions;
using Mes.Domain.Common;
using Mes.Infrastructure.Persistence;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.SignalR;
using StackExchange.Redis;

namespace Mes.Presentation;

public static class TenantAccess
{
    public const string ClaimType = "tenant_id";

    /// <summary>The tenant ALWAYS comes from the authenticated principal, never from a header/body/hub argument.</summary>
    public static bool TryGet(ClaimsPrincipal? user, out TenantId tenant)
    {
        tenant = default;
        return user?.FindFirst(ClaimType)?.Value is { } v && Guid.TryParse(v, out var g) && g != Guid.Empty && (tenant = new TenantId(g)).Value == g;
    }

    public static string MachineGroup(TenantId t, MachineId m) => $"{t}:machine:{m}";

    public static string LineGroup(TenantId t, string line) => $"{t}:line:{line}";
}

/// <summary>
/// Client → server: <c>JoinMachine(id)</c> / <c>JoinLine(code)</c>. Groups are tenant-qualified
/// (<c>{tenant}:machine:{id}</c>, <c>{tenant}:line:{line}</c>), so even a guessed machine id from another tenant maps to a
/// group that tenant's connections can never be in. Server → client event: <c>oee</c> (LiveViewDto).
/// </summary>
[Authorize]
public sealed class LineHub(IMachineProfileProvider profiles) : Hub
{
    public async Task JoinMachine(Guid machineId)
    {
        if (!TenantAccess.TryGet(Context.User, out var tenant))
        {
            throw new HubException("No tenant.");
        }

        // Existence check is tenant-scoped (RLS): unknown or foreign machine ids look identical to the caller.
        if (await profiles.GetAsync(tenant, new MachineId(machineId), Context.ConnectionAborted) is null)
        {
            throw new HubException("Unknown machine.");
        }

        await Groups.AddToGroupAsync(Context.ConnectionId, TenantAccess.MachineGroup(tenant, new MachineId(machineId)));
    }

    public async Task JoinLine(string line)
    {
        if (!TenantAccess.TryGet(Context.User, out var tenant) || string.IsNullOrWhiteSpace(line) || line.Length > 64)
        {
            throw new HubException("Invalid request.");
        }

        await Groups.AddToGroupAsync(Context.ConnectionId, TenantAccess.LineGroup(tenant, line));
    }

    public Task Leave(string group) => Groups.RemoveFromGroupAsync(Context.ConnectionId, group);
}

/// <summary>
/// Redis pub/sub → SignalR with 2 Hz coalescing. Updates arrive per ingest batch (could be 10+ per second per machine);
/// we only mark the key dirty, and a 500 ms timer sends the LATEST value once per key. Intermediate values are
/// intentionally dropped: the live view is a "current state" feed, not an event stream.
/// </summary>
public sealed class OeeBroadcaster(
    IConnectionMultiplexer redis,
    IOeeLiveStore live,
    IMachineProfileProvider profiles,
    IHubContext<LineHub> hub,
    ILogger<OeeBroadcaster> logger) : BackgroundService
{
    private readonly ConcurrentDictionary<string, byte> _dirty = new();

    public static readonly TimeSpan Tick = TimeSpan.FromMilliseconds(500);

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        var sub = redis.GetSubscriber();
        await sub.SubscribeAsync(RedisChannel.Literal(RedisOeeLiveStore.UpdatesChannel), (_, key) =>
        {
            if (key.HasValue)
            {
                _dirty[(string)key!] = 0;
            }
        }).ConfigureAwait(false);

        using var timer = new PeriodicTimer(Tick);
        try
        {
            while (await timer.WaitForNextTickAsync(stoppingToken).ConfigureAwait(false))
            {
                await FlushAsync(stoppingToken).ConfigureAwait(false);
            }
        }
        catch (OperationCanceledException)
        {
            // shutdown
        }
    }

    internal async Task FlushAsync(CancellationToken ct)
    {
        foreach (var key in _dirty.Keys.ToList())
        {
            _dirty.TryRemove(key, out _);
            // key = oee:live:{tenant}:{machine}:{scope}
            var p = key.Split(':');
            if (p.Length != 5 || !Guid.TryParse(p[2], out var tg) || !Guid.TryParse(p[3], out var mg))
            {
                continue;
            }

            try
            {
                var tenant = new TenantId(tg);
                var machine = new MachineId(mg);
                if (await live.GetAsync(tenant, machine, p[4], ct).ConfigureAwait(false) is not { } view)
                {
                    continue;
                }

                var dto = LiveViewDto.From(view);
                await hub.Clients.Group(TenantAccess.MachineGroup(tenant, machine)).SendAsync("oee", dto, ct).ConfigureAwait(false);
                if (await profiles.GetAsync(tenant, machine, ct).ConfigureAwait(false) is { LineCode: { } line })
                {
                    await hub.Clients.Group(TenantAccess.LineGroup(tenant, line)).SendAsync("oee", dto, ct).ConfigureAwait(false);
                }
            }
            catch (Exception ex) when (ex is not OperationCanceledException)
            {
                logger.LogWarning(ex, "Broadcast failed for {Key}", key);
            }
        }
    }
}
