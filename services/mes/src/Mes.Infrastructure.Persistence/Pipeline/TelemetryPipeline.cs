using System.Threading.Channels;
using Mes.Application.Commands;
using Mes.Application.Contracts;
using Mes.Domain.Common;
using Mes.Domain.Telemetry;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;

namespace Mes.Infrastructure.Persistence.Pipeline;

public sealed record PipelineOptions(int Partitions = 8, int PartitionCapacity = 1_000, int MaxItemsPerWindow = 64, TimeSpan? Window = null);

/// <summary>
/// Partitioned ingestion pipeline. Machines are hashed to a partition (FNV-1a on the machine id, stable across
/// processes); each partition has ONE bounded channel and ONE worker, so for any machine there is exactly one writer
/// and its samples are processed in arrival order — the per-machine state machine and counter trackers need no locks.
/// <para>Back-pressure: the channels are bounded with <c>Wait</c>; when a partition is saturated
/// <see cref="EnqueueAsync"/> awaits, the HTTP ingest request slows down and the edge keeps the data in its WAL.</para>
/// <para>Failure mode: if a handler throws, only that item's task faults (the edge retries the whole batch; the batch
/// dedupe key makes the retry safe) and the worker carries on with the next item.</para>
/// <para>Scaling: partitions are in-process. To scale out across replicas, route by the same hash at the load balancer
/// (consistent hashing on machine id) — two replicas must never write the same machine.</para>
/// </summary>
public sealed class TelemetryPipeline : BackgroundService
{
    private sealed record Item(TenantId Tenant, string BatchId, IReadOnlyList<TelemetrySample> Samples, TaskCompletionSource<IngestResult> Done);

    private readonly Channel<Item>[] _partitions;
    private readonly PipelineOptions _options;
    private readonly IngestTelemetryBatchHandler _handler;
    private readonly ILogger<TelemetryPipeline> _logger;

    public TelemetryPipeline(IngestTelemetryBatchHandler handler, PipelineOptions options, ILogger<TelemetryPipeline> logger)
    {
        _handler = handler;
        _options = options;
        _logger = logger;
        _partitions = Enumerable.Range(0, options.Partitions)
            .Select(_ => Channel.CreateBounded<Item>(new BoundedChannelOptions(options.PartitionCapacity)
            {
                FullMode = BoundedChannelFullMode.Wait,
                SingleReader = true,
                SingleWriter = false,
            }))
            .ToArray();
    }

    /// <summary>Routes each machine's samples to its partition and completes when all of them have been durably handled.</summary>
    public async Task<IngestResult> EnqueueAsync(TenantId tenant, string batchId, IReadOnlyList<TelemetrySample> samples, CancellationToken ct)
    {
        var waits = new List<Task<IngestResult>>();
        foreach (var byMachine in samples.GroupBy(s => s.MachineId))
        {
            var done = new TaskCompletionSource<IngestResult>(TaskCreationOptions.RunContinuationsAsynchronously);
            var channel = _partitions[byMachine.Key.PartitionOf(_partitions.Length)];
            await channel.Writer.WriteAsync(new Item(tenant, batchId, byMachine.ToList(), done), ct).ConfigureAwait(false);
            waits.Add(done.Task);
        }

        var results = await Task.WhenAll(waits).ConfigureAwait(false);
        return new IngestResult(results.Sum(r => r.Accepted), results.Sum(r => r.Rejected), results.Sum(r => r.DuplicateMachines));
    }

    protected override Task ExecuteAsync(CancellationToken stoppingToken) =>
        Task.WhenAll(_partitions.Select((c, i) => Task.Run(() => RunPartitionAsync(i, c.Reader, stoppingToken), CancellationToken.None)));

    private async Task RunPartitionAsync(int index, ChannelReader<Item> reader, CancellationToken stop)
    {
        // Buffer(count, time): drain whatever arrived within the window, then process strictly in order.
        // Not cancelled by `stop` mid-flight: on shutdown the channel is completed and drained (see StopAsync).
        await foreach (var window in ChannelBatching.ReadBatchesAsync(reader, _options.MaxItemsPerWindow, _options.Window ?? TimeSpan.FromMilliseconds(50), CancellationToken.None).ConfigureAwait(false))
        {
            foreach (var item in window)
            {
                try
                {
                    item.Done.TrySetResult(await _handler.HandleAsync(new IngestTelemetryBatch(item.Tenant, item.BatchId, item.Samples), CancellationToken.None).ConfigureAwait(false));
                }
                catch (Exception ex)
                {
                    _logger.LogError(ex, "Ingest failed in partition {Partition} (tenant {Tenant}, batch {Batch})", index, item.Tenant, item.BatchId);
                    item.Done.TrySetException(ex);
                }
            }
        }
    }

    public override async Task StopAsync(CancellationToken cancellationToken)
    {
        foreach (var p in _partitions)
        {
            p.Writer.TryComplete(); // stop accepting; workers drain what is queued
        }

        await base.StopAsync(cancellationToken).ConfigureAwait(false);
    }
}

/// <summary>Edge batch → domain samples → pipeline. Rejects tenants that do not match the authenticated tenant.</summary>
public sealed class IngestService(TelemetryPipeline pipeline, TimeProvider time)
{
    public async Task<WireAck> IngestAsync(TenantId authenticatedTenant, WireBatch batch, CancellationToken ct)
    {
        if (batch.TenantId != authenticatedTenant.Value)
        {
            throw new UnauthorizedAccessException("Batch tenant does not match the authenticated tenant.");
        }

        var now = time.GetUtcNow();
        var samples = batch.Samples.Select(w => WireMapper.ToDomain(w, authenticatedTenant, now)).ToList();
        var r = await pipeline.EnqueueAsync(authenticatedTenant, batch.BatchId, samples, ct).ConfigureAwait(false);
        return new WireAck(batch.BatchId, r.Accepted, r.Rejected, r.DuplicateMachines);
    }
}
