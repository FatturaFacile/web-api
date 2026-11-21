using Mes.Application.Abstractions;
using Mes.Application.Calendar;
using Mes.Domain.Common;
using Microsoft.Extensions.Logging;

namespace Mes.Infrastructure.Persistence;

/// <summary>
/// Resolves a machine's shift calendar from the mirrored Laravel data: <c>ref_machine.payload.shift_calendar_id</c> →
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

    private async Task<ShiftSchedule?> LoadAsync(TenantId tenant, MachineId machine, CancellationToken ct)
    {
        await using var scope = await session.OpenAsync(tenant, ct).ConfigureAwait(false);
        string? calId;
        await using (var m = scope.Command("SELECT payload->>'shift_calendar_id' FROM ref_machine WHERE tenant_id=@t AND id=@m AND NOT deleted"))
        {
            m.Parameters.AddWithValue("t", tenant.Value);
            m.Parameters.AddWithValue("m", machine.Value);
            calId = await m.ExecuteScalarAsync(ct).ConfigureAwait(false) as string;
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
