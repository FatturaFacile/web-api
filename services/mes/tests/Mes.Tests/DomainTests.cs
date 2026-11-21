using FsCheck;
using FluentAssertions;
using Interval = Mes.Domain.Common.Interval;
using FsCheck.Xunit;
using Mes.Domain.Common;
using Mes.Domain.Counters;
using Mes.Domain.Energy;
using Mes.Domain.Oee;
using Mes.Domain.State;
using Xunit;

namespace Mes.Tests;

public class OeeCalculatorTests
{
    private static readonly OeePolicySnapshot Policy = new(1, SetupClassification.AvailabilityLoss, GapPolicy.ExcludeFromPlanned);

    [Fact]
    public void Single_order_matches_hand_computed_values()
    {
        // 8h planned, 6h20m run, ideal 1s/unit, 20_000 units of which 19_000 good.
        var acc = OeeAccumulator.Empty
            .WithTime(MachineState.Running, 22_800_000, Policy) // 6h20m
            .WithTime(MachineState.Down, 6_000_000, Policy)     // 1h40m
            .WithProduction(20_000, 19_000, 1000);

        var r = OeeCalculator.Compute(acc);

        r.Availability.Should().BeApproximately(22_800_000d / 28_800_000d, 1e-12);
        r.Performance.Should().BeApproximately(20_000_000d / 22_800_000d, 1e-12);
        r.Quality.Should().BeApproximately(0.95, 1e-12);
        r.Oee.Should().BeApproximately(19_000_000d / 28_800_000d, 1e-12);
        r.IsComplete.Should().BeTrue();
        OeeCalculator.InvariantHolds(acc).Should().BeTrue();
    }

    [Fact]
    public void Live_shift_to_date_is_sum_of_bucket_accumulators()
    {
        var buckets = Enumerable.Range(0, 90).Select(i =>
            OeeAccumulator.Empty
                .WithTime(i % 10 == 0 ? MachineState.Down : MachineState.Running, 60_000, Policy)
                .WithProduction(i % 10 == 0 ? 0 : 50, i % 10 == 0 ? 0 : 48, 1000)).ToList();

        var sum = buckets.Aggregate(OeeAccumulator.Empty, (a, b) => a + b);
        var r = OeeCalculator.Compute(sum);

        sum.PlannedMs.Should().Be(90 * 60_000);
        sum.RunMs.Should().Be(81 * 60_000);
        r.Oee.Should().BeApproximately(sum.IdealGoodMs / sum.PlannedMs, 1e-12);
        r.Availability.Should().BeApproximately(0.9, 1e-12);
    }

    [Fact]
    public void Multi_order_is_ideal_time_weighted_not_an_average_of_percentages()
    {
        // Order A: 1h planned/run, cycle 1s, 3000 units, 2900 good. Order B: 3h planned, 1h run, cycle 0.5s, 5000 units, 4000 good.
        var a = OeeAccumulator.Empty.WithTime(MachineState.Running, 3_600_000, Policy).WithProduction(3000, 2900, 1000);
        var b = OeeAccumulator.Empty
            .WithTime(MachineState.Running, 3_600_000, Policy)
            .WithTime(MachineState.Down, 7_200_000, Policy)
            .WithProduction(5000, 4000, 500);

        var combined = a + b;
        var oee = OeeCalculator.Compute(combined);
        var naiveAverage = (OeeCalculator.Compute(a).Oee!.Value + OeeCalculator.Compute(b).Oee!.Value) / 2;

        oee.Oee.Should().BeApproximately(combined.IdealGoodMs / combined.PlannedMs, 1e-12);
        oee.Oee!.Value.Should().NotBeApproximately(naiveAverage, 0.01); // the classic averaging bug would land here
        oee.Quality.Should().BeApproximately((2_900_000d + 2_000_000d) / (3_000_000d + 2_500_000d), 1e-12);
        OeeCalculator.InvariantHolds(combined).Should().BeTrue();
    }

