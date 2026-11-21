using FluentAssertions;
using Mes.Application.Abstractions;
using Mes.Domain.Common;
using Mes.Domain.Oee;
using Mes.Infrastructure.Persistence;
using Npgsql;
using Testcontainers.PostgreSql;
using Xunit;

namespace Mes.Tests;

/// <summary>
/// Runs when a PostgreSQL is reachable: either an external one (<c>MES_TEST_PG</c>, a superuser connection string — used
/// by CI service containers) or a Testcontainers one when Docker is available. Otherwise reported as skipped, never
/// silently green.
/// </summary>
public sealed class DockerFactAttribute : FactAttribute
{
    public DockerFactAttribute()
    {
        if (!DockerAvailable.Yes)
        {
            Skip = "No PostgreSQL available (set MES_TEST_PG or start Docker): integration test skipped.";
        }
    }
}

internal static class DockerAvailable
{
    public const string ExternalEnv = "MES_TEST_PG";

    public static string? ExternalPg => Environment.GetEnvironmentVariable(ExternalEnv) is { Length: > 0 } s ? s : null;

    public static bool Yes => ExternalPg is not null || Probe.Value;

    private static readonly Lazy<bool> Probe = new(() =>
    {
        try
        {
            using var p = System.Diagnostics.Process.Start(new System.Diagnostics.ProcessStartInfo("docker", "info") { RedirectStandardOutput = true, RedirectStandardError = true, UseShellExecute = false, CreateNoWindow = true });
            return p is not null && p.WaitForExit(30000) && p.ExitCode == 0;
        }
        catch (Exception)
        {
            return false;
        }
    });
}

/// <summary>
/// Real PostgreSQL (plain image; TimescaleDB hypertables are optional in the schema script). Exercises what the
/// in-memory fakes cannot: the DDL, RLS tenant isolation, revision-guarded upserts, additive bucket upserts, the
/// one-open-interval index and SKIP LOCKED claiming.
/// </summary>
public sealed class PostgresIntegrationTests : IAsyncLifetime
{
    private readonly PostgreSqlContainer? _pg = DockerAvailable.ExternalPg is null && DockerAvailable.Yes ? new PostgreSqlBuilder("postgres:16-alpine").Build() : null;
    private NpgsqlDataSource _owner = null!;
    private NpgsqlDataSource _app = null!;

    public async Task InitializeAsync()
    {
        if (!DockerAvailable.Yes)
        {
            return;
        }

        string ownerConnection;
        if (_pg is not null)
        {
            await _pg.StartAsync();
            ownerConnection = _pg.GetConnectionString();
        }
        else
        {
            ownerConnection = DockerAvailable.ExternalPg!;
        }

        _owner = NpgsqlDataSource.Create(ownerConnection);
        await new SchemaMigrator(_owner).MigrateAsync(default); // idempotent: safe on a shared CI database

        // RLS does not apply to the table owner / superuser, so the app connects as a plain role.
        await using (var c = await _owner.OpenConnectionAsync())
        {
            await using var cmd = new NpgsqlCommand("""
                DO $$ BEGIN
                  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'mes_app') THEN
                    CREATE ROLE mes_app LOGIN PASSWORD 'mes_app' NOBYPASSRLS;
                  END IF;
                END $$;
                GRANT ALL ON ALL TABLES IN SCHEMA public TO mes_app;
                """, c);
            await cmd.ExecuteNonQueryAsync();
        }

        var b = new NpgsqlConnectionStringBuilder(ownerConnection) { Username = "mes_app", Password = "mes_app" };
        _app = NpgsqlDataSource.Create(b.ConnectionString);
    }

    public async Task DisposeAsync()
    {
        if (DockerAvailable.Yes)
        {
            await _app.DisposeAsync();
            await _owner.DisposeAsync();
        }

        if (_pg is not null)
        {
            await _pg.DisposeAsync();
        }
    }

    [DockerFact]
    public async Task Counter_checkpoint_roundtrips_never_moves_back_and_is_tenant_isolated()
    {
        var a = TenantId.New();
        var b = TenantId.New();
        var m = MachineId.New();
        var store = new PgCounterCheckpointStore(new PgSession(_app));
        var t0 = new DateTimeOffset(2026, 1, 1, 8, 0, 0, TimeSpan.Zero);

        await store.SaveAsync(a, m, [new CounterCheckpoint("total", 12345.5m, t0), new CounterCheckpoint("good", 12000m, t0)], default);
        await store.SaveAsync(a, m, [new CounterCheckpoint("total", 10m, t0.AddSeconds(-5))], default); // stale replay: ignored
        await store.SaveAsync(a, m, [new CounterCheckpoint("total", 12400m, t0.AddSeconds(5))], default);

        var loaded = (await store.LoadAsync(a, m, default)).ToDictionary(c => c.Key);
        loaded["total"].Value.Should().Be(12400m);
        loaded["good"].Value.Should().Be(12000m);
        (await store.LoadAsync(b, m, default)).Should().BeEmpty();
    }

