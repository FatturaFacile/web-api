using Mes.Application.Abstractions;
using Mes.Application.Calendar;
using Mes.Domain.Common;
using Microsoft.Extensions.Logging;

namespace Mes.Infrastructure.Persistence;

/// <summary>
/// Resolves a machine's shift calendar from the mirrored Laravel data. Primary source: the dated UTC shift instances of the
/// machine's work centre (<c>ref_machine.payload.work_center_id</c> → <c>ref_shift_calendar.payload.scope_id</c>), with their breaks
/// and planned maintenance (<see cref="ShiftSchedule.ParseInstance"/>). Legacy fallback: <c>ref_machine.payload.shift_calendar_id</c> →
/// <c>ref_shift_calendar.payload</c> (<see cref="ShiftSchedule.Parse"/>). A machine without a calendar is "always planned"
/// (explicit, logged once); a calendar that fails to parse ALSO falls back to always-planned but logs an error — silently
/// shrinking planned time to zero would be worse than a visibly over-optimistic day.
/// </summary>
public sealed class RefShiftCalendarProvider(PgSession session, TimeProvider time, ILogger<RefShiftCalendarProvider> logger) : IShiftCalendarProvider
{
    private readonly Dictionary<(TenantId, MachineId), (DateTimeOffset At, ShiftSchedule? Schedule)> _cache = [];
    private readonly Lock _gate = new();
    private static readonly TimeSpan Ttl = TimeSpan.FromMinutes(1);

    public async Task<IReadOnlyList<Interval>> PlannedWindowsAsync(TenantId tenant, MachineId machine, Interval window, CancellationToken ct)
    {
        ShiftSchedule? schedule = null;
        var cached = false;
        lock (_gate)
        {
            if (_cache.TryGetValue((tenant, machine), out var hit) && time.GetUtcNow() - hit.At < Ttl)
            {
                cached = true;
                schedule = hit.Schedule; // may legitimately be null: "no calendar"
            }
        }

        if (!cached)
        {
            schedule = await LoadAsync(tenant, machine, ct).ConfigureAwait(false);
            lock (_gate)
            {
                _cache[(tenant, machine)] = (time.GetUtcNow(), schedule);
            }
        }

        return schedule is null ? [window] : schedule.PlannedWindows(window);
    }

    /// <summary>The dated instances of the work centre. None mirrored yet ⇒ always planned (logged); an unreadable one ⇒ always planned, logged as an error.</summary>
    private async Task<ShiftSchedule?> LoadInstancesAsync(PgSession.Scope scope, TenantId tenant, MachineId machine, string workCenter, CancellationToken ct)
    {
        var payloads = new List<string>();
        await using (var c = scope.Command("SELECT payload::text FROM ref_shift_calendar WHERE tenant_id=@t AND NOT deleted AND payload->>'scope_id' = @w"))
        {
            c.Parameters.AddWithValue("t", tenant.Value);
            c.Parameters.AddWithValue("w", workCenter);
            await using var r = await c.ExecuteReaderAsync(ct).ConfigureAwait(false);
            while (await r.ReadAsync(ct).ConfigureAwait(false))
            {
                payloads.Add(r.GetString(0));
            }
        }

        if (payloads.Count == 0)
        {
            logger.LogWarning("No shift instances of work centre {WorkCenter} mirrored yet: machine {Machine} is always planned", workCenter, machine);
            return null;
        }

        try
        {
            var shifts = new List<Interval>();
            var holes = new List<Interval>();
            foreach (var p in payloads)
            {
                var (shift, h) = ShiftSchedule.ParseInstance(p, machine.Value);
                shifts.Add(shift);
                holes.AddRange(h);
            }

            return ShiftSchedule.FromInstances(shifts, holes);
        }
        catch (FormatException ex)
        {
            logger.LogError(ex, "Shift instances of work centre {WorkCenter} are invalid: machine {Machine} falls back to always planned", workCenter, machine);
            return null;
        }
    }

    private async Task<ShiftSchedule?> LoadAsync(TenantId tenant, MachineId machine, CancellationToken ct)
    {
        await using var scope = await session.OpenAsync(tenant, ct).ConfigureAwait(false);
        string? calId = null, workCenter = null;
        await using (var m = scope.Command("SELECT payload->>'shift_calendar_id', payload->>'work_center_id' FROM ref_machine WHERE tenant_id=@t AND id=@m AND NOT deleted"))
        {
            m.Parameters.AddWithValue("t", tenant.Value);
            m.Parameters.AddWithValue("m", machine.Value);
            await using var mr = await m.ExecuteReaderAsync(ct).ConfigureAwait(false);
            if (await mr.ReadAsync(ct).ConfigureAwait(false))
            {
                calId = mr.IsDBNull(0) ? null : mr.GetString(0);
                workCenter = mr.IsDBNull(1) ? null : mr.GetString(1);
            }
        }

        if (Guid.TryParse(workCenter, out _))
        {
            return await LoadInstancesAsync(scope, tenant, machine, workCenter!, ct).ConfigureAwait(false);
        }

        if (!Guid.TryParse(calId, out var id))
        {
            logger.LogInformation("Machine {Machine} has no shift calendar: treated as always planned", machine);
            return null;
        }

        string? payload;
        await using (var c = scope.Command("SELECT payload::text FROM ref_shift_calendar WHERE tenant_id=@t AND id=@i AND NOT deleted"))
        {
            c.Parameters.AddWithValue("t", tenant.Value);
            c.Parameters.AddWithValue("i", id);
            payload = await c.ExecuteScalarAsync(ct).ConfigureAwait(false) as string;
        }

        if (payload is null)
        {
            logger.LogWarning("Shift calendar {Calendar} of machine {Machine} not mirrored yet: always planned", id, machine);
            return null;
        }

        try
        {
            return ShiftSchedule.Parse(payload);
        }
        catch (FormatException ex)
        {
            logger.LogError(ex, "Shift calendar {Calendar} is invalid: machine {Machine} falls back to always planned", id, machine);
            return null;
        }
    }
}
