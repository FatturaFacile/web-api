using System.Text.Json;
using Mes.Application.Contracts;
using Microsoft.Data.Sqlite;

namespace Edge.Gateway.Wal;

public sealed record WalOptions(string Path, long MaxBytes = 2L * 1024 * 1024 * 1024, double DownsampleAtFraction = 0.8, TimeSpan? DownsampleStep = null);

public sealed record WalBatch(long Id, WireBatch Batch, bool Downsampled);

/// <summary>
/// Durable store-and-forward queue on SQLite in WAL mode. Everything is written here BEFORE it is sent; a batch is
/// deleted only after the server acknowledged it (at-least-once; the server de-duplicates by batch id).
/// <list type="bullet">
/// <item><c>wal_batch</c>: one row per batch (JSON payload).</item>
/// <item><c>wal_meta</c>: counters (dropped batches, downsampled batches) that survive restarts.</item>
/// </list>
/// Disk pressure: at <see cref="WalOptions.DownsampleAtFraction"/> of the cap the OLDEST batches are downsampled in
/// place (keep change-points plus one sample per tag per step); at the cap, whole oldest batches are dropped and counted.
/// Single process owns the file; calls are serialised by a lock (SQLite connection is not thread-safe).
/// </summary>
public sealed class WalStore : IDisposable
{
    private readonly WalOptions _options;
    private readonly SqliteConnection _db;
    private readonly Lock _gate = new();

    public WalStore(WalOptions options)
    {
        _options = options;
        var dir = System.IO.Path.GetDirectoryName(System.IO.Path.GetFullPath(options.Path));
        if (dir is not null)
        {
            Directory.CreateDirectory(dir);
        }

        _db = new SqliteConnection($"Data Source={options.Path}");
        _db.Open();
        Exec("PRAGMA journal_mode=WAL;");
        Exec("PRAGMA synchronous=NORMAL;"); // durable across app crashes; a power loss may lose the last commits only
        Exec("""
             CREATE TABLE IF NOT EXISTS wal_batch (
               id INTEGER PRIMARY KEY AUTOINCREMENT,
               batch_id TEXT NOT NULL UNIQUE,
               created_ms INTEGER NOT NULL,
               sample_count INTEGER NOT NULL,
               bytes INTEGER NOT NULL,
               downsampled INTEGER NOT NULL DEFAULT 0,
               payload TEXT NOT NULL);
             CREATE TABLE IF NOT EXISTS wal_meta (key TEXT PRIMARY KEY, value TEXT NOT NULL);
             CREATE TABLE IF NOT EXISTS wal_quarantine (
               id INTEGER PRIMARY KEY AUTOINCREMENT,
               batch_id TEXT NOT NULL,
               quarantined_ms INTEGER NOT NULL,
               reason TEXT NOT NULL,
               detail TEXT NOT NULL);
             """);
    }

    public long PayloadBytes
    {
        get
        {
            lock (_gate)
            {
                return Scalar<long>("SELECT COALESCE(SUM(bytes),0) FROM wal_batch");
            }
        }
    }

    public long Pending
    {
        get
        {
            lock (_gate)
            {
                return Scalar<long>("SELECT COUNT(*) FROM wal_batch");
            }
        }
    }

    public long Meta(string key)
    {
        lock (_gate)
        {
            using var c = _db.CreateCommand();
            c.CommandText = "SELECT value FROM wal_meta WHERE key=$k";
            c.Parameters.AddWithValue("$k", key);
            return c.ExecuteScalar() is string s && long.TryParse(s, out var v) ? v : 0;
        }
    }

    public void Append(WireBatch batch)
    {
        var json = JsonSerializer.Serialize(batch);
        lock (_gate)
        {
            using var tx = _db.BeginTransaction();
            using (var c = _db.CreateCommand())
            {
                c.Transaction = tx;
                c.CommandText = "INSERT OR IGNORE INTO wal_batch(batch_id, created_ms, sample_count, bytes, payload) VALUES($b,$t,$n,$l,$p)";
                c.Parameters.AddWithValue("$b", batch.BatchId);
                c.Parameters.AddWithValue("$t", DateTimeOffset.UtcNow.ToUnixTimeMilliseconds());
                c.Parameters.AddWithValue("$n", batch.Samples.Count);
                c.Parameters.AddWithValue("$l", json.Length);
                c.Parameters.AddWithValue("$p", json);
                c.ExecuteNonQuery();
            }

            tx.Commit();
            EnforceCap();
        }
    }

