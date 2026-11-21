using Edge.Gateway.Mapping;
using Edge.Gateway.Time;
using Edge.Gateway.Wal;
using FluentAssertions;
using Mes.Application.Abstractions;
using Mes.Application.Contracts;
using Mes.Domain.Common;
using Mes.Domain.Machines;
using Mes.Domain.Telemetry;
using Microsoft.Data.Sqlite;
using Xunit;

namespace Mes.Tests;

public class ClockOffsetEstimatorTests
{
    [Fact]
    public void Symmetric_exchange_recovers_the_offset()
    {
        var e = new ClockOffsetEstimator();
        // local clock is 5 s behind the server; 20 ms each way; 2 ms server processing.
        e.Add(new ClockExchange(1_000, 6_020, 6_022, 1_042));
        e.Current.OffsetMs.Should().BeApproximately(5_000, 1e-9);
        e.Current.UncertaintyMs.Should().BeApproximately(20, 1e-9);
    }

    [Fact]
    public void Min_rtt_sample_wins_over_a_congested_one()
    {
        var e = new ClockOffsetEstimator();
        e.Add(new ClockExchange(0, 5_900, 5_901, 1_000));   // 1 s RTT, badly asymmetric -> offset estimate 5400
        e.Add(new ClockExchange(2_000, 7_010, 7_011, 2_022)); // 21 ms RTT -> ~5000
        e.Current.OffsetMs.Should().BeApproximately(5_000, 15);
        e.Current.UncertaintyMs.Should().BeLessThan(15);
    }

    [Fact]
    public void Non_causal_exchanges_are_discarded()
    {
        var e = new ClockOffsetEstimator();
        e.Add(new ClockExchange(100, 0, 0, 50));
        e.Current.Samples.Should().Be(0);
        double.IsInfinity(e.Current.UncertaintyMs).Should().BeTrue();
    }

    [Fact]
    public void Watermark_is_the_minimum_across_sources()
    {
        var w = new WatermarkTracker();
        w.Watermark.Should().BeNull();
        w.Observe("a", 100);
        w.Observe("b", 50);
        w.Observe("a", 120);
        w.Watermark.Should().Be(50);
        w.Forget("b");
        w.Watermark.Should().Be(120);
    }
}

public class TagMapperTests
{
    private static readonly DateTimeOffset Rx = new(2026, 1, 1, 8, 0, 0, TimeSpan.Zero);
    private readonly TenantId _t = TenantId.New();
    private readonly MachineId _m = MachineId.New();

    private TagMapSnapshot Snap(int v, double scale = 1, double offset = 0, double? deadband = null) =>
        TagMapSnapshot.Create(v, _t, _m, [new TagMapping("n1", new TagId("temp"), scale, offset, deadband)]);

    private static RawSample Raw(double v, DateTimeOffset? src = null, string addr = "n1") => new(addr, v, null, src, Rx, SampleQuality.Good);

    [Fact]
    public void Applies_scale_offset_and_stamps_version()
    {
        var m = new TagMapper(new ClockOffsetEstimator());
        m.Swap(Snap(3, 0.1, -5));
        var s = m.Map(Raw(250))!;
        s.NumericValue.Should().BeApproximately(20, 1e-12);
        s.MappingVersion.Should().Be(3);
        s.TagId.Value.Should().Be("temp");
        s.MachineId.Should().Be(_m);
    }

    [Fact]
    public void Unmapped_addresses_are_dropped() =>
        new TagMapper(new ClockOffsetEstimator()).Map(Raw(1)).Should().BeNull();

    [Fact]
    public void Hot_reload_only_accepts_newer_versions()
    {
        var m = new TagMapper(new ClockOffsetEstimator());
        m.Swap(Snap(2)).Should().BeTrue();
        m.Swap(Snap(1)).Should().BeFalse();
        m.Swap(Snap(2)).Should().BeFalse();
        m.Swap(Snap(3, scale: 2)).Should().BeTrue();
        m.Map(Raw(10))!.NumericValue.Should().Be(20);
    }

    [Fact]
    public void Deadband_suppresses_small_changes()
    {
        var m = new TagMapper(new ClockOffsetEstimator());
        m.Swap(Snap(1, deadband: 0.5));
        m.Map(Raw(10)).Should().NotBeNull();
        m.Map(Raw(10.2)).Should().BeNull();
        m.Map(Raw(10.6)).Should().NotBeNull();
    }