    [Fact]
    public void Null_reasons_are_explicit()
    {
        OeeCalculator.Compute(OeeAccumulator.Empty).NullReasons.Should().Contain(OeeNullReason.NoPlannedTime);

        var noQuality = OeeAccumulator.Empty.WithTime(MachineState.Running, 1000, Policy).WithProduction(1, null, 500);
        var r = OeeCalculator.Compute(noQuality);
        r.Quality.Should().BeNull();
        r.NullReasons.Should().Contain(OeeNullReason.NoQualityData);
        r.Oee.Should().BeApproximately(0.5, 1e-12); // Q defaults to 1

        OeeCalculator.Compute(OeeAccumulator.Empty.WithTime(MachineState.Down, 1000, Policy)).Oee.Should().Be(0.0);
    }

    [Fact]
    public void Performance_above_one_is_capped_in_oee_but_reported()
    {
        var acc = OeeAccumulator.Empty.WithTime(MachineState.Running, 1000, Policy).WithProduction(10, 10, 200); // ideal work 2000 ms in 1000 ms run
        var r = OeeCalculator.Compute(acc);
        r.Performance.Should().BeApproximately(2.0, 1e-12);
        r.Oee.Should().BeApproximately(1.0, 1e-12);
    }

    [Fact]
    public void Setup_and_gap_policies_change_planned_time()
    {
        var loss = new OeePolicySnapshot(1, SetupClassification.AvailabilityLoss, GapPolicy.CountAsDowntime);
        var excl = new OeePolicySnapshot(2, SetupClassification.PlannedExcluded, GapPolicy.ExcludeFromPlanned);

        OeeAccumulator Build(OeePolicySnapshot p) => OeeAccumulator.Empty
            .WithTime(MachineState.Running, 600, p).WithTime(MachineState.Setup, 200, p).WithTime(MachineState.Unknown, 200, p);

        Build(loss).PlannedMs.Should().Be(1000);
        Build(excl).PlannedMs.Should().Be(600);
        OeeCalculator.Compute(Build(excl)).CoveragePct.Should().BeApproximately(100.0 * 800 / 1000, 1e-9);
    }

    [Property]
    public bool Invariant_holds_whenever_performance_is_at_most_one(PositiveInt run, NonNegativeInt idle, PositiveInt units, PositiveInt goodPct, PositiveInt fill)
    {
        var runMs = (long)run.Get;
        var n = (long)units.Get;
        var cycle = runMs * ((fill.Get % 100) + 1) / 100.0 / n; // P in (0, 1]
        var good = n * (goodPct.Get % 101) / 100;
        var acc = OeeAccumulator.Empty
            .WithTime(MachineState.Running, runMs, Policy)
            .WithTime(MachineState.Down, idle.Get, Policy)
            .WithProduction(n, good, cycle);
        var r = OeeCalculator.Compute(acc);
        return OeeCalculator.InvariantHolds(acc) && r.Oee <= 1.0 + 1e-9 && r.Oee >= 0;
    }
}

public class MachineStateMachineTests
{
    private static readonly DateTimeOffset T0 = new(2026, 1, 1, 8, 0, 0, TimeSpan.Zero);
    private static MachineStateMachine New(double debounceSeconds = 5) => new(MachineId.New(), TimeSpan.FromSeconds(debounceSeconds));

    [Fact]
    public void Transition_is_committed_after_debounce_and_back_dated_to_first_seen()
    {
        var m = New();
        m.Observe(MachineState.Running, T0).Should().BeEmpty();
        m.Observe(MachineState.Down, T0.AddSeconds(10)).Should().BeEmpty();
        m.Observe(MachineState.Down, T0.AddSeconds(12)).Should().BeEmpty();

        var closed = m.Observe(MachineState.Down, T0.AddSeconds(15));

        closed.Should().ContainSingle();
        closed[0].State.Should().Be(MachineState.Running);
        closed[0].Start.Should().Be(T0);
        closed[0].End.Should().Be(T0.AddSeconds(10)); // back-dated, not 15
        m.Current!.Start.Should().Be(T0.AddSeconds(10));
        m.State.Should().Be(MachineState.Down);
    }

