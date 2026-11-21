namespace Edge.Gateway.Time;

/// <summary>NTP-style exchange: t0 client send, t1 server receive, t2 server send, t3 client receive (all UTC ms).</summary>
public readonly record struct ClockExchange(long T0, long T1, long T2, long T3)
{
    /// <summary>Round-trip time minus server processing time.</summary>
    public long RoundTripMs => (T3 - T0) - (T2 - T1);

    /// <summary>Estimated amount to ADD to the local clock to obtain server time.</summary>
    public double OffsetMs => ((T1 - T0) + (T2 - T3)) / 2.0;
}

public readonly record struct ClockEstimate(double OffsetMs, double UncertaintyMs, int Samples)
{
    public static ClockEstimate Unknown => new(0, double.PositiveInfinity, 0);
}

/// <summary>
/// Min-RTT offset estimator. Of the last N exchanges the one with the smallest round trip is the least contaminated by
/// asymmetric queueing delay, so its offset is used; the true offset is within ± RTT/2 of it (that is the reported
/// uncertainty). Thread-safe; fed from the publisher thread, read from the mapping hot path.
/// </summary>
public sealed class ClockOffsetEstimator(int window = 16)
{
    private readonly Lock _gate = new();
    private readonly Queue<ClockExchange> _recent = new();
    private ClockEstimate _estimate = ClockEstimate.Unknown;

    public ClockEstimate Current
    {
        get
        {
            lock (_gate)
            {
                return _estimate;
            }
        }
    }

    public void Add(ClockExchange x)
    {
        if (x.RoundTripMs < 0 || x.T3 < x.T0 || x.T2 < x.T1)
        {
            return; // non-causal exchange (clock stepped mid-request): discard
        }

        lock (_gate)
        {
            _recent.Enqueue(x);
            while (_recent.Count > window)
            {
                _recent.Dequeue();
            }

            var best = _recent.MinBy(e => e.RoundTripMs);
            _estimate = new ClockEstimate(best.OffsetMs, best.RoundTripMs / 2.0, _recent.Count);
        }
    }
}

/// <summary>
/// Tracks the highest event time seen per source. The batch watermark is the minimum across sources: everything at or
/// below it has been emitted, so the server knows how complete the data up to that instant is.
/// </summary>
public sealed class WatermarkTracker
{
    private readonly Lock _gate = new();
    private readonly Dictionary<string, long> _perSource = [];

    public void Observe(string source, long eventMs)
    {
        lock (_gate)
        {
            _perSource[source] = _perSource.TryGetValue(source, out var cur) ? Math.Max(cur, eventMs) : eventMs;
        }
    }

    public void Forget(string source)
    {
        lock (_gate)
        {
            _perSource.Remove(source);
        }
    }

    /// <summary>Minimum over sources, or null when nothing was observed yet.</summary>
    public long? Watermark
    {
        get
        {
            lock (_gate)
            {
                return _perSource.Count == 0 ? null : _perSource.Values.Min();
            }
        }
    }
}
