using System.Net;
using System.Text;
using System.Text.Json;
using FluentAssertions;
using Mes.Application.Abstractions;
using Mes.Domain.Common;
using Mes.Infrastructure.LaravelSync;
using Microsoft.Extensions.Logging.Abstractions;
using Microsoft.Extensions.Options;
using Xunit;

namespace Mes.Tests;

public class HmacTests
{
    private static readonly byte[] Secret = Encoding.UTF8.GetBytes("super-secret-1");
    private static readonly DateTimeOffset Now = new(2026, 1, 1, 12, 0, 0, TimeSpan.Zero);
    private static byte[]? Keys(string kid) => kid == "k1" ? Secret : kid == "k0" ? Encoding.UTF8.GetBytes("old") : null;

    [Fact]
    public void Round_trip_verifies()
    {
        var body = """{"a":1}"""u8.ToArray();
        var header = Hmac.Sign("k1", Secret, body, Now);
        header.Should().MatchRegex(@"^t=\d+,kid=k1,v1=[0-9a-f]{64}$");
        Hmac.Verify(header, body, Keys, Now.AddSeconds(30)).Should().Be(SignatureFailure.None);
    }

    [Fact]
    public void Tampered_body_wrong_key_unknown_kid_and_expiry_are_rejected()
    {
        var body = "hello"u8.ToArray();
        var header = Hmac.Sign("k1", Secret, body, Now);

        Hmac.Verify(header, "hellO"u8.ToArray(), Keys, Now).Should().Be(SignatureFailure.Mismatch);
        Hmac.Verify(header.Replace("kid=k1", "kid=k0"), body, Keys, Now).Should().Be(SignatureFailure.Mismatch);
        Hmac.Verify(header.Replace("kid=k1", "kid=zz"), body, Keys, Now).Should().Be(SignatureFailure.UnknownKey);
        Hmac.Verify(header, body, Keys, Now.AddMinutes(6)).Should().Be(SignatureFailure.Expired);
        Hmac.Verify(header, body, Keys, Now.AddMinutes(-6)).Should().Be(SignatureFailure.Expired);
        Hmac.Verify(null, body, Keys, Now).Should().Be(SignatureFailure.Malformed);
        Hmac.Verify("garbage", body, Keys, Now).Should().Be(SignatureFailure.Malformed);
        Hmac.Verify("t=abc,kid=k1,v1=00", body, Keys, Now).Should().Be(SignatureFailure.Malformed);
    }

    [Fact]
    public void Replaying_with_a_changed_timestamp_fails()
    {
        var body = "x"u8.ToArray();
        var header = Hmac.Sign("k1", Secret, body, Now);
        var forged = header.Replace($"t={Now.ToUnixTimeSeconds()}", $"t={Now.AddSeconds(10).ToUnixTimeSeconds()}");
        Hmac.Verify(forged, body, Keys, Now).Should().Be(SignatureFailure.Mismatch);
    }
}

internal sealed class StubHandler(Func<HttpRequestMessage, Task<HttpResponseMessage>> fn) : HttpMessageHandler
{
    public List<string> Requests { get; } = [];

    protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken ct)
    {
        Requests.Add($"{request.Method} {request.RequestUri!.PathAndQuery}");
        return await fn(request);
    }
}

internal static class Json
{
    public static HttpResponseMessage Ok(object body, HttpStatusCode code = HttpStatusCode.OK) =>
        new(code) { Content = new StringContent(JsonSerializer.Serialize(body), Encoding.UTF8, "application/json") };
}

internal sealed class InMemoryRefStore : IReferenceStore
{
    public Dictionary<(TenantId, RefResource), long> Cursors { get; } = [];

    public Dictionary<(TenantId, RefResource, Guid), RefRecord> Rows { get; } = [];

    public Task<long> GetCursorAsync(TenantId t, RefResource r, CancellationToken ct) => Task.FromResult(Cursors.GetValueOrDefault((t, r)));

    public Task SetCursorAsync(TenantId t, RefResource r, long seq, CancellationToken ct)
    {
        Cursors[(t, r)] = Math.Max(Cursors.GetValueOrDefault((t, r)), seq);
        return Task.CompletedTask;
    }

