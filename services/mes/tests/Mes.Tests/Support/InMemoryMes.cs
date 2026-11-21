using Mes.Application.Abstractions;
using Mes.Domain.Common;
using Mes.Domain.Counters;
using Mes.Domain.Machines;
using Mes.Domain.Oee;
using Mes.Domain.State;
using Mes.Domain.Telemetry;

namespace Mes.Tests.Support;

public sealed class FakeTime(DateTimeOffset start) : TimeProvider
{
    private DateTimeOffset _now = start;

    public override DateTimeOffset GetUtcNow() => _now;

    public void Advance(TimeSpan by) => _now += by;
}

/// <summary>Single in-memory implementation of every Mes persistence port, for handler-level tests.</summary>
public sealed class InMemoryMes : IMesStore, ITelemetryStore, IOutbox, ITransactionRunner, IMachineProfileProvider, IOeeLiveStore, ICounterCheckpointStore
{
    public Dictionary<(MachineId, DateTimeOffset), StateInterval> Intervals { get; } = [];

    public Dictionary<(MachineId, DateTimeOffset), OeeBucket> Buckets { get; } = [];

    public List<OutboxMessage> Outbox { get; } = [];

    public List<Correction> Corrections { get; } = [];

    public HashSet<string> Batches { get; } = [];

    public Dictionary<Guid, ProductionRun> Runs { get; } = [];

    public List<TelemetrySample> Samples { get; } = [];

    public Dictionary<MachineId, MachineProfile> Profiles { get; } = [];

    public Dictionary<(MachineId, string), OeeLiveView> Live { get; } = [];

    public Dictionary<(MachineId, string), CounterCheckpoint> Checkpoints { get; } = [];

    public int FailNextRuns { get; set; }

    public Task<IReadOnlyList<CounterCheckpoint>> LoadAsync(TenantId tenant, MachineId machine, CancellationToken ct) =>
        Task.FromResult<IReadOnlyList<CounterCheckpoint>>(Checkpoints.Where(kv => kv.Key.Item1 == machine).Select(kv => kv.Value).ToList());

    public Task SaveAsync(TenantId tenant, MachineId machine, IReadOnlyList<CounterCheckpoint> checkpoints, CancellationToken ct)
    {
        foreach (var cp in checkpoints)
        {
            Checkpoints[(machine, cp.Key)] = cp;
        }

        return Task.CompletedTask;
    }

    /// <summary>Real transactional semantics are approximated: a failing run restores a snapshot of everything it could touch.</summary>
    public async Task RunAsync(Func<CancellationToken, Task> work, CancellationToken ct)
    {
        var snap = (new Dictionary<(MachineId, DateTimeOffset), StateInterval>(Intervals), new Dictionary<(MachineId, DateTimeOffset), OeeBucket>(Buckets), Outbox.ToList(), new HashSet<string>(Batches), new Dictionary<(MachineId, string), CounterCheckpoint>(Checkpoints));
        try
        {
            await work(ct);
            if (FailNextRuns > 0)
            {
                FailNextRuns--;
                throw new InvalidOperationException("simulated commit failure");
            }
        }
        catch
        {
            Intervals.Clear();
            foreach (var kv in snap.Item1) { Intervals[kv.Key] = kv.Value; }
            Buckets.Clear();
            foreach (var kv in snap.Item2) { Buckets[kv.Key] = kv.Value; }
            Outbox.Clear();
            Outbox.AddRange(snap.Item3);
            Batches.Clear();
            Batches.UnionWith(snap.Item4);
            Checkpoints.Clear();
            foreach (var kv in snap.Item5) { Checkpoints[kv.Key] = kv.Value; }
            throw;
        }
    }

    public Task InsertSamplesAsync(IReadOnlyList<TelemetrySample> samples, CancellationToken ct)
    {
        Samples.AddRange(samples);
        return Task.CompletedTask;
    }

    public Task<StateInterval?> GetOpenIntervalAsync(TenantId tenant, MachineId machine, CancellationToken ct) =>
        Task.FromResult(Intervals.Values.FirstOrDefault(i => i.MachineId == machine && i.IsOpen));

    public Task UpsertIntervalsAsync(TenantId tenant, IReadOnlyList<StateInterval> intervals, CancellationToken ct)
    {
        foreach (var i in intervals)
        {
            if (i.IsOpen)
            {
                foreach (var stale in Intervals.Where(kv => kv.Value.MachineId == i.MachineId && kv.Value.IsOpen && kv.Key.Item2 != i.Start).Select(kv => kv.Key).ToList())
                {
                    Intervals.Remove(stale);
                }
            }

            Intervals[(i.MachineId, i.Start)] = i;
        }

        return Task.CompletedTask;
    }

