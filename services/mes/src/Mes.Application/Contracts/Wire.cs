using System.Runtime.CompilerServices;
using System.Threading.Channels;
using Mes.Domain.Common;
using Mes.Domain.Telemetry;

namespace Mes.Application.Contracts;

/// <summary>Compact edge → Mes.Core wire form. Times are Unix milliseconds in UTC.</summary>
public sealed record WireSample(Guid MachineId, string Tag, long EventMs, long RxMs, double? V, string? S, byte Q, int MapVersion);

/// <summary>
/// One idempotent batch. <see cref="BatchId"/> is the dedupe key: the gateway may resend it any number of times.
/// <see cref="WatermarkMs"/>: every sample with event time &lt;= watermark from this gateway has already been sent.
/// </summary>
public sealed record WireBatch(string BatchId, Guid TenantId, string GatewayId, long WatermarkMs, IReadOnlyList<WireSample> Samples);

public sealed record WireAck(string BatchId, int Accepted, int Rejected, int DuplicateMachines);

public static class WireMapper
{
    public static WireSample ToWire(TelemetrySample s) => new(
        s.MachineId.Value,
        s.TagId.Value,
        s.Times.EventTime.ToUnixTimeMilliseconds(),
        s.Times.GatewayRxTime.ToUnixTimeMilliseconds(),
        s.NumericValue,
        s.TextValue,
        (byte)s.Quality,
        s.MappingVersion);

    public static TelemetrySample ToDomain(WireSample w, TenantId tenant, DateTimeOffset ingest) => new(
        tenant,
        new MachineId(w.MachineId),
        new TagId(w.Tag),
        new SampleTimes(DateTimeOffset.FromUnixTimeMilliseconds(w.EventMs), DateTimeOffset.FromUnixTimeMilliseconds(w.RxMs), ingest),
        w.V,
        w.S,
        Enum.IsDefined((SampleQuality)w.Q) ? (SampleQuality)w.Q : SampleQuality.Bad,
        w.MapVersion);
}

public static class ChannelBatching
{
    /// <summary>
    /// Reads from <paramref name="reader"/> and yields batches of up to <paramref name="maxCount"/> items, closing a
    /// batch early when <paramref name="maxWait"/> elapses after its FIRST item (so an idle stream never holds data
    /// back longer than maxWait, and an empty window yields nothing). Completes when the channel is completed and drained.
    /// <para>This is the explicit Buffer(count, timespan) operator: it does not rely on any LINQ/Rx extension.</para>
    /// </summary>
    public static async IAsyncEnumerable<IReadOnlyList<T>> ReadBatchesAsync<T>(
        ChannelReader<T> reader,
        int maxCount,
        TimeSpan maxWait,
        [EnumeratorCancellation] CancellationToken ct = default)
    {
        ArgumentOutOfRangeException.ThrowIfLessThan(maxCount, 1);
        var buffer = new List<T>(maxCount);
        while (true)
        {
            bool more;
            try
            {
                more = await reader.WaitToReadAsync(ct).ConfigureAwait(false);
            }
            catch (OperationCanceledException)
            {
                break;
            }

            if (!more)
            {
                break;
            }

            using var window = CancellationTokenSource.CreateLinkedTokenSource(ct);
            window.CancelAfter(maxWait);
            try
            {
                while (buffer.Count < maxCount)
                {
                    if (reader.TryRead(out var item))
                    {
                        buffer.Add(item);
                    }
                    else if (!await reader.WaitToReadAsync(window.Token).ConfigureAwait(false))
                    {
                        break; // channel completed
                    }
                }
            }
            catch (OperationCanceledException) when (!ct.IsCancellationRequested)
            {
                // maxWait elapsed: flush what we have
            }
            catch (OperationCanceledException)
            {
                // shutdown: flush what we have below, then stop
            }

            if (buffer.Count > 0)
            {
                yield return buffer.ToArray();
                buffer.Clear();
            }

            if (ct.IsCancellationRequested)
            {
                yield break;
            }
        }

        if (buffer.Count > 0)
        {
            yield return buffer.ToArray();
        }
    }
}
