using System.Collections.Concurrent;
using Mes.Application.Abstractions;
using Mes.Domain.Common;
using Mes.Domain.Oee;
using Mes.Domain.State;

namespace Mes.Application.Processing;

/// <summary>Keeps one <see cref="MachineProcessor"/> per machine. Callers guarantee single-writer-per-machine.</summary>
public sealed class MachineProcessorRegistry(IMesStore store, IMachineProfileProvider profiles, IStateMapper mapper, ICounterCheckpointStore checkpoints)
{
    private readonly ConcurrentDictionary<MachineId, (MachineProcessor Processor, MachineProfile Profile)> _cache = new();

    public async Task<(MachineProcessor Processor, MachineProfile Profile)?> GetAsync(TenantId tenant, MachineId machine, CancellationToken ct)
    {
        if (_cache.TryGetValue(machine, out var hit))
        {
            return hit;
        }

        var profile = await profiles.GetAsync(tenant, machine, ct);
        if (profile is null)
        {
            return null;
        }

        var open = await store.GetOpenIntervalAsync(tenant, machine, ct);
        var restored = await checkpoints.LoadAsync(tenant, machine, ct);
        var created = (new MachineProcessor(profile, mapper, open, restored), profile);
        _cache[machine] = created;
        return created;
    }

    /// <summary>Called after a failed write: in-memory state may be ahead of the DB, so rebuild from the store next time.</summary>
    public void Evict(MachineId machine) => _cache.TryRemove(machine, out _);
}

/// <summary>
/// Builds the live OEE view = shift-to-date additive sum of closed 1-minute buckets + the still-open interval up to
/// "now" (which is not yet in any bucket, so there is no double count).
/// </summary>
public sealed class LiveViewBuilder(IMesStore store, IShiftCalendarProvider calendar, IOeeLiveStore live, TimeProvider time)
{
    public const string ShiftScope = "shift";

    public async Task PublishAsync(MachineProfile profile, CancellationToken ct)
    {
        var now = time.GetUtcNow();
        var lookback = new Interval(now.AddHours(-12), now);
        var windows = await calendar.PlannedWindowsAsync(profile.TenantId, profile.MachineId, lookback, ct);
        var shiftStart = windows.Count > 0 ? windows[^1].Start : new DateTimeOffset(now.UtcDateTime.Date, TimeSpan.Zero);
        var window = new Interval(shiftStart, now);

        var buckets = await store.GetBucketsAsync(profile.TenantId, profile.MachineId, window, ct);
        var acc = OeeProjector.Sum(buckets);

        var open = await store.GetOpenIntervalAsync(profile.TenantId, profile.MachineId, ct);
        var state = open?.State ?? MachineState.Unknown;
        if (open is not null)
        {
            var from = open.Start > shiftStart ? open.Start : shiftStart;
            if (from < now)
            {
                foreach (var b in await OeeProjector.TimeBucketsAsync(profile, [open with { Start = from }], now, calendar, ct))
                {
                    acc += b.Acc.TimeOnly();
                }
            }
        }

        var result = OeeCalculator.Compute(acc, profile.Policy.PerformanceCap);
        await live.PublishAsync(new OeeLiveView(profile.TenantId, profile.MachineId, ShiftScope, acc, result, state, now), ct);
    }
}
