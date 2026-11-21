using FluentAssertions;
using Mes.Application.Abstractions;
using Mes.Application.Calendar;
using Mes.Application.Commands;
using Mes.Application.Processing;
using Mes.Domain.Common;
using Mes.Domain.Counters;
using Mes.Domain.State;
using Mes.Presentation;
using Mes.Tests.Support;
using Xunit;
using Interval = Mes.Domain.Common.Interval;

namespace Mes.Tests;

public class CounterRestoreTests
{
    private static CounterTracker Tracker(decimal? mod = 65536m, decimal max = 1000m, decimal afterRestart = 100_000m) => new(new CounterPolicy(mod, max, 2, afterRestart));

    [Fact]
    public void Restored_baseline_attributes_production_that_happened_while_down()
    {
        var t = Tracker();
        t.Restore(10_000);
        var r = t.Observe(14_500); // 4500 > normal ceiling (1000) but legitimate after an outage
        r.Kind.Should().Be(CounterEventKind.Normal);
        r.Delta.Should().Be(4500);
    }

    [Fact]
    public void Relaxed_ceiling_applies_to_the_first_sample_only()
    {
        var t = Tracker();
        t.Restore(0);
        t.Observe(4000).Delta.Should().Be(4000);
        t.Observe(9000).Kind.Should().Be(CounterEventKind.Quarantined); // back to the strict ceiling
    }

    [Fact]
    public void Rollover_while_down_is_bridged()
    {
        var t = Tracker();
        t.Restore(65_530);
        var r = t.Observe(4);
        r.Kind.Should().Be(CounterEventKind.Rollover);
        r.Delta.Should().Be(10);
    }

    [Fact]
    public void Reset_while_down_is_not_mistaken_for_a_rollover()
    {
        var t = Tracker();
        t.Restore(5_000);
        var r = t.Observe(12); // would be a 60 548-unit "rollover": more than half the modulus, so it is a reset
        r.Kind.Should().Be(CounterEventKind.Reset);
        r.Delta.Should().Be(12);
    }

    [Fact]
    public void Absurd_jump_after_restart_is_still_quarantined()
    {
        var t = Tracker(mod: null);
        t.Restore(100);
        t.Observe(5_000_000).Kind.Should().Be(CounterEventKind.Quarantined);
    }

    [Fact]
    public void Last_exposes_the_value_to_checkpoint()
    {
        var t = Tracker();
        t.Last.Should().BeNull();
        t.Observe(7);
        t.Last.Should().Be(7);
    }
}

public class RestartAttributionTests
{
    private static readonly DateTimeOffset T0 = new(2026, 1, 1, 8, 0, 0, TimeSpan.Zero);
    private readonly TenantId _tenant = TenantId.New();
    private readonly MachineId _machine = MachineId.New();
    private readonly InMemoryMes _mem = new();
    private readonly FakeTime _time = new(T0.AddHours(1));

    private IngestTelemetryBatchHandler NewProcess()
    {
        // A "process" = fresh registry (empty processor cache) + fresh handler over the SAME durable store.
        _mem.Profiles[_machine] = InMemoryMes.Profile(_tenant, _machine, 1000);
        var calendar = new AlwaysPlannedCalendarProvider();
        var registry = new MachineProcessorRegistry(_mem, _mem, new SignalStateMapper(), _mem);
        return new IngestTelemetryBatchHandler(registry, _mem, _mem, _mem, _mem, _mem, calendar, new LiveViewBuilder(_mem, calendar, _mem, _time), _time);
    }

    private IReadOnlyList<Mes.Domain.Telemetry.TelemetrySample> Run(double count, int fromSec, int toSec) =>
        Enumerable.Range(0, (toSec - fromSec) / 10 + 1).SelectMany(i =>
        {
            var at = T0.AddSeconds(fromSec + i * 10);
            var isLast = fromSec + i * 10 == toSec;
            return new[]
            {
                InMemoryMes.Sample(_tenant, _machine, "run", at, 1),
                InMemoryMes.Sample(_tenant, _machine, "count", at, isLast ? count : count - 1),
            };
        }).ToList();