    public Task ResetCursorAsync(TenantId t, RefResource r, CancellationToken ct)
    {
        Cursors[(t, r)] = 0;
        return Task.CompletedTask;
    }

    public Task<int> UpsertAsync(TenantId t, RefResource r, IReadOnlyList<RefRecord> records, CancellationToken ct)
    {
        var applied = 0;
        foreach (var rec in records)
        {
            if (Rows.TryGetValue((t, r, rec.Id), out var cur) && cur.Revision >= rec.Revision)
            {
                continue; // revision guard: ref.revision < excluded.revision
            }

            Rows[(t, r, rec.Id)] = rec;
            applied++;
        }

        return Task.FromResult(applied);
    }

    public Task<IReadOnlyDictionary<Guid, long>> ListRevisionsAsync(TenantId t, RefResource r, CancellationToken ct) =>
        Task.FromResult<IReadOnlyDictionary<Guid, long>>(Rows.Where(kv => kv.Key.Item1 == t && kv.Key.Item2 == r && !kv.Value.Deleted).ToDictionary(kv => kv.Key.Item3, kv => kv.Value.Revision));
}

public class ReferenceSyncTests
{
    private readonly TenantId _t = TenantId.New();
    private readonly InMemoryRefStore _store = new();
    private static readonly Guid A = Guid.NewGuid(), B = Guid.NewGuid(), C = Guid.NewGuid();

    private LaravelReferenceSyncClient Client(StubHandler h) => new(
        new HttpClient(h) { BaseAddress = new Uri("https://erp.test") },
        _store,
        Options.Create(new LaravelSyncOptions { PageSize = 2 }),
        NullLogger<LaravelReferenceSyncClient>.Instance);

    private static object Item(Guid id, long seq, long rev, bool deleted = false) => new { id, seq, revision = rev, deleted, payload = new { code = "M" + seq } };

    [Fact]
    public async Task Pull_pages_by_seq_cursor_and_applies_revision_guard()
    {
        var calls = 0;
        var h = new StubHandler(req =>
        {
            calls++;
            var after = long.Parse(System.Web.HttpUtility.ParseQueryString(req.RequestUri!.Query)["after_seq"]!);
            return Task.FromResult(after switch
            {
                0 => Json.Ok(new { data = new[] { Item(A, 1, 1), Item(B, 2, 1) }, next_seq = 2, has_more = true }),
                2 => Json.Ok(new { data = new[] { Item(C, 3, 1) }, next_seq = 3, has_more = false }),
                _ => throw new InvalidOperationException("unexpected cursor " + after),
            });
        });

        var applied = await Client(h).PullAsync(_t, RefResource.Machines, default);

        applied.Should().Be(3);
        calls.Should().Be(2);
        _store.Cursors[(_t, RefResource.Machines)].Should().Be(3);

        // an older revision of A must not overwrite a newer one already stored
        await _store.UpsertAsync(_t, RefResource.Machines, [new RefRecord(A, 9, 5, false, "{\"newer\":true}")], default);
        (await _store.UpsertAsync(_t, RefResource.Machines, [new RefRecord(A, 10, 2, false, "{\"older\":true}")], default)).Should().Be(0);
    }

    [Fact]
    public async Task Gone_410_resets_the_cursor_and_rebootstraps_once()
    {
        _store.Cursors[(_t, RefResource.WorkOrders)] = 500;
        var h = new StubHandler(req =>
        {
            var after = long.Parse(System.Web.HttpUtility.ParseQueryString(req.RequestUri!.Query)["after_seq"]!);
            return Task.FromResult(after == 500
                ? new HttpResponseMessage(HttpStatusCode.Gone)
                : Json.Ok(new { data = new[] { Item(A, 1, 1) }, next_seq = 1, has_more = false }));
        });

        var applied = await Client(h).PullAsync(_t, RefResource.WorkOrders, default);

        applied.Should().Be(1);
        h.Requests.Should().Equal("GET /api/v1/manufacturing/mes-sync/work-orders?after_seq=500&limit=2", "GET /api/v1/manufacturing/mes-sync/work-orders?after_seq=0&limit=2");
        _store.Cursors[(_t, RefResource.WorkOrders)].Should().Be(1);
    }

