using System.Reflection;
using Mes.Application.Abstractions;
using Mes.Infrastructure.Persistence.Pipeline;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.DependencyInjection.Extensions;
using Npgsql;
using StackExchange.Redis;

namespace Mes.Infrastructure.Persistence;

public sealed class SchemaMigrator(NpgsqlDataSource dataSource)
{
    /// <summary>Applies embedded <c>Schema/*.sql</c> in name order, once each (tracked in schema_migration). Run with the OWNER role.</summary>
    public async Task MigrateAsync(CancellationToken ct)
    {
        await using var conn = await dataSource.OpenConnectionAsync(ct).ConfigureAwait(false);
        await using (var init = new NpgsqlCommand("CREATE TABLE IF NOT EXISTS schema_migration (name text PRIMARY KEY, applied_at timestamptz NOT NULL DEFAULT now())", conn))
        {
            await init.ExecuteNonQueryAsync(ct).ConfigureAwait(false);
        }

        var asm = typeof(SchemaMigrator).Assembly;
        foreach (var res in asm.GetManifestResourceNames().Where(n => n.Contains(".Schema.", StringComparison.Ordinal) && n.EndsWith(".sql", StringComparison.Ordinal)).Order(StringComparer.Ordinal))
        {
            await using var check = new NpgsqlCommand("SELECT 1 FROM schema_migration WHERE name=@n", conn);
            check.Parameters.AddWithValue("n", res);
            if (await check.ExecuteScalarAsync(ct).ConfigureAwait(false) is not null)
            {
                continue;
            }

            await using var stream = asm.GetManifestResourceStream(res)!;
            using var reader = new StreamReader(stream);
            var sql = await reader.ReadToEndAsync(ct).ConfigureAwait(false);
            await using var tx = await conn.BeginTransactionAsync(ct).ConfigureAwait(false);
            await using (var apply = new NpgsqlCommand(sql, conn, tx))
            {
                await apply.ExecuteNonQueryAsync(ct).ConfigureAwait(false);
            }

            await using (var mark = new NpgsqlCommand("INSERT INTO schema_migration(name) VALUES (@n)", conn, tx))
            {
                mark.Parameters.AddWithValue("n", res);
                await mark.ExecuteNonQueryAsync(ct).ConfigureAwait(false);
            }

            await tx.CommitAsync(ct).ConfigureAwait(false);
        }
    }
}

public static class PersistenceServiceCollectionExtensions
{
    /// <param name="appConnectionString">Role subject to RLS (no BYPASSRLS).</param>
    /// <param name="systemConnectionString">Role with BYPASSRLS for the outbox relay (cross-tenant).</param>
    public static IServiceCollection AddMesPersistence(this IServiceCollection services, string appConnectionString, string systemConnectionString, string redisConnectionString, PipelineOptions? pipeline = null)
    {
        services.TryAddSingleton(TimeProvider.System);
        services.AddSingleton(_ => NpgsqlDataSource.Create(appConnectionString));
        services.AddSingleton(_ => new SystemDataSource(NpgsqlDataSource.Create(systemConnectionString)));
        services.AddSingleton<PgSession>();
        services.AddSingleton<PgMesStore>();
        services.AddSingleton<IMesStore>(sp => sp.GetRequiredService<PgMesStore>());
        services.AddSingleton<ITelemetryStore>(sp => sp.GetRequiredService<PgMesStore>());
        services.AddSingleton<IOutbox>(sp => sp.GetRequiredService<PgMesStore>());
        services.AddSingleton<ITransactionRunner, PgTransactionRunner>();
        services.AddSingleton<IReferenceStore, PgReferenceStore>();
        services.AddSingleton<ICounterCheckpointStore, PgCounterCheckpointStore>();
        services.AddSingleton<IOutboxStore, PgOutboxStore>();
        services.AddSingleton<IMachineProfileProvider, RefMachineProfileProvider>();
        services.AddSingleton<IShiftCalendarProvider, RefShiftCalendarProvider>(); // replaces the always-planned default
        services.AddSingleton<SchemaMigrator>();

        services.AddSingleton<IConnectionMultiplexer>(_ => ConnectionMultiplexer.Connect(redisConnectionString));
        services.AddSingleton<IOeeLiveStore, RedisOeeLiveStore>();

        services.AddSingleton(pipeline ?? new PipelineOptions());
        services.AddSingleton<TelemetryPipeline>();
        services.AddHostedService(sp => sp.GetRequiredService<TelemetryPipeline>());
        services.AddSingleton<IngestService>();
        return services;
    }
}
