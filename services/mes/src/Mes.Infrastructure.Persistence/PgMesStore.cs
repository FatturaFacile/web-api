using Mes.Application.Abstractions;
using Mes.Domain.Common;
using Mes.Domain.Machines;
using Mes.Domain.Oee;
using Mes.Domain.State;
using Mes.Domain.Telemetry;
using NpgsqlTypes;

namespace Mes.Infrastructure.Persistence;

/// <summary>PostgreSQL/TimescaleDB implementation of the derived-data store, sample store and outbox writer.</summary>
public sealed class PgMesStore(PgSession session) : IMesStore, ITelemetryStore, IOutbox
{
    // ───────────── telemetry ─────────────
    public async Task InsertSamplesAsync(IReadOnlyList<TelemetrySample> samples, CancellationToken ct)
    {
        if (samples.Count == 0)
        {
            return;
        }

        foreach (var byTenant in samples.GroupBy(s => s.TenantId))
        {
            await using var scope = await session.OpenAsync(byTenant.Key, ct).ConfigureAwait(false);
            var rows = byTenant.ToList();
            await using var cmd = scope.Command("""
                INSERT INTO telemetry_sample (tenant_id, machine_id, tag_id, event_time, gateway_rx_time, ingest_time, num_value, text_value, quality, mapping_version)
                SELECT @tenant, m, t, e, r, @ingest, v, s, q, mv
                FROM unnest(@machines, @tags, @events, @rxs, @vals, @texts, @quals, @maps) AS x(m, t, e, r, v, s, q, mv)
                ON CONFLICT (tenant_id, machine_id, tag_id, event_time) DO NOTHING
                """);
            cmd.Parameters.AddWithValue("tenant", byTenant.Key.Value);
            cmd.Parameters.AddWithValue("ingest", DateTimeOffset.UtcNow);
            cmd.Parameters.AddWithValue("machines", rows.Select(s => s.MachineId.Value).ToArray());
            cmd.Parameters.AddWithValue("tags", rows.Select(s => s.TagId.Value).ToArray());
            cmd.Parameters.AddWithValue("events", rows.Select(s => s.Times.EventTime.UtcDateTime).ToArray());
            cmd.Parameters.AddWithValue("rxs", rows.Select(s => s.Times.GatewayRxTime.UtcDateTime).ToArray());
            cmd.Parameters.Add(new Npgsql.NpgsqlParameter("vals", NpgsqlDbType.Array | NpgsqlDbType.Double) { Value = rows.Select(s => s.NumericValue).ToArray() });
            cmd.Parameters.Add(new Npgsql.NpgsqlParameter("texts", NpgsqlDbType.Array | NpgsqlDbType.Text) { Value = rows.Select(s => s.TextValue).ToArray() });
            cmd.Parameters.AddWithValue("quals", rows.Select(s => (short)s.Quality).ToArray());
            cmd.Parameters.AddWithValue("maps", rows.Select(s => s.MappingVersion).ToArray());
            await cmd.ExecuteNonQueryAsync(ct).ConfigureAwait(false);
        }
    }

    // ───────────── intervals ─────────────
    public async Task<StateInterval?> GetOpenIntervalAsync(TenantId tenant, MachineId machine, CancellationToken ct)
    {
        await using var scope = await session.OpenAsync(tenant, ct).ConfigureAwait(false);
        await using var cmd = scope.Command("SELECT start_time, state, reason FROM machine_state_interval WHERE tenant_id=@t AND machine_id=@m AND end_time IS NULL");
        cmd.Parameters.AddWithValue("t", tenant.Value);
        cmd.Parameters.AddWithValue("m", machine.Value);
        await using var r = await cmd.ExecuteReaderAsync(ct).ConfigureAwait(false);
        return await r.ReadAsync(ct).ConfigureAwait(false)
            ? new StateInterval(machine, (MachineState)r.GetInt16(1), r.GetFieldValue<DateTimeOffset>(0), null, r.IsDBNull(2) ? null : new ReasonCode(r.GetString(2)))
            : null;
    }

