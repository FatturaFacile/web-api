using System.Globalization;
using System.Text.Json;
using Mes.Domain.Common;

namespace Mes.Application.Calendar;

/// <summary>Weekly recurring shift: <see cref="End"/> &lt;= <see cref="Start"/> means it ends the next day (night shift).</summary>
public sealed record ShiftPattern(IReadOnlySet<DayOfWeek> Days, TimeOnly Start, TimeOnly End);

/// <summary>
/// Planned production windows of a machine, derived from a weekly pattern in a local time zone plus closures
/// (holidays, maintenance). DST-safe: local wall-clock times are converted per date, so a 06:00 shift stays 06:00 local
/// across a clock change, and a skipped/ambiguous local time is resolved deterministically.
/// <para><b>Tentative JSON shape</b> (to be replaced when the Laravel <c>shift-calendars</c> payload is final):
/// <code>{"timezone":"Europe/Rome","shifts":[{"days":[1,2,3,4,5],"start":"06:00","end":"14:00"}],
/// "closures":[{"from":"2026-12-25T00:00:00Z","to":"2026-12-26T00:00:00Z"}]}</code>
/// <c>days</c> are ISO weekdays (1 = Monday … 7 = Sunday).</para>
/// </summary>
public sealed class ShiftSchedule(TimeZoneInfo zone, IReadOnlyList<ShiftPattern> patterns, IReadOnlyList<Interval> closures)
{
    private IReadOnlyList<Interval>? _dated;

    /// <summary>
    /// A schedule made of dated shift intervals (what Laravel materialises: UTC instances) minus holes (breaks, planned
    /// maintenance). Outside the given shifts nothing is planned.
    /// </summary>
    public static ShiftSchedule FromInstances(IReadOnlyList<Interval> shifts, IReadOnlyList<Interval> holes) =>
        new(TimeZoneInfo.Utc, [], holes) { _dated = shifts };

    /// <summary>
    /// Parses one shift instance of the Laravel <c>shift-calendars</c> feed
    /// (<c>{"starts_at","ends_at","non_production":[{"kind","from","to","machine_id"?}]}</c>, UTC): the shift and the holes
    /// that apply to <paramref name="machine"/> (entries without a machine apply to every machine of the work centre).
    /// </summary>
    public static (Interval Shift, IReadOnlyList<Interval> Holes) ParseInstance(string json, Guid machine)
    {
        try
        {
            using var doc = JsonDocument.Parse(json);
            var root = doc.RootElement;
            var shift = new Interval(root.GetProperty("starts_at").GetDateTimeOffset(), root.GetProperty("ends_at").GetDateTimeOffset());
            var holes = new List<Interval>();
            if (root.TryGetProperty("non_production", out var np) && np.ValueKind == JsonValueKind.Array)
            {
                foreach (var h in np.EnumerateArray())
                {
                    var forMachine = !h.TryGetProperty("machine_id", out var m) || m.ValueKind == JsonValueKind.Null || (Guid.TryParse(m.GetString(), out var g) && g == machine);
                    if (forMachine)
                    {
                        holes.Add(new Interval(h.GetProperty("from").GetDateTimeOffset(), h.GetProperty("to").GetDateTimeOffset()));
                    }
                }
            }

            return (shift, holes);
        }
        catch (Exception ex) when (ex is JsonException or KeyNotFoundException or InvalidOperationException or ArgumentException or FormatException)
        {
            throw new FormatException("Invalid shift instance payload: " + ex.Message, ex);
        }
    }

    public IReadOnlyList<Interval> PlannedWindows(Interval window)
    {
        if (_dated is not null)
        {
            var inside = _dated.Select(s => s.Intersect(window)).OfType<Interval>().ToList();
            return Subtract(Merge(inside), closures);
        }

        var raw = new List<Interval>();
        // A night shift that started yesterday can overlap the window: begin one local day early, end one day late.
        var firstDate = DateOnly.FromDateTime(TimeZoneInfo.ConvertTime(window.Start, zone).DateTime).AddDays(-1);
        var lastDate = DateOnly.FromDateTime(TimeZoneInfo.ConvertTime(window.End, zone).DateTime).AddDays(1);

        for (var d = firstDate; d <= lastDate; d = d.AddDays(1))
        {
            foreach (var p in patterns.Where(p => p.Days.Contains(d.DayOfWeek)))
            {
                var start = ToUtc(d, p.Start);
                var end = ToUtc(p.End <= p.Start ? d.AddDays(1) : d, p.End);
                if (end > start && new Interval(start, end).Intersect(window) is { } part)
                {
                    raw.Add(part);
                }
            }
        }

        return Subtract(Merge(raw), closures);
    }

