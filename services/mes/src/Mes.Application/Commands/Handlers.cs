using System.Globalization;
using System.Text.Json;
using Mes.Application.Abstractions;
using Mes.Application.Processing;
using Mes.Domain.Common;
using Mes.Domain.Oee;
using Mes.Domain.State;

namespace Mes.Application.Commands;

internal static class Json
{
    public static readonly JsonSerializerOptions Options = new()
    {
        PropertyNamingPolicy = JsonNamingPolicy.SnakeCaseLower,
        DefaultIgnoreCondition = System.Text.Json.Serialization.JsonIgnoreCondition.WhenWritingNull,
    };

    public static string Serialize<T>(T value) => JsonSerializer.Serialize(value, Options);

    public static object OeeSummary(OeeAccumulator a, OeeResult r) => new
    {
        planned_ms = a.PlannedMs,
        run_ms = a.RunMs,
        setup_ms = a.SetupMs,
        alarm_ms = a.AlarmMs,
        down_ms = a.DownMs,
        unknown_ms = a.UnknownMs,
        ideal_work_ms = a.IdealWorkMs,
        ideal_good_ms = a.IdealGoodMs,
        total_count = a.TotalCount,
        good_count = a.GoodCount,
        has_quality_data = a.HasQualityData,
        availability = r.Availability,
        performance = r.Performance,
        quality = r.Quality,
        oee = r.Oee,
        coverage_pct = r.CoveragePct,
        null_reasons = r.NullReasons.Select(x => x.ToString()).ToArray(),
    };
}

/// <summary>
/// Ingest path. Per machine, in ONE transaction: batch idempotency guard, interval upserts, additive bucket deltas and
/// outbox rows commit together. Callers (the partitioned pipeline) guarantee a single writer per machine.
/// </summary>
public sealed class IngestTelemetryBatchHandler(
    MachineProcessorRegistry registry,
    ITelemetryStore telemetry,
    ICounterCheckpointStore checkpoints,
    IMesStore store,
    IOutbox outbox,
    ITransactionRunner tx,
    IShiftCalendarProvider calendar,
    LiveViewBuilder live,
    TimeProvider time)
{
    public async Task<IngestResult> HandleAsync(IngestTelemetryBatch cmd, CancellationToken ct)
    {
        int accepted = 0, rejected = 0, dupes = 0;
        foreach (var group in cmd.Samples.GroupBy(s => s.MachineId))
        {
            var samples = group.Where(s => s.TenantId == cmd.TenantId).ToList();
            rejected += group.Count() - samples.Count; // cross-tenant samples are never processed

            var entry = await registry.GetAsync(cmd.TenantId, group.Key, ct);
            if (entry is null)
            {
                rejected += samples.Count;
                continue;
            }

            var (processor, profile) = entry.Value;
            var applied = false;
            try
            {
                await tx.RunAsync(async c =>
                {
                    if (!await store.TryRegisterBatchAsync(cmd.TenantId, $"{cmd.BatchId}:{group.Key}", c))
                    {
                        return;
                    }

                    applied = true;
                    await telemetry.InsertSamplesAsync(samples, c);
                    var r = processor.Process(samples);
                    if (r.Checkpoints.Count > 0)
                    {
                        await checkpoints.SaveAsync(cmd.TenantId, group.Key, r.Checkpoints, c);
                    }


                    var intervals = r.ClosedIntervals.Concat(r.OpenInterval is null ? [] : [r.OpenInterval]).ToList();
                    if (intervals.Count > 0)
                    {
                        await store.UpsertIntervalsAsync(cmd.TenantId, intervals, c);
                    }

                    var hasGood = profile.Roles.Values.Contains(TagRole.GoodCounter);
                    var deltas = OeeProjector.Merge(
                        (await OeeProjector.TimeBucketsAsync(profile, r.ClosedIntervals, time.GetUtcNow(), calendar, c))
                        .Concat(OeeProjector.ProductionBuckets(profile, r.Counts, hasGood)));
                    if (deltas.Count > 0)
                    {
                        await store.AddToBucketsAsync(deltas, c);
                    }

                    foreach (var at in r.AlarmEntries)
                    {
                        await outbox.EnqueueAsync(
                            new OutboxMessage(
                                Guid.CreateVersion7(),
                                cmd.TenantId,
                                OutboxTypes.MachineAlarmRaised,
                                $"alarm:{group.Key}:{at:O}",
                                Json.Serialize(new { machine_id = group.Key.Value, raised_at = at }),
                                time.GetUtcNow()),
                            c);
                    }

                    if (r.Counts.Count > 0)
                    {
                        var last = r.Counts.Max(x => x.At);

                        // The order being produced, so Laravel can attribute the counts to an operation (null when no run is open).
                        var openRun = await store.GetOpenRunAsync(cmd.TenantId, group.Key, c);
                        await outbox.EnqueueAsync(
                            new OutboxMessage(
                                Guid.CreateVersion7(),
                                cmd.TenantId,
                                OutboxTypes.ProductionCountCheckpoint,
                                $"ckpt:{group.Key}:{cmd.BatchId}",
                                Json.Serialize(new { machine_id = group.Key.Value, at = last, total = r.Counts.Sum(x => x.Total), good = r.Counts.Sum(x => x.Good ?? 0), run_id = openRun?.Id, work_order_ref = openRun?.WorkOrderRef }),
                                time.GetUtcNow()),
                            c);
                    }
                }, ct);
            }
            catch
            {
                registry.Evict(group.Key); // in-memory state may be ahead of the committed DB state
                throw;
            }

            if (applied)
            {
                accepted += samples.Count;
                await live.PublishAsync(profile, ct); // best-effort read model, outside the transaction
            }
            else
            {
                dupes++;
            }
        }

        return new IngestResult(accepted, rejected, dupes);
    }
}

