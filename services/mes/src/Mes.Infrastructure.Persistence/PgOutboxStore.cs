using Mes.Application.Abstractions;
using Mes.Domain.Common;
using Npgsql;

namespace Mes.Infrastructure.Persistence;

/// <summary>
/// Relay-side outbox access (cross-tenant ⇒ uses the BYPASSRLS system data source).
/// <para><b>Claim-then-release</b> (implemented): <c>ClaimAsync</c> runs one short transaction that
/// <c>SELECT … FOR UPDATE SKIP LOCKED</c>s due rows and stamps <c>locked_until</c>, then COMMITS. The HTTP call to
/// Laravel happens afterwards with no DB transaction or row lock held; the outcome is written by a second short
/// transaction. If the relay crashes mid-flight the lease simply expires and another relay picks the row up
/// (at-least-once; Laravel de-duplicates on <c>dedupe_key</c>, which is why 207 reports <c>duplicate</c>).</para>
/// <para><b>Transactional lock</b> (alternative, not used): keep the claiming transaction open across the HTTP call.
/// Simpler state machine, but a slow/hung Laravel pins a connection and the rows for the whole call and a crash
/// leaves the lock until the server notices the dead session. Fine for a single relay with a tight timeout; poor
/// at scale. Switch only if you need strict one-at-a-time ordering per tenant.</para>
/// </summary>
public sealed class PgOutboxStore(SystemDataSource system) : IOutboxStore
{
    public async Task<IReadOnlyList<ClaimedOutbox>> ClaimAsync(int batchSize, TimeSpan lease, CancellationToken ct)
    {
        await using var conn = await system.Inner.OpenConnectionAsync(ct).ConfigureAwait(false);
        await using var tx = await conn.BeginTransactionAsync(ct).ConfigureAwait(false);
        await using var cmd = new NpgsqlCommand("""
            WITH due AS (
              SELECT id FROM mes_outbox
              WHERE status = 'pending' AND next_attempt_at <= now() AND (locked_until IS NULL OR locked_until < now())
              ORDER BY next_attempt_at, id
              LIMIT @n
              FOR UPDATE SKIP LOCKED)
            UPDATE mes_outbox o
            SET locked_until = now() + @lease, attempts = o.attempts + 1
            FROM due
            WHERE o.id = due.id
            RETURNING o.id, o.tenant_id, o.type, o.dedupe_key, o.payload::text, o.created_at, o.attempts
            """, conn, tx);
        cmd.Parameters.AddWithValue("n", batchSize);
        cmd.Parameters.AddWithValue("lease", lease);
        var list = new List<ClaimedOutbox>();
        await using (var r = await cmd.ExecuteReaderAsync(ct).ConfigureAwait(false))
        {
            while (await r.ReadAsync(ct).ConfigureAwait(false))
            {
                list.Add(new ClaimedOutbox(
                    new OutboxMessage(r.GetGuid(0), new TenantId(r.GetGuid(1)), r.GetString(2), r.GetString(3), r.GetString(4), r.GetFieldValue<DateTimeOffset>(5)),
                    r.GetInt32(6)));
            }
        }

        await tx.CommitAsync(ct).ConfigureAwait(false);
        return list;
    }

    public Task CompleteAsync(Guid id, string status, CancellationToken ct) =>
        Exec("UPDATE mes_outbox SET status='sent', locked_until=NULL, last_error=@s WHERE id=@i", id, status, null, ct);

    public Task RescheduleAsync(Guid id, DateTimeOffset nextAttemptAt, string error, CancellationToken ct) =>
        Exec("UPDATE mes_outbox SET locked_until=NULL, next_attempt_at=@n, last_error=@s WHERE id=@i", id, error, nextAttemptAt, ct);

    public Task DeadLetterAsync(Guid id, string error, CancellationToken ct) =>
        Exec("UPDATE mes_outbox SET status='dead', locked_until=NULL, last_error=@s WHERE id=@i", id, error, null, ct);

    private async Task Exec(string sql, Guid id, string text, DateTimeOffset? next, CancellationToken ct)
    {
        await using var conn = await system.Inner.OpenConnectionAsync(ct).ConfigureAwait(false);
        await using var cmd = new NpgsqlCommand(sql, conn);
        cmd.Parameters.AddWithValue("i", id);
        cmd.Parameters.AddWithValue("s", text.Length > 2000 ? text[..2000] : text);
        if (next is { } n)
        {
            cmd.Parameters.AddWithValue("n", n.UtcDateTime);
        }

        await cmd.ExecuteNonQueryAsync(ct).ConfigureAwait(false);
    }
}

