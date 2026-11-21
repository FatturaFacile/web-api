namespace Mes.Domain.Counters;

public enum CounterEventKind
{
    Baseline,
    Normal,
    Rollover,
    Reset,
    Quarantined,
    Rebaselined,
}

public readonly record struct CounterResult(decimal Delta, CounterEventKind Kind);

/// <summary>
/// <paramref name="RolloverModulus"/>: wrap value of the PLC counter (e.g. 65536 or 4294967296); null = no rollover.
/// <paramref name="MaxDeltaPerSample"/>: plausibility ceiling; larger jumps are quarantined instead of counted.
/// <paramref name="RebaselineAfter"/>: consecutive mutually-consistent anomalous samples before the new level is trusted.
/// </summary>
public interface ICounterPolicy
{
    decimal? RolloverModulus { get; }

    decimal MaxDeltaPerSample { get; }

    int RebaselineAfter { get; }

    /// <summary>
    /// Plausibility ceiling for the FIRST sample after a restore from a checkpoint: the service may have been down
    /// for minutes, so the legitimate delta is larger than a normal inter-sample delta.
    /// </summary>
    decimal MaxDeltaAfterRestart { get; }
}

public sealed record CounterPolicy(decimal? RolloverModulus, decimal MaxDeltaPerSample, int RebaselineAfter = 2, decimal MaxDeltaAfterRestart = 100_000m) : ICounterPolicy;

/// <summary>
/// Converts a cumulative PLC counter into per-sample deltas. Handles rollover, resets to zero and quarantines
/// implausible jumps (glitches, PLC re-download) without counting them. One tracker per counter tag, single writer.
/// </summary>
public sealed class CounterTracker(ICounterPolicy policy)
{
    private decimal? _last;
    private decimal? _anomalyLevel;
    private int _anomalyRun;

    private bool _restored;

    public long QuarantinedCount { get; private set; }

    /// <summary>Last accepted counter value (the durable checkpoint), null before the first sample.</summary>
    public decimal? Last => _last;

    /// <summary>
    /// Seeds the baseline from a persisted checkpoint so counts accumulated while the service was down are attributed
    /// to the first sample after the restart (with a relaxed plausibility ceiling for that one sample).
    /// </summary>
    public void Restore(decimal last)
    {
        _last = last;
        _restored = true;
        _anomalyLevel = null;
        _anomalyRun = 0;
    }

    public CounterResult Observe(decimal value)
    {
        if (_last is not { } prev)
        {
            _last = value;
            return new(0m, CounterEventKind.Baseline);
        }

        var limit = _restored ? Math.Max(policy.MaxDeltaPerSample, policy.MaxDeltaAfterRestart) : policy.MaxDeltaPerSample;
        _restored = false;

        decimal delta;
        CounterEventKind kind;

        if (value >= prev)
        {
            delta = value - prev;
            kind = CounterEventKind.Normal;
        }
        else if (policy.RolloverModulus is { } mod && value + mod - prev <= Math.Min(limit, mod / 2))
        {
            delta = value + mod - prev; // wrapped past the modulus
            kind = CounterEventKind.Rollover;
        }
        else
        {
            delta = value; // counter restarted from zero: everything since the reset is new production
            kind = CounterEventKind.Reset;
        }

        if (delta <= limit)
        {
            _last = value;
            _anomalyLevel = null;
            _anomalyRun = 0;
            return new(delta, kind);
        }

        // Implausible jump: quarantine. Keep the old baseline; if the new level persists, adopt it WITHOUT counting.
        QuarantinedCount++;
        if (_anomalyLevel is { } lvl && value >= lvl && value - lvl <= policy.MaxDeltaPerSample)
        {
            _anomalyRun++;
        }
        else
        {
            _anomalyRun = 1;
        }

        _anomalyLevel = value;
        if (_anomalyRun >= policy.RebaselineAfter)
        {
            _last = value;
            _anomalyLevel = null;
            _anomalyRun = 0;
            return new(0m, CounterEventKind.Rebaselined);
        }

        return new(0m, CounterEventKind.Quarantined);
    }
}
