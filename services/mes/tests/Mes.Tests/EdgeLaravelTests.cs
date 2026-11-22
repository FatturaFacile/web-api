using System.Net;
using System.Text;
using System.Text.Json;
using Edge.Gateway;
using Edge.Gateway.Publishing;
using Edge.Gateway.Wal;
using FluentAssertions;
using Mes.Application.Contracts;
using Microsoft.Data.Sqlite;
using Microsoft.Extensions.Logging.Abstractions;
using Microsoft.Extensions.Options;
using Xunit;

namespace Mes.Tests;

public sealed class StaticMonitor(GatewayConfig value) : IOptionsMonitor<GatewayConfig>
{
    public GatewayConfig CurrentValue => value;

    public GatewayConfig Get(string? name) => value;

    public IDisposable? OnChange(Action<GatewayConfig, string?> listener) => null;
}

public sealed class EdgeStubHandler(Func<HttpRequestMessage, byte[], HttpResponseMessage> respond) : HttpMessageHandler
{
    public List<(string GatewayId, string Signature, byte[] Body)> Calls { get; } = [];

    protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
    {
        var body = await request.Content!.ReadAsByteArrayAsync(cancellationToken);
        Calls.Add((request.Headers.GetValues("X-Gateway-Id").Single(), request.Headers.GetValues("X-Signature").Single(), body));
        return respond(request, body);
    }
}

public class EdgeSignatureTests
{
    [Fact]
    public void Fixed_body_and_secret_give_the_expected_lowercase_hex()
    {
        // Expected value computed independently with PHP: hash_hmac('sha256', $body, 's3cr3t-fixture').
        var ev = new EdgeEvent(Guid.Parse("11111111-1111-4111-8111-111111111111"), Guid.Parse("22222222-2222-4222-8222-222222222222"), "RUNNING", "2026-01-01T08:00:00.000Z", null);
        var body = LaravelTelemetryPublisher.Serialize([ev]);

        Encoding.UTF8.GetString(body).Should().Be("""{"events":[{"event_uuid":"11111111-1111-4111-8111-111111111111","machine_id":"22222222-2222-4222-8222-222222222222","state":"RUNNING","occurred_at":"2026-01-01T08:00:00.000Z","payload":null}]}""");
        EdgeSignature.Sign("s3cr3t-fixture", body).Should().Be("81365527f4798dcc08e874e1ff1a8511daa8d2588ccee02e165ca4030226076c");
    }
}

public class EdgeStateMappingTests
{
    private static ConnectorEntry Entry(params (string Match, string State)[] map) => new()
    {
        Name = "c", MachineId = Guid.NewGuid(), StateTag = "state",
        StateMap = map.Select(m => new StateMapEntry { Match = m.Match, State = m.State }).ToList(),
    };

    private static WireSample Num(ConnectorEntry e, double v) => new(e.MachineId, "state", 1, 1, v, null, 0, 1);

    private static WireSample Txt(ConnectorEntry e, string s) => new(e.MachineId, "state", 1, 1, null, s, 0, 1);

    [Fact]
    public void Opc_ua_enum_and_modbus_register_values_use_the_configured_table()
    {
        var opc = Entry(("1", "running"), ("2", "SETUP"), ("3", "IDLE"), ("4", "FAULT"), ("5", "MAINTENANCE"));
        StateMapper.Map(opc, Num(opc, 1)).Should().Be("RUNNING");
        StateMapper.Map(opc, Num(opc, 4)).Should().Be("FAULT");
        StateMapper.Map(opc, Num(opc, 5)).Should().Be("MAINTENANCE");

        var modbus = Entry(("0", "IDLE"), ("255", "RUNNING")); // coil/status word style values
        StateMapper.Map(modbus, Num(modbus, 255)).Should().Be("RUNNING");
        StateMapper.Map(modbus, Num(modbus, 0)).Should().Be("IDLE");
    }

    [Fact]
    public void Mqtt_text_and_cnc_program_status_map_by_table_then_by_known_names()
    {
        var mqtt = Entry();
        StateMapper.Map(mqtt, Txt(mqtt, "Running")).Should().Be("RUNNING");
        StateMapper.Map(mqtt, Txt(mqtt, " ALARM ")).Should().Be("FAULT");
        StateMapper.Map(mqtt, Txt(mqtt, "standby")).Should().Be("IDLE");

        var cnc = Entry(("CYCLE_ACTIVE", "RUNNING"), ("FEED_HOLD", "IDLE"), ("TOOL_CHANGE", "SETUP"));
        StateMapper.Map(cnc, Txt(cnc, "feed_hold")).Should().Be("IDLE");
        StateMapper.Map(cnc, Txt(cnc, "TOOL_CHANGE")).Should().Be("SETUP");
    }

