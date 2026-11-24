using System.Threading.Channels;
using Edge.Gateway.Mapping;
using Edge.Gateway.Time;
using Edge.Gateway.Wal;
using Mes.Application.Abstractions;
using Mes.Application.Contracts;
using Mes.Domain.Common;
using Mes.Domain.Machines;
using Mes.Domain.Telemetry;
using Mes.Infrastructure.Connectors;
using Mes.Infrastructure.Connectors.Modbus;
using Mes.Infrastructure.Connectors.Mqtt;
using Mes.Infrastructure.Connectors.OpcUa;
using Microsoft.Extensions.Options;

namespace Edge.Gateway;

/// <summary>
/// Owns the connector fleet for this line/cell and reconciles it with the (hot-reloaded) configuration:
/// new connector → start; removed → stop; connection settings changed → restart that connector only;
/// only the tag map / <c>MapVersion</c> changed → atomic mapping swap, no reconnect.
/// Pipeline: connector → TagMapper → bounded channel → (count, time) batcher → WAL → PublisherService.
/// </summary>
public sealed class GatewaySupervisor : BackgroundService
{
    private sealed class Running(ConnectorEntry entry, IConnector connector, TagMapper mapper, CancellationTokenSource cts, Task task)
    {
        public ConnectorEntry Entry { get; set; } = entry;

        public IConnector Connector { get; } = connector;

        public TagMapper Mapper { get; } = mapper;

        public CancellationTokenSource Cts { get; } = cts;

        public Task Task { get; } = task;

        public string Fingerprint { get; set; } = entry.ConnectionFingerprint();
    }

    private readonly IOptionsMonitor<GatewayConfig> _options;
    private readonly WalStore _wal;
    private readonly ClockOffsetEstimator _clock;
    private readonly WatermarkTracker _watermark;
    private readonly ILoggerFactory _loggers;
    private readonly ILogger<GatewaySupervisor> _logger;
    private readonly Channel<TelemetrySample> _mapped = Channel.CreateBounded<TelemetrySample>(new BoundedChannelOptions(100_000) { FullMode = BoundedChannelFullMode.Wait, SingleReader = true });
    private readonly Dictionary<string, Running> _running = [];
    private readonly SemaphoreSlim _reconcileLock = new(1, 1);
    private CancellationToken _stop;

    public GatewaySupervisor(IOptionsMonitor<GatewayConfig> options, WalStore wal, ClockOffsetEstimator clock, WatermarkTracker watermark, ILoggerFactory loggers)
    {
        _options = options;
        _wal = wal;
        _clock = clock;
        _watermark = watermark;
        _loggers = loggers;
        _logger = loggers.CreateLogger<GatewaySupervisor>();
    }

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        _stop = stoppingToken;
        using var sub = _options.OnChange(changed => { _ = ReconcileSafeAsync(); });
        await ReconcileSafeAsync().ConfigureAwait(false);

        try
        {
            await foreach (var batch in ChannelBatching.ReadBatchesAsync(_mapped.Reader, _options.CurrentValue.BatchSize, TimeSpan.FromMilliseconds(_options.CurrentValue.BatchWindowMs), stoppingToken).ConfigureAwait(false))
            {
                var cfg = _options.CurrentValue;
                var wm = _watermark.Watermark ?? batch.Max(s => s.Times.EventTime.ToUnixTimeMilliseconds());
                _wal.Append(new WireBatch($"{cfg.GatewayId}-{Guid.CreateVersion7():N}", cfg.TenantId, cfg.GatewayId, wm, batch.Select(WireMapper.ToWire).ToList()));
            }
        }
        catch (OperationCanceledException)
        {
            // shutdown
        }