/// <summary>Recomputes the TIME part of buckets from the closed intervals of a window. Production sums are preserved, so it is idempotent.</summary>
public sealed class WindowRecomputer(IMesStore store, IMachineProfileProvider profiles, IShiftCalendarProvider calendar, IOutbox outbox, TimeProvider time)
{
    public async Task RecomputeInTransactionAsync(TenantId tenant, MachineId machine, Interval window, CancellationToken ct)
    {
        var profile = await profiles.GetAsync(tenant, machine, ct) ?? throw new MesNotFoundException($"Machine {machine} has no profile.");
        var closed = (await store.GetIntervalsAsync(tenant, machine, window, ct))
            .Where(i => !i.IsOpen) // the open interval is projected incrementally when it closes: never here (no double count)
            .Select(i => Clip(i, window))
            .OfType<StateInterval>()
            .ToList();

        var fresh = (await OeeProjector.TimeBucketsAsync(profile, closed, window.End, calendar, ct)).ToDictionary(b => b.BucketStart);
        var old = (await store.GetBucketsAsync(tenant, machine, window, ct)).ToDictionary(b => b.BucketStart);

        var replaced = new List<OeeBucket>();
        foreach (var start in fresh.Keys.Union(old.Keys))
        {
            var t = fresh.TryGetValue(start, out var f) ? f.Acc.TimeOnly() : default;
            var p = old.TryGetValue(start, out var o) ? o.Acc.ProductionOnly() : default;
            replaced.Add(new OeeBucket(tenant, machine, start, profile.Policy.PolicyVersion, t + p));
        }

        if (replaced.Count == 0)
        {
            return;
        }

        await store.ReplaceBucketsAsync(replaced, ct);

        var sum = OeeProjector.Sum(replaced);
        var oee = OeeCalculator.Compute(sum, profile.Policy.PerformanceCap);
        await outbox.EnqueueAsync(
            new OutboxMessage(
                Guid.CreateVersion7(),
                tenant,
                OutboxTypes.OeeSnapshotRevised,
                // Identical recompute => identical key => de-duplicated downstream.
                string.Create(CultureInfo.InvariantCulture, $"revise:{machine}:{window.Start:O}:{window.End:O}:{sum.PlannedMs}:{sum.RunMs}:{sum.SetupMs}:{sum.AlarmMs}:{sum.DownMs}:{sum.UnknownMs}:{sum.GoodCount}:{profile.Policy.PolicyVersion}"),
                Json.Serialize(new { machine_id = machine.Value, window_start = window.Start, window_end = window.End, policy_version = profile.Policy.PolicyVersion, oee = Json.OeeSummary(sum, oee) }),
                time.GetUtcNow()),
            ct);
    }

    private static StateInterval? Clip(StateInterval i, Interval w)
    {
        var s = i.Start > w.Start ? i.Start : w.Start;
        var e = i.End!.Value < w.End ? i.End.Value : w.End;
        return e > s ? i with { Start = s, End = e } : null;
    }
}

