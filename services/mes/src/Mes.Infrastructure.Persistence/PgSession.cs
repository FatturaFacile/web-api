using Mes.Application.Abstractions;
using Mes.Domain.Common;
using Npgsql;

namespace Mes.Infrastructure.Persistence;

/// <summary>Marker wrapper: a data source for a role with BYPASSRLS, used only by cross-tenant background jobs.</summary>
public sealed class SystemDataSource(NpgsqlDataSource inner)
{
    public NpgsqlDataSource Inner { get; } = inner;
}

/// <summary>
/// Connection/transaction scope with tenant context. Inside <see cref="PgTransactionRunner.RunAsync"/> all stores share
/// ONE connection+transaction (ambient via AsyncLocal) so interval, bucket and outbox writes commit atomically.
/// Outside a runner each call gets its own short transaction. Every transaction sets <c>app.tenant_id</c>
/// (transaction-local) so Postgres RLS enforces tenant isolation even if a query forgets its WHERE clause.
/// A single transaction serves a single tenant; mixing tenants in one transaction throws.
/// </summary>
public sealed class PgSession(NpgsqlDataSource dataSource)
{
    private sealed class Ambient
    {
        public NpgsqlConnection? Connection;
        public NpgsqlTransaction? Transaction;
        public TenantId? Tenant;
    }

    private static readonly AsyncLocal<Ambient?> Current = new();

    public sealed class Scope(NpgsqlConnection connection, NpgsqlTransaction transaction, bool owns) : IAsyncDisposable
    {
        public NpgsqlConnection Connection { get; } = connection;

        public NpgsqlTransaction Transaction { get; } = transaction;

        public NpgsqlCommand Command(string sql) => new(sql, Connection, Transaction);

        public async ValueTask DisposeAsync()
        {
            if (!owns)
            {
                return;
            }

            await Transaction.CommitAsync().ConfigureAwait(false);
            await Transaction.DisposeAsync().ConfigureAwait(false);
            await Connection.DisposeAsync().ConfigureAwait(false);
        }
    }

    public async Task<Scope> OpenAsync(TenantId tenant, CancellationToken ct)
    {
        if (tenant.IsEmpty)
        {
            throw new ArgumentException("Tenant is required.", nameof(tenant));
        }

        if (Current.Value is { } amb)
        {
            if (amb.Tenant is { } t && t != tenant)
            {
                throw new InvalidOperationException($"Transaction already bound to tenant {t}; refusing to touch tenant {tenant}.");
            }

            if (amb.Transaction is null)
            {
                amb.Connection = await dataSource.OpenConnectionAsync(ct).ConfigureAwait(false);
                amb.Transaction = await amb.Connection.BeginTransactionAsync(ct).ConfigureAwait(false);
                amb.Tenant = tenant;
                await SetTenantAsync(amb.Connection, amb.Transaction, tenant, ct).ConfigureAwait(false);
            }

            return new Scope(amb.Connection!, amb.Transaction, owns: false);
        }

        var conn = await dataSource.OpenConnectionAsync(ct).ConfigureAwait(false);
        var tx = await conn.BeginTransactionAsync(ct).ConfigureAwait(false);
        await SetTenantAsync(conn, tx, tenant, ct).ConfigureAwait(false);
        return new Scope(conn, tx, owns: true);
    }

    private static async Task SetTenantAsync(NpgsqlConnection c, NpgsqlTransaction tx, TenantId tenant, CancellationToken ct)
    {
        await using var cmd = new NpgsqlCommand("SELECT set_config('app.tenant_id', @t, true)", c, tx);
        cmd.Parameters.AddWithValue("t", tenant.ToString());
        await cmd.ExecuteNonQueryAsync(ct).ConfigureAwait(false);
    }

    internal static bool HasAmbient => Current.Value is not null;

    internal static object Begin()
    {
        var a = new Ambient();
        Current.Value = a;
        return a;
    }

    internal static async Task EndAsync(object? ambient, bool commit)
    {
        var a = (Ambient)ambient!;
        Current.Value = null;
        if (a.Transaction is null)
        {
            return;
        }

        try
        {
            if (commit)
            {
                await a.Transaction.CommitAsync().ConfigureAwait(false);
            }
            else
            {
                await a.Transaction.RollbackAsync().ConfigureAwait(false);
            }
        }
        finally
        {
            await a.Transaction.DisposeAsync().ConfigureAwait(false);
            await a.Connection!.DisposeAsync().ConfigureAwait(false);
        }
    }
}

public sealed class PgTransactionRunner : ITransactionRunner
{
    public async Task RunAsync(Func<CancellationToken, Task> work, CancellationToken ct)
    {
        if (PgSession.HasAmbient)
        {
            await work(ct).ConfigureAwait(false); // nested call joins the outer transaction
            return;
        }

        var ambient = PgSession.Begin();
        var ok = false;
        try
        {
            await work(ct).ConfigureAwait(false);
            ok = true;
        }
        finally
        {
            await PgSession.EndAsync(ambient, ok).ConfigureAwait(false);
        }
    }
}
