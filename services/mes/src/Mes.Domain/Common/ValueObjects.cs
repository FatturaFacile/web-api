namespace Mes.Domain.Common;

/// <summary>Tenant identifier. New ids are UUIDv7 (time-ordered, index friendly).</summary>
public readonly record struct TenantId(Guid Value)
{
    public static TenantId New() => new(Guid.CreateVersion7());

    public static TenantId Parse(string s) => new(Guid.Parse(s));

    public bool IsEmpty => Value == Guid.Empty;

    public override string ToString() => Value.ToString("D");
}

public readonly record struct MachineId(Guid Value)
{
    public static MachineId New() => new(Guid.CreateVersion7());

    /// <summary>Stable, non-negative hash used to pick a pipeline partition (single writer per machine).</summary>
    public int PartitionOf(int partitions)
    {
        ArgumentOutOfRangeException.ThrowIfLessThan(partitions, 1);
        Span<byte> b = stackalloc byte[16];
        Value.TryWriteBytes(b);
        // FNV-1a: deterministic across processes (string.GetHashCode is randomised per process).
        uint h = 2166136261;
        foreach (var x in b)
        {
            h = (h ^ x) * 16777619;
        }

        return (int)(h % (uint)partitions);
    }

    public override string ToString() => Value.ToString("D");
}

public readonly record struct TagId
{
    public string Value { get; }

    public TagId(string value)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(value);
        Value = value;
    }

    public override string ToString() => Value;
}

public readonly record struct ReasonCode
{
    public string Value { get; }

    public ReasonCode(string value)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(value);
        Value = value.Trim();
    }

    public override string ToString() => Value;
}

public readonly record struct Quantity
{
    public decimal Value { get; }

    public Quantity(decimal value)
    {
        if (value < 0)
        {
            throw new ArgumentOutOfRangeException(nameof(value), "Quantity cannot be negative.");
        }

        Value = value;
    }

    public static Quantity Zero => new(0m);

    public static Quantity operator +(Quantity a, Quantity b) => new(a.Value + b.Value);
}

public readonly record struct Rpm
{
    public double Value { get; }

    public Rpm(double value)
    {
        if (!double.IsFinite(value) || value < 0)
        {
            throw new ArgumentOutOfRangeException(nameof(value));
        }

        Value = value;
    }
}

public readonly record struct PowerKw
{
    public double Value { get; }

    public PowerKw(double value)
    {
        if (!double.IsFinite(value))
        {
            throw new ArgumentOutOfRangeException(nameof(value));
        }

        Value = value; // negative power is legal (regeneration)
    }
}

public readonly record struct EnergyKwh
{
    public double Value { get; }

    public EnergyKwh(double value)
    {
        if (!double.IsFinite(value))
        {
            throw new ArgumentOutOfRangeException(nameof(value));
        }

        Value = value;
    }

    public static EnergyKwh operator +(EnergyKwh a, EnergyKwh b) => new(a.Value + b.Value);
}

/// <summary>Finite, non-negative ratio. Not clamped to 1: performance may legitimately exceed 1.0 before capping.</summary>
public readonly record struct Ratio
{
    public double Value { get; }

    public Ratio(double value)
    {
        if (!double.IsFinite(value) || value < 0)
        {
            throw new ArgumentOutOfRangeException(nameof(value));
        }

        Value = value;
    }

    public Ratio Capped(double max = 1.0) => new(Math.Min(Value, max));

    public override string ToString() => Value.ToString("P2", System.Globalization.CultureInfo.InvariantCulture);
}

/// <summary>Half-open UTC interval [Start, End).</summary>
public readonly record struct Interval
{
    public DateTimeOffset Start { get; }

    public DateTimeOffset End { get; }

    public Interval(DateTimeOffset start, DateTimeOffset end)
    {
        if (end < start)
        {
            throw new ArgumentException("Interval end precedes start.");
        }

        Start = start.ToUniversalTime();
        End = end.ToUniversalTime();
    }

    public TimeSpan Duration => End - Start;

    public long DurationMs => (long)Duration.TotalMilliseconds;

    public bool Overlaps(Interval other) => Start < other.End && other.Start < End;

    public Interval? Intersect(Interval other)
    {
        var s = Start > other.Start ? Start : other.Start;
        var e = End < other.End ? End : other.End;
        return e > s ? new Interval(s, e) : null;
    }

    public bool Contains(DateTimeOffset t) => t >= Start && t < End;
}
