using System.Text.Json;
using Mes.Application.Abstractions;
using Mes.Domain.Telemetry;
using Microsoft.Extensions.Logging;
using MQTTnet;
using MQTTnet.Protocol;

namespace Mes.Infrastructure.Connectors.Mqtt;

public enum MqttPayloadFormat
{
    Json,
    SparkplugB,
}

public sealed record MqttSubscriptionSpec(string TopicFilter, MqttPayloadFormat Format, int Qos = 1);

public sealed record MqttBroker(string Host, int Port = 8883);

public sealed record MqttConnectorOptions(
    IReadOnlyList<MqttBroker> Brokers,
    string ClientId,
    IReadOnlyList<MqttSubscriptionSpec> Subscriptions,
    bool UseTls = true,
    string? Username = null,
    string? Password = null,
    string? WillTopic = null,
    TimeSpan? KeepAlive = null,
    int ChannelCapacity = 50_000);

/// <summary>Where unparseable / schema-invalid messages go instead of being silently lost.</summary>
public interface IDeadLetterSink
{
    void Write(string source, string topic, ReadOnlyMemory<byte> payload, string reason);
}

public sealed class NullDeadLetterSink : IDeadLetterSink
{
    public void Write(string source, string topic, ReadOnlyMemory<byte> payload, string reason)
    {
    }
}

/// <summary>Pure JSON-topic parser (schema validation) so it can be tested without a broker.</summary>
public static class MqttJsonPayload
{
    /// <summary>
    /// Accepted shapes: <c>{"value": 12.3}</c>, <c>{"tag":"x","value":true,"ts":1700000000000}</c> or
    /// <c>{"tag":"x","value":"RUN","ts":"2026-01-01T00:00:00Z"}</c>. <c>tag</c> defaults to the topic.
    /// <c>ts</c> is epoch milliseconds or ISO-8601 (UTC assumed when no offset is present).
    /// </summary>
    public static bool TryParse(string topic, ReadOnlySpan<byte> payload, DateTimeOffset rx, out RawSample? sample, out string? error)
    {
        sample = null;
        error = null;
        try
        {
            using var doc = JsonDocument.Parse(payload.ToArray());
            var root = doc.RootElement;
            if (root.ValueKind != JsonValueKind.Object)
            {
                error = "payload is not a JSON object";
                return false;
            }

            if (!root.TryGetProperty("value", out var v))
            {
                error = "missing 'value'";
                return false;
            }

            var tag = topic;
            if (root.TryGetProperty("tag", out var t))
            {
                if (t.ValueKind != JsonValueKind.String || string.IsNullOrWhiteSpace(t.GetString()))
                {
                    error = "'tag' must be a non-empty string";
                    return false;
                }

                tag = t.GetString()!;
            }

            DateTimeOffset? src = null;
            if (root.TryGetProperty("ts", out var ts))
            {
                if (ts.ValueKind == JsonValueKind.Number && ts.TryGetInt64(out var ms))
                {
                    src = DateTimeOffset.FromUnixTimeMilliseconds(ms);
                }
                else if (ts.ValueKind == JsonValueKind.String
                         && DateTimeOffset.TryParse(ts.GetString(), System.Globalization.CultureInfo.InvariantCulture, System.Globalization.DateTimeStyles.AssumeUniversal, out var parsed))
                {
                    src = parsed;
                }
                else
                {
                    error = "'ts' must be epoch ms or ISO-8601";
                    return false;
                }
            }

            switch (v.ValueKind)
            {
                case JsonValueKind.Number when v.TryGetDouble(out var d) && double.IsFinite(d):
                    sample = new RawSample(tag, d, null, src, rx, SampleQuality.Good);
                    return true;
                case JsonValueKind.True:
                case JsonValueKind.False:
                    sample = new RawSample(tag, v.GetBoolean() ? 1 : 0, null, src, rx, SampleQuality.Good);
                    return true;
                case JsonValueKind.String:
                    sample = new RawSample(tag, null, v.GetString(), src, rx, SampleQuality.Good);
                    return true;
                default:
                    error = $"unsupported 'value' kind {v.ValueKind}";
                    return false;
            }
        }
        catch (JsonException ex)
        {
            error = "invalid JSON: " + ex.Message;
            return false;
        }
    }
}

/// <summary>
/// MQTT connector (JSON topics and Sparkplug B) over MQTTnet 5.x.
/// <list type="bullet">
/// <item>QoS 0/1 per subscription; persistent session (<c>CleanSession=false</c>) so QoS1 messages queued broker-side survive a reconnect.</item>
/// <item>LWT: a retained "offline" will on <see cref="MqttConnectorOptions.WillTopic"/>, replaced by "online" after connect.</item>
/// <item>Failover: brokers are tried round-robin, one per (re)connect attempt, behind the base class backoff.</item>
/// <item>Invalid messages are dead-lettered, never dropped silently and never allowed to kill the receive loop.</item>
/// </list>
/// Threading: <c>ApplicationMessageReceivedAsync</c> runs on the client's receive loop; the handler is synchronous and
/// uses <c>TryWrite</c>, so a slow consumer can never stall MQTT keep-alives.
/// </summary>
public sealed class MqttConnector : ConnectorBase
{
    private readonly MqttConnectorOptions _options;
    private readonly IDeadLetterSink _dlq;
    private readonly SparkplugAliasMap _aliases = new();
    private int _brokerIndex;

