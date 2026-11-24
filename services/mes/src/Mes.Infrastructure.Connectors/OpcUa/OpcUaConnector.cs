using Mes.Application.Abstractions;
using Mes.Domain.Telemetry;
using Microsoft.Extensions.Logging;
using Opc.Ua;
using Opc.Ua.Client;

namespace Mes.Infrastructure.Connectors.OpcUa;

/// <summary>
/// One OPC UA node to monitor. Nodes are addressed by <b>namespace URI + identifier</b>, never by raw namespace index:
/// indexes are server-local, can change after a server restart/upgrade, and are re-resolved on every (re)connect.
/// </summary>
public sealed record OpcUaTagSpec(string SourceAddress, string NamespaceUri, string Identifier, double SamplingIntervalMs = 1000, double? AbsoluteDeadband = null);

public sealed record OpcUaConnectorOptions(
    string EndpointUrl,
    IReadOnlyList<OpcUaTagSpec> Tags,
    bool UseSecurity = true,
    double PublishingIntervalMs = 1000,
    uint KeepAliveCount = 10,
    uint LifetimeCount = 30,
    uint QueueSize = 10,
    TimeSpan? SessionTimeout = null,
    TimeSpan? StaleAfter = null,
    TimeSpan? KeepAliveInterval = null,
    TimeSpan? ReconnectTimeout = null,
    TimeSpan? WatchdogInterval = null,
    int ChannelCapacity = 50_000);

/// <summary>
/// Supplies the OPC UA client application configuration (certificates, trust lists). Kept behind an interface so the
/// connector stays testable and certificate policy lives in the host.
/// </summary>
public interface IOpcUaApplicationConfigurationProvider
{
    Task<ApplicationConfiguration> GetAsync(CancellationToken ct);
}

/// <summary>
/// OPC UA connector: monitored-item subscription with server-side deadband, keep-alive supervision, session
/// reconnect with a post-reconnect subscription health check, and URI-based node resolution.
/// <para>Threading: <c>MonitoredItem.Notification</c> fires on an SDK thread. The handler only reads the queued values
/// and calls <see cref="ConnectorBase.TryEmit"/> (<c>Channel.TryWrite</c>) — it never awaits or blocks.</para>
/// <para>SDK caveat: written against OPCFoundation.NetStandard.Opc.Ua 1.5.378.x (the last line that targets net9.0 without
/// dragging in .NET 10 dependencies). The 2.x line renames several of these types; pin the package.</para>
/// </summary>
public sealed class OpcUaConnector : ConnectorBase
{
    private readonly OpcUaConnectorOptions _options;
    private readonly IOpcUaApplicationConfigurationProvider _config;
    private static ITelemetryContext Telemetry => OpcUaCertificateSetup.Telemetry;
    private ISession? _session;
    private Subscription? _subscription;
    private SessionReconnectHandler? _reconnect;
    private TaskCompletionSource<Exception?> _lost = NewLost();
    private long _lastNotificationTicks;

    public OpcUaConnector(string name, OpcUaConnectorOptions options, IOpcUaApplicationConfigurationProvider config, ILogger<OpcUaConnector> logger, TimeProvider? time = null, BackoffOptions? backoff = null)
        : base(name, options.ChannelCapacity, logger, time, backoff)
    {
        _options = options;
        _config = config;
    }

    private static TaskCompletionSource<Exception?> NewLost() => new(TaskCreationOptions.RunContinuationsAsynchronously);

