using Mes.Application.Abstractions;
using Mes.Domain.Common;
using Mes.Domain.Oee;
using Mes.Domain.State;

namespace Mes.Application.Processing;

/// <summary>Turns closed state intervals and count events into additive 1-minute bucket deltas.</summary>
public static class OeeProjector
{
    public static async Task<IReadOnlyList<OeeBucket>> TimeBucketsAsync(
        MachineProfile profile,
        IEnumerable<StateInterval> intervals,
        DateTimeOffset openEnd,
        IShiftCalendarProvider calendar,
        CancellationToken ct)
    {
        var acc = new Dictionary<DateTimeOffset, OeeAccumulator>();
        foreach (var iv in intervals)
        {
            var span = iv.ToInterval(openEnd);
            if (span.DurationMs <= 0)
            {
                continue;
            }

            var planned = await calendar.PlannedWindowsAsync(profile.TenantId, profile.MachineId, span, ct);
            foreach (var w in planned)
            {
                if (span.Intersect(w) is not { } part)
                {
                    continue;
                }

                foreach (var (start, ms) in BucketSplitter.SplitAcrossBuckets(part))
                {
                    acc[start] = acc.GetValueOrDefault(start).WithTime(iv.State, ms, profile.Policy);
                }
            }

            // Time outside planned windows is deliberately not accumulated: it is not planned production time.
            // (Unknown/gap handling for in-window time is governed by the GapPolicy inside WithTime.)
        }

        return acc.Select(kv => new OeeBucket(profile.TenantId, profile.MachineId, kv.Key, profile.Policy.PolicyVersion, kv.Value)).ToList();
    }

    public static IReadOnlyList<OeeBucket> ProductionBuckets(MachineProfile profile, IEnumerable<CountEvent> counts, bool hasGoodCounter)
    {
        var perBucket = new Dictionary<DateTimeOffset, (long Total, long Good)>();
        foreach (var c in counts)
        {
            var b = new DateTimeOffset(c.At.UtcTicks - (c.At.UtcTicks % TimeSpan.TicksPerMinute), TimeSpan.Zero);
            var cur = perBucket.GetValueOrDefault(b);
            perBucket[b] = (cur.Total + c.Total, cur.Good + (c.Good ?? 0));
        }

        return perBucket
            .Select(kv => new OeeBucket(
                profile.TenantId,
                profile.MachineId,
                kv.Key,
                profile.Policy.PolicyVersion,
                OeeAccumulator.Empty.WithProduction(kv.Value.Total, hasGoodCounter ? kv.Value.Good : null, profile.IdealCycleMs)))
            .Where(b => b.Acc.TotalCount > 0)
            .ToList();
    }

    /// <summary>Sum several bucket rows into one accumulator (additive, never averaged).</summary>
    public static OeeAccumulator Sum(IEnumerable<OeeBucket> buckets) =>
        buckets.Aggregate(OeeAccumulator.Empty, (a, b) => a + b.Acc);

    /// <summary>Merge deltas for the same bucket start so one batch issues one upsert per bucket.</summary>
    public static IReadOnlyList<OeeBucket> Merge(IEnumerable<OeeBucket> deltas) =>
        deltas.GroupBy(d => (d.TenantId, d.MachineId, d.BucketStart, d.PolicyVersion))
            .Select(g => new OeeBucket(g.Key.TenantId, g.Key.MachineId, g.Key.BucketStart, g.Key.PolicyVersion, g.Aggregate(OeeAccumulator.Empty, (a, b) => a + b.Acc)))
            .ToList();
}
