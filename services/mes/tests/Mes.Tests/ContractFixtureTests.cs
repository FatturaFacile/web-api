using System.Net;
using System.Text;
using System.Text.Json;
using System.Text.Json.Nodes;
using FluentAssertions;
using Mes.Application.Abstractions;
using Mes.Domain.Common;
using Mes.Infrastructure.LaravelSync;
using Mes.Presentation;
using Microsoft.Extensions.Logging.Abstractions;
using Microsoft.Extensions.Options;
using Xunit;

namespace Mes.Tests;

/// <summary>
/// Cross-service contract with the Laravel module (<c>modules/30_Manufacturing</c>). The golden fixtures in
/// <c>contracts/mes-sync/fixtures</c> are the single source of truth and are also read by the Pest suite
/// (<c>MesContractFixturesTest</c>): a change of field casing, timestamp format, envelope or HMAC convention in either
/// service fails one of the two builds. Regenerate with <c>php contracts/mes-sync/generate-fixtures.php</c>.
/// </summary>
public class ContractFixtureTests
{
    private static readonly string Root = Path.Combine(AppContext.BaseDirectory, "contract-fixtures");

    private sealed class FixedTime(DateTimeOffset now) : TimeProvider
    {
        public override DateTimeOffset GetUtcNow() => now;
    }

    public sealed record Signed(string Description, string Method, string Path, Guid TenantId, string Kid, string Secret, long T, string Body, string Signature)
    {
        public byte[] Bytes => Encoding.UTF8.GetBytes(Body);

        public byte[] Key => Encoding.UTF8.GetBytes(Secret);

        public DateTimeOffset SignedAt => DateTimeOffset.FromUnixTimeSeconds(T);

        public override string ToString() => Description;
    }

    private static JsonNode Load(string relative) => JsonNode.Parse(File.ReadAllText(Path.Combine(Root, relative)))!;

    private static Signed LoadSigned(string relative)
    {
        var n = Load(relative);
        return new Signed(
            (string)n["description"]!,
            (string)n["method"]!,
            (string)n["path"]!,
            Guid.Parse((string)n["tenant_id"]!),
            (string)n["kid"]!,
            (string)n["secret"]!,
            (long)n["t"]!,
            (string)n["body"]!,
            (string)n["signature"]!);
    }

    public static IEnumerable<object[]> SignedFixtures() =>
        Directory.GetFiles(Path.Combine(Root, "mes-to-laravel"), "*.json")
            .Concat(Directory.GetFiles(Path.Combine(Root, "laravel-to-mes"), "ref-event.*.json"))
            .Select(f => new object[] { Path.GetRelativePath(Root, f).Replace('\\', '/') });

    [Fact]
    public void The_fixture_directory_is_not_empty_so_the_contract_cannot_pass_by_finding_nothing()
    {
        SignedFixtures().Count().Should().BeGreaterThanOrEqualTo(7);
        Directory.GetFiles(Path.Combine(Root, "laravel-to-mes"), "feed.*.json").Should().HaveCountGreaterThanOrEqualTo(3);
    }

    [Theory]
    [MemberData(nameof(SignedFixtures))]
    public void Fixture_signature_is_what_the_MES_Core_computes_and_accepts(string file)
    {
        var f = LoadSigned(file);
        byte[]? Keys(string kid) => kid == f.Kid ? f.Key : null;

        Hmac.Sign(f.Kid, f.Key, f.Bytes, f.SignedAt).Should().Be(f.Signature);
        Hmac.Verify(f.Signature, f.Bytes, Keys, f.SignedAt).Should().Be(SignatureFailure.None);
        Hmac.Verify(f.Signature, f.Bytes, Keys, f.SignedAt.AddSeconds(299)).Should().Be(SignatureFailure.None);
        Hmac.Verify(f.Signature, f.Bytes, Keys, f.SignedAt.AddSeconds(301)).Should().Be(SignatureFailure.Expired);
        Hmac.Verify(f.Signature, f.Bytes, Keys, f.SignedAt.AddSeconds(-301)).Should().Be(SignatureFailure.Expired);
        Hmac.Verify(f.Signature, Encoding.UTF8.GetBytes(f.Body + " "), Keys, f.SignedAt).Should().Be(SignatureFailure.Mismatch);
    }

    [Theory]
    [InlineData("laravel-to-mes/ref-event.work-order-released.json", RefResource.WorkOrders)]
    [InlineData("laravel-to-mes/ref-event.shift-calendar-updated.json", RefResource.ShiftCalendars)]
    public void Laravel_push_binds_to_the_RefEventDto_the_ref_events_endpoint_reads(string file, RefResource resource)
    {
        var f = LoadSigned(file);

        // Same options as Program.cs: web defaults. Without explicit names tenant_id would never bind (Guid.Empty => 400).
        var dto = JsonSerializer.Deserialize<RefEventDto>(f.Body, new JsonSerializerOptions(JsonSerializerDefaults.Web));

        dto.Should().NotBeNull();
        dto!.TenantId.Should().Be(f.TenantId);
        RefResourceExtensions.FromSlug(dto.Resource).Should().Be(resource);
        dto.Seq.Should().BeGreaterThan(0);
        dto.Revision.Should().Be(dto.Seq);
        dto.Deleted.Should().BeFalse();
        dto.Payload.Should().BeNull("Laravel pushes a nudge and the MES Core pulls the authoritative feed");
    }