    public Task<IReadOnlyList<StateInterval>> GetIntervalsAsync(TenantId tenant, MachineId machine, Interval window, CancellationToken ct) =>
        Task.FromResult<IReadOnlyList<StateInterval>>(Intervals.Values
            .Where(i => i.MachineId == machine && i.Start < window.End && (i.End is null || i.End > window.Start))
            .OrderBy(i => i.Start)
            .ToList());

    public Task AddToBucketsAsync(IReadOnlyList<OeeBucket> deltas, CancellationToken ct)
    {
        foreach (var d in deltas)
        {
            var k = (d.MachineId, d.BucketStart);
            Buckets[k] = Buckets.TryGetValue(k, out var cur) ? cur with { Acc = cur.Acc + d.Acc, PolicyVersion = d.PolicyVersion } : d;
        }

        return Task.CompletedTask;
    }

    public Task ReplaceBucketsAsync(IReadOnlyList<OeeBucket> buckets, CancellationToken ct)
    {
        foreach (var b in buckets)
        {
            Buckets[(b.MachineId, b.BucketStart)] = b;
        }

        return Task.CompletedTask;
    }

    public Task<IReadOnlyList<OeeBucket>> GetBucketsAsync(TenantId tenant, MachineId machine, Interval window, CancellationToken ct) =>
        Task.FromResult<IReadOnlyList<OeeBucket>>(Buckets.Values.Where(b => b.MachineId == machine && b.BucketStart >= window.Start && b.BucketStart < window.End).OrderBy(b => b.BucketStart).ToList());

    public Task InsertCorrectionAsync(Correction correction, CancellationToken ct)
    {
        Corrections.Add(correction);
        return Task.CompletedTask;
    }

    public Task<bool> TryRegisterBatchAsync(TenantId tenant, string batchKey, CancellationToken ct) => Task.FromResult(Batches.Add(batchKey));

    public Task<ProductionRun?> GetRunAsync(TenantId tenant, Guid runId, CancellationToken ct) => Task.FromResult(Runs.GetValueOrDefault(runId));

    public Task SaveRunAsync(ProductionRun run, CancellationToken ct)
    {
        Runs[run.Id] = run;
        return Task.CompletedTask;
    }

    public Task EnqueueAsync(OutboxMessage message, CancellationToken ct)
    {
        if (!Outbox.Any(o => o.TenantId == message.TenantId && o.DedupeKey == message.DedupeKey))
        {
            Outbox.Add(message);
        }

        return Task.CompletedTask;
    }

    public Task<MachineProfile?> GetAsync(TenantId tenant, MachineId machine, CancellationToken ct) => Task.FromResult(Profiles.GetValueOrDefault(machine));

    public Task PublishAsync(OeeLiveView view, CancellationToken ct)
    {
        Live[(view.MachineId, view.Scope)] = view;
        return Task.CompletedTask;
    }

    Task<OeeLiveView?> IOeeLiveStore.GetAsync(TenantId tenant, MachineId machine, string scope, CancellationToken ct) => Task.FromResult(Live.GetValueOrDefault((machine, scope)));

    public static MachineProfile Profile(TenantId tenant, MachineId machine, double idealCycleMs = 1000, bool goodCounter = false, OeePolicySnapshot? policy = null) => new(
        tenant,
        machine,
        new Dictionary<TagId, TagRole>
        {
            [new TagId("run")] = TagRole.Running,
            [new TagId("alarm")] = TagRole.Alarm,
            [new TagId("count")] = TagRole.TotalCounter,
            [new TagId("good")] = TagRole.GoodCounter,
        }.Where(kv => goodCounter || kv.Value != TagRole.GoodCounter).ToDictionary(kv => kv.Key, kv => kv.Value),
        idealCycleMs,
        new CounterPolicy(65536m, 1000m),
        TimeSpan.FromSeconds(2),
        TimeSpan.FromMinutes(5),
        policy ?? OeePolicyDefaults.V1);

    public static TelemetrySample Sample(TenantId t, MachineId m, string tag, DateTimeOffset at, double v) =>
        new(t, m, new TagId(tag), new SampleTimes(at, at), v, null, SampleQuality.Good, 1);
}
