using System.Buffers.Binary;
using System.Net.Sockets;
using Mes.Application.Abstractions;
using Mes.Domain.Telemetry;
using Microsoft.Extensions.Logging;
using Polly;
using Polly.CircuitBreaker;
using Polly.Retry;
using Polly.Timeout;

namespace Mes.Infrastructure.Connectors.Modbus;

/// <summary>
/// The ONLY Modbus surface the gateway may use. Read-only by construction: there is no write method to call, and
/// <c>BannedSymbols.txt</c> bans the write APIs of the common Modbus libraries so one cannot be smuggled in.
/// </summary>
public interface IReadOnlyModbusMaster : IAsyncDisposable
{
    Task ConnectAsync(CancellationToken ct);

    /// <summary>Function code 0x03 only.</summary>
    Task<ushort[]> ReadHoldingRegistersAsync(byte unitId, ushort start, ushort count, CancellationToken ct);
}

public sealed class ModbusException(byte functionCode, byte exceptionCode)
    : Exception($"Modbus exception 0x{exceptionCode:X2} for function 0x{functionCode:X2}")
{
    public byte ExceptionCode { get; } = exceptionCode;
}

/// <summary>Minimal Modbus/TCP client implementing FC03 only (no other function code is ever put on the wire).</summary>
public sealed class ModbusTcpMaster(string host, int port, TimeSpan requestTimeout) : IReadOnlyModbusMaster
{
    private const byte ReadHolding = 0x03;
    private TcpClient? _client;
    private NetworkStream? _stream;
    private ushort _txId;

    public async Task ConnectAsync(CancellationToken ct)
    {
        _client = new TcpClient { NoDelay = true };
        using var cts = CancellationTokenSource.CreateLinkedTokenSource(ct);
        cts.CancelAfter(requestTimeout);
        await _client.ConnectAsync(host, port, cts.Token).ConfigureAwait(false);
        _stream = _client.GetStream();
    }

    public async Task<ushort[]> ReadHoldingRegistersAsync(byte unitId, ushort start, ushort count, CancellationToken ct)
    {
        if (_stream is null)
        {
            throw new InvalidOperationException("Not connected.");
        }

        if (count is 0 or > ModbusReadPlanner.MaxRegistersPerRequest)
        {
            throw new ArgumentOutOfRangeException(nameof(count));
        }

        using var cts = CancellationTokenSource.CreateLinkedTokenSource(ct);
        cts.CancelAfter(requestTimeout);

        var tx = ++_txId;
        var req = new byte[12];
        BinaryPrimitives.WriteUInt16BigEndian(req.AsSpan(0), tx);
        BinaryPrimitives.WriteUInt16BigEndian(req.AsSpan(2), 0); // protocol id
        BinaryPrimitives.WriteUInt16BigEndian(req.AsSpan(4), 6); // remaining length
        req[6] = unitId;
        req[7] = ReadHolding;
        BinaryPrimitives.WriteUInt16BigEndian(req.AsSpan(8), start);
        BinaryPrimitives.WriteUInt16BigEndian(req.AsSpan(10), count);
        await _stream.WriteAsync(req, cts.Token).ConfigureAwait(false);

        var header = new byte[9]; // MBAP(7) + fc + (byteCount | exception code)
        await _stream.ReadExactlyAsync(header, cts.Token).ConfigureAwait(false);
        if (BinaryPrimitives.ReadUInt16BigEndian(header) != tx)
        {
            throw new IOException("Modbus transaction id mismatch (stream desynchronised).");
        }

        if ((header[7] & 0x80) != 0)
        {
            throw new ModbusException(header[7], header[8]);
        }

        var byteCount = header[8];
        if (byteCount != count * 2)
        {
            throw new IOException($"Unexpected byte count {byteCount} for {count} registers.");
        }

        var data = new byte[byteCount];
        await _stream.ReadExactlyAsync(data, cts.Token).ConfigureAwait(false);
        var regs = new ushort[count];
        for (var i = 0; i < count; i++)
        {
            regs[i] = BinaryPrimitives.ReadUInt16BigEndian(data.AsSpan(i * 2));
        }

        return regs;
    }