    [Fact]
    public void Unknown_values_and_invalid_targets_are_not_guessed()
    {
        var e = Entry(("9", "NOT_A_STATE"));
        StateMapper.Map(e, Num(e, 9)).Should().BeNull();
        StateMapper.Map(e, Num(e, 42)).Should().BeNull();
        StateMapper.Map(e, Txt(e, "gibberish")).Should().BeNull();
    }

    [Fact]
    public void Event_id_is_stable_and_depends_on_every_input()
    {
        var m = Guid.NewGuid();
        var a = EdgeEventId.For("GW", m, "state", 1000, "RUNNING");
        EdgeEventId.For("gw", m, "state", 1000, "RUNNING").Should().Be(a); // gateway id is case-insensitive (uuid)
        EdgeEventId.For("gw", m, "state", 1001, "RUNNING").Should().NotBe(a);
        EdgeEventId.For("gw", m, "state", 1000, "IDLE").Should().NotBe(a);
        a.ToString()[14].Should().Be('5');
    }
}

public sealed class EdgeForwarderTests : IDisposable
{
    private const string Secret = "unit-test-secret";
    private static readonly string GatewayUuid = "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa";
    private readonly string _dir = Path.Combine(Path.GetTempPath(), "mes-edge-laravel-" + Guid.NewGuid().ToString("N"));
    private readonly Guid _machine = Guid.Parse("bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb");
    private readonly GatewayConfig _cfg;
    private readonly WalStore _wal;

    public EdgeForwarderTests()
    {
        _cfg = new GatewayConfig
        {
            Laravel = new LaravelEdgeEntry { BaseUrl = "http://localhost", GatewayUuid = GatewayUuid, Secret = Secret },
            Connectors =
            [
                new ConnectorEntry
                {
                    Name = "plc", MachineId = _machine, StateTag = "state",
                    StateMap = [new StateMapEntry { Match = "1", State = "RUNNING" }, new StateMapEntry { Match = "2", State = "FAULT" }],
                },
            ],
        };
        _wal = new WalStore(new WalOptions(Path.Combine(_dir, "wal.db")));
    }

    private WireBatch Batch(string id, params (long Ms, double V)[] states) =>
        new(id, Guid.NewGuid(), "gw", states.Max(s => s.Ms), states.Select(s => new WireSample(_machine, "state", s.Ms, s.Ms, s.V, null, 0, 1)).ToList());

    private LaravelForwarder Forwarder(EdgeStubHandler h)
    {
        var mon = new StaticMonitor(_cfg);
        var http = new HttpClient(h) { BaseAddress = new Uri("http://localhost") };
        return new LaravelForwarder(_wal, new LaravelTelemetryPublisher(http, mon), mon, NullLogger<LaravelForwarder>.Instance);
    }

    private static HttpResponseMessage Json(HttpStatusCode code, string json) => new(code) { Content = new StringContent(json, Encoding.UTF8, "application/json") };

    private static HttpResponseMessage Ok(int accepted, int duplicates, params string[] rejected) =>
        Json(HttpStatusCode.OK, JsonSerializer.Serialize(new { success = true, data = new { accepted, duplicates, rejected } }));

    [Fact]
    public async Task Sends_gateway_id_and_a_signature_over_the_exact_bytes_it_sent()
    {
        _wal.Append(Batch("b1", (1_000, 1), (2_000, 2)));
        var h = new EdgeStubHandler((_, _) => Ok(2, 0));

        (await Forwarder(h).DrainOnceAsync(default)).Should().Be(DrainStatus.Progress);

        var call = h.Calls.Single();
        call.GatewayId.Should().Be(GatewayUuid);
        call.Signature.Should().Be(EdgeSignature.Sign(Secret, call.Body)).And.MatchRegex("^[0-9a-f]{64}$");
        using var doc = JsonDocument.Parse(call.Body);
        var events = doc.RootElement.GetProperty("events");
        events.GetArrayLength().Should().Be(2);
        events[0].GetProperty("state").GetString().Should().Be("RUNNING");
        events[0].GetProperty("machine_id").GetString().Should().Be(_machine.ToString());
        events[0].GetProperty("occurred_at").GetString().Should().Be("1970-01-01T00:00:01.000Z");
        events[1].GetProperty("state").GetString().Should().Be("FAULT");
        _wal.Pending.Should().Be(0);
    }