public sealed class RecomputeWindowHandler(WindowRecomputer recomputer, ITransactionRunner tx, LiveViewBuilder live, IMachineProfileProvider profiles)
{
    public async Task HandleAsync(RecomputeWindow cmd, CancellationToken ct)
    {
        await tx.RunAsync(c => recomputer.RecomputeInTransactionAsync(cmd.TenantId, cmd.MachineId, cmd.Window, c), ct);
        if (await profiles.GetAsync(cmd.TenantId, cmd.MachineId, ct) is { } p)
        {
            await live.PublishAsync(p, ct);
        }
    }
}

public sealed class SplitIntervalHandler(IMesStore store, ITransactionRunner tx)
{
    public Task HandleAsync(SplitInterval cmd, CancellationToken ct) => tx.RunAsync(async c =>
    {
        var at = cmd.At.ToUniversalTime();
        var probe = new Interval(at.AddMilliseconds(-1), at.AddMilliseconds(1));
        var target = (await store.GetIntervalsAsync(cmd.TenantId, cmd.MachineId, probe, c))
            .FirstOrDefault(i => !i.IsOpen && i.Start < at && at < i.End!.Value)
            ?? throw new MesValidationException("No closed interval strictly contains the split instant.");

        await store.UpsertIntervalsAsync(cmd.TenantId, [target with { End = at }, target with { Start = at }], c);
        await store.InsertCorrectionAsync(
            new Correction(Guid.CreateVersion7(), cmd.TenantId, cmd.MachineId, "split_interval", null, Json.Serialize(new { at }), DateTimeOffset.UtcNow, cmd.Actor),
            c);
    }, ct);
}

public sealed class ClassifyDowntimeHandler(IMesStore store, WindowRecomputer recomputer, ITransactionRunner tx, LiveViewBuilder live, IMachineProfileProvider profiles, TimeProvider time)
{
    public async Task HandleAsync(ClassifyDowntime cmd, CancellationToken ct)
    {
        if (cmd.ReclassifyAs is MachineState.Running)
        {
            throw new MesValidationException("Downtime cannot be reclassified as Running.");
        }

        await tx.RunAsync(async c =>
        {
            var hits = (await store.GetIntervalsAsync(cmd.TenantId, cmd.MachineId, cmd.Window, c))
                .Where(i => !i.IsOpen && i.State is MachineState.Down or MachineState.Alarm or MachineState.Unknown)
                .ToList();
            if (hits.Count == 0)
            {
                throw new MesValidationException("No closed downtime inside the window.");
            }

            var writes = new List<StateInterval>();
            foreach (var i in hits)
            {
                var s = i.Start > cmd.Window.Start ? i.Start : cmd.Window.Start;
                var e = i.End!.Value < cmd.Window.End ? i.End.Value : cmd.Window.End;
                if (e <= s)
                {
                    continue;
                }

                if (s > i.Start)
                {
                    writes.Add(i with { End = s }); // untouched head keeps its original key (machine, start)
                }

                writes.Add(i with { Start = s, End = e, Reason = cmd.Reason, State = cmd.ReclassifyAs ?? i.State });
                if (e < i.End!.Value)
                {
                    writes.Add(i with { Start = e }); // untouched tail
                }
            }

            await store.UpsertIntervalsAsync(cmd.TenantId, writes, c);
            await store.InsertCorrectionAsync(
                new Correction(
                    Guid.CreateVersion7(),
                    cmd.TenantId,
                    cmd.MachineId,
                    "classify_downtime",
                    cmd.Window,
                    Json.Serialize(new { reason = cmd.Reason.Value, reclassify_as = cmd.ReclassifyAs?.ToString() }),
                    time.GetUtcNow(),
                    cmd.Actor),
                c);
            await recomputer.RecomputeInTransactionAsync(cmd.TenantId, cmd.MachineId, cmd.Window, c);
        }, ct);

        if (await profiles.GetAsync(cmd.TenantId, cmd.MachineId, ct) is { } p)
        {
            await live.PublishAsync(p, ct);
        }
    }
}

