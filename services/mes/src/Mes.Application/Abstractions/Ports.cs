using System.Threading.Channels;
using Mes.Domain.Common;
using Mes.Domain.Machines;
using Mes.Domain.Oee;
using Mes.Domain.State;
using Mes.Domain.Telemetry;

namespace Mes.Application.Abstractions;

// ───────────────────────────── Connectors (edge side) ─────────────────────────────

/// <summary>Un-mapped sample as read from a protocol. <see cref="SourceTime"/> is null when the device has no clock.</summary>
public sealed record RawSample(
    string SourceAddress,
    double? NumericValue,
    string? TextValue,
    DateTimeOffset? SourceTime,
    DateTimeOffset GatewayRxTime,
    SampleQuality Quality);

public enum ConnectorStatus
{
    Stopped,
    Connecting,
    Connected,
    Backoff,
    Faulted,
}

public sealed record ConnectorHealth(ConnectorStatus Status, DateTimeOffset Since, int ConsecutiveFailures, string? LastError, long DroppedSamples);

public interface IConnector : IAsyncDisposable
{
    string Name { get; }

    ConnectorHealth Health { get; }

    ChannelReader<RawSample> Samples { get; }

    /// <summary>Runs until cancelled; reconnects internally with exponential backoff + jitter.</summary>
    Task RunAsync(CancellationToken ct);
}

public interface IConnectorFactory
{
    IConnector Create(ConnectorConfiguration configuration);
}

// ───────────────────────────── Persistence ports ─────────────────────────────

public interface ITelemetryStore
{
    Task InsertSamplesAsync(IReadOnlyList<TelemetrySample> samples, CancellationToken ct);
}

public sealed record OeeBucket(TenantId TenantId, MachineId MachineId, DateTimeOffset BucketStart, int PolicyVersion, OeeAccumulator Acc);

public sealed record Correction(Guid Id, TenantId TenantId, MachineId MachineId, string Kind, Interval? Window, string PayloadJson, DateTimeOffset CreatedAt, string? Actor);

/// <summary>Derived MES data: state intervals, 1-minute OEE buckets, corrections.</summary>
public interface IMesStore
{
    Task<StateInterval?> GetOpenIntervalAsync(TenantId tenant, MachineId machine, CancellationToken ct);

    /// <summary>Upserts by (machine, start). Closed intervals are immutable apart from reason/state corrections.</summary>
    Task UpsertIntervalsAsync(TenantId tenant, IReadOnlyList<StateInterval> intervals, CancellationToken ct);

    /// <summary>Intervals overlapping <paramref name="window"/> (not clipped), ordered by start.</summary>
    Task<IReadOnlyList<StateInterval>> GetIntervalsAsync(TenantId tenant, MachineId machine, Interval window, CancellationToken ct);

    /// <summary>Additive upsert: every column is summed into the existing row (incremental projector).</summary>
    Task AddToBucketsAsync(IReadOnlyList<OeeBucket> deltas, CancellationToken ct);

    /// <summary>Absolute upsert (replaces the row). Used by recompute, which is therefore idempotent.</summary>
    Task ReplaceBucketsAsync(IReadOnlyList<OeeBucket> buckets, CancellationToken ct);

    Task<IReadOnlyList<OeeBucket>> GetBucketsAsync(TenantId tenant, MachineId machine, Interval window, CancellationToken ct);

    Task InsertCorrectionAsync(Correction correction, CancellationToken ct);

    /// <summary>Idempotency guard for edge batches: false when <paramref name="batchKey"/> was already applied.</summary>
    Task<bool> TryRegisterBatchAsync(TenantId tenant, string batchKey, CancellationToken ct);

    Task<ProductionRun?> GetRunAsync(TenantId tenant, Guid runId, CancellationToken ct);

    /// <summary>The most recently started run of the machine that has not ended, if any (the order being produced now).</summary>
    Task<ProductionRun?> GetOpenRunAsync(TenantId tenant, MachineId machine, CancellationToken ct);