    private long TotalCount => OeeProjector.Sum(_mem.Buckets.Values).TotalCount;

    [Fact]
    public async Task Counts_between_last_sample_and_first_sample_after_restart_are_attributed()
    {
        var p1 = NewProcess();
        await p1.HandleAsync(new IngestTelemetryBatch(_tenant, "b1", Run(1000, 0, 60)), default);
        var before = TotalCount;
        _mem.Checkpoints[(_machine, "total")].Value.Should().Be(1000);

        // --- service restarts; machine kept producing 450 units meanwhile ---
        var p2 = NewProcess();
        await p2.HandleAsync(new IngestTelemetryBatch(_tenant, "b2", [InMemoryMes.Sample(_tenant, _machine, "run", T0.AddSeconds(120), 1), InMemoryMes.Sample(_tenant, _machine, "count", T0.AddSeconds(120), 1450)]), default);

        TotalCount.Should().Be(before + 450);
        _mem.Checkpoints[(_machine, "total")].Value.Should().Be(1450);
    }

    [Fact]
    public async Task Without_checkpoint_the_old_behaviour_would_have_lost_them()
    {
        // Documents the bug being fixed: wiping the checkpoint reproduces the pre-fix attribution gap.
        var p1 = NewProcess();
        await p1.HandleAsync(new IngestTelemetryBatch(_tenant, "b1", Run(1000, 0, 60)), default);
        var before = TotalCount;
        _mem.Checkpoints.Clear();

        var p2 = NewProcess();
        await p2.HandleAsync(new IngestTelemetryBatch(_tenant, "b2", [InMemoryMes.Sample(_tenant, _machine, "run", T0.AddSeconds(120), 1), InMemoryMes.Sample(_tenant, _machine, "count", T0.AddSeconds(120), 1450)]), default);

        TotalCount.Should().Be(before);
    }

    [Fact]
    public async Task Rollover_across_a_restart()
    {
        var p1 = NewProcess();
        await p1.HandleAsync(new IngestTelemetryBatch(_tenant, "b1", Run(65_530, 0, 30)), default);
        var before = TotalCount;

        var p2 = NewProcess();
        await p2.HandleAsync(new IngestTelemetryBatch(_tenant, "b2", [InMemoryMes.Sample(_tenant, _machine, "run", T0.AddSeconds(90), 1), InMemoryMes.Sample(_tenant, _machine, "count", T0.AddSeconds(90), 4)]), default);

        TotalCount.Should().Be(before + 10);
    }

    [Fact]
    public async Task Reset_across_a_restart_counts_from_zero()
    {
        var p1 = NewProcess();
        await p1.HandleAsync(new IngestTelemetryBatch(_tenant, "b1", Run(5_000, 0, 30)), default);
        var before = TotalCount;

        var p2 = NewProcess();
        await p2.HandleAsync(new IngestTelemetryBatch(_tenant, "b2", [InMemoryMes.Sample(_tenant, _machine, "run", T0.AddSeconds(90), 1), InMemoryMes.Sample(_tenant, _machine, "count", T0.AddSeconds(90), 12)]), default);

        TotalCount.Should().Be(before + 12);
    }

