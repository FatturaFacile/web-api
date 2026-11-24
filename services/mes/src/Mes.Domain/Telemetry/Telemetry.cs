using Mes.Domain.Common;

namespace Mes.Domain.Telemetry;

/// <summary>
/// The three clocks of a sample. <see cref="EventTime"/> is the (offset-corrected) source time and is the only
/// one used for OEE math; <see cref="GatewayRxTime"/> is when the edge received it; <see cref="IngestTime"/> is
/// stamped by Mes.Core on arrival and is used for lag monitoring only.
/// </summary>
public readonly record struct SampleTimes(DateTimeOffset EventTime, DateTimeOffset GatewayRxTime, DateTimeOffset? IngestTime = null)
{
    public SampleTimes WithIngest(DateTimeOffset ingest) => this with { IngestTime = ingest };
}

public enum SampleQuality : byte
{
    Good = 0,
    Uncertain = 1,
    Bad = 2,
}

/// <summary>A normalised sample for one tag of one machine (output of the edge tag mapping).</summary>
public sealed record TelemetrySample(
    TenantId TenantId,
    MachineId MachineId,
    TagId TagId,
    SampleTimes Times,
    double? NumericValue,
    string? TextValue,
    SampleQuality Quality,
    int MappingVersion)
{
    public bool IsUsable => Quality != SampleQuality.Bad && (NumericValue is { } v ? double.IsFinite(v) : TextValue is not null);
}