    [DockerFact]
    public async Task Restart_attribution_end_to_end_on_postgres_and_rollback_leaves_checkpoint_consistent()
    {
        var t = TenantId.New();
        var m = MachineId.New();
        var session = new PgSession(_app);
        var store = new PgMesStore(session);
        var checkpoints = new PgCounterCheckpointStore(session);
        var tx = new PgTransactionRunner();
        var t0 = new DateTimeOffset(2026, 1, 1, 8, 0, 0, TimeSpan.Zero);

        // Committed: checkpoint 500 together with a bucket.
        await tx.RunAsync(async c =>
        {
            await checkpoints.SaveAsync(t, m, [new CounterCheckpoint("total", 500m, t0)], c);
            await store.AddToBucketsAsync([new OeeBucket(t, m, t0, 1, OeeAccumulator.Empty.WithProduction(5, null, 1000))], c);
        }, default);

        // Rolled back: checkpoint 900 must NOT survive, otherwise 400 units would silently vanish from production.
        var act = () => tx.RunAsync(async c =>
        {
            await checkpoints.SaveAsync(t, m, [new CounterCheckpoint("total", 900m, t0.AddMinutes(1))], c);
            throw new InvalidOperationException("boom");
        }, default);
        await act.Should().ThrowAsync<InvalidOperationException>();

        (await checkpoints.LoadAsync(t, m, default)).Single().Value.Should().Be(500m);
    }

    [DockerFact]
    public async Task Rls_hides_other_tenants_rows_and_blocks_cross_tenant_writes()
    {
        var a = TenantId.New();
        var b = TenantId.New();
        var session = new PgSession(_app);
        var refs = new PgReferenceStore(session);
        var id = Guid.NewGuid();

        await refs.UpsertAsync(a, RefResource.Machines, [new RefRecord(id, 1, 1, false, "{\"code\":\"A\"}")], default);

        (await refs.ListRevisionsAsync(a, RefResource.Machines, default)).Should().ContainKey(id);
        (await refs.ListRevisionsAsync(b, RefResource.Machines, default)).Should().BeEmpty();

        // Even raw SQL with a forged tenant_id is stopped by the policy's WITH CHECK.
        await using var scope = await session.OpenAsync(b, default);
        await using var cmd = scope.Command("INSERT INTO ref_machine (tenant_id, id, seq, revision, payload) VALUES (@t, gen_random_uuid(), 1, 1, '{}')");
        cmd.Parameters.AddWithValue("t", a.Value);
        var act = () => cmd.ExecuteNonQueryAsync();
        await act.Should().ThrowAsync<PostgresException>();
    }

    [DockerFact]
    public async Task Reference_upsert_is_revision_guarded_and_cursor_never_moves_back_except_on_reset()
    {
        var t = TenantId.New();
        var refs = new PgReferenceStore(new PgSession(_app));
        var id = Guid.NewGuid();

        (await refs.UpsertAsync(t, RefResource.WorkOrders, [new RefRecord(id, 1, 5, false, "{}")], default)).Should().Be(1);
        (await refs.UpsertAsync(t, RefResource.WorkOrders, [new RefRecord(id, 2, 4, false, "{}")], default)).Should().Be(0);
        (await refs.UpsertAsync(t, RefResource.WorkOrders, [new RefRecord(id, 3, 6, true, "{}")], default)).Should().Be(1);

        await refs.SetCursorAsync(t, RefResource.WorkOrders, 10, default);
        await refs.SetCursorAsync(t, RefResource.WorkOrders, 4, default);
        (await refs.GetCursorAsync(t, RefResource.WorkOrders, default)).Should().Be(10);
        await refs.ResetCursorAsync(t, RefResource.WorkOrders, default);
        (await refs.GetCursorAsync(t, RefResource.WorkOrders, default)).Should().Be(0);
    }