    private DateTimeOffset ToUtc(DateOnly date, TimeOnly time)
    {
        var local = date.ToDateTime(time, DateTimeKind.Unspecified);
        if (zone.IsInvalidTime(local))
        {
            local = local.AddHours(1); // spring-forward gap: the shift starts when the wall clock resumes
        }

        var offset = zone.IsAmbiguousTime(local) ? zone.GetAmbiguousTimeOffsets(local).Max() : zone.GetUtcOffset(local);
        return new DateTimeOffset(local, offset).ToUniversalTime(); // ambiguous (fall-back): first occurrence
    }

    private static List<Interval> Merge(List<Interval> items)
    {
        var merged = new List<Interval>();
        foreach (var i in items.OrderBy(x => x.Start))
        {
            if (merged.Count > 0 && i.Start <= merged[^1].End)
            {
                merged[^1] = new Interval(merged[^1].Start, i.End > merged[^1].End ? i.End : merged[^1].End);
            }
            else
            {
                merged.Add(i);
            }
        }

        return merged;
    }

    private static List<Interval> Subtract(List<Interval> windows, IReadOnlyList<Interval> holes)
    {
        var result = windows;
        foreach (var h in holes)
        {
            var next = new List<Interval>();
            foreach (var w in result)
            {
                if (!w.Overlaps(h))
                {
                    next.Add(w);
                    continue;
                }

                if (h.Start > w.Start)
                {
                    next.Add(new Interval(w.Start, h.Start));
                }

                if (h.End < w.End)
                {
                    next.Add(new Interval(h.End, w.End));
                }
            }

            result = next;
        }

        return result;
    }

    /// <summary>Parses the tentative payload; throws <see cref="FormatException"/> with a precise message on bad input.</summary>
    public static ShiftSchedule Parse(string json)
    {
        try
        {
            using var doc = JsonDocument.Parse(json);
            var root = doc.RootElement;
            var zone = root.TryGetProperty("timezone", out var tz) ? TimeZoneInfo.FindSystemTimeZoneById(tz.GetString()!) : TimeZoneInfo.Utc;

            var patterns = new List<ShiftPattern>();
            foreach (var s in root.GetProperty("shifts").EnumerateArray())
            {
                var days = s.GetProperty("days").EnumerateArray().Select(d => d.GetInt32() switch
                {
                    >= 1 and <= 6 and var n => (DayOfWeek)n,
                    7 => DayOfWeek.Sunday,
                    var bad => throw new FormatException($"ISO weekday out of range: {bad}"),
                }).ToHashSet();
                patterns.Add(new ShiftPattern(days, TimeOnly.ParseExact(s.GetProperty("start").GetString()!, "HH:mm", CultureInfo.InvariantCulture), TimeOnly.ParseExact(s.GetProperty("end").GetString()!, "HH:mm", CultureInfo.InvariantCulture)));
            }

            var closures = new List<Interval>();
            if (root.TryGetProperty("closures", out var cl))
            {
                closures.AddRange(cl.EnumerateArray().Select(c => new Interval(c.GetProperty("from").GetDateTimeOffset(), c.GetProperty("to").GetDateTimeOffset())));
            }

            return new ShiftSchedule(zone, patterns, closures);
        }
        catch (Exception ex) when (ex is JsonException or KeyNotFoundException or InvalidOperationException or TimeZoneNotFoundException or ArgumentException or FormatException)
        {
            throw new FormatException("Invalid shift calendar payload: " + ex.Message, ex);
        }
    }
}
