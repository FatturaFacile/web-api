using FluentAssertions;
using Mes.Application.Abstractions;
using Mes.Application.Commands;
using Mes.Application.Processing;
using Mes.Domain.Common;
using Mes.Domain.Machines;
using Mes.Domain.Oee;
using Mes.Domain.State;
using Mes.Tests.Support;
using Xunit;

namespace Mes.Tests;

public class IngestAndRecomputeTests
{
    private static readonly DateTimeOffset T0 = new(2026, 1, 1, 8, 0, 0, TimeSpan.Zero);
    private readonly TenantId _tenant = TenantId.New();
    private readonly MachineId _machine = MachineId.New();
    private readonly InMemoryMes _mem = new();
    private readonly FakeTime _time = new(T0.AddHours(1));
    private readonly IShiftCalendarProvider _calendar = new AlwaysPlannedCalendarProvider();

    private (IngestTelemetryBatchHandler Ingest, WindowRecomputer Recomputer, RecomputeWindowHandler Recompute, ClassifyDowntimeHandler Classify) Build(bool goodCounter = false)
    {
        _mem.Profiles[_machine] = InMemoryMes.Profile(_tenant, _machine, 1000, goodCounter);
        var registry = new MachineProcessorRegistry(_mem, _mem, new SignalStateMapper(), _mem);
        var live = new LiveViewBuilder(_mem, _calendar, _mem, _time);
        var recomputer = new WindowRecomputer(_mem, _mem, _calendar, _mem, _time);
        return (
            new IngestTelemetryBatchHandler(registry, _mem, _mem, _mem, _mem, _mem, _calendar, live, _time),
            recomputer,
            new RecomputeWindowHandler(recomputer, _mem, live, _mem),
            new ClassifyDowntimeHandler(_mem, recomputer, _mem, live, _mem, _time));
    }

    private IReadOnlyList<Mes.Domain.Telemetry.TelemetrySample> Scenario()
    {
        // 08:00 run; counter ticks every 10 s (+8 units); 08:03:00 stops for 2 min; 08:05 runs again; 08:06 alarm.
        var s = new List<Mes.Domain.Telemetry.TelemetrySample>();
        double count = 100;
        for (var sec = 0; sec <= 420; sec += 10)
        {
            var at = T0.AddSeconds(sec);
            var running = sec < 180 || (sec >= 300 && sec < 360);
            var alarm = sec >= 360;
            s.Add(InMemoryMes.Sample(_tenant, _machine, "run", at, running ? 1 : 0));
            s.Add(InMemoryMes.Sample(_tenant, _machine, "alarm", at, alarm ? 1 : 0));
            if (running)
            {
                count += 8;
            }

            s.Add(InMemoryMes.Sample(_tenant, _machine, "count", at, count));
        }

        return s;
    }

    [Fact]
    public async Task Ingest_builds_intervals_buckets_and_outbox_in_one_pass()
    {
        var (ingest, _, _, _) = Build();
        var r = await ingest.HandleAsync(new IngestTelemetryBatch(_tenant, "b1", Scenario()), default);

        r.Accepted.Should().BeGreaterThan(0);
        var closed = _mem.Intervals.Values.Where(i => !i.IsOpen).OrderBy(i => i.Start).ToList();
        closed.Select(i => i.State).Should().Equal(MachineState.Running, MachineState.Down, MachineState.Running);
        closed[0].End.Should().Be(T0.AddSeconds(180)); // back-dated to first Down sample, not +debounce
        _mem.Intervals.Values.Single(i => i.IsOpen).State.Should().Be(MachineState.Alarm);

        var sum = OeeProjector.Sum(_mem.Buckets.Values);
        sum.RunMs.Should().Be(180_000 + 60_000);
        sum.DownMs.Should().Be(120_000);
        sum.TotalCount.Should().BeGreaterThan(0);

        _mem.Outbox.Select(o => o.Type).Should().Contain([OutboxTypes.MachineAlarmRaised, OutboxTypes.ProductionCountCheckpoint]);
        _mem.Live.Should().ContainKey((_machine, LiveViewBuilder.ShiftScope));
    }

    [Fact]
    public async Task Count_checkpoint_names_the_order_being_produced_so_Laravel_can_attribute_it()
    {
        var operation = Guid.NewGuid().ToString();
        var run = new ProductionRun(Guid.NewGuid(), _tenant, _machine, operation, 1000, T0);
        await _mem.SaveRunAsync(run, default);
        var (ingest, _, _, _) = Build();

        await ingest.HandleAsync(new IngestTelemetryBatch(_tenant, "b-run", Scenario()), default);

        var payload = System.Text.Json.JsonDocument.Parse(_mem.Outbox.Single(o => o.Type == OutboxTypes.ProductionCountCheckpoint).PayloadJson).RootElement;
        payload.GetProperty("work_order_ref").GetString().Should().Be(operation);
        payload.GetProperty("run_id").GetGuid().Should().Be(run.Id);
    }