    [Fact]
    public void Event_time_prefers_device_clock_only_when_it_agrees_with_the_corrected_gateway_clock()
    {
        var clock = new ClockOffsetEstimator();
        clock.Add(new ClockExchange(0, 2_010, 2_010, 20)); // gateway 2 s behind server
        var m = new TagMapper(clock, TimeSpan.FromSeconds(5));
        m.Swap(Snap(1));

        var near = Rx.AddSeconds(2).AddMilliseconds(-300);
        m.Map(Raw(1, near))!.Times.EventTime.Should().Be(near);

        var wild = Rx.AddHours(3);
        m.Map(Raw(1, wild))!.Times.EventTime.Should().BeCloseTo(Rx.AddSeconds(2), TimeSpan.FromMilliseconds(5)); // device clock rejected

        m.Map(Raw(1, null))!.Times.EventTime.Should().BeCloseTo(Rx.AddSeconds(2), TimeSpan.FromMilliseconds(5));
    }
}

public sealed class WalStoreTests : IDisposable
{
    private readonly string _dir = Path.Combine(Path.GetTempPath(), "mes-wal-" + Guid.NewGuid().ToString("N"));

    private WalStore New(long maxBytes = 2L * 1024 * 1024 * 1024) => new(new WalOptions(Path.Combine(_dir, "wal.db"), maxBytes));

    private static WireBatch Batch(string id, int n, long t0 = 0, double value = 1) =>
        new(id, Guid.NewGuid(), "gw", t0 + n, Enumerable.Range(0, n).Select(i => new WireSample(Guid.Empty, "tag", t0 + i * 1000L, t0 + i * 1000L, value + i, null, 0, 1)).ToList());

    [Fact]
    public void Survives_restart_and_acks_remove_rows()
    {
        using (var w = New())
        {
            w.Append(Batch("b1", 3));
            w.Append(Batch("b2", 2));
            w.Append(Batch("b1", 3)); // same id: ignored (idempotent append)
            w.Pending.Should().Be(2);
        }

        SqliteConnection.ClearAllPools();
        using var again = New();
        var peek = again.PeekOldest(10);
        peek.Select(p => p.Batch.BatchId).Should().Equal("b1", "b2");
        peek[0].Batch.Samples.Should().HaveCount(3);
        again.Ack([peek[0].Id]);
        again.PeekOldest(10).Should().ContainSingle().Which.Batch.BatchId.Should().Be("b2");
    }

    [Fact]
    public void Uses_wal_journal_mode()
    {
        using var w = New();
        using var c = new SqliteConnection($"Data Source={Path.Combine(_dir, "wal.db")}");
        c.Open();
        using var cmd = c.CreateCommand();
        cmd.CommandText = "PRAGMA journal_mode;";
        ((string)cmd.ExecuteScalar()!).Should().Be("wal");
    }

    [Fact]
    public void Downsample_keeps_changes_edges_and_last_sample()
    {
        var m = Guid.NewGuid();
        var samples = new List<WireSample>();
        for (var i = 0; i < 100; i++)
        {
            samples.Add(new WireSample(m, "state", i * 1000L, i * 1000L, i == 40 ? 0 : 1, null, 0, 1)); // one dip at i=40
        }

        var slim = WalStore.Downsample(samples, 10_000);

        slim.Should().Contain(s => s.EventMs == 0);
        slim.Should().Contain(s => s.EventMs == 40_000 && s.V == 0); // the edge survives
        slim.Should().Contain(s => s.EventMs == 41_000);             // and the return to normal
        slim.Should().Contain(s => s.EventMs == 99_000);             // last sample always kept
        slim.Count.Should().BeLessThan(30);
    }

    [Fact]
    public void Disk_pressure_downsamples_then_drops_oldest_and_counts_it()
    {
        using var w = New(maxBytes: 60_000);
        for (var i = 0; i < 40; i++)
        {
            w.Append(Batch($"b{i:00}", 60, i * 100_000L));
        }

        w.PayloadBytes.Should().BeLessThanOrEqualTo(60_000);
        w.Meta("downsampled_batches").Should().BeGreaterThan(0);
        w.Meta("dropped_batches").Should().BeGreaterThan(0);
        w.PeekOldest(1).Single().Batch.BatchId.Should().NotBe("b00"); // oldest went first
    }

    public void Dispose()
    {
        SqliteConnection.ClearAllPools();
        try
        {
            Directory.Delete(_dir, true);
        }
        catch (IOException)
        {
            // best effort
        }
    }
}
