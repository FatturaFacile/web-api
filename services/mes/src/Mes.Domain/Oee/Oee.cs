using Mes.Domain.Common;
using Mes.Domain.State;

namespace Mes.Domain.Oee;

public enum SetupClassification
{
    /// <summary>Setup time stays in planned production time and lowers Availability.</summary>
    AvailabilityLoss,

    /// <summary>Setup time is removed from planned production time (not counted against the machine).</summary>
    PlannedExcluded,
}

public enum GapPolicy
{
    /// <summary>Time with no data is excluded from planned time; <c>CoveragePct</c> reports how much is known.</summary>
    ExcludeFromPlanned,

    /// <summary>Time with no data is counted as downtime (stays in planned time).</summary>
    CountAsDowntime,
}

public interface ISetupClassificationPolicy
{
    SetupClassification Classify(ReasonCode? reason);
}

public interface IGapPolicy
{
    GapPolicy Policy { get; }
}

public interface IPerformancePolicy
{
    /// <summary>Ideal cycle time in ms per unit for the product/run; must be &gt; 0.</summary>
    double IdealCycleMs(string? productRef);

    /// <summary>Upper cap applied to Performance before it enters OEE (normally 1.0).</summary>
    double PerformanceCap { get; }
}

/// <summary>Policy values frozen with a version so historic buckets stay reproducible.</summary>
public sealed record OeePolicySnapshot(int PolicyVersion, SetupClassification Setup, GapPolicy Gap, double PerformanceCap = 1.0)
    : ISetupClassificationPolicy, IGapPolicy
{
    GapPolicy IGapPolicy.Policy => Gap;

    public SetupClassification Classify(ReasonCode? reason) => Setup;
}

public enum OeeNullReason
{
    None = 0,
    NoPlannedTime,
    NoRunTime,
    NoProduction,
    NoQualityData,
    NoIdealWork,
}

/// <summary>
/// Additive OEE accumulator. Everything here is a SUM, so accumulators from buckets, shifts or orders can be added
/// and the ratios computed once at the end. Never average percentages (ADR-05).
/// </summary>
public readonly record struct OeeAccumulator(
    long PlannedMs,
    long RunMs,
    long SetupMs,
    long AlarmMs,
    long DownMs,
    long UnknownMs,
    double IdealWorkMs,
    double IdealGoodMs,
    long TotalCount,
    long GoodCount,
    bool HasQualityData)
{
    public static OeeAccumulator Empty => default;

    public static OeeAccumulator operator +(OeeAccumulator a, OeeAccumulator b) => new(
        a.PlannedMs + b.PlannedMs,
        a.RunMs + b.RunMs,
        a.SetupMs + b.SetupMs,
        a.AlarmMs + b.AlarmMs,
        a.DownMs + b.DownMs,
        a.UnknownMs + b.UnknownMs,
        a.IdealWorkMs + b.IdealWorkMs,
        a.IdealGoodMs + b.IdealGoodMs,
        a.TotalCount + b.TotalCount,
        a.GoodCount + b.GoodCount,
        a.HasQualityData || b.HasQualityData);

    /// <summary>Adds time spent in <paramref name="state"/>, applying setup/gap policies to decide what is "planned".</summary>
    public OeeAccumulator WithTime(MachineState state, long ms, OeePolicySnapshot policy)
    {
        if (ms <= 0)
        {
            return this;
        }

        return state switch
        {
            MachineState.Running => this with { PlannedMs = PlannedMs + ms, RunMs = RunMs + ms },
            MachineState.Alarm => this with { PlannedMs = PlannedMs + ms, AlarmMs = AlarmMs + ms },
            MachineState.Down => this with { PlannedMs = PlannedMs + ms, DownMs = DownMs + ms },
            MachineState.Setup => policy.Setup == SetupClassification.AvailabilityLoss
                ? this with { PlannedMs = PlannedMs + ms, SetupMs = SetupMs + ms }
                : this with { SetupMs = SetupMs + ms },
            _ => policy.Gap == GapPolicy.CountAsDowntime
                ? this with { PlannedMs = PlannedMs + ms, UnknownMs = UnknownMs + ms }
                : this with { UnknownMs = UnknownMs + ms },
        };
    }

    /// <summary>Moves <paramref name="scrap"/> units from good to scrap (late quality finding). Negative good is impossible by construction of callers.</summary>
    public OeeAccumulator WithScrap(long scrap, double idealCycleMs) => scrap <= 0
        ? this
        : this with { GoodCount = GoodCount - scrap, IdealGoodMs = IdealGoodMs - scrap * idealCycleMs, HasQualityData = true };

    /// <summary>Time-only part (production fields zeroed).</summary>
    public OeeAccumulator TimeOnly() => this with { IdealWorkMs = 0, IdealGoodMs = 0, TotalCount = 0, GoodCount = 0, HasQualityData = false };

    /// <summary>Production-only part (time fields zeroed).</summary>
    public OeeAccumulator ProductionOnly() => this with { PlannedMs = 0, RunMs = 0, SetupMs = 0, AlarmMs = 0, DownMs = 0, UnknownMs = 0 };

    /// <summary>
    /// Adds produced units. <paramref name="goodCount"/> null means "quality not reported": those units are treated as
    /// good for the ideal-time weighting (so Q stays a pure ratio of ideal times) and do not set <see cref="HasQualityData"/>.
    /// </summary>
    public OeeAccumulator WithProduction(long totalCount, long? goodCount, double idealCycleMs)
    {
        if (totalCount <= 0)
        {
            return this;
        }

        var good = goodCount is { } g ? Math.Clamp(g, 0, totalCount) : totalCount;
        return this with
        {
            TotalCount = TotalCount + totalCount,
            GoodCount = GoodCount + good,
            IdealWorkMs = IdealWorkMs + totalCount * idealCycleMs,
            IdealGoodMs = IdealGoodMs + good * idealCycleMs,
            HasQualityData = HasQualityData || goodCount is not null,
        };
    }
}