    public async Task UpsertIntervalsAsync(TenantId tenant, IReadOnlyList<StateInterval> intervals, CancellationToken ct)
    {
        await using var scope = await session.OpenAsync(tenant, ct).ConfigureAwait(false);

        // The partial unique index allows ONE open interval per machine: close the previous open one first
        // (a closed interval with the same start key is updated by the ON CONFLICT below).
        foreach (var i in intervals.OrderBy(x => x.Start))
        {
            if (i.End is not null)
            {
                await using var close = scope.Command("""
                    INSERT INTO machine_state_interval (tenant_id, machine_id, start_time, end_time, state, reason)
                    VALUES (@t,@m,@s,@e,@st,@r)
                    ON CONFLICT (tenant_id, machine_id, start_time) DO UPDATE SET end_time=EXCLUDED.end_time, state=EXCLUDED.state, reason=EXCLUDED.reason
                    """);
                Bind(close, tenant, i);
                await close.ExecuteNonQueryAsync(ct).ConfigureAwait(false);
            }
            else
            {
                // Replace whatever open interval existed with the new open one (its closed twin was upserted above).
                await using var del = scope.Command("DELETE FROM machine_state_interval WHERE tenant_id=@t AND machine_id=@m AND end_time IS NULL AND start_time<>@s");
                del.Parameters.AddWithValue("t", tenant.Value);
                del.Parameters.AddWithValue("m", i.MachineId.Value);
                del.Parameters.AddWithValue("s", i.Start.UtcDateTime);
                await del.ExecuteNonQueryAsync(ct).ConfigureAwait(false);

                await using var open = scope.Command("""
                    INSERT INTO machine_state_interval (tenant_id, machine_id, start_time, end_time, state, reason)
                    VALUES (@t,@m,@s,NULL,@st,@r)
                    ON CONFLICT (tenant_id, machine_id, start_time) DO UPDATE SET end_time=NULL, state=EXCLUDED.state, reason=EXCLUDED.reason
                    """);
                Bind(open, tenant, i);
                await open.ExecuteNonQueryAsync(ct).ConfigureAwait(false);
            }
        }
    }

    private static void Bind(Npgsql.NpgsqlCommand c, TenantId tenant, StateInterval i)
    {
        c.Parameters.AddWithValue("t", tenant.Value);
        c.Parameters.AddWithValue("m", i.MachineId.Value);
        c.Parameters.AddWithValue("s", i.Start.UtcDateTime);
        c.Parameters.Add(new Npgsql.NpgsqlParameter("e", NpgsqlDbType.TimestampTz) { Value = i.End is { } e ? e.UtcDateTime : DBNull.Value });
        c.Parameters.AddWithValue("st", (short)i.State);
        c.Parameters.Add(new Npgsql.NpgsqlParameter("r", NpgsqlDbType.Text) { Value = i.Reason is { } r ? r.Value : DBNull.Value });
    }

    public async Task<IReadOnlyList<StateInterval>> GetIntervalsAsync(TenantId tenant, MachineId machine, Interval window, CancellationToken ct)
    {
        await using var scope = await session.OpenAsync(tenant, ct).ConfigureAwait(false);
        await using var cmd = scope.Command("""
            SELECT start_time, end_time, state, reason FROM machine_state_interval
            WHERE tenant_id=@t AND machine_id=@m AND start_time < @we AND (end_time IS NULL OR end_time > @ws)
            ORDER BY start_time
            """);
        cmd.Parameters.AddWithValue("t", tenant.Value);
        cmd.Parameters.AddWithValue("m", machine.Value);
        cmd.Parameters.AddWithValue("ws", window.Start.UtcDateTime);
        cmd.Parameters.AddWithValue("we", window.End.UtcDateTime);
        var list = new List<StateInterval>();
        await using var r = await cmd.ExecuteReaderAsync(ct).ConfigureAwait(false);
        while (await r.ReadAsync(ct).ConfigureAwait(false))
        {
            list.Add(new StateInterval(
                machine,
                (MachineState)r.GetInt16(2),
                r.GetFieldValue<DateTimeOffset>(0),
                r.IsDBNull(1) ? null : r.GetFieldValue<DateTimeOffset>(1),
                r.IsDBNull(3) ? null : new ReasonCode(r.GetString(3))));
        }

        return list;
    }

    // ───────────── buckets ─────────────
    private const string BucketCols = "tenant_id, machine_id, bucket_start, policy_version, planned_ms, run_ms, setup_ms, alarm_ms, down_ms, unknown_ms, ideal_work_ms, ideal_good_ms, total_count, good_count, has_quality_data";