    /// <summary>Oldest-first, so replay preserves order per machine (the server also tolerates reordering across batches).</summary>
    public IReadOnlyList<WalBatch> PeekOldest(int limit)
    {
        lock (_gate)
        {
            using var c = _db.CreateCommand();
            c.CommandText = "SELECT id, payload, downsampled FROM wal_batch ORDER BY id LIMIT $n";
            c.Parameters.AddWithValue("$n", limit);
            using var r = c.ExecuteReader();
            var list = new List<WalBatch>();
            while (r.Read())
            {
                var b = JsonSerializer.Deserialize<WireBatch>(r.GetString(1));
                if (b is not null)
                {
                    list.Add(new WalBatch(r.GetInt64(0), b, r.GetInt64(2) != 0));
                }
            }

            return list;
        }
    }

    public void Ack(IEnumerable<long> ids)
    {
        lock (_gate)
        {
            using var tx = _db.BeginTransaction();
            foreach (var id in ids)
            {
                using var c = _db.CreateCommand();
                c.Transaction = tx;
                c.CommandText = "DELETE FROM wal_batch WHERE id=$i";
                c.Parameters.AddWithValue("$i", id);
                c.ExecuteNonQuery();
            }

            tx.Commit();
        }
    }

    /// <summary>Number of quarantine records (batches or events the backend refused for good).</summary>
    public long QuarantinedCount
    {
        get
        {
            lock (_gate)
            {
                return Scalar<long>("SELECT COUNT(*) FROM wal_quarantine");
            }
        }
    }

    /// <summary>The kept JSON of every quarantine record, oldest first (operator inspection / manual replay).</summary>
    public IReadOnlyList<string> QuarantineDetails()
    {
        lock (_gate)
        {
            using var c = _db.CreateCommand();
            c.CommandText = "SELECT detail FROM wal_quarantine ORDER BY id";
            using var r = c.ExecuteReader();
            var list = new List<string>();
            while (r.Read())
            {
                list.Add(r.GetString(0));
            }

            return list;
        }
    }

    /// <summary>
    /// Moves a record out of the send queue into <c>wal_quarantine</c> (kept for inspection/replay, never retried
    /// automatically) so a permanently refused batch cannot block the queue. <paramref name="detail"/> is the JSON to keep
    /// (the whole batch, or the refused event ids). Atomic with the removal from <c>wal_batch</c>.
    /// </summary>
    public void Quarantine(long walId, string batchId, string reason, string detail)
    {
        lock (_gate)
        {
            using var tx = _db.BeginTransaction();
            using (var c = _db.CreateCommand())
            {
                c.Transaction = tx;
                c.CommandText = "INSERT INTO wal_quarantine(batch_id, quarantined_ms, reason, detail) VALUES($b,$t,$r,$d)";
                c.Parameters.AddWithValue("$b", batchId);
                c.Parameters.AddWithValue("$t", DateTimeOffset.UtcNow.ToUnixTimeMilliseconds());
                c.Parameters.AddWithValue("$r", reason);
                c.Parameters.AddWithValue("$d", detail);
                c.ExecuteNonQuery();
            }

            using (var c = _db.CreateCommand())
            {
                c.Transaction = tx;
                c.CommandText = "DELETE FROM wal_batch WHERE id=$i";
                c.Parameters.AddWithValue("$i", walId);
                c.ExecuteNonQuery();
            }

            tx.Commit();
        }
    }