    [Fact]
    public void Micro_stop_shorter_than_debounce_is_absorbed()
    {
        var m = New();
        m.Observe(MachineState.Running, T0);
        m.Observe(MachineState.Down, T0.AddSeconds(10));
        m.Observe(MachineState.Running, T0.AddSeconds(13)).Should().BeEmpty();
        m.Observe(MachineState.Running, T0.AddSeconds(30)).Should().BeEmpty();

        m.State.Should().Be(MachineState.Running);
        m.Current!.Start.Should().Be(T0);
    }

    [Fact]
    public void Flapping_candidate_restarts_the_debounce_window()
    {
        var m = New();
        m.Observe(MachineState.Running, T0);
        m.Observe(MachineState.Down, T0.AddSeconds(10));
        m.Observe(MachineState.Alarm, T0.AddSeconds(12)); // different candidate: window restarts at +12
        m.Observe(MachineState.Alarm, T0.AddSeconds(16)).Should().BeEmpty();
        var closed = m.Observe(MachineState.Alarm, T0.AddSeconds(17));
        closed.Should().ContainSingle().Which.End.Should().Be(T0.AddSeconds(12));
    }

    [Fact]
    public void Back_dating_never_precedes_current_interval_start_and_never_emits_zero_length()
    {
        var m = New();
        m.Restore(new StateInterval(MachineId.New(), MachineState.Running, T0, null));
        m.Observe(MachineState.Down, T0);
        m.Observe(MachineState.Down, T0.AddSeconds(5)).Should().BeEmpty(); // boundary == start: nothing to close
        m.State.Should().Be(MachineState.Down);
        m.Current!.Start.Should().Be(T0);
    }

    [Fact]
    public void Out_of_order_samples_are_ignored()
    {
        var m = New(0);
        m.Observe(MachineState.Running, T0.AddSeconds(10));
        m.Observe(MachineState.Down, T0.AddSeconds(5)).Should().BeEmpty();
        m.State.Should().Be(MachineState.Running);
    }

    [Fact]
    public void Force_transition_is_clamped_and_immediate()
    {
        var m = New();
        m.Observe(MachineState.Running, T0.AddSeconds(10));
        var closed = m.ForceTransition(MachineState.Unknown, T0); // earlier than interval start
        closed!.End.Should().Be(T0.AddSeconds(10));
        m.State.Should().Be(MachineState.Unknown);
    }

    [Fact]
    public void Signal_mapper_priorities()
    {
        var map = new SignalStateMapper();
        map.Map(new StateSignals(true, true, null, null)).Should().Be(MachineState.Alarm);
        map.Map(new StateSignals(true, false, true, null)).Should().Be(MachineState.Setup);
        map.Map(new StateSignals(null, null, null, 120)).Should().Be(MachineState.Running);
        map.Map(new StateSignals(false, false, false, 0)).Should().Be(MachineState.Down);
        map.Map(new StateSignals(null, null, null, null)).Should().Be(MachineState.Unknown);
    }
}

public class CounterTrackerTests
{
    private static CounterTracker New(decimal? mod = 65536m, decimal max = 1000m) => new(new CounterPolicy(mod, max));

    [Fact]
    public void Counts_deltas_after_baseline()
    {
        var c = New();
        c.Observe(100).Kind.Should().Be(CounterEventKind.Baseline);
        c.Observe(105).Delta.Should().Be(5);
        c.Observe(105).Delta.Should().Be(0);
    }

    [Fact]
    public void Rollover_is_bridged()
    {
        var c = New();
        c.Observe(65530);
        var r = c.Observe(4);
        r.Kind.Should().Be(CounterEventKind.Rollover);
        r.Delta.Should().Be(10);
    }