    public Task AddToBucketsAsync(IReadOnlyList<OeeBucket> deltas, CancellationToken ct) => WriteBucketsAsync(deltas, additive: true, ct);

    public Task ReplaceBucketsAsync(IReadOnlyList<OeeBucket> buckets, CancellationToken ct) => WriteBucketsAsync(buckets, additive: false, ct);

    private async Task WriteBucketsAsync(IReadOnlyList<OeeBucket> buckets, bool additive, CancellationToken ct)
    {
        foreach (var byTenant in buckets.GroupBy(b => b.TenantId))
        {
            await using var scope = await session.OpenAsync(byTenant.Key, ct).ConfigureAwait(false);
            var onConflict = additive
                ? """
                  planned_ms = oee_bucket_1m.planned_ms + EXCLUDED.planned_ms, run_ms = oee_bucket_1m.run_ms + EXCLUDED.run_ms,
                  setup_ms = oee_bucket_1m.setup_ms + EXCLUDED.setup_ms, alarm_ms = oee_bucket_1m.alarm_ms + EXCLUDED.alarm_ms,
                  down_ms = oee_bucket_1m.down_ms + EXCLUDED.down_ms, unknown_ms = oee_bucket_1m.unknown_ms + EXCLUDED.unknown_ms,
                  ideal_work_ms = oee_bucket_1m.ideal_work_ms + EXCLUDED.ideal_work_ms, ideal_good_ms = oee_bucket_1m.ideal_good_ms + EXCLUDED.ideal_good_ms,
                  total_count = oee_bucket_1m.total_count + EXCLUDED.total_count, good_count = oee_bucket_1m.good_count + EXCLUDED.good_count,
                  has_quality_data = oee_bucket_1m.has_quality_data OR EXCLUDED.has_quality_data, policy_version = EXCLUDED.policy_version
                  """
                : """
                  planned_ms = EXCLUDED.planned_ms, run_ms = EXCLUDED.run_ms, setup_ms = EXCLUDED.setup_ms, alarm_ms = EXCLUDED.alarm_ms,
                  down_ms = EXCLUDED.down_ms, unknown_ms = EXCLUDED.unknown_ms, ideal_work_ms = EXCLUDED.ideal_work_ms, ideal_good_ms = EXCLUDED.ideal_good_ms,
                  total_count = EXCLUDED.total_count, good_count = EXCLUDED.good_count, has_quality_data = EXCLUDED.has_quality_data, policy_version = EXCLUDED.policy_version
                  """;

            // One statement per bucket keeps the SQL obvious; batches are small (a few buckets per machine per ingest).
            foreach (var b in byTenant)
            {
                await using var cmd = scope.Command($"INSERT INTO oee_bucket_1m ({BucketCols}) VALUES (@t,@m,@b,@pv,@pl,@run,@set,@al,@dn,@un,@iw,@ig,@tc,@gc,@hq) ON CONFLICT (tenant_id, machine_id, bucket_start) DO UPDATE SET {onConflict}");
                var a = b.Acc;
                cmd.Parameters.AddWithValue("t", b.TenantId.Value);
                cmd.Parameters.AddWithValue("m", b.MachineId.Value);
                cmd.Parameters.AddWithValue("b", b.BucketStart.UtcDateTime);
                cmd.Parameters.AddWithValue("pv", b.PolicyVersion);
                cmd.Parameters.AddWithValue("pl", a.PlannedMs);
                cmd.Parameters.AddWithValue("run", a.RunMs);
                cmd.Parameters.AddWithValue("set", a.SetupMs);
                cmd.Parameters.AddWithValue("al", a.AlarmMs);
                cmd.Parameters.AddWithValue("dn", a.DownMs);
                cmd.Parameters.AddWithValue("un", a.UnknownMs);
                cmd.Parameters.AddWithValue("iw", a.IdealWorkMs);
                cmd.Parameters.AddWithValue("ig", a.IdealGoodMs);
                cmd.Parameters.AddWithValue("tc", a.TotalCount);
                cmd.Parameters.AddWithValue("gc", a.GoodCount);
                cmd.Parameters.AddWithValue("hq", a.HasQualityData);
                await cmd.ExecuteNonQueryAsync(ct).ConfigureAwait(false);
            }
        }
    }