    private void EnforceCap()
    {
        var size = Scalar<long>("SELECT COALESCE(SUM(bytes),0) FROM wal_batch");
        if (size < _options.MaxBytes * _options.DownsampleAtFraction)
        {
            return;
        }

        // Phase 1: downsample oldest not-yet-downsampled batches until we are back under the threshold.
        var step = _options.DownsampleStep ?? TimeSpan.FromSeconds(10);
        var ids = new List<long>();
        using (var c = _db.CreateCommand())
        {
            c.CommandText = "SELECT id FROM wal_batch WHERE downsampled=0 ORDER BY id LIMIT 200";
            using var r = c.ExecuteReader();
            while (r.Read())
            {
                ids.Add(r.GetInt64(0));
            }
        }

        foreach (var id in ids)
        {
            if (size < _options.MaxBytes * _options.DownsampleAtFraction * 0.9)
            {
                break;
            }

            string payload;
            using (var c = _db.CreateCommand())
            {
                c.CommandText = "SELECT payload FROM wal_batch WHERE id=$i";
                c.Parameters.AddWithValue("$i", id);
                payload = (string)c.ExecuteScalar()!;
            }

            var batch = JsonSerializer.Deserialize<WireBatch>(payload)!;
            var slim = batch with { Samples = Downsample(batch.Samples, (long)step.TotalMilliseconds) };
            var json = JsonSerializer.Serialize(slim);
            using (var c = _db.CreateCommand())
            {
                c.CommandText = "UPDATE wal_batch SET payload=$p, bytes=$l, sample_count=$n, downsampled=1 WHERE id=$i";
                c.Parameters.AddWithValue("$p", json);
                c.Parameters.AddWithValue("$l", json.Length);
                c.Parameters.AddWithValue("$n", slim.Samples.Count);
                c.Parameters.AddWithValue("$i", id);
                c.ExecuteNonQuery();
            }

            size -= payload.Length - json.Length;
            Bump("downsampled_batches", 1);
        }

        // Phase 2: hard cap. Drop whole oldest batches (counted, so the loss is visible and reported).
        while (size > _options.MaxBytes)
        {
            long id, bytes;
            using (var c = _db.CreateCommand())
            {
                c.CommandText = "SELECT id, bytes FROM wal_batch ORDER BY id LIMIT 1";
                using var r = c.ExecuteReader();
                if (!r.Read())
                {
                    break;
                }

                id = r.GetInt64(0);
                bytes = r.GetInt64(1);
            }

            using (var c = _db.CreateCommand())
            {
                c.CommandText = "DELETE FROM wal_batch WHERE id=$i";
                c.Parameters.AddWithValue("$i", id);
                c.ExecuteNonQuery();
            }

            size -= bytes;
            Bump("dropped_batches", 1);
        }
    }

    /// <summary>
    /// Keeps, per (machine, tag): the first sample, every sample whose value differs from the last KEPT one (so state
    /// flips and alarm edges survive), at least one sample per <paramref name="stepMs"/>, and the final sample.
    /// Generic on purpose — the edge does not know which tags are counters or states. Cumulative counters stay correct
    /// because only intermediate points are removed.
    /// </summary>
    public static IReadOnlyList<WireSample> Downsample(IReadOnlyList<WireSample> samples, long stepMs)
    {
        var kept = new List<WireSample>(samples.Count / 4 + 1);
        var last = new Dictionary<(Guid, string), WireSample>();
        var lastSeen = new Dictionary<(Guid, string), WireSample>();
        foreach (var s in samples.OrderBy(x => x.EventMs))
        {
            var key = (s.MachineId, s.Tag);
            lastSeen[key] = s;
            if (!last.TryGetValue(key, out var prev) || s.V != prev.V || s.S != prev.S || s.EventMs - prev.EventMs >= stepMs)
            {
                kept.Add(s);
                last[key] = s;
            }
        }

        foreach (var (key, final) in lastSeen)
        {
            if (!ReferenceEquals(last[key], final))
            {
                kept.Add(final);
            }
        }

        return kept.OrderBy(x => x.EventMs).ToList();
    }

    private void Bump(string key, long by)
    {
        using var c = _db.CreateCommand();
        c.CommandText = "INSERT INTO wal_meta(key,value) VALUES($k,$v) ON CONFLICT(key) DO UPDATE SET value = CAST(value AS INTEGER) + $d";
        c.Parameters.AddWithValue("$k", key);
        c.Parameters.AddWithValue("$v", by.ToString(System.Globalization.CultureInfo.InvariantCulture));
        c.Parameters.AddWithValue("$d", by);
        c.ExecuteNonQuery();
    }

    private void Exec(string sql)
    {
        using var c = _db.CreateCommand();
        c.CommandText = sql;
        c.ExecuteNonQuery();
    }

    private T Scalar<T>(string sql)
    {
        using var c = _db.CreateCommand();
        c.CommandText = sql;
        return (T)Convert.ChangeType(c.ExecuteScalar()!, typeof(T), System.Globalization.CultureInfo.InvariantCulture);
    }

    public void Dispose() => _db.Dispose();
}