    protected override async Task ConnectAndRunAsync(CancellationToken ct)
    {
        _lost = NewLost();
        var cfg = await _config.GetAsync(ct).ConfigureAwait(false);
        var selected = await CoreClientUtils.SelectEndpointAsync(cfg, _options.EndpointUrl, _options.UseSecurity, Telemetry, ct).ConfigureAwait(false);
        var endpoint = new ConfiguredEndpoint(null, selected, EndpointConfiguration.Create(cfg));

        var factory = new DefaultSessionFactory(Telemetry);
        var session = await factory.CreateAsync(
            cfg,
            endpoint,
            false,
            Name,
            (uint)(_options.SessionTimeout ?? TimeSpan.FromMinutes(1)).TotalMilliseconds,
            new UserIdentity(new AnonymousIdentityToken()),
            null,
            ct).ConfigureAwait(false);

        _session = session;
        session.KeepAliveInterval = (int)(_options.KeepAliveInterval ?? TimeSpan.FromSeconds(5)).TotalMilliseconds;
        session.KeepAlive += OnKeepAlive;
        try
        {
            await CreateSubscriptionAsync(session, ct).ConfigureAwait(false);
            Interlocked.Exchange(ref _lastNotificationTicks, Time.GetUtcNow().UtcTicks);
            MarkConnected();

            // Supervise: wait for connection loss, or for silence beyond StaleAfter (a "connected but dead" subscription).
            var stale = _options.StaleAfter ?? TimeSpan.FromSeconds(Math.Max(30, _options.PublishingIntervalMs * _options.KeepAliveCount * 3 / 1000));
            using var watchdog = new PeriodicTimer(_options.WatchdogInterval ?? TimeSpan.FromSeconds(5), Time);
            var lostTask = _lost.Task;
            while (true)
            {
                var tick = watchdog.WaitForNextTickAsync(ct).AsTask();
                var done = await Task.WhenAny(lostTask, tick).ConfigureAwait(false);
                if (done == lostTask)
                {
                    throw (await lostTask.ConfigureAwait(false)) ?? new InvalidOperationException("OPC UA session lost.");
                }

                if (!await tick.ConfigureAwait(false))
                {
                    return;
                }

                var silence = Time.GetUtcNow() - new DateTimeOffset(Interlocked.Read(ref _lastNotificationTicks), TimeSpan.Zero);
                if (_reconnectInProgress)
                {
                    // The SDK handler retries forever; bound it so a server that never comes back (or lost our session)
                    // falls through to a clean full reconnect with backoff instead of hanging here.
                    var reconnecting = Time.GetUtcNow() - new DateTimeOffset(Interlocked.Read(ref _reconnectStartedTicks), TimeSpan.Zero);
                    if (reconnecting > (_options.ReconnectTimeout ?? TimeSpan.FromSeconds(60)))
                    {
                        throw new TimeoutException($"OPC UA session reconnect did not complete within {reconnecting.TotalSeconds:F0}s.");
                    }
                }
                else if (silence > stale)
                {
                    throw new TimeoutException($"No OPC UA data or keep-alive progress for {silence.TotalSeconds:F0}s.");
                }
            }
        }
        finally
        {
            session.KeepAlive -= OnKeepAlive;
            _reconnect?.Dispose();
            _reconnect = null;
            _subscription = null;
            _session = null;
            try
            {
                await session.CloseAsync(5_000, true, CancellationToken.None).ConfigureAwait(false);
            }
            catch (Exception ex)
            {
                Logger.LogDebug(ex, "OPC UA close failed (connection already gone)");
            }

            session.Dispose();
        }
    }

    private volatile bool _reconnectInProgress;
    private long _reconnectStartedTicks;

    /// <summary>Resolves node ids from namespace URIs against the CURRENT namespace table, then creates the subscription.</summary>
    private async Task CreateSubscriptionAsync(ISession session, CancellationToken ct)
    {
        var subscription = new Subscription(session.DefaultSubscription)
        {
            PublishingInterval = (int)_options.PublishingIntervalMs,
            KeepAliveCount = _options.KeepAliveCount,
            LifetimeCount = _options.LifetimeCount,
            PublishingEnabled = true,
            DisplayName = Name,
        };

        foreach (var tag in _options.Tags)
        {
            var index = session.NamespaceUris.GetIndex(tag.NamespaceUri);
            if (index < 0)
            {
                Logger.LogError("Namespace URI {Uri} not exposed by server; tag {Tag} skipped", tag.NamespaceUri, tag.SourceAddress);
                continue;
            }

            var item = new MonitoredItem(subscription.DefaultItem)
            {
                StartNodeId = new NodeId(tag.Identifier, (ushort)index),
                AttributeId = Attributes.Value,
                DisplayName = tag.SourceAddress,
                SamplingInterval = (int)tag.SamplingIntervalMs,
                QueueSize = _options.QueueSize,
                DiscardOldest = true,
                MonitoringMode = MonitoringMode.Reporting,
            };

            if (tag.AbsoluteDeadband is { } db)
            {
                item.Filter = new DataChangeFilter
                {
                    Trigger = DataChangeTrigger.StatusValue,
                    DeadbandType = (uint)DeadbandType.Absolute,
                    DeadbandValue = db,
                };
            }

            item.Notification += OnItemNotification; // SDK thread: non-blocking handler only
            subscription.AddItem(item);
        }

        session.AddSubscription(subscription);
        await subscription.CreateAsync(ct).ConfigureAwait(false);
        _subscription = subscription;
    }