    public async Task<IReadOnlyList<OeeBucket>> GetBucketsAsync(TenantId tenant, MachineId machine, Interval window, CancellationToken ct)
    {
        await using var scope = await session.OpenAsync(tenant, ct).ConfigureAwait(false);
        await using var cmd = scope.Command($"SELECT {BucketCols} FROM oee_bucket_1m WHERE tenant_id=@t AND machine_id=@m AND bucket_start >= @s AND bucket_start < @e ORDER BY bucket_start");
        cmd.Parameters.AddWithValue("t", tenant.Value);
        cmd.Parameters.AddWithValue("m", machine.Value);
        cmd.Parameters.AddWithValue("s", window.Start.UtcDateTime);
        cmd.Parameters.AddWithValue("e", window.End.UtcDateTime);
        var list = new List<OeeBucket>();
        await using var r = await cmd.ExecuteReaderAsync(ct).ConfigureAwait(false);
        while (await r.ReadAsync(ct).ConfigureAwait(false))
        {
            list.Add(new OeeBucket(
                tenant,
                machine,
                r.GetFieldValue<DateTimeOffset>(2),
                r.GetInt32(3),
                new OeeAccumulator(r.GetInt64(4), r.GetInt64(5), r.GetInt64(6), r.GetInt64(7), r.GetInt64(8), r.GetInt64(9), r.GetDouble(10), r.GetDouble(11), r.GetInt64(12), r.GetInt64(13), r.GetBoolean(14))));
        }

        return list;
    }

    // ───────────── corrections, batches, runs ─────────────
    public async Task InsertCorrectionAsync(Correction c, CancellationToken ct)
    {
        await using var scope = await session.OpenAsync(c.TenantId, ct).ConfigureAwait(false);
        await using var cmd = scope.Command("INSERT INTO mes_correction (tenant_id, id, machine_id, kind, window_start, window_end, payload, actor, created_at) VALUES (@t,@i,@m,@k,@ws,@we,@p::jsonb,@a,@c)");
        cmd.Parameters.AddWithValue("t", c.TenantId.Value);
        cmd.Parameters.AddWithValue("i", c.Id);
        cmd.Parameters.AddWithValue("m", c.MachineId.Value);
        cmd.Parameters.AddWithValue("k", c.Kind);
        cmd.Parameters.Add(new Npgsql.NpgsqlParameter("ws", NpgsqlDbType.TimestampTz) { Value = c.Window is { } w ? w.Start.UtcDateTime : DBNull.Value });
        cmd.Parameters.Add(new Npgsql.NpgsqlParameter("we", NpgsqlDbType.TimestampTz) { Value = c.Window is { } w2 ? w2.End.UtcDateTime : DBNull.Value });
        cmd.Parameters.AddWithValue("p", c.PayloadJson);
        cmd.Parameters.Add(new Npgsql.NpgsqlParameter("a", NpgsqlDbType.Text) { Value = (object?)c.Actor ?? DBNull.Value });
        cmd.Parameters.AddWithValue("c", c.CreatedAt.UtcDateTime);
        await cmd.ExecuteNonQueryAsync(ct).ConfigureAwait(false);
    }

    public async Task<bool> TryRegisterBatchAsync(TenantId tenant, string batchKey, CancellationToken ct)
    {
        await using var scope = await session.OpenAsync(tenant, ct).ConfigureAwait(false);
        await using var cmd = scope.Command("INSERT INTO ingest_batch (tenant_id, batch_key) VALUES (@t,@k) ON CONFLICT DO NOTHING");
        cmd.Parameters.AddWithValue("t", tenant.Value);
        cmd.Parameters.AddWithValue("k", batchKey);
        return await cmd.ExecuteNonQueryAsync(ct).ConfigureAwait(false) == 1;
    }

    public async Task<ProductionRun?> GetRunAsync(TenantId tenant, Guid runId, CancellationToken ct)
    {
        await using var scope = await session.OpenAsync(tenant, ct).ConfigureAwait(false);
        await using var cmd = scope.Command("SELECT machine_id, work_order_ref, ideal_cycle_ms, started_at, ended_at, total_count, scrap_count FROM production_run WHERE tenant_id=@t AND id=@i FOR UPDATE");
        cmd.Parameters.AddWithValue("t", tenant.Value);
        cmd.Parameters.AddWithValue("i", runId);
        await using var r = await cmd.ExecuteReaderAsync(ct).ConfigureAwait(false);
        if (!await r.ReadAsync(ct).ConfigureAwait(false))
        {
            return null;
        }

        var run = new ProductionRun(runId, tenant, new MachineId(r.GetGuid(0)), r.GetString(1), r.GetDouble(2), r.GetFieldValue<DateTimeOffset>(3));
        run.Count(r.GetInt64(5));
        run.ReportScrap(new Quantity(r.GetInt64(6)));
        if (!r.IsDBNull(4))
        {
            run.Complete(r.GetFieldValue<DateTimeOffset>(4));
        }

        return run;
    }