    [Fact]
    public void Reset_to_zero_counts_new_production()
    {
        var c = New();
        c.Observe(5000);
        var r = c.Observe(12);
        r.Kind.Should().Be(CounterEventKind.Reset);
        r.Delta.Should().Be(12);
    }

    [Fact]
    public void Implausible_jump_is_quarantined_and_later_rebaselined_without_counting()
    {
        var c = New(mod: null);
        c.Observe(100);
        c.Observe(1_000_000).Kind.Should().Be(CounterEventKind.Quarantined);
        c.Observe(103).Delta.Should().Be(3); // glitch: old baseline still valid
        c.QuarantinedCount.Should().Be(1);

        var d = New(mod: null);
        d.Observe(100);
        d.Observe(1_000_000).Delta.Should().Be(0);
        d.Observe(1_000_010).Kind.Should().Be(CounterEventKind.Rebaselined); // PLC genuinely jumped
        d.Observe(1_000_015).Delta.Should().Be(5);
    }
}

public class EnergyCalculatorTests
{
    private static readonly DateTimeOffset T0 = new(2026, 1, 1, 0, 0, 0, TimeSpan.Zero);

    [Fact]
    public void Cumulative_delta_and_meter_reset()
    {
        EnergyCalculator.DeltaFromCumulative(new EnergyKwh(10), new EnergyKwh(12.5))!.Value.Value.Should().Be(2.5);
        EnergyCalculator.DeltaFromCumulative(new EnergyKwh(10), new EnergyKwh(1)).Should().BeNull();
    }

    [Fact]
    public void Trapezoid_integrates_and_does_not_bridge_gaps()
    {
        var pts = new List<PowerPoint>
        {
            new(T0, new PowerKw(10)),
            new(T0.AddMinutes(30), new PowerKw(20)),      // (10+20)/2 * 0.5h = 7.5
            new(T0.AddMinutes(40), new PowerKw(20)),      // 20 * 1/6h = 3.333..
            new(T0.AddHours(5), new PowerKw(20)),         // 4h20m gap > maxGap: skipped
            new(T0.AddHours(5).AddMinutes(6), new PowerKw(10)), // 15 * 0.1h = 1.5
        };

        var e = EnergyCalculator.TrapezoidKwh(pts, TimeSpan.FromMinutes(45), out var skipped);

        e.Value.Should().BeApproximately(7.5 + 20.0 / 6 + 1.5, 1e-9);
        skipped.Should().Be(TimeSpan.FromHours(5) - TimeSpan.FromMinutes(40));
    }
}

public class BucketSplitterTests
{
    [Fact]
    public void Splits_on_minute_boundaries()
    {
        var start = new DateTimeOffset(2026, 1, 1, 8, 0, 50, TimeSpan.Zero);
        var parts = BucketSplitter.SplitAcrossBuckets(new Interval(start, start.AddSeconds(80)));
        parts.Should().HaveCount(3);
        parts[0].Should().Be((new DateTimeOffset(2026, 1, 1, 8, 0, 0, TimeSpan.Zero), 10_000L));
        parts[1].Should().Be((new DateTimeOffset(2026, 1, 1, 8, 1, 0, TimeSpan.Zero), 60_000L));
        parts[2].Should().Be((new DateTimeOffset(2026, 1, 1, 8, 2, 0, TimeSpan.Zero), 10_000L));
    }

    [Property]
    public bool Shares_always_sum_to_the_interval_duration(NonNegativeInt startMs, NonNegativeInt durationMs)
    {
        var start = DateTimeOffset.UnixEpoch.AddMilliseconds(startMs.Get);
        var iv = new Interval(start, start.AddMilliseconds(durationMs.Get));
        var parts = BucketSplitter.SplitAcrossBuckets(iv);
        return parts.Sum(p => p.Ms) == iv.DurationMs && parts.All(p => p.Ms > 0 && p.Ms <= 60_000);
    }
}