    public MqttConnector(string name, MqttConnectorOptions options, IDeadLetterSink dlq, ILogger<MqttConnector> logger, TimeProvider? time = null, BackoffOptions? backoff = null)
        : base(name, options.ChannelCapacity, logger, time, backoff)
    {
        if (options.Brokers.Count == 0)
        {
            throw new ArgumentException("At least one broker is required.", nameof(options));
        }

        _options = options;
        _dlq = dlq;
    }

    protected override async Task ConnectAndRunAsync(CancellationToken ct)
    {
        var broker = _options.Brokers[_brokerIndex++ % _options.Brokers.Count];
        var lost = new TaskCompletionSource(TaskCreationOptions.RunContinuationsAsynchronously);

        using var client = new MqttClientFactory().CreateMqttClient();
        client.ApplicationMessageReceivedAsync += e =>
        {
            Handle(e.ApplicationMessage.Topic, System.Buffers.BuffersExtensions.ToArray(e.ApplicationMessage.Payload));
            return Task.CompletedTask;
        };
        client.DisconnectedAsync += e =>
        {
            lost.TrySetResult();
            return Task.CompletedTask;
        };

        var builder = new MqttClientOptionsBuilder()
            .WithTcpServer(broker.Host, broker.Port)
            .WithClientId(_options.ClientId)
            .WithCleanSession(false)
            .WithKeepAlivePeriod(_options.KeepAlive ?? TimeSpan.FromSeconds(30));
        if (_options.Username is not null)
        {
            builder = builder.WithCredentials(_options.Username, _options.Password);
        }

        if (_options.UseTls)
        {
            builder = builder.WithTlsOptions(o => o.UseTls());
        }

        if (_options.WillTopic is not null)
        {
            builder = builder.WithWillTopic(_options.WillTopic).WithWillPayload("offline"u8.ToArray())
                .WithWillQualityOfServiceLevel(MqttQualityOfServiceLevel.AtLeastOnce).WithWillRetain();
        }

        await client.ConnectAsync(builder.Build(), ct).ConfigureAwait(false);

        var sub = new MqttClientSubscribeOptionsBuilder();
        foreach (var s in _options.Subscriptions)
        {
            sub = sub.WithTopicFilter(f => f.WithTopic(s.TopicFilter).WithQualityOfServiceLevel((MqttQualityOfServiceLevel)Math.Clamp(s.Qos, 0, 1)));
        }

        await client.SubscribeAsync(sub.Build(), ct).ConfigureAwait(false);

        if (_options.WillTopic is not null)
        {
            await client.PublishStringAsync(_options.WillTopic, "online", MqttQualityOfServiceLevel.AtLeastOnce, retain: true, cancellationToken: ct).ConfigureAwait(false);
        }

        MarkConnected();
        using var reg = ct.Register(() => lost.TrySetCanceled(ct));
        await lost.Task.ConfigureAwait(false); // returns normally on broker disconnect => base class reconnects (next broker)
    }

    internal void Handle(string topic, byte[] payload)
    {
        var rx = Time.GetUtcNow();
        try
        {
            if (topic.StartsWith("spBv1.0/", StringComparison.Ordinal))
            {
                HandleSparkplug(topic, payload, rx);
                return;
            }

            if (MqttJsonPayload.TryParse(topic, payload, rx, out var sample, out var error) && sample is not null)
            {
                TryEmit(sample);
            }
            else
            {
                _dlq.Write(Name, topic, payload, error ?? "invalid");
            }
        }
        catch (Exception ex)
        {
            _dlq.Write(Name, topic, payload, "handler error: " + ex.Message); // never let one bad message break the receive loop
        }
    }

    private void HandleSparkplug(string topic, byte[] payload, DateTimeOffset rx)
    {
        // spBv1.0/{group}/{type}/{edge}[/{device}]
        var parts = topic.Split('/');
        if (parts.Length < 4)
        {
            _dlq.Write(Name, topic, payload, "malformed Sparkplug topic");
            return;
        }

        var type = parts[2];
        var edge = parts[3];
        var device = parts.Length > 4 ? parts[4] : string.Empty;

        if (type is "NDEATH" or "DDEATH")
        {
            _aliases.Forget(edge, device);
            return;
        }

        SparkplugPayload decoded;
        try
        {
            decoded = SparkplugDecoder.Decode(payload);
        }
        catch (Exception ex) when (ex is Google.Protobuf.InvalidProtocolBufferException or InvalidOperationException)
        {
            _dlq.Write(Name, topic, payload, "invalid Sparkplug protobuf: " + ex.Message);
            return;
        }

        if (type is "NBIRTH" or "DBIRTH")
        {
            _aliases.LearnBirth(edge, device, decoded);
        }

        foreach (var m in decoded.Metrics)
        {
            if (m.IsNull)
            {
                continue;
            }

            var name = _aliases.Resolve(edge, device, m);
            if (name is null)
            {
                _dlq.Write(Name, topic, payload, $"metric alias {m.Alias} unknown (missed BIRTH?)");
                continue;
            }

            var ts = m.TimestampMs ?? decoded.TimestampMs;
            DateTimeOffset? src = ts is { } t ? DateTimeOffset.FromUnixTimeMilliseconds((long)t) : null;
            var address = device.Length > 0 ? $"{edge}/{device}/{name}" : $"{edge}/{name}";
            TryEmit(new RawSample(address, m.Numeric, m.Text, src, rx, SampleQuality.Good));
        }
    }
}
