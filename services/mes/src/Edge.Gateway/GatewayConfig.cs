using System.Text.Json;

namespace Edge.Gateway;

public sealed class GatewayConfig
{
    public const string Section = "Gateway";

    public string GatewayId { get; set; } = Environment.MachineName;

    public Guid TenantId { get; set; }

    public string MesBaseUrl { get; set; } = "https://mes.example.invalid";

    /// <summary>PFX client certificate for mTLS towards Mes.Core. Null = server-auth TLS only (dev).</summary>
    public string? ClientCertPath { get; set; }

    public string? ClientCertPassword { get; set; }

    public string WalPath { get; set; } = "data/edge-wal.db";

    public long WalMaxBytes { get; set; } = 2L * 1024 * 1024 * 1024;

    public int BatchSize { get; set; } = 500;

    public int BatchWindowMs { get; set; } = 1000;

    public List<ConnectorEntry> Connectors { get; set; } = [];

    /// <summary>Which backend the WAL is forwarded to. <c>MesCore</c> (default) = ingest batches; <c>Laravel</c> = signed state events.</summary>
    public string Publisher { get; set; } = "MesCore";

    /// <summary>Settings of the signed Laravel telemetry endpoint (<c>Publisher = Laravel</c>).</summary>
    public LaravelEdgeEntry Laravel { get; set; } = new();
}

/// <summary>
/// <c>POST /api/v1/manufacturing/edge/telemetry</c>. The secret is shown once by <c>POST .../edge/gateways</c>; supply it
/// through the environment (<c>Gateway__Laravel__Secret</c>) or a secret store, never in a committed file. It is never logged.
/// </summary>
public sealed class LaravelEdgeEntry
{
    /// <summary>Base URL of the Laravel API, e.g. <c>https://erp.example.com</c>.</summary>
    public string BaseUrl { get; set; } = "";

    /// <summary>UUID of the registered gateway (<c>X-Gateway-Id</c>).</summary>
    public string GatewayUuid { get; set; } = "";

    /// <summary>HMAC-SHA256 secret of that gateway.</summary>
    public string Secret { get; set; } = "";

    /// <summary>Events per request; the backend accepts 1..500.</summary>
    public int MaxEventsPerRequest { get; set; } = 500;
}

/// <summary>Maps one raw state value (number or text) of a machine to one of the five backend states.</summary>
public sealed class StateMapEntry
{
    /// <summary>Numeric (<c>"3"</c>) or text (<c>"Running"</c>) value as emitted by the tag; text is compared case-insensitively.</summary>
    public string Match { get; set; } = "";

    /// <summary>RUNNING, SETUP, IDLE, FAULT or MAINTENANCE.</summary>
    public string State { get; set; } = "";
}

public sealed class TagEntry
{
    public string Source { get; set; } = "";

    public string Tag { get; set; } = "";

    public double Scale { get; set; } = 1.0;

    public double Offset { get; set; }

    public double? Deadband { get; set; }
}

public sealed class ConnectorEntry
{
    public string Name { get; set; } = "";

    public Guid MachineId { get; set; }

    /// <summary>Monotonic version of the tag map; stamped on every sample. Bump it to hot-reload tags without reconnecting.</summary>
    public int MapVersion { get; set; } = 1;

    public string Protocol { get; set; } = "";

    /// <summary>Id of the machine centre in the backend (<c>mfg_machine_centers.id</c>); defaults to <see cref="MachineId"/>.</summary>
    public Guid? BackendMachineId { get; set; }

    /// <summary>Tag (mapped id) whose value carries the machine state. Null = this connector emits no state events.</summary>
    public string? StateTag { get; set; }

    /// <summary>Raw value → state table. Text values that are not listed fall back to well-known names (run, setup, idle, alarm, maintenance…).</summary>
    public List<StateMapEntry> StateMap { get; set; } = [];

    public List<TagEntry> Tags { get; set; } = [];

    public ModbusEntry? Modbus { get; set; }

    public OpcUaEntry? OpcUa { get; set; }

    public MqttEntry? Mqtt { get; set; }

    /// <summary>Everything that requires a reconnect when it changes (i.e. NOT the tag map).</summary>
    public string ConnectionFingerprint() => JsonSerializer.Serialize(new { Protocol, Modbus, OpcUa, Mqtt });
}

public sealed class ModbusEntry
{
    public string Host { get; set; } = "";

    public int Port { get; set; } = 502;

    public byte UnitId { get; set; } = 1;

    public int PollMs { get; set; } = 1000;

    public List<ModbusRegisterEntry> Registers { get; set; } = [];
}

public sealed class ModbusRegisterEntry
{
    public string Source { get; set; } = "";

    public ushort Register { get; set; }

    public string Type { get; set; } = "UInt16";

    public string Order { get; set; } = "ABCD";
}

public sealed class OpcUaEntry
{
    public string EndpointUrl { get; set; } = "";

    public bool UseSecurity { get; set; } = true;

    public double PublishingIntervalMs { get; set; } = 1000;

    public List<OpcUaNodeEntry> Nodes { get; set; } = [];
}

public sealed class OpcUaNodeEntry
{
    public string Source { get; set; } = "";

    public string NamespaceUri { get; set; } = "";

    public string Identifier { get; set; } = "";

    public double SamplingMs { get; set; } = 1000;

    public double? AbsoluteDeadband { get; set; }
}

public sealed class MqttEntry
{
    public List<string> Brokers { get; set; } = [];

    public string ClientId { get; set; } = "";

    public bool UseTls { get; set; } = true;

    public string? Username { get; set; }

    public string? Password { get; set; }

    public string? WillTopic { get; set; }

    public List<MqttSubscriptionEntry> Subscriptions { get; set; } = [];
}

public sealed class MqttSubscriptionEntry
{
    public string Topic { get; set; } = "";

    public string Format { get; set; } = "Json";

    public int Qos { get; set; } = 1;
}