    [Theory]
    [MemberData(nameof(OutboxFixtures))]
    public async Task Outbox_relay_produces_exactly_the_bytes_Laravel_verifies(string file)
    {
        var f = LoadSigned(file);
        var evt = JsonNode.Parse(f.Body)!["events"]![0]!;
        var tenant = new TenantId(f.TenantId);
        var message = new OutboxMessage(
            Guid.Parse((string)evt["id"]!),
            tenant,
            (string)evt["type"]!,
            (string)evt["dedupe_key"]!,
            evt["payload"]!.ToJsonString(),
            DateTimeOffset.Parse((string)evt["occurred_at"]!, System.Globalization.CultureInfo.InvariantCulture));

        byte[]? sentBody = null;
        string? sentSignature = null;
        string? sentPath = null;
        var inner = new StubHandler(async req =>
        {
            sentBody = await req.Content!.ReadAsByteArrayAsync();
            sentSignature = req.Headers.GetValues(Hmac.Header).Single();
            sentPath = req.RequestUri!.AbsolutePath;
            return Json.Ok(new { results = new[] { new { id = message.Id, status = "applied", message = (string?)null } } }, HttpStatusCode.MultiStatus);
        });
        var signing = new HmacSigningHandler(new LaravelSyncOptions { KeyId = f.Kid, Secret = f.Secret }, new FixedTime(f.SignedAt)) { InnerHandler = inner };
        var store = new FakeOutboxStore([new ClaimedOutbox(message, 1)]);
        var relay = new OutboxRelay(store, new HttpClient(signing) { BaseAddress = new Uri("https://erp.test") }, Options.Create(new OutboxRelayOptions()), NullLogger<OutboxRelay>.Instance);

        await relay.DeliverAsync(tenant, [new ClaimedOutbox(message, 1)], new OutboxRelayOptions(), default);

        sentPath.Should().Be(f.Path);
        Encoding.UTF8.GetString(sentBody!).Should().Be(f.Body, "the relay must serialise the envelope byte-for-byte like the fixture (field names, order, timestamp format, escaping)");
        sentSignature.Should().Be(f.Signature);
        store.Outcomes.Single().State.Should().Be("sent:applied");
    }

    public static IEnumerable<object[]> OutboxFixtures() =>
        Directory.GetFiles(Path.Combine(Root, "mes-to-laravel"), "*.json").Select(f => new object[] { Path.GetRelativePath(Root, f).Replace('\\', '/') });

    [Theory]
    [InlineData("laravel-to-mes/feed.machines.json", RefResource.Machines, 2)]
    [InlineData("laravel-to-mes/feed.work-orders.json", RefResource.WorkOrders, 1)]
    [InlineData("laravel-to-mes/feed.shift-calendars.json", RefResource.ShiftCalendars, 1)]
    public async Task Feed_page_of_Laravel_is_read_by_the_sync_client(string file, RefResource resource, int rows)
    {
        var fixture = Load(file);
        var tenant = new TenantId(Guid.Parse((string)fixture["tenant_id"]!));
        var store = new InMemoryRefStore();
        var handler = new StubHandler(req =>
        {
            req.Headers.GetValues("X-Tenant-Id").Single().Should().Be(tenant.ToString());
            return Task.FromResult(new HttpResponseMessage(HttpStatusCode.OK) { Content = new StringContent(fixture["response"]!.ToJsonString(), Encoding.UTF8, "application/json") });
        });
        var client = new LaravelReferenceSyncClient(
            new HttpClient(handler) { BaseAddress = new Uri("https://erp.test") },
            store,
            Options.Create(new LaravelSyncOptions()),
            NullLogger<LaravelReferenceSyncClient>.Instance);

        var applied = await client.PullAsync(tenant, resource, default);

        applied.Should().Be(rows);
        handler.Requests.Should().ContainSingle().Which.Should().StartWith("GET /api/v1/manufacturing/mes-sync/" + resource.Slug() + "?after_seq=0");
        store.Cursors[(tenant, resource)].Should().Be((long)fixture["response"]!["next_seq"]!);
        foreach (var item in fixture["response"]!["data"]!.AsArray())
        {
            var row = store.Rows[(tenant, resource, Guid.Parse((string)item!["id"]!))];
            row.Revision.Should().Be((long)item["revision"]!);
            row.Deleted.Should().Be((bool)item["deleted"]!);
            JsonNode.DeepEquals(JsonNode.Parse(row.PayloadJson), item["payload"]).Should().BeTrue();
        }
    }

    [Fact]
    public async Task Manifest_of_Laravel_reconciles_without_drift_against_a_matching_mirror()
    {
        var fixture = Load("laravel-to-mes/manifest.machines.json");
        var tenant = new TenantId(Guid.Parse((string)fixture["tenant_id"]!));
        var store = new InMemoryRefStore();
        foreach (var item in fixture["response"]!["items"]!.AsArray())
        {
            await store.UpsertAsync(tenant, RefResource.Machines, [new RefRecord(Guid.Parse((string)item!["id"]!), 1, (long)item["revision"]!, false, "{}")], default);
        }

        var handler = new StubHandler(_ => Task.FromResult(new HttpResponseMessage(HttpStatusCode.OK) { Content = new StringContent(fixture["response"]!.ToJsonString(), Encoding.UTF8, "application/json") }));
        var client = new LaravelReferenceSyncClient(new HttpClient(handler) { BaseAddress = new Uri("https://erp.test") }, store, Options.Create(new LaravelSyncOptions()), NullLogger<LaravelReferenceSyncClient>.Instance);

        var report = await client.ReconcileAsync(tenant, RefResource.Machines, default);

        report.Repaired.Should().BeFalse();
        handler.Requests.Should().ContainSingle().Which.Should().Be("GET " + (string)fixture["path"]!);
    }
}