    Task SaveRunAsync(ProductionRun run, CancellationToken ct);
}

public sealed record OeeLiveView(TenantId TenantId, MachineId MachineId, string Scope, OeeAccumulator Acc, OeeResult Result, MachineState State, DateTimeOffset AsOf);

public interface IOeeLiveStore
{
    Task PublishAsync(OeeLiveView view, CancellationToken ct);

    Task<OeeLiveView?> GetAsync(TenantId tenant, MachineId machine, string scope, CancellationToken ct);
}

public interface ITransactionRunner
{
    /// <summary>Runs <paramref name="work"/> in one DB transaction; stores and the outbox join it implicitly (ambient).</summary>
    Task RunAsync(Func<CancellationToken, Task> work, CancellationToken ct);
}

// ───────────────────────────── Outbox ─────────────────────────────

public static class OutboxTypes
{
    public const string OeeShiftClosed = "OeeShiftClosed";
    public const string OeeOrderCompleted = "OeeOrderCompleted";
    public const string OeeSnapshotRevised = "OeeSnapshotRevised";
    public const string MachineAlarmRaised = "MachineAlarmRaised";
    public const string ProductionCountCheckpoint = "ProductionCountCheckpoint";
}

public sealed record OutboxMessage(Guid Id, TenantId TenantId, string Type, string DedupeKey, string PayloadJson, DateTimeOffset CreatedAt);

public interface IOutbox
{
    /// <summary>Must be called inside <see cref="ITransactionRunner.RunAsync"/> so the row commits with the business change.</summary>
    Task EnqueueAsync(OutboxMessage message, CancellationToken ct);
}

public sealed record ClaimedOutbox(OutboxMessage Message, int Attempts);

public interface IOutboxStore
{
    /// <summary>Claim-then-release: marks rows as leased (SKIP LOCKED) and returns immediately, so no HTTP happens in a DB transaction.</summary>
    Task<IReadOnlyList<ClaimedOutbox>> ClaimAsync(int batchSize, TimeSpan lease, CancellationToken ct);

    Task CompleteAsync(Guid id, string status, CancellationToken ct);

    Task RescheduleAsync(Guid id, DateTimeOffset nextAttemptAt, string error, CancellationToken ct);

    Task DeadLetterAsync(Guid id, string error, CancellationToken ct);
}

// ───────────────────────────── Laravel reference sync ─────────────────────────────

public enum RefResource
{
    Machines,
    WorkOrders,
    ShiftCalendars,
    ReasonCodes,
}

public static class RefResourceExtensions
{
    public static string Slug(this RefResource r) => r switch
    {
        RefResource.Machines => "machines",
        RefResource.WorkOrders => "work-orders",
        RefResource.ShiftCalendars => "shift-calendars",
        RefResource.ReasonCodes => "reason-codes",
        _ => throw new ArgumentOutOfRangeException(nameof(r)),
    };

    public static RefResource FromSlug(string slug) => slug switch
    {
        "machines" => RefResource.Machines,
        "work-orders" => RefResource.WorkOrders,
        "shift-calendars" => RefResource.ShiftCalendars,
        "reason-codes" => RefResource.ReasonCodes,
        _ => throw new ArgumentException($"Unknown resource '{slug}'.", nameof(slug)),
    };

    public static string Table(this RefResource r) => r switch
    {
        RefResource.Machines => "ref_machine",
        RefResource.WorkOrders => "ref_work_order",
        RefResource.ShiftCalendars => "ref_shift_calendar",
        RefResource.ReasonCodes => "ref_reason_code",
        _ => throw new ArgumentOutOfRangeException(nameof(r)),
    };
}

/// <summary>One row of the Laravel change feed. <see cref="Seq"/> is the monotonic cursor, <see cref="Revision"/> the per-record version guard.</summary>
public sealed record RefRecord(Guid Id, long Seq, long Revision, bool Deleted, string PayloadJson);

public interface IReferenceStore
{
    Task<long> GetCursorAsync(TenantId tenant, RefResource resource, CancellationToken ct);