    public async Task<ProductionRun?> GetOpenRunAsync(TenantId tenant, MachineId machine, CancellationToken ct)
    {
        await using var scope = await session.OpenAsync(tenant, ct).ConfigureAwait(false);
        await using var cmd = scope.Command("SELECT id, work_order_ref, ideal_cycle_ms, started_at, total_count, scrap_count FROM production_run WHERE tenant_id=@t AND machine_id=@m AND ended_at IS NULL ORDER BY started_at DESC LIMIT 1");
        cmd.Parameters.AddWithValue("t", tenant.Value);
        cmd.Parameters.AddWithValue("m", machine.Value);
        await using var r = await cmd.ExecuteReaderAsync(ct).ConfigureAwait(false);
        if (!await r.ReadAsync(ct).ConfigureAwait(false))
        {
            return null;
        }

        var run = new ProductionRun(r.GetGuid(0), tenant, machine, r.GetString(1), r.GetDouble(2), r.GetFieldValue<DateTimeOffset>(3));
        run.Count(r.GetInt64(4));
        run.ReportScrap(new Quantity(r.GetInt64(5)));
        return run;
    }

    public async Task SaveRunAsync(ProductionRun run, CancellationToken ct)
    {
        await using var scope = await session.OpenAsync(run.TenantId, ct).ConfigureAwait(false);
        await using var cmd = scope.Command("""
            INSERT INTO production_run (tenant_id, id, machine_id, work_order_ref, ideal_cycle_ms, started_at, ended_at, total_count, scrap_count)
            VALUES (@t,@i,@m,@w,@c,@s,@e,@tc,@sc)
            ON CONFLICT (tenant_id, id) DO UPDATE SET ended_at=EXCLUDED.ended_at, total_count=EXCLUDED.total_count, scrap_count=EXCLUDED.scrap_count
            """);
        cmd.Parameters.AddWithValue("t", run.TenantId.Value);
        cmd.Parameters.AddWithValue("i", run.Id);
        cmd.Parameters.AddWithValue("m", run.MachineId.Value);
        cmd.Parameters.AddWithValue("w", run.WorkOrderRef);
        cmd.Parameters.AddWithValue("c", run.IdealCycleMs);
        cmd.Parameters.AddWithValue("s", run.StartedAt.UtcDateTime);
        cmd.Parameters.Add(new Npgsql.NpgsqlParameter("e", NpgsqlDbType.TimestampTz) { Value = run.EndedAt is { } e ? e.UtcDateTime : DBNull.Value });
        cmd.Parameters.AddWithValue("tc", run.TotalCount);
        cmd.Parameters.AddWithValue("sc", run.ScrapCount);
        await cmd.ExecuteNonQueryAsync(ct).ConfigureAwait(false);
    }

    // ───────────── outbox (enqueue side) ─────────────
    public async Task EnqueueAsync(OutboxMessage m, CancellationToken ct)
    {
        await using var scope = await session.OpenAsync(m.TenantId, ct).ConfigureAwait(false);
        await using var cmd = scope.Command("INSERT INTO mes_outbox (id, tenant_id, type, dedupe_key, payload, created_at) VALUES (@i,@t,@ty,@k,@p::jsonb,@c) ON CONFLICT (tenant_id, dedupe_key) DO NOTHING");
        cmd.Parameters.AddWithValue("i", m.Id);
        cmd.Parameters.AddWithValue("t", m.TenantId.Value);
        cmd.Parameters.AddWithValue("ty", m.Type);
        cmd.Parameters.AddWithValue("k", m.DedupeKey);
        cmd.Parameters.AddWithValue("p", m.PayloadJson);
        cmd.Parameters.AddWithValue("c", m.CreatedAt.UtcDateTime);
        await cmd.ExecuteNonQueryAsync(ct).ConfigureAwait(false);
    }
}
