using System.Text.Json;
using Mes.Application.Abstractions;
using Mes.Domain.Common;
using Mes.Domain.Oee;
using Mes.Domain.State;
using StackExchange.Redis;

namespace Mes.Infrastructure.Persistence;

public sealed record LiveViewDto(Guid TenantId, Guid MachineId, string Scope, OeeAccumulator Acc, OeeResult Result, MachineState State, DateTimeOffset AsOf)
{
    public static LiveViewDto From(OeeLiveView v) => new(v.TenantId.Value, v.MachineId.Value, v.Scope, v.Acc, v.Result, v.State, v.AsOf);

    public OeeLiveView ToView() => new(new TenantId(TenantId), new MachineId(MachineId), Scope, Acc, Result, State, AsOf);
}

/// <summary>
/// Live OEE read model in Redis: <c>oee:live:{tenant}:{machine}:{scope}</c> (JSON, short TTL so a dead machine does not
/// show stale numbers forever) plus a pub/sub notification on <see cref="UpdatesChannel"/> so every Mes.Core replica can
/// push to its own SignalR clients. Redis is a cache: the source of truth is the bucket table, and losing Redis only
/// costs one recompute on the next ingest.
/// </summary>
public sealed class RedisOeeLiveStore(IConnectionMultiplexer redis) : IOeeLiveStore
{
    public const string UpdatesChannel = "oee:live:updates";
    private static readonly TimeSpan Ttl = TimeSpan.FromMinutes(10);
    private static readonly JsonSerializerOptions Json = new() { Converters = { new System.Text.Json.Serialization.JsonStringEnumConverter() } };

    public static string Key(TenantId t, MachineId m, string scope) => $"oee:live:{t}:{m}:{scope}";

    public async Task PublishAsync(OeeLiveView view, CancellationToken ct)
    {
        var db = redis.GetDatabase();
        var key = Key(view.TenantId, view.MachineId, view.Scope);
        var json = JsonSerializer.Serialize(LiveViewDto.From(view), Json);
        await db.StringSetAsync(key, json, Ttl).ConfigureAwait(false);
        await redis.GetSubscriber().PublishAsync(RedisChannel.Literal(UpdatesChannel), key, CommandFlags.FireAndForget).ConfigureAwait(false);
    }

    public async Task<OeeLiveView?> GetAsync(TenantId tenant, MachineId machine, string scope, CancellationToken ct)
    {
        var v = await redis.GetDatabase().StringGetAsync(Key(tenant, machine, scope)).ConfigureAwait(false);
        return v.IsNullOrEmpty ? null : JsonSerializer.Deserialize<LiveViewDto>((string)v!, Json)?.ToView();
    }
}
