using Edge.Gateway.Time;
using Mes.Application.Abstractions;
using Mes.Domain.Common;
using Mes.Domain.Machines;
using Mes.Domain.Telemetry;

namespace Edge.Gateway.Mapping;

/// <summary>Immutable mapping table. Replaced as a whole (atomic swap), never mutated: readers never see a half-applied reload.</summary>
public sealed record TagMapSnapshot(int Version, TenantId TenantId, MachineId MachineId, IReadOnlyDictionary<string, TagMapping> BySourceAddress)
{
    public static TagMapSnapshot Create(int version, TenantId tenant, MachineId machine, IEnumerable<TagMapping> mappings) =>
        new(version, tenant, machine, mappings.ToDictionary(m => m.SourceAddress, StringComparer.Ordinal));
}

/// <summary>
/// RawSample → TelemetrySample with the active versioned mapping. Pure signal conditioning (scale/offset, deadband,
/// clock correction): no state-machine or OEE knowledge lives here.
/// </summary>
public sealed class TagMapper(ClockOffsetEstimator clock, TimeSpan? maxSourceSkew = null)
{
    private readonly TimeSpan _maxSkew = maxSourceSkew ?? TimeSpan.FromSeconds(5);
    private readonly Dictionary<string, double> _lastEmitted = [];
    private TagMapSnapshot? _snapshot;

    public int? Version => Volatile.Read(ref _snapshot)?.Version;

    /// <summary>Hot reload. Ignored when <paramref name="next"/> is not newer, so a stale file event cannot roll the map back.</summary>
    public bool Swap(TagMapSnapshot next)
    {
        var cur = Volatile.Read(ref _snapshot);
        if (cur is not null && next.Version <= cur.Version && next.MachineId == cur.MachineId)
        {
            return false;
        }

        Volatile.Write(ref _snapshot, next);
        lock (_lastEmitted)
        {
            _lastEmitted.Clear(); // deadband baselines belong to the previous mapping
        }

        return true;
    }

    /// <summary>
    /// EventTime policy: the device timestamp when it agrees with the offset-corrected gateway clock within
    /// <c>maxSourceSkew</c> (devices have cheap clocks); otherwise the corrected gateway receive time.
    /// </summary>
    public TelemetrySample? Map(RawSample raw)
    {
        var snap = Volatile.Read(ref _snapshot);
        if (snap is null || !snap.BySourceAddress.TryGetValue(raw.SourceAddress, out var m))
        {
            return null;
        }

        var est = clock.Current;
        var corrected = double.IsFinite(est.UncertaintyMs) ? raw.GatewayRxTime.AddMilliseconds(est.OffsetMs) : raw.GatewayRxTime;
        var eventTime = raw.SourceTime is { } src && (src - corrected).Duration() <= _maxSkew ? src : corrected;

        double? value = raw.NumericValue is { } n ? n * m.Scale + m.Offset : null;
        if (value is { } v && m.Deadband is { } db)
        {
            lock (_lastEmitted)
            {
                if (_lastEmitted.TryGetValue(raw.SourceAddress, out var last) && Math.Abs(v - last) < db)
                {
                    return null;
                }

                _lastEmitted[raw.SourceAddress] = v;
            }
        }

        return new TelemetrySample(
            snap.TenantId,
            snap.MachineId,
            m.TagId,
            new SampleTimes(eventTime, raw.GatewayRxTime),
            value,
            raw.TextValue,
            raw.Quality,
            snap.Version);
    }
}