        foreach (var r in _running.Values.ToList())
        {
            await StopAsync(r).ConfigureAwait(false);
        }
    }

    private async Task ReconcileSafeAsync()
    {
        await _reconcileLock.WaitAsync().ConfigureAwait(false);
        try
        {
            await ReconcileAsync(_options.CurrentValue).ConfigureAwait(false);
        }
        catch (Exception ex)
        {
            _logger.LogError(ex, "Configuration reload failed; keeping the previous connector set");
        }
        finally
        {
            _reconcileLock.Release();
        }
    }

    private async Task ReconcileAsync(GatewayConfig cfg)
    {
        var wanted = cfg.Connectors.ToDictionary(c => c.Name);
        foreach (var name in _running.Keys.Except(wanted.Keys).ToList())
        {
            await StopAsync(_running[name]).ConfigureAwait(false);
            _running.Remove(name);
            _watermark.Forget(name);
        }

        foreach (var entry in cfg.Connectors)
        {
            var snapshot = TagMapSnapshot.Create(entry.MapVersion, new TenantId(cfg.TenantId), new MachineId(entry.MachineId), entry.Tags.Select(ToMapping));
            if (_running.TryGetValue(entry.Name, out var r))
            {
                if (r.Fingerprint != entry.ConnectionFingerprint())
                {
                    _logger.LogInformation("Connector {Name}: connection settings changed, restarting", entry.Name);
                    await StopAsync(r).ConfigureAwait(false);
                    _running.Remove(entry.Name);
                }
                else
                {
                    if (r.Mapper.Swap(snapshot))
                    {
                        _logger.LogInformation("Connector {Name}: tag map v{Version} hot-swapped", entry.Name, entry.MapVersion);
                    }

                    r.Entry = entry;
                    continue;
                }
            }

            Start(entry, snapshot);
        }
    }

    private void Start(ConnectorEntry entry, TagMapSnapshot snapshot)
    {
        var connector = EdgeConnectorFactory.Create(entry, _loggers);
        var mapper = new TagMapper(_clock);
        mapper.Swap(snapshot);
        var cts = CancellationTokenSource.CreateLinkedTokenSource(_stop);
        var task = Task.Run(async () =>
        {
            var run = connector.RunAsync(cts.Token);
            await foreach (var raw in connector.Samples.ReadAllAsync(cts.Token).ConfigureAwait(false))
            {
                var s = mapper.Map(raw);
                _watermark.Observe(entry.Name, (s?.Times.EventTime ?? raw.GatewayRxTime).ToUnixTimeMilliseconds());
                if (s is not null)
                {
                    await _mapped.Writer.WriteAsync(s, cts.Token).ConfigureAwait(false); // back-pressure here is fine: we are off the protocol thread
                }
            }

            await run.ConfigureAwait(false);
        });
        _running[entry.Name] = new Running(entry, connector, mapper, cts, task);
        _logger.LogInformation("Connector {Name} ({Protocol}) started, tag map v{Version}", entry.Name, entry.Protocol, entry.MapVersion);
    }

    private static async Task StopAsync(Running r)
    {
        await r.Cts.CancelAsync().ConfigureAwait(false);
        try
        {
            await r.Task.ConfigureAwait(false);
        }
        catch (OperationCanceledException)
        {
            // expected
        }

        await r.Connector.DisposeAsync().ConfigureAwait(false);
        r.Cts.Dispose();
    }

    private static TagMapping ToMapping(TagEntry t) => new(t.Source, new TagId(t.Tag), t.Scale, t.Offset, t.Deadband);
}

public static class EdgeConnectorFactory
{
    public static IConnector Create(ConnectorEntry e, ILoggerFactory loggers)
    {
        switch (e.Protocol.ToLowerInvariant())
        {
            case "modbus" when e.Modbus is { } m:
            {
                var tags = m.Registers.Select(r => new ModbusTagSpec(r.Source, r.Register, Enum.Parse<ModbusDataType>(r.Type, true), ModbusByteOrder.Parse(r.Order))).ToList();
                return new ModbusConnector(
                    e.Name,
                    () => new ModbusTcpMaster(m.Host, m.Port, TimeSpan.FromSeconds(3)),
                    new ModbusConnectorOptions(m.UnitId, TimeSpan.FromMilliseconds(m.PollMs), tags),
                    loggers.CreateLogger<ModbusConnector>());
            }

            case "opcua" when e.OpcUa is { } o:
                return new OpcUaConnector(
                    e.Name,
                    new OpcUaConnectorOptions(o.EndpointUrl, o.Nodes.Select(n => new OpcUaTagSpec(n.Source, n.NamespaceUri, n.Identifier, n.SamplingMs, n.AbsoluteDeadband)).ToList(), o.UseSecurity, o.PublishingIntervalMs),
                    new PkiOpcUaConfigProvider("FatturaFacile.EdgeGateway", Path.Combine(AppContext.BaseDirectory, "pki")),
                    loggers.CreateLogger<OpcUaConnector>());

            case "mqtt" when e.Mqtt is { } q:
                return new MqttConnector(
                    e.Name,
                    new MqttConnectorOptions(
                        q.Brokers.Select(b => b.Split(':') is [var h, var p] ? new MqttBroker(h, int.Parse(p, System.Globalization.CultureInfo.InvariantCulture)) : new MqttBroker(b)).ToList(),
                        q.ClientId,
                        q.Subscriptions.Select(s => new MqttSubscriptionSpec(s.Topic, Enum.Parse<MqttPayloadFormat>(s.Format, true), s.Qos)).ToList(),
                        q.UseTls,
                        q.Username,
                        q.Password,
                        q.WillTopic),
                    new NullDeadLetterSink(),
                    loggers.CreateLogger<MqttConnector>());

            default:
                throw new InvalidOperationException($"Connector '{e.Name}': unsupported protocol '{e.Protocol}' or missing settings.");
        }
    }
}