    Task SetCursorAsync(TenantId tenant, RefResource resource, long seq, CancellationToken ct);

    /// <summary>Upserts guarded by <c>ref.revision &lt; excluded.revision</c>. Returns rows actually applied.</summary>
    Task<int> UpsertAsync(TenantId tenant, RefResource resource, IReadOnlyList<RefRecord> records, CancellationToken ct);

    /// <summary>Drops the cursor (and optionally nothing else): next pull re-bootstraps from seq 0.</summary>
    Task ResetCursorAsync(TenantId tenant, RefResource resource, CancellationToken ct);

    Task<IReadOnlyDictionary<Guid, long>> ListRevisionsAsync(TenantId tenant, RefResource resource, CancellationToken ct);
}

/// <summary>Verified push notification from Laravel (<c>mfg.mes.*</c>).</summary>
public sealed record RefEvent(TenantId TenantId, RefResource Resource, Guid Id, long Seq, long Revision, bool Deleted, string? PayloadJson);

public sealed record ReconcileReport(RefResource Resource, int Missing, int Stale, int Orphaned, bool Repaired);

public interface IManufacturingReferenceSync
{
    /// <summary>Pages through the change feed from the stored cursor; on 410 Gone resets the cursor and re-bootstraps.</summary>
    Task<int> PullAsync(TenantId tenant, RefResource resource, CancellationToken ct);

    /// <summary>Applies a push notification. Gaps in <c>Seq</c> trigger a pull instead of trusting the payload.</summary>
    Task ApplyPushAsync(RefEvent evt, CancellationToken ct);

    Task<ReconcileReport> ReconcileAsync(TenantId tenant, RefResource resource, CancellationToken ct);
}

// ───────────────────────────── Machine profile / calendar ─────────────────────────────

public enum TagRole
{
    Running,
    Alarm,
    Setup,
    Speed,
    TotalCounter,
    GoodCounter,
    Power,
    EnergyCumulative,
}

public sealed record MachineProfile(
    TenantId TenantId,
    MachineId MachineId,
    IReadOnlyDictionary<TagId, TagRole> Roles,
    double IdealCycleMs,
    Mes.Domain.Counters.CounterPolicy Counter,
    TimeSpan Debounce,
    TimeSpan StaleAfter,
    OeePolicySnapshot Policy,
    Guid? RunId = null,
    string? LineCode = null);

public interface IMachineProfileProvider
{
    Task<MachineProfile?> GetAsync(TenantId tenant, MachineId machine, CancellationToken ct);
}

public interface IShiftCalendarProvider
{
    /// <summary>Planned-production windows intersecting <paramref name="window"/>, already clipped to it.</summary>
    Task<IReadOnlyList<Interval>> PlannedWindowsAsync(TenantId tenant, MachineId machine, Interval window, CancellationToken ct);
}

/// <summary>Always planned: used until a shift calendar is mirrored.</summary>
public sealed class AlwaysPlannedCalendarProvider : IShiftCalendarProvider
{
    public Task<IReadOnlyList<Interval>> PlannedWindowsAsync(TenantId tenant, MachineId machine, Interval window, CancellationToken ct) =>
        Task.FromResult<IReadOnlyList<Interval>>([window]);
}

// ───────────────────────────── Counter checkpoints ─────────────────────────────

/// <summary>Durable baseline of one cumulative counter (key = "total" | "good").</summary>
public sealed record CounterCheckpoint(string Key, decimal Value, DateTimeOffset At);

/// <summary>
/// Persists the last accepted counter value per machine so a restart resumes from it instead of re-learning a
/// baseline (which would drop the production that happened in between). Written in the ingest transaction.
/// </summary>
public interface ICounterCheckpointStore
{
    Task<IReadOnlyList<CounterCheckpoint>> LoadAsync(TenantId tenant, MachineId machine, CancellationToken ct);

    Task SaveAsync(TenantId tenant, MachineId machine, IReadOnlyList<CounterCheckpoint> checkpoints, CancellationToken ct);
}