/// <summary>Reference mirror: only the sync client writes here; revision-guarded so replays and out-of-order pushes are harmless.</summary>
public sealed class PgReferenceStore(PgSession session) : IReferenceStore
{
    public async Task<long> GetCursorAsync(TenantId tenant, RefResource resource, CancellationToken ct)
    {
        await using var scope = await session.OpenAsync(tenant, ct).ConfigureAwait(false);
        await using var cmd = scope.Command("SELECT seq FROM ref_cursor WHERE tenant_id=@t AND resource=@r");
        cmd.Parameters.AddWithValue("t", tenant.Value);
        cmd.Parameters.AddWithValue("r", resource.Slug());
        return await cmd.ExecuteScalarAsync(ct).ConfigureAwait(false) is long l ? l : 0;
    }

    public async Task SetCursorAsync(TenantId tenant, RefResource resource, long seq, CancellationToken ct)
    {
        await using var scope = await session.OpenAsync(tenant, ct).ConfigureAwait(false);
        // GREATEST: the cursor never moves backwards except through ResetCursorAsync (410 Gone).
        await using var cmd = scope.Command("INSERT INTO ref_cursor (tenant_id, resource, seq) VALUES (@t,@r,@s) ON CONFLICT (tenant_id, resource) DO UPDATE SET seq = GREATEST(ref_cursor.seq, EXCLUDED.seq)");
        cmd.Parameters.AddWithValue("t", tenant.Value);
        cmd.Parameters.AddWithValue("r", resource.Slug());
        cmd.Parameters.AddWithValue("s", seq);
        await cmd.ExecuteNonQueryAsync(ct).ConfigureAwait(false);
    }

    public async Task ResetCursorAsync(TenantId tenant, RefResource resource, CancellationToken ct)
    {
        await using var scope = await session.OpenAsync(tenant, ct).ConfigureAwait(false);
        await using var cmd = scope.Command("INSERT INTO ref_cursor (tenant_id, resource, seq) VALUES (@t,@r,0) ON CONFLICT (tenant_id, resource) DO UPDATE SET seq = 0");
        cmd.Parameters.AddWithValue("t", tenant.Value);
        cmd.Parameters.AddWithValue("r", resource.Slug());
        await cmd.ExecuteNonQueryAsync(ct).ConfigureAwait(false);
    }

    public async Task<int> UpsertAsync(TenantId tenant, RefResource resource, IReadOnlyList<RefRecord> records, CancellationToken ct)
    {
        if (records.Count == 0)
        {
            return 0;
        }

        var table = resource.Table(); // fixed enum → table mapping, never user input
        await using var scope = await session.OpenAsync(tenant, ct).ConfigureAwait(false);
        var applied = 0;
        foreach (var rec in records)
        {
            await using var cmd = scope.Command($"""
                INSERT INTO {table} AS ref (tenant_id, id, seq, revision, deleted, payload, updated_at)
                VALUES (@t,@i,@s,@rev,@d,@p::jsonb, now())
                ON CONFLICT (tenant_id, id) DO UPDATE
                SET seq=EXCLUDED.seq, revision=EXCLUDED.revision, deleted=EXCLUDED.deleted, payload=EXCLUDED.payload, updated_at=now()
                WHERE ref.revision < EXCLUDED.revision
                """);
            cmd.Parameters.AddWithValue("t", tenant.Value);
            cmd.Parameters.AddWithValue("i", rec.Id);
            cmd.Parameters.AddWithValue("s", rec.Seq);
            cmd.Parameters.AddWithValue("rev", rec.Revision);
            cmd.Parameters.AddWithValue("d", rec.Deleted);
            cmd.Parameters.AddWithValue("p", rec.PayloadJson);
            applied += await cmd.ExecuteNonQueryAsync(ct).ConfigureAwait(false);
        }

        return applied;
    }

    public async Task<IReadOnlyDictionary<Guid, long>> ListRevisionsAsync(TenantId tenant, RefResource resource, CancellationToken ct)
    {
        await using var scope = await session.OpenAsync(tenant, ct).ConfigureAwait(false);
        await using var cmd = scope.Command($"SELECT id, revision FROM {resource.Table()} WHERE tenant_id=@t AND NOT deleted");
        cmd.Parameters.AddWithValue("t", tenant.Value);
        var d = new Dictionary<Guid, long>();
        await using var r = await cmd.ExecuteReaderAsync(ct).ConfigureAwait(false);
        while (await r.ReadAsync(ct).ConfigureAwait(false))
        {
            d[r.GetGuid(0)] = r.GetInt64(1);
        }

        return d;
    }
}