    [Fact]
    public async Task Count_checkpoint_without_an_open_run_carries_no_order()
    {
        var (ingest, _, _, _) = Build();

        await ingest.HandleAsync(new IngestTelemetryBatch(_tenant, "b-norun", Scenario()), default);

        var payload = System.Text.Json.JsonDocument.Parse(_mem.Outbox.Single(o => o.Type == OutboxTypes.ProductionCountCheckpoint).PayloadJson).RootElement;
        payload.TryGetProperty("work_order_ref", out _).Should().BeFalse();
    }

    [Fact]
    public async Task Replaying_the_same_batch_changes_nothing()
    {
        var (ingest, _, _, _) = Build();
        var samples = Scenario();
        await ingest.HandleAsync(new IngestTelemetryBatch(_tenant, "b1", samples), default);
        var before = OeeProjector.Sum(_mem.Buckets.Values);
        var outbox = _mem.Outbox.Count;

        var again = await ingest.HandleAsync(new IngestTelemetryBatch(_tenant, "b1", samples), default);

        again.DuplicateMachines.Should().Be(1);
        again.Accepted.Should().Be(0);
        OeeProjector.Sum(_mem.Buckets.Values).Should().Be(before);
        _mem.Outbox.Should().HaveCount(outbox);
    }

    [Fact]
    public async Task Cross_tenant_samples_are_rejected()
    {
        var (ingest, _, _, _) = Build();
        var foreign = new Mes.Domain.Telemetry.TelemetrySample(TenantId.New(), _machine, new TagId("run"), new(T0, T0), 1, null, Mes.Domain.Telemetry.SampleQuality.Good, 1);
        var r = await ingest.HandleAsync(new IngestTelemetryBatch(_tenant, "b", [foreign]), default);
        r.Rejected.Should().Be(1);
        _mem.Samples.Should().BeEmpty();
    }

    [Fact]
    public async Task Silence_longer_than_stale_threshold_becomes_Unknown_not_the_old_state()
    {
        var (ingest, _, _, _) = Build();
        await ingest.HandleAsync(new IngestTelemetryBatch(_tenant, "a", [InMemoryMes.Sample(_tenant, _machine, "run", T0, 1), InMemoryMes.Sample(_tenant, _machine, "run", T0.AddSeconds(30), 1)]), default);
        await ingest.HandleAsync(new IngestTelemetryBatch(_tenant, "b", [InMemoryMes.Sample(_tenant, _machine, "run", T0.AddMinutes(30), 1), InMemoryMes.Sample(_tenant, _machine, "run", T0.AddMinutes(30).AddSeconds(10), 1)]), default);

        var unknown = _mem.Intervals.Values.Single(i => i.State == MachineState.Unknown);
        unknown.Start.Should().Be(T0.AddSeconds(30));
        unknown.End.Should().Be(T0.AddMinutes(30));
        OeeProjector.Sum(_mem.Buckets.Values).UnknownMs.Should().Be(1_770_000);
    }

    [Fact]
    public async Task RecomputeWindow_is_idempotent_and_preserves_production()
    {
        var (ingest, _, recompute, _) = Build();
        await ingest.HandleAsync(new IngestTelemetryBatch(_tenant, "b1", Scenario()), default);
        var window = new Interval(T0, T0.AddMinutes(10));
        var before = OeeProjector.Sum(_mem.Buckets.Values);

        await recompute.HandleAsync(new RecomputeWindow(_tenant, _machine, window), default);
        var once = OeeProjector.Sum(_mem.Buckets.Values);
        var outboxAfterOnce = _mem.Outbox.Count;
        await recompute.HandleAsync(new RecomputeWindow(_tenant, _machine, window), default);
        var twice = OeeProjector.Sum(_mem.Buckets.Values);

        once.Should().Be(twice);
        once.TotalCount.Should().Be(before.TotalCount);
        once.IdealWorkMs.Should().Be(before.IdealWorkMs);
        once.RunMs.Should().Be(before.RunMs);
        _mem.Outbox.Should().HaveCount(outboxAfterOnce, "identical recompute yields an identical dedupe key");
    }

    [Fact]
    public async Task ClassifyDowntime_as_setup_moves_time_out_of_planned_when_policy_excludes_setup()
    {
        _mem.Profiles[_machine] = InMemoryMes.Profile(_tenant, _machine, 1000, false, new OeePolicySnapshot(2, SetupClassification.PlannedExcluded, GapPolicy.ExcludeFromPlanned));
        var (ingest, _, _, classify) = Build();
        _mem.Profiles[_machine] = InMemoryMes.Profile(_tenant, _machine, 1000, false, new OeePolicySnapshot(2, SetupClassification.PlannedExcluded, GapPolicy.ExcludeFromPlanned));
        await ingest.HandleAsync(new IngestTelemetryBatch(_tenant, "b1", Scenario()), default);
        var before = OeeProjector.Sum(_mem.Buckets.Values);

        await classify.HandleAsync(new ClassifyDowntime(_tenant, _machine, new Interval(T0.AddSeconds(180), T0.AddSeconds(240)), new ReasonCode("CHANGEOVER"), MachineState.Setup, "tester"), default);

        var after = OeeProjector.Sum(_mem.Buckets.Values);
        after.SetupMs.Should().Be(60_000);
        after.DownMs.Should().Be(before.DownMs - 60_000);
        after.PlannedMs.Should().Be(before.PlannedMs - 60_000);
        after.TotalCount.Should().Be(before.TotalCount);
        _mem.Corrections.Should().ContainSingle(c => c.Kind == "classify_downtime");
        _mem.Outbox.Should().Contain(o => o.Type == OutboxTypes.OeeSnapshotRevised);
        _mem.Intervals.Values.Where(i => i.Reason is { Value: "CHANGEOVER" }).Should().ContainSingle();
    }