    [DockerFact]
    public async Task Bucket_add_is_additive_replace_is_absolute_and_the_transaction_is_atomic()
    {
        var t = TenantId.New();
        var m = MachineId.New();
        var session = new PgSession(_app);
        var store = new PgMesStore(session);
        var tx = new PgTransactionRunner();
        var minute = new DateTimeOffset(2026, 1, 1, 8, 0, 0, TimeSpan.Zero);
        var delta = new OeeBucket(t, m, minute, 1, new OeeAccumulator(60_000, 50_000, 0, 0, 10_000, 0, 40_000, 38_000, 40, 38, true));

        await tx.RunAsync(async c =>
        {
            await store.AddToBucketsAsync([delta], c);
            await store.AddToBucketsAsync([delta], c);
        }, default);
        var added = (await store.GetBucketsAsync(t, m, new Interval(minute, minute.AddMinutes(1)), default)).Single();
        added.Acc.PlannedMs.Should().Be(120_000);
        added.Acc.GoodCount.Should().Be(76);

        await store.ReplaceBucketsAsync([delta], default);
        (await store.GetBucketsAsync(t, m, new Interval(minute, minute.AddMinutes(1)), default)).Single().Acc.PlannedMs.Should().Be(60_000);

        // Atomicity: a failure after the bucket write rolls back the bucket AND the outbox row together.
        var act = () => tx.RunAsync(async c =>
        {
            await store.AddToBucketsAsync([delta], c);
            await store.EnqueueAsync(new OutboxMessage(Guid.NewGuid(), t, OutboxTypes.OeeShiftClosed, "k1", "{}", DateTimeOffset.UtcNow), c);
            throw new InvalidOperationException("boom");
        }, default);
        await act.Should().ThrowAsync<InvalidOperationException>();
        (await store.GetBucketsAsync(t, m, new Interval(minute, minute.AddMinutes(1)), default)).Single().Acc.PlannedMs.Should().Be(60_000);
        await using var check = await _owner.OpenConnectionAsync();
        await using var cmd = new NpgsqlCommand("SELECT count(*) FROM mes_outbox WHERE tenant_id=@t", check);
        cmd.Parameters.AddWithValue("t", t.Value);
        ((long)(await cmd.ExecuteScalarAsync())!).Should().Be(0);
    }

    [DockerFact]
    public async Task Outbox_claim_uses_skip_locked_so_two_relays_never_take_the_same_row()
    {
        var t = TenantId.New();
        var store = new PgMesStore(new PgSession(_app));
        for (var i = 0; i < 20; i++)
        {
            await store.EnqueueAsync(new OutboxMessage(Guid.NewGuid(), t, OutboxTypes.MachineAlarmRaised, $"k{i}", "{}", DateTimeOffset.UtcNow), default);
        }

        var system = new SystemDataSource(_owner); // superuser bypasses RLS in this test
        var relay1 = new PgOutboxStore(system);
        var relay2 = new PgOutboxStore(system);

        var claims = await Task.WhenAll(relay1.ClaimAsync(15, TimeSpan.FromMinutes(1), default), relay2.ClaimAsync(15, TimeSpan.FromMinutes(1), default));

        var ids = claims.SelectMany(c => c).Select(c => c.Message.Id).ToList();
        ids.Should().OnlyHaveUniqueItems();
        ids.Count.Should().Be(20);
        claims.SelectMany(c => c).Should().OnlyContain(c => c.Attempts == 1);
    }

    [DockerFact]
    public async Task Only_one_open_interval_per_machine_and_replaying_samples_is_idempotent()
    {
        var t = TenantId.New();
        var m = MachineId.New();
        var store = new PgMesStore(new PgSession(_app));
        var t0 = new DateTimeOffset(2026, 1, 1, 8, 0, 0, TimeSpan.Zero);

        await store.UpsertIntervalsAsync(t, [new Mes.Domain.State.StateInterval(m, Mes.Domain.State.MachineState.Running, t0, null)], default);
        await store.UpsertIntervalsAsync(t, [new Mes.Domain.State.StateInterval(m, Mes.Domain.State.MachineState.Running, t0, t0.AddMinutes(5)), new Mes.Domain.State.StateInterval(m, Mes.Domain.State.MachineState.Down, t0.AddMinutes(5), null)], default);

        (await store.GetOpenIntervalAsync(t, m, default))!.State.Should().Be(Mes.Domain.State.MachineState.Down);
        (await store.GetIntervalsAsync(t, m, new Interval(t0, t0.AddHours(1)), default)).Should().HaveCount(2);

        var s = new Mes.Domain.Telemetry.TelemetrySample(t, m, new TagId("x"), new(t0, t0), 1, null, Mes.Domain.Telemetry.SampleQuality.Good, 1);
        await store.InsertSamplesAsync([s, s], default);
        await store.InsertSamplesAsync([s], default); // replay from the edge WAL: ON CONFLICT DO NOTHING
        (await store.TryRegisterBatchAsync(t, "b1", default)).Should().BeTrue();
        (await store.TryRegisterBatchAsync(t, "b1", default)).Should().BeFalse();
    }
}