    public ValueTask DisposeAsync()
    {
        _stream?.Dispose();
        _client?.Dispose();
        return ValueTask.CompletedTask;
    }
}

public sealed record ModbusConnectorOptions(
    byte UnitId,
    TimeSpan PollInterval,
    IReadOnlyList<ModbusTagSpec> Tags,
    int MaxGap = 8,
    int ChannelCapacity = 10_000);

public static class ModbusResilience
{
    /// <summary>
    /// Polly v8 pipeline (outermost first): retry with exponential backoff + jitter for transient I/O,
    /// circuit breaker so a dead PLC is not hammered, per-attempt timeout.
    /// A Modbus exception response (illegal address, etc.) is NOT retried: it is deterministic.
    /// </summary>
    public static ResiliencePipeline Create(TimeSpan attemptTimeout) => new ResiliencePipelineBuilder()
        .AddRetry(new RetryStrategyOptions
        {
            MaxRetryAttempts = 3,
            Delay = TimeSpan.FromMilliseconds(200),
            BackoffType = DelayBackoffType.Exponential,
            UseJitter = true,
            ShouldHandle = new PredicateBuilder().Handle<IOException>().Handle<SocketException>().Handle<TimeoutRejectedException>(),
        })
        .AddCircuitBreaker(new CircuitBreakerStrategyOptions
        {
            FailureRatio = 0.5,
            MinimumThroughput = 6,
            SamplingDuration = TimeSpan.FromSeconds(30),
            BreakDuration = TimeSpan.FromSeconds(15),
            ShouldHandle = new PredicateBuilder().Handle<IOException>().Handle<SocketException>().Handle<TimeoutRejectedException>(),
        })
        .AddTimeout(attemptTimeout)
        .Build();
}

public sealed class ModbusConnector : ConnectorBase
{
    private readonly Func<IReadOnlyModbusMaster> _masterFactory;
    private readonly ModbusConnectorOptions _options;
    private readonly IReadOnlyList<ReadBlock> _plan;
    private readonly ResiliencePipeline _pipeline;

    public ModbusConnector(string name, Func<IReadOnlyModbusMaster> masterFactory, ModbusConnectorOptions options, ILogger<ModbusConnector> logger, TimeProvider? time = null, ResiliencePipeline? pipeline = null)
        : base(name, options.ChannelCapacity, logger, time)
    {
        _masterFactory = masterFactory;
        _options = options;
        _plan = ModbusReadPlanner.Coalesce(options.Tags, options.MaxGap);
        _pipeline = pipeline ?? ModbusResilience.Create(TimeSpan.FromSeconds(3));
    }

    protected override async Task ConnectAndRunAsync(CancellationToken ct)
    {
        await using var master = _masterFactory();
        await master.ConnectAsync(ct).ConfigureAwait(false);
        MarkConnected();

        using var timer = new PeriodicTimer(_options.PollInterval, Time);
        do
        {
            foreach (var block in _plan)
            {
                // Any failure that survives the pipeline (breaker open, retries exhausted) bubbles up: the base class
                // tears the connection down and reconnects with backoff.
                var regs = await _pipeline.ExecuteAsync(
                    async token => await master.ReadHoldingRegistersAsync(_options.UnitId, block.Start, block.Count, token).ConfigureAwait(false),
                    ct).ConfigureAwait(false);
                var rx = Time.GetUtcNow();

                foreach (var tag in block.Tags)
                {
                    var value = ModbusDecoder.Decode(regs.AsSpan(tag.Register - block.Start, tag.Count), tag.Type, tag.Order);
                    // Modbus carries no timestamps: SourceTime stays null and the edge uses gateway time.
                    TryEmit(new RawSample(tag.SourceAddress, value, null, null, rx, double.IsFinite(value) ? SampleQuality.Good : SampleQuality.Bad));
                }
            }
        }
        while (await timer.WaitForNextTickAsync(ct).ConfigureAwait(false));
    }
}