public sealed record OeeResult(
    double? Availability,
    double? Performance,
    double? Quality,
    double? Oee,
    double CoveragePct,
    IReadOnlyList<OeeNullReason> NullReasons)
{
    public bool IsComplete => NullReasons.Count == 0;
}

public static class OeeCalculator
{
    public const double InvariantTolerance = 1e-9;

    /// <summary>
    /// A = Run/Planned; P = IdealWork/Run; Q = IdealGood/IdealWork (ideal-time weighted);
    /// OEE = A * min(P, cap) * (Q ?? 1).  When P &lt;= cap and quality data exists this equals IdealGood/Planned exactly.
    /// If P cannot be computed OEE is null, except RunMs == 0 with planned time, which is a true 0.
    /// </summary>
    public static OeeResult Compute(in OeeAccumulator acc, double performanceCap = 1.0)
    {
        var reasons = new List<OeeNullReason>();

        double? a = null;
        if (acc.PlannedMs > 0)
        {
            a = (double)acc.RunMs / acc.PlannedMs;
        }
        else
        {
            reasons.Add(OeeNullReason.NoPlannedTime);
        }

        double? p = null;
        if (acc.RunMs > 0 && acc.TotalCount > 0)
        {
            p = acc.IdealWorkMs / acc.RunMs;
        }
        else
        {
            reasons.Add(acc.RunMs <= 0 ? OeeNullReason.NoRunTime : OeeNullReason.NoProduction);
        }

        double? q = null;
        if (acc.HasQualityData && acc.IdealWorkMs > 0)
        {
            q = acc.IdealGoodMs / acc.IdealWorkMs;
        }
        else
        {
            reasons.Add(acc.HasQualityData ? OeeNullReason.NoIdealWork : OeeNullReason.NoQualityData);
        }

        double? oee = null;
        if (a is { } av)
        {
            if (p is { } pv)
            {
                oee = av * Math.Min(pv, performanceCap) * (q ?? 1.0);
            }
            else if (acc.RunMs <= 0)
            {
                oee = 0.0;
            }
        }

        var known = acc.RunMs + acc.SetupMs + acc.AlarmMs + acc.DownMs;
        var total = known + acc.UnknownMs;
        var coverage = total > 0 ? 100.0 * known / total : 0.0;

        return new OeeResult(a, p, q, oee, coverage, reasons);
    }

    /// <summary>True when |A·min(P,1)·Q − IdealGood/Planned| &lt; 1e-9. Only meaningful when P &lt;= 1 and quality data exists.</summary>
    public static bool InvariantHolds(in OeeAccumulator acc)
    {
        var r = Compute(acc, double.PositiveInfinity);
        if (r.Availability is not { } a || r.Performance is not { } p || r.Quality is not { } q)
        {
            return true; // nothing to check
        }

        return Math.Abs(a * p * q - acc.IdealGoodMs / acc.PlannedMs) < InvariantTolerance;
    }
}

public static class BucketSplitter
{
    /// <summary>
    /// Splits <paramref name="interval"/> across fixed-size UTC buckets (default 1 minute). The returned millisecond
    /// shares always sum to exactly the interval duration.
    /// </summary>
    public static IReadOnlyList<(DateTimeOffset BucketStart, long Ms)> SplitAcrossBuckets(Interval interval, TimeSpan? bucket = null)
    {
        var size = bucket ?? TimeSpan.FromMinutes(1);
        if (size <= TimeSpan.Zero)
        {
            throw new ArgumentOutOfRangeException(nameof(bucket));
        }

        var result = new List<(DateTimeOffset, long)>();
        var ticks = size.Ticks;
        var cursor = interval.Start;
        while (cursor < interval.End)
        {
            var bucketStartTicks = cursor.UtcTicks - (cursor.UtcTicks % ticks);
            var bucketStart = new DateTimeOffset(bucketStartTicks, TimeSpan.Zero);
            var bucketEnd = bucketStart + size;
            var segmentEnd = bucketEnd < interval.End ? bucketEnd : interval.End;
            result.Add((bucketStart, (long)(segmentEnd - cursor).TotalMilliseconds));
            cursor = segmentEnd;
        }

        return result;
    }
}