public sealed class ReportScrapHandler(IMesStore store, IMachineProfileProvider profiles, IOutbox outbox, ITransactionRunner tx, LiveViewBuilder live, TimeProvider time)
{
    public async Task HandleAsync(ReportScrap cmd, CancellationToken ct)
    {
        var profile = await profiles.GetAsync(cmd.TenantId, cmd.MachineId, ct) ?? throw new MesNotFoundException("Unknown machine.");
        var n = (long)cmd.Scrap.Value;
        var at = cmd.At.ToUniversalTime();
        var minute = new DateTimeOffset(at.UtcTicks - (at.UtcTicks % TimeSpan.TicksPerMinute), TimeSpan.Zero);

        await tx.RunAsync(async c =>
        {
            var run = await store.GetRunAsync(cmd.TenantId, cmd.RunId, c) ?? throw new MesNotFoundException("Unknown production run.");
            try
            {
                run.ReportScrap(cmd.Scrap);
            }
            catch (InvalidOperationException ex)
            {
                throw new MesValidationException(ex.Message);
            }

            await store.SaveRunAsync(run, c);
            var delta = OeeAccumulator.Empty.WithScrap(n, run.IdealCycleMs);
            await store.AddToBucketsAsync([new OeeBucket(cmd.TenantId, cmd.MachineId, minute, profile.Policy.PolicyVersion, delta)], c);
            await store.InsertCorrectionAsync(
                new Correction(Guid.CreateVersion7(), cmd.TenantId, cmd.MachineId, "report_scrap", new Interval(minute, minute.AddMinutes(1)), Json.Serialize(new { run_id = cmd.RunId, scrap = n }), time.GetUtcNow(), cmd.Actor),
                c);
            await outbox.EnqueueAsync(
                new OutboxMessage(
                    Guid.CreateVersion7(),
                    cmd.TenantId,
                    OutboxTypes.OeeSnapshotRevised,
                    $"scrap:{cmd.RunId}:{run.ScrapCount}",
                    Json.Serialize(new { machine_id = cmd.MachineId.Value, run_id = cmd.RunId, work_order_ref = run.WorkOrderRef, total = run.TotalCount, scrap_total = run.ScrapCount, at = minute }),
                    time.GetUtcNow()),
                c);
        }, ct);

        await live.PublishAsync(profile, ct);
    }
}

public sealed class CompleteProductionRunHandler(IMesStore store, IOutbox outbox, ITransactionRunner tx, TimeProvider time)
{
    public Task HandleAsync(CompleteProductionRun cmd, CancellationToken ct) => tx.RunAsync(async c =>
    {
        var run = await store.GetRunAsync(cmd.TenantId, cmd.RunId, c) ?? throw new MesNotFoundException("Unknown production run.");
        run.Complete(cmd.At);
        await store.SaveRunAsync(run, c);
        await outbox.EnqueueAsync(
            new OutboxMessage(
                Guid.CreateVersion7(),
                cmd.TenantId,
                OutboxTypes.OeeOrderCompleted,
                $"order-completed:{run.Id}",
                Json.Serialize(new { run_id = run.Id, work_order_ref = run.WorkOrderRef, machine_id = run.MachineId.Value, total = run.TotalCount, scrap = run.ScrapCount, ended_at = cmd.At }),
                time.GetUtcNow()),
            c);
    }, ct);
}

public sealed class CloseShiftHandler(IMesStore store, IMachineProfileProvider profiles, IOutbox outbox, ITransactionRunner tx, TimeProvider time)
{
    public async Task HandleAsync(CloseShift cmd, CancellationToken ct)
    {
        var profile = await profiles.GetAsync(cmd.TenantId, cmd.MachineId, ct) ?? throw new MesNotFoundException("Unknown machine.");
        await tx.RunAsync(async c =>
        {
            // Sum additive accumulators over the whole shift and divide once (never average per-bucket percentages).
            var acc = OeeProjector.Sum(await store.GetBucketsAsync(cmd.TenantId, cmd.MachineId, cmd.Shift, c));
            var oee = OeeCalculator.Compute(acc, profile.Policy.PerformanceCap);
            await outbox.EnqueueAsync(
                new OutboxMessage(
                    Guid.CreateVersion7(),
                    cmd.TenantId,
                    OutboxTypes.OeeShiftClosed,
                    $"shift-closed:{cmd.MachineId}:{cmd.ShiftRef}",
                    Json.Serialize(new { machine_id = cmd.MachineId.Value, shift_ref = cmd.ShiftRef, start = cmd.Shift.Start, end = cmd.Shift.End, policy_version = profile.Policy.PolicyVersion, oee = Json.OeeSummary(acc, oee) }),
                    time.GetUtcNow()),
                c);
        }, ct);
    }
}