    private void OnItemNotification(MonitoredItem item, MonitoredItemNotificationEventArgs e)
    {
        Interlocked.Exchange(ref _lastNotificationTicks, Time.GetUtcNow().UtcTicks);
        var rx = Time.GetUtcNow();
        var address = item.DisplayName; // set to SourceAddress when the item was built
        foreach (var dv in item.DequeueValues())
        {
            var good = StatusCode.IsGood(dv.StatusCode);
            var quality = good ? SampleQuality.Good : StatusCode.IsUncertain(dv.StatusCode) ? SampleQuality.Uncertain : SampleQuality.Bad;
            var src = dv.SourceTimestamp == DateTime.MinValue ? (DateTimeOffset?)null : new DateTimeOffset(DateTime.SpecifyKind(dv.SourceTimestamp, DateTimeKind.Utc));

            double? num = null;
            string? text = null;
            switch (dv.Value)
            {
                case bool b:
                    num = b ? 1 : 0;
                    break;
                case string s:
                    text = s;
                    break;
                case null:
                    break;
                default:
                    try
                    {
                        num = Convert.ToDouble(dv.Value, System.Globalization.CultureInfo.InvariantCulture);
                    }
                    catch (Exception ex) when (ex is InvalidCastException or FormatException or OverflowException)
                    {
                        text = dv.Value.ToString();
                    }

                    break;
            }

            TryEmit(new RawSample(address, num, text, src, rx, quality));
        }
    }

    private void OnKeepAlive(ISession session, KeepAliveEventArgs e)
    {
        if (!ServiceResult.IsBad(e.Status))
        {
            Interlocked.Exchange(ref _lastNotificationTicks, Time.GetUtcNow().UtcTicks);
            return;
        }

        if (_reconnect is not null)
        {
            return; // already reconnecting
        }

        Logger.LogWarning("OPC UA keep-alive bad ({Status}); starting session reconnect", e.Status);
        _reconnectInProgress = true;
        Interlocked.Exchange(ref _reconnectStartedTicks, Time.GetUtcNow().UtcTicks);
        _reconnect = new SessionReconnectHandler(Telemetry, true, 30_000);
        _reconnect.BeginReconnect(session, 5_000, OnReconnectComplete);
        e.CancelKeepAlive = true;
    }

    private void OnReconnectComplete(object? sender, EventArgs e)
    {
        if (_reconnect is null)
        {
            return;
        }

        var handler = _reconnect;
        var newSession = handler.Session;
        _reconnect = null;
        _reconnectInProgress = false;
        handler.Dispose();

        if (newSession is null)
        {
            _lost.TrySetResult(new InvalidOperationException("OPC UA reconnect failed."));
            return;
        }

        _session = newSession;
        // The SDK may have transferred the subscription, or the server may have lost it (restart / lifetime expired).
        // Verify instead of assuming, otherwise we would sit "connected" and receive nothing forever.
        _ = Task.Run(async () =>
        {
            try
            {
                var healthy = newSession.Subscriptions.Any(s => s.Created && s.Id != 0) && _subscription is { Created: true };
                if (!healthy)
                {
                    Logger.LogWarning("OPC UA subscription missing after reconnect; recreating");
                    foreach (var s in newSession.Subscriptions.ToList())
                    {
                        await newSession.RemoveSubscriptionAsync(s, CancellationToken.None).ConfigureAwait(false);
                    }

                    await CreateSubscriptionAsync(newSession, CancellationToken.None).ConfigureAwait(false);
                }
            }
            catch (Exception ex)
            {
                _lost.TrySetResult(ex);
            }
        });
    }

    public override async ValueTask DisposeAsync()
    {
        _reconnect?.Dispose();
        await base.DisposeAsync().ConfigureAwait(false);
    }
}