    [Fact]
    public async Task Failed_commit_rolls_back_checkpoint_and_the_retry_counts_exactly_once()
    {
        var p = NewProcess();
        await p.HandleAsync(new IngestTelemetryBatch(_tenant, "b1", Run(1000, 0, 60)), default);
        var before = TotalCount;

        var retryBatch = new[] { InMemoryMes.Sample(_tenant, _machine, "run", T0.AddSeconds(70), 1), InMemoryMes.Sample(_tenant, _machine, "count", T0.AddSeconds(70), 1040) };
        _mem.FailNextRuns = 1;
        var act = () => p.HandleAsync(new IngestTelemetryBatch(_tenant, "b2", retryBatch), default);
        await act.Should().ThrowAsync<InvalidOperationException>();

        TotalCount.Should().Be(before, "the failed transaction rolled back buckets AND checkpoint together");
        _mem.Checkpoints[(_machine, "total")].Value.Should().Be(1000);

        // edge resends the same batch; the evicted processor rebuilds from the committed checkpoint
        await p.HandleAsync(new IngestTelemetryBatch(_tenant, "b2", retryBatch), default);
        TotalCount.Should().Be(before + 40);
    }
}

public class ShiftScheduleTests
{
    private static ShiftSchedule Utc(string shifts, string closures = "[]") =>
        ShiftSchedule.Parse($$"""{"timezone":"UTC","shifts":{{shifts}},"closures":{{closures}}}""");

    private static DateTimeOffset At(int day, int h, int m = 0) => new(2026, 6, day, h, m, 0, TimeSpan.Zero);

    // 2026-06-01 is a Monday.
    [Fact]
    public void Day_shift_on_weekdays_only()
    {
        var s = Utc("""[{"days":[1,2,3,4,5],"start":"06:00","end":"14:00"}]""");
        s.PlannedWindows(new Interval(At(1, 0), At(2, 0))).Should().Equal(new Interval(At(1, 6), At(1, 14)));
        s.PlannedWindows(new Interval(At(6, 0), At(7, 0))).Should().BeEmpty(); // Saturday
    }

    [Fact]
    public void Night_shift_started_the_previous_day_is_included()
    {
        var s = Utc("""[{"days":[1,2,3,4,5],"start":"22:00","end":"06:00"}]""");
        s.PlannedWindows(new Interval(At(2, 0), At(2, 12))).Should().Equal(new Interval(At(2, 0), At(2, 6)));
        s.PlannedWindows(new Interval(At(1, 20), At(2, 8))).Should().Equal(new Interval(At(1, 22), At(2, 6)));
    }

    [Fact]
    public void Overlapping_shifts_are_merged_and_closures_subtracted()
    {
        var s = Utc("""[{"days":[1],"start":"06:00","end":"14:00"},{"days":[1],"start":"12:00","end":"16:00"}]""", """[{"from":"2026-06-01T10:00:00Z","to":"2026-06-01T11:00:00Z"}]""");
        s.PlannedWindows(new Interval(At(1, 0), At(2, 0))).Should().Equal(new Interval(At(1, 6), At(1, 10)), new Interval(At(1, 11), At(1, 16)));
    }

    [Fact]
    public void Dst_keeps_local_wall_clock_time()
    {
        var zone = Environment.OSVersion.Platform == PlatformID.Win32NT ? "W. Europe Standard Time" : "Europe/Rome";
        var s = ShiftSchedule.Parse($$"""{"timezone":"{{zone}}","shifts":[{"days":[1,2,3,4,5,6,7],"start":"06:00","end":"14:00"}]}""");

        // 2026-03-28 is CET (UTC+1), 2026-03-29 is CEST (UTC+2): the same 06:00 local is an hour earlier in UTC.
        var sat = s.PlannedWindows(new Interval(new DateTimeOffset(2026, 3, 28, 0, 0, 0, TimeSpan.Zero), new DateTimeOffset(2026, 3, 28, 23, 0, 0, TimeSpan.Zero)));
        var sun = s.PlannedWindows(new Interval(new DateTimeOffset(2026, 3, 29, 0, 0, 0, TimeSpan.Zero), new DateTimeOffset(2026, 3, 29, 23, 0, 0, TimeSpan.Zero)));
        sat.Single().Start.Should().Be(new DateTimeOffset(2026, 3, 28, 5, 0, 0, TimeSpan.Zero));
        sun.Single().Start.Should().Be(new DateTimeOffset(2026, 3, 29, 4, 0, 0, TimeSpan.Zero));
    }