    [Fact]
    public async Task ClassifyDowntime_rejects_windows_without_downtime()
    {
        var (ingest, _, _, classify) = Build();
        await ingest.HandleAsync(new IngestTelemetryBatch(_tenant, "b1", Scenario()), default);
        var act = () => classify.HandleAsync(new ClassifyDowntime(_tenant, _machine, new Interval(T0, T0.AddSeconds(60)), new ReasonCode("X"), null, null), default);
        await act.Should().ThrowAsync<MesValidationException>();
    }

    [Fact]
    public async Task ReportScrap_lowers_quality_and_is_validated()
    {
        var (ingest, _, _, _) = Build(goodCounter: false);
        var run = new ProductionRun(Guid.NewGuid(), _tenant, _machine, "WO-1", 1000, T0);
        run.Count(100);
        _mem.Runs[run.Id] = run;
        await ingest.HandleAsync(new IngestTelemetryBatch(_tenant, "b1", Scenario()), default);
        var before = OeeCalculator.Compute(OeeProjector.Sum(_mem.Buckets.Values));
        before.Quality.Should().BeNull(); // no quality source yet

        var live = new LiveViewBuilder(_mem, _calendar, _mem, _time);
        var scrap = new ReportScrapHandler(_mem, _mem, _mem, _mem, live, _time);
        await scrap.HandleAsync(new ReportScrap(_tenant, _machine, run.Id, new Quantity(10), T0.AddMinutes(1), "q"), default);

        var after = OeeCalculator.Compute(OeeProjector.Sum(_mem.Buckets.Values));
        after.Quality.Should().NotBeNull();
        after.Quality!.Value.Should().BeLessThan(1.0);
        run.ScrapCount.Should().Be(10);

        var tooMuch = () => scrap.HandleAsync(new ReportScrap(_tenant, _machine, run.Id, new Quantity(1000), T0, null), default);
        await tooMuch.Should().ThrowAsync<MesValidationException>();
    }

    [Fact]
    public async Task CloseShift_and_CompleteRun_emit_outbox_events_once()
    {
        var (ingest, _, _, _) = Build();
        await ingest.HandleAsync(new IngestTelemetryBatch(_tenant, "b1", Scenario()), default);
        var run = new ProductionRun(Guid.NewGuid(), _tenant, _machine, "WO-9", 1000, T0);
        _mem.Runs[run.Id] = run;

        var close = new CloseShiftHandler(_mem, _mem, _mem, _mem, _time);
        var shift = new Interval(T0, T0.AddHours(8));
        await close.HandleAsync(new CloseShift(_tenant, _machine, shift, "S1"), default);
        await close.HandleAsync(new CloseShift(_tenant, _machine, shift, "S1"), default);
        var complete = new CompleteProductionRunHandler(_mem, _mem, _mem, _time);
        await complete.HandleAsync(new CompleteProductionRun(_tenant, run.Id, T0.AddHours(2)), default);

        _mem.Outbox.Count(o => o.Type == OutboxTypes.OeeShiftClosed).Should().Be(1);
        _mem.Outbox.Count(o => o.Type == OutboxTypes.OeeOrderCompleted).Should().Be(1);
        run.Status.Should().Be(ProductionRunStatus.Completed);
    }
}

public class DomainValueTests
{
    [Fact]
    public void TenantId_new_is_uuid_v7() => TenantId.New().Value.Version.Should().Be(7);

    [Fact]
    public void Partitioning_is_stable_and_in_range()
    {
        var m = MachineId.New();
        m.PartitionOf(8).Should().Be(m.PartitionOf(8));
        Enumerable.Range(0, 1000).Select(_ => MachineId.New().PartitionOf(8)).Should().OnlyContain(p => p >= 0 && p < 8);
        Enumerable.Range(0, 1000).Select(_ => MachineId.New().PartitionOf(8)).Distinct().Count().Should().Be(8);
    }

    [Fact]
    public void Value_objects_validate()
    {
        FluentActions.Invoking(() => new Quantity(-1)).Should().Throw<ArgumentOutOfRangeException>();
        FluentActions.Invoking(() => new Ratio(double.NaN)).Should().Throw<ArgumentOutOfRangeException>();
        FluentActions.Invoking(() => new Interval(DateTimeOffset.UnixEpoch.AddSeconds(5), DateTimeOffset.UnixEpoch)).Should().Throw<ArgumentException>();
        new Ratio(1.7).Capped().Value.Should().Be(1.0);
    }
}
