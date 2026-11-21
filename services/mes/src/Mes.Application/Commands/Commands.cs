using Mes.Domain.Common;
using Mes.Domain.State;
using Mes.Domain.Telemetry;

namespace Mes.Application.Commands;

public sealed record IngestTelemetryBatch(TenantId TenantId, string BatchId, IReadOnlyList<TelemetrySample> Samples);

public sealed record IngestResult(int Accepted, int Rejected, int DuplicateMachines);

/// <summary>Reclassify closed Down/Alarm/Unknown time in <paramref name="Window"/> (optionally changing its state, e.g. to Setup).</summary>
public sealed record ClassifyDowntime(TenantId TenantId, MachineId MachineId, Interval Window, ReasonCode Reason, MachineState? ReclassifyAs, string? Actor);

public sealed record ReportScrap(TenantId TenantId, MachineId MachineId, Guid RunId, Quantity Scrap, DateTimeOffset At, string? Actor);

public sealed record SplitInterval(TenantId TenantId, MachineId MachineId, DateTimeOffset At, string? Actor);

public sealed record RecomputeWindow(TenantId TenantId, MachineId MachineId, Interval Window);

public sealed record CompleteProductionRun(TenantId TenantId, Guid RunId, DateTimeOffset At);

public sealed record CloseShift(TenantId TenantId, MachineId MachineId, Interval Shift, string ShiftRef);

public sealed class MesNotFoundException(string message) : Exception(message);

public sealed class MesValidationException(string message) : Exception(message);