    [Fact]
    public async Task Repeated_410_after_reset_fails_instead_of_looping()
    {
        var h = new StubHandler(_ => Task.FromResult(new HttpResponseMessage(HttpStatusCode.Gone)));
        var act = () => Client(h).PullAsync(_t, RefResource.Machines, default);
        await act.Should().ThrowAsync<InvalidOperationException>();
        h.Requests.Should().HaveCount(2);
    }

    [Fact]
    public async Task Push_in_sequence_is_applied_without_a_pull_but_a_gap_triggers_a_pull()
    {
        _store.Cursors[(_t, RefResource.Machines)] = 4;
        var h = new StubHandler(_ => Task.FromResult(Json.Ok(new { data = new[] { Item(B, 7, 1) }, next_seq = 7, has_more = false })));
        var client = Client(h);

        await client.ApplyPushAsync(new RefEvent(_t, RefResource.Machines, A, 5, 1, false, "{\"x\":1}"), default);
        h.Requests.Should().BeEmpty();
        _store.Cursors[(_t, RefResource.Machines)].Should().Be(5);

        await client.ApplyPushAsync(new RefEvent(_t, RefResource.Machines, A, 5, 1, false, "{\"x\":1}"), default); // replay
        h.Requests.Should().BeEmpty();

        await client.ApplyPushAsync(new RefEvent(_t, RefResource.Machines, C, 9, 1, false, "{\"x\":1}"), default); // 6..8 lost
        h.Requests.Should().ContainSingle().Which.Should().Contain("after_seq=5");
        _store.Rows.Should().NotContainKey((_t, RefResource.Machines, C), "an out-of-order push is never trusted; the feed is authoritative");
    }

    [Fact]
    public async Task Reconcile_repairs_missing_stale_and_orphaned_rows()
    {
        await _store.UpsertAsync(_t, RefResource.ReasonCodes, [new RefRecord(A, 1, 1, false, "{}"), new RefRecord(B, 2, 1, false, "{}"), new RefRecord(C, 3, 1, false, "{}")], default);
        var h = new StubHandler(req => Task.FromResult(req.RequestUri!.AbsolutePath.EndsWith("/manifest", StringComparison.Ordinal)
            ? Json.Ok(new { items = new[] { new { id = A, revision = 1L }, new { id = B, revision = 3L }, new { id = Guid.NewGuid(), revision = 1L } } }) // C orphaned, B stale, 1 missing
            : Json.Ok(new { data = new[] { Item(B, 10, 3) }, next_seq = 10, has_more = false })));

        var report = await Client(h).ReconcileAsync(_t, RefResource.ReasonCodes, default);

        report.Should().Be(new ReconcileReport(RefResource.ReasonCodes, 1, 1, 1, true));
        _store.Rows[(_t, RefResource.ReasonCodes, C)].Deleted.Should().BeTrue();
        _store.Rows[(_t, RefResource.ReasonCodes, B)].Revision.Should().Be(3);
        h.Requests.Should().Contain(r => r.Contains("after_seq=0", StringComparison.Ordinal));
    }

    [Fact]
    public async Task Reconcile_without_drift_is_a_noop()
    {
        await _store.UpsertAsync(_t, RefResource.Machines, [new RefRecord(A, 1, 4, false, "{}")], default);
        var h = new StubHandler(_ => Task.FromResult(Json.Ok(new { items = new[] { new { id = A, revision = 4L } } })));
        (await Client(h).ReconcileAsync(_t, RefResource.Machines, default)).Repaired.Should().BeFalse();
        h.Requests.Should().ContainSingle();
    }
}

internal sealed class FakeOutboxStore(IEnumerable<ClaimedOutbox> claimed) : IOutboxStore
{
    public List<(Guid Id, string State, string? Detail)> Outcomes { get; } = [];

    public List<DateTimeOffset> Rescheduled { get; } = [];

    public Task<IReadOnlyList<ClaimedOutbox>> ClaimAsync(int batchSize, TimeSpan lease, CancellationToken ct) => Task.FromResult<IReadOnlyList<ClaimedOutbox>>(claimed.ToList());

