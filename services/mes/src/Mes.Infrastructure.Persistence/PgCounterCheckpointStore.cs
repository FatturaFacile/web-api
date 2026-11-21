using Mes.Application.Abstractions;
using Mes.Domain.Common;

namespace Mes.Infrastructure.Persistence;

/// <summary>
/// PostgreSQL counter checkpoints. Joins the ambient ingest transaction (see <see cref="PgSession"/>), so the checkpoint
/// and the buckets built from the same deltas commit or roll back together — a rollback can therefore never leave a
/// checkpoint ahead of the production that was actually recorded.
/// </summary>
public sealed class PgCounterCheckpointStore(PgSession session) : ICounterCheckpointStore
{
    public async Task<IReadOnlyList<CounterCheckpoint>> LoadAsync(TenantId tenant, MachineId machine, CancellationToken ct)
    {
        await using var scope = await session.OpenAsync(tenant, ct).ConfigureAwait(false);
        await using var cmd = scope.Command("SELECT key, value, at FROM counter_checkpoint WHERE tenant_id=@t AND machine_id=@m");
        cmd.Parameters.AddWithValue("t", tenant.Value);
        cmd.Parameters.AddWithValue("m", machine.Value);
        var list = new List<CounterCheckpoint>();
        await using var r = await cmd.ExecuteReaderAsync(ct).ConfigureAwait(false);
        while (await r.ReadAsync(ct).ConfigureAwait(false))
        {
            list.Add(new CounterCheckpoint(r.GetString(0), r.GetDecimal(1), r.GetFieldValue<DateTimeOffset>(2)));
        }

        return list;
    }

    public async Task SaveAsync(TenantId tenant, MachineId machine, IReadOnlyList<CounterCheckpoint> checkpoints, CancellationToken ct)
    {
        if (checkpoints.Count == 0)
        {
            return;
        }

        await using var scope = await session.OpenAsync(tenant, ct).ConfigureAwait(false);
        foreach (var cp in checkpoints)
        {
            // Never move a checkpoint backwards in time (late/replayed batch): keep the newer one.
            await using var cmd = scope.Command("""
                INSERT INTO counter_checkpoint (tenant_id, machine_id, key, value, at) VALUES (@t,@m,@k,@v,@a)
                ON CONFLICT (tenant_id, machine_id, key) DO UPDATE SET value=EXCLUDED.value, at=EXCLUDED.at
                WHERE counter_checkpoint.at <= EXCLUDED.at
                """);
            cmd.Parameters.AddWithValue("t", tenant.Value);
            cmd.Parameters.AddWithValue("m", machine.Value);
            cmd.Parameters.AddWithValue("k", cp.Key);
            cmd.Parameters.AddWithValue("v", cp.Value);
            cmd.Parameters.AddWithValue("a", cp.At.UtcDateTime);
            await cmd.ExecuteNonQueryAsync(ct).ConfigureAwait(false);
        }
    }
}
