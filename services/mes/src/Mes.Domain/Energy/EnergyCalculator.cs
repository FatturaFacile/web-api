using Mes.Domain.Common;

namespace Mes.Domain.Energy;

public readonly record struct PowerPoint(DateTimeOffset At, PowerKw Power);

public static class EnergyCalculator
{
    /// <summary>
    /// Energy from a cumulative kWh meter. A decrease means a meter reset/replacement: the delta is unknowable and
    /// returned as null (never negative energy, never a guess).
    /// </summary>
    public static EnergyKwh? DeltaFromCumulative(EnergyKwh previous, EnergyKwh current) =>
        current.Value >= previous.Value ? new EnergyKwh(current.Value - previous.Value) : null;

    /// <summary>
    /// Trapezoidal integration of instantaneous power. Segments longer than <paramref name="maxGap"/> are NOT bridged
    /// (we do not invent energy across an outage); the skipped time is reported in <paramref name="skipped"/>.
    /// Points must be sorted by time; out-of-order points are skipped.
    /// </summary>
    public static EnergyKwh TrapezoidKwh(IReadOnlyList<PowerPoint> points, TimeSpan maxGap, out TimeSpan skipped)
    {
        double kwh = 0;
        skipped = TimeSpan.Zero;
        for (var i = 1; i < points.Count; i++)
        {
            var dt = points[i].At - points[i - 1].At;
            if (dt <= TimeSpan.Zero)
            {
                continue;
            }

            if (dt > maxGap)
            {
                skipped += dt;
                continue;
            }

            kwh += (points[i - 1].Power.Value + points[i].Power.Value) / 2.0 * dt.TotalHours;
        }

        return new EnergyKwh(kwh);
    }
}