    public Task CompleteAsync(Guid id, string status, CancellationToken ct)
    {
        Outcomes.Add((id, "sent:" + status, null));
        return Task.CompletedTask;
    }

    public Task RescheduleAsync(Guid id, DateTimeOffset nextAttemptAt, string error, CancellationToken ct)
    {
        Outcomes.Add((id, "retry", error));
        Rescheduled.Add(nextAttemptAt);
        return Task.CompletedTask;
    }

    public Task DeadLetterAsync(Guid id, string error, CancellationToken ct)
    {
        Outcomes.Add((id, "dead", error));
        return Task.CompletedTask;
    }
}

public class OutboxRelayTests
{
    private readonly TenantId _t = TenantId.New();

    private ClaimedOutbox Msg(int attempts) =>
        new(new OutboxMessage(Guid.NewGuid(), _t, OutboxTypes.OeeShiftClosed, Guid.NewGuid().ToString(), "{\"k\":1}", DateTimeOffset.UtcNow), attempts);

    private static OutboxRelay Relay(FakeOutboxStore store, StubHandler h) =>
        new(store, new HttpClient(h) { BaseAddress = new Uri("https://erp.test") }, Options.Create(new OutboxRelayOptions()), NullLogger<OutboxRelay>.Instance);

    [Fact]
    public async Task Multi_status_207_is_applied_per_event()
    {
        var items = new[] { Msg(1), Msg(1), Msg(1), Msg(1), Msg(1) };
        var statuses = new[] { "applied", "duplicate", "stale", "rejected", "wat" };
        var store = new FakeOutboxStore(items);
        var h = new StubHandler(_ => Task.FromResult(Json.Ok(new { results = items.Select((m, i) => new { id = m.Message.Id, status = statuses[i], message = "msg" }) }, HttpStatusCode.MultiStatus)));

        await Relay(store, h).DeliverAsync(_t, items, new OutboxRelayOptions(), default);

        store.Outcomes.Select(o => o.State).Should().Equal("sent:applied", "sent:duplicate", "sent:stale", "dead", "retry");
    }

    [Fact]
    public async Task Server_error_reschedules_everything_with_backoff()
    {
        var items = new[] { Msg(1), Msg(3) };
        var store = new FakeOutboxStore(items);
        var h = new StubHandler(_ => Task.FromResult(new HttpResponseMessage(HttpStatusCode.ServiceUnavailable)));

        await Relay(store, h).DeliverAsync(_t, items, new OutboxRelayOptions(), default);

        store.Outcomes.Should().OnlyContain(o => o.State == "retry");
        store.Rescheduled.Should().OnlyContain(d => d > DateTimeOffset.UtcNow);
    }

    [Fact]
    public async Task Dead_letters_when_the_twelfth_attempt_fails()
    {
        var items = new[] { Msg(11), Msg(12) };
        var store = new FakeOutboxStore(items);
        var h = new StubHandler(_ => throw new HttpRequestException("boom"));

        await Relay(store, h).DeliverAsync(_t, items, new OutboxRelayOptions(), default);

        store.Outcomes.Select(o => o.State).Should().Equal("retry", "dead");
    }

    [Fact]
    public void Backoff_grows_exponentially_with_jitter_and_is_capped()
    {
        var rng = new Random(7);
        var b = TimeSpan.FromSeconds(5);
        var cap = TimeSpan.FromMinutes(15);
        for (var attempt = 1; attempt <= 12; attempt++)
        {
            RetryPolicy.Delay(attempt, b, cap, rng).Should().BeGreaterThanOrEqualTo(TimeSpan.FromSeconds(1)).And.BeLessThanOrEqualTo(cap);
        }

        Enumerable.Range(0, 300).Max(_ => RetryPolicy.Delay(1, b, cap, rng)).Should().BeLessThanOrEqualTo(b);
        Enumerable.Range(0, 300).Max(_ => RetryPolicy.Delay(10, b, cap, rng)).Should().BeGreaterThan(TimeSpan.FromMinutes(10));
    }
}
