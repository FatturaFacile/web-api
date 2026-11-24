using Mes.Domain.Common;
using Mes.Domain.Oee;

namespace Mes.Domain.Machines;

public interface IDomainEvent
{
    TenantId TenantId { get; }

    DateTimeOffset OccurredAt { get; }
}

public sealed record MachineStateChanged(TenantId TenantId, MachineId MachineId, State.MachineState From, State.MachineState To, DateTimeOffset OccurredAt) : IDomainEvent;

public sealed record ProductionCounted(TenantId TenantId, MachineId MachineId, decimal Delta, DateTimeOffset OccurredAt) : IDomainEvent;

public sealed record DowntimeClassified(TenantId TenantId, MachineId MachineId, Interval Window, ReasonCode Reason, DateTimeOffset OccurredAt) : IDomainEvent;

public sealed record ScrapReported(TenantId TenantId, MachineId MachineId, Quantity Scrap, DateTimeOffset OccurredAt) : IDomainEvent;

public sealed record MachineAlarmRaisedEvent(TenantId TenantId, MachineId MachineId, string AlarmCode, DateTimeOffset OccurredAt) : IDomainEvent;

/// <summary>Machine master data as mirrored (read-only) from Laravel's <c>ref_machine</c>.</summary>
public sealed class Machine(TenantId tenantId, MachineId id, string code, string? lineCode, long revision)
{
    public TenantId TenantId { get; } = tenantId;

    public MachineId Id { get; } = id;

    public string Code { get; } = code;

    public string? LineCode { get; } = lineCode;

    public long Revision { get; } = revision;
}

public enum ProductionRunStatus
{
    Running,
    Completed,
}

public sealed class ProductionRun
{
    public ProductionRun(Guid id, TenantId tenantId, MachineId machineId, string workOrderRef, double idealCycleMs, DateTimeOffset startedAt)
    {
        if (idealCycleMs <= 0 || !double.IsFinite(idealCycleMs))
        {
            throw new ArgumentOutOfRangeException(nameof(idealCycleMs), "Ideal cycle time must be positive.");
        }

        Id = id;
        TenantId = tenantId;
        MachineId = machineId;
        WorkOrderRef = workOrderRef;
        IdealCycleMs = idealCycleMs;
        StartedAt = startedAt;
    }

    public Guid Id { get; }

    public TenantId TenantId { get; }

    public MachineId MachineId { get; }

    public string WorkOrderRef { get; }

    public double IdealCycleMs { get; }

    public DateTimeOffset StartedAt { get; }

    public DateTimeOffset? EndedAt { get; private set; }

    public long TotalCount { get; private set; }

    public long ScrapCount { get; private set; }

    public ProductionRunStatus Status => EndedAt is null ? ProductionRunStatus.Running : ProductionRunStatus.Completed;

    public void Count(long delta)
    {
        EnsureRunning();
        ArgumentOutOfRangeException.ThrowIfNegative(delta);
        TotalCount += delta;
    }

    public void ReportScrap(Quantity scrap)
    {
        var n = (long)scrap.Value;
        if (ScrapCount + n > TotalCount)
        {
            throw new InvalidOperationException("Scrap cannot exceed the total produced count.");
        }

        ScrapCount += n; // allowed after completion: late quality findings are legitimate corrections
    }

    public void Complete(DateTimeOffset at)
    {
        EnsureRunning();
        if (at < StartedAt)
        {
            throw new ArgumentException("Completion precedes start.", nameof(at));
        }

        EndedAt = at;
    }

    private void EnsureRunning()
    {
        if (EndedAt is not null)
        {
            throw new InvalidOperationException("Production run already completed.");
        }
    }
}

public enum ConnectorProtocol
{
    OpcUa,
    Mqtt,
    ModbusTcp,
}

public sealed record TagMapping(string SourceAddress, TagId TagId, double Scale = 1.0, double Offset = 0.0, double? Deadband = null);

/// <summary>Versioned connector configuration; the edge hot-reloads it and stamps <see cref="Version"/> on every sample.</summary>
public sealed record ConnectorConfiguration(
    Guid Id,
    TenantId TenantId,
    MachineId MachineId,
    ConnectorProtocol Protocol,
    IReadOnlyList<string> Endpoints,
    int Version,
    IReadOnlyList<TagMapping> Tags);

public static class OeePolicyDefaults
{
    public static OeePolicySnapshot V1 { get; } = new(1, SetupClassification.AvailabilityLoss, GapPolicy.ExcludeFromPlanned);
}