    [Fact]
    public async Task Resend_after_a_lost_response_carries_the_same_event_uuids_and_duplicates_are_acked()
    {
        _wal.Append(Batch("b1", (1_000, 1), (2_000, 2)));
        var first = new EdgeStubHandler((_, _) => throw new HttpRequestException("response lost"));
        (await Forwarder(first).DrainOnceAsync(default)).Should().Be(DrainStatus.Backoff);
        _wal.Pending.Should().Be(1);

        // A new process (fresh forwarder, no memory of the earlier attempt) resends; the server already has both events.
        var second = new EdgeStubHandler((_, _) => Ok(0, 2));
        (await Forwarder(second).DrainOnceAsync(default)).Should().Be(DrainStatus.Progress);

        static string[] Ids(byte[] body) => JsonDocument.Parse(body).RootElement.GetProperty("events").EnumerateArray().Select(e => e.GetProperty("event_uuid").GetString()!).ToArray();
        Ids(second.Calls.Single().Body).Should().HaveCount(2).And.OnlyHaveUniqueItems().And.Equal(Ids(first.Calls.Single().Body)); // same ids, byte-identical body
        second.Calls.Single().Body.Should().Equal(first.Calls.Single().Body);
        _wal.Pending.Should().Be(0);
        _wal.QuarantinedCount.Should().Be(0);
    }

    [Fact]
    public async Task Rejected_events_are_quarantined_once_and_never_retried()
    {
        _wal.Append(Batch("b1", (1_000, 1), (2_000, 2)));
        string? rejectedId = null;
        var h = new EdgeStubHandler((_, body) =>
        {
            rejectedId = JsonDocument.Parse(body).RootElement.GetProperty("events")[1].GetProperty("event_uuid").GetString();
            return Ok(1, 0, rejectedId!);
        });
        var f = Forwarder(h);

        (await f.DrainOnceAsync(default)).Should().Be(DrainStatus.Progress);
        (await f.DrainOnceAsync(default)).Should().Be(DrainStatus.Idle);

        h.Calls.Should().HaveCount(1);
        _wal.Pending.Should().Be(0);
        _wal.QuarantinedCount.Should().Be(1);
        _wal.QuarantineDetails().Single().Should().Contain(rejectedId!);
    }

    [Theory]
    [InlineData(HttpStatusCode.Unauthorized, DrainStatus.AuthBlocked, 1, 0)]
    [InlineData(HttpStatusCode.InternalServerError, DrainStatus.Backoff, 1, 0)]
    [InlineData(HttpStatusCode.ServiceUnavailable, DrainStatus.Backoff, 1, 0)]
    [InlineData(HttpStatusCode.UnprocessableEntity, DrainStatus.Progress, 0, 1)]
    public async Task Error_statuses_keep_or_quarantine_the_batch(HttpStatusCode code, DrainStatus expected, long pending, long quarantined)
    {
        _wal.Append(Batch("b1", (1_000, 1)));
        var h = new EdgeStubHandler((_, _) => Json(code, """{"message":"x"}"""));

        (await Forwarder(h).DrainOnceAsync(default)).Should().Be(expected);

        _wal.Pending.Should().Be(pending);
        _wal.QuarantinedCount.Should().Be(quarantined);
    }

    [Fact]
    public async Task Offline_buffer_then_flush_delivers_everything_in_order()
    {
        for (var i = 0; i < 3; i++)
        {
            _wal.Append(Batch($"b{i}", (i * 10_000L + 1_000, i % 2 == 0 ? 1 : 2)));
        }

        var online = false;
        var h = new EdgeStubHandler((_, _) => online ? Ok(1, 0) : throw new HttpRequestException("offline"));
        var f = Forwarder(h);

        for (var i = 0; i < 3; i++)
        {
            (await f.DrainOnceAsync(default)).Should().Be(DrainStatus.Backoff);
        }

        _wal.Pending.Should().Be(3); // nothing lost while offline

        online = true;
        (await f.DrainOnceAsync(default)).Should().Be(DrainStatus.Progress);
        _wal.Pending.Should().Be(0);
        h.Calls.Skip(3).Select(c => JsonDocument.Parse(c.Body).RootElement.GetProperty("events")[0].GetProperty("occurred_at").GetString())
            .Should().Equal("1970-01-01T00:00:01.000Z", "1970-01-01T00:00:11.000Z", "1970-01-01T00:00:21.000Z");
    }

    [Fact]
    public async Task Missing_credentials_never_reach_the_network()
    {
        _cfg.Laravel.Secret = "";
        _wal.Append(Batch("b1", (1_000, 1)));
        var h = new EdgeStubHandler((_, _) => Ok(1, 0));

        (await Forwarder(h).DrainOnceAsync(default)).Should().Be(DrainStatus.AuthBlocked);

        h.Calls.Should().BeEmpty();
        _wal.Pending.Should().Be(1);
    }

    public void Dispose()
    {
        _wal.Dispose();
        SqliteConnection.ClearAllPools();
        try
        {
            Directory.Delete(_dir, true);
        }
        catch (IOException)
        {
            // best effort
        }
    }
}