    [Theory]
    [InlineData("not json")]
    [InlineData("""{"shifts":[{"days":[9],"start":"06:00","end":"14:00"}]}""")]
    [InlineData("""{"shifts":[{"days":[1],"start":"6am","end":"14:00"}]}""")]
    [InlineData("""{"timezone":"Mars/Olympus","shifts":[]}""")]
    [InlineData("""{"nothing":true}""")]
    public void Invalid_payloads_fail_with_a_clear_exception(string json) =>
        FluentActions.Invoking(() => ShiftSchedule.Parse(json)).Should().Throw<FormatException>().WithMessage("Invalid shift calendar payload*");

    private sealed class ScheduleProvider(ShiftSchedule s) : IShiftCalendarProvider
    {
        public Task<IReadOnlyList<Interval>> PlannedWindowsAsync(TenantId tenant, MachineId machine, Interval window, CancellationToken ct) => Task.FromResult(s.PlannedWindows(window));
    }

    [Fact]
    public async Task Time_outside_planned_windows_never_reaches_the_buckets()
    {
        var tenant = TenantId.New();
        var machine = MachineId.New();
        var mem = new InMemoryMes();
        mem.Profiles[machine] = InMemoryMes.Profile(tenant, machine);
        var calendar = new ScheduleProvider(Utc("""[{"days":[1],"start":"06:00","end":"07:00"}]"""));
        var time = new FakeTime(At(1, 12));
        var handler = new IngestTelemetryBatchHandler(new MachineProcessorRegistry(mem, mem, new SignalStateMapper(), mem), mem, mem, mem, mem, mem, calendar, new LiveViewBuilder(mem, calendar, mem, time), time);

        // Running continuously 05:00 -> 08:00 (samples each 30 s), only 06:00-07:00 is planned production time.
        var samples = Enumerable.Range(0, 361).Select(i => InMemoryMes.Sample(tenant, machine, "run", At(1, 5).AddSeconds(i * 30), 1)).ToList();
        await handler.HandleAsync(new IngestTelemetryBatch(tenant, "b", samples), default);
        // close the open interval by flipping state after 08:00
        await handler.HandleAsync(new IngestTelemetryBatch(tenant, "c", [InMemoryMes.Sample(tenant, machine, "run", At(1, 8, 1), 0), InMemoryMes.Sample(tenant, machine, "run", At(1, 8, 2), 0)]), default);

        var sum = OeeProjector.Sum(mem.Buckets.Values);
        sum.PlannedMs.Should().Be(3_600_000);
        sum.RunMs.Should().Be(3_600_000);
    }
}

public class GatewayCertificateTests
{
    private static readonly Guid TenantA = Guid.NewGuid();

    private static readonly List<GatewayCertificateEntry> Gateways =
    [
        new() { Thumbprint = "AB:CD:12:34", TenantId = TenantA, GatewayId = "gw-1" },
        new() { Thumbprint = "FFFF", TenantId = Guid.Empty },
    ];

    [Theory]
    [InlineData("ABCD1234")]
    [InlineData("abcd1234")]
    [InlineData("AB CD 12 34")]
    public void Thumbprint_matches_regardless_of_case_and_separators(string tp)
    {
        CertificateTenantMap.TryMap(tp, Gateways, out var e).Should().BeTrue();
        e!.TenantId.Should().Be(TenantA);
    }

    [Theory]
    [InlineData(null)]
    [InlineData("")]
    [InlineData("DEADBEEF")]
    [InlineData("FFFF")] // enrolled without a tenant: never authenticates
    public void Unknown_or_tenantless_certificates_are_rejected(string? tp) =>
        CertificateTenantMap.TryMap(tp, Gateways, out _).Should().BeFalse();
}
