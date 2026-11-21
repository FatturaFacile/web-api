using System.Net;
using System.Net.Http.Json;
using System.Text.Json;
using System.Text.Json.Serialization;
using Mes.Application.Abstractions;
using Mes.Domain.Common;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;

namespace Mes.Infrastructure.LaravelSync;

internal sealed record FeedItem(
    [property: JsonPropertyName("id")] Guid Id,
    [property: JsonPropertyName("seq")] long Seq,
    [property: JsonPropertyName("revision")] long Revision,
    [property: JsonPropertyName("deleted")] bool Deleted,
    [property: JsonPropertyName("payload")] JsonElement Payload);

internal sealed record FeedPage(
    [property: JsonPropertyName("data")] List<FeedItem> Data,
    [property: JsonPropertyName("next_seq")] long NextSeq,
    [property: JsonPropertyName("has_more")] bool HasMore);

internal sealed record ManifestItem([property: JsonPropertyName("id")] Guid Id, [property: JsonPropertyName("revision")] long Revision);

internal sealed record Manifest([property: JsonPropertyName("items")] List<ManifestItem> Items);

/// <summary>
/// Pulls the Laravel change feed (<c>GET /api/v1/manufacturing/mes-sync/{resource}?after_seq=&amp;limit=</c>) into the
/// read-only <c>ref_*</c> mirror. Contract assumptions (to be confirmed with the Laravel module):
/// monotonic <c>seq</c> per tenant+resource, per-record <c>revision</c>, soft deletes surfaced as <c>deleted:true</c>,
/// <c>410 Gone</c> when the cursor is older than the retained history, and a <c>/manifest</c> listing id+revision.
/// </summary>
public sealed class LaravelReferenceSyncClient(
    HttpClient http,
    IReferenceStore store,
    IOptions<LaravelSyncOptions> options,
    ILogger<LaravelReferenceSyncClient> logger) : IManufacturingReferenceSync
{
    private const string Prefix = "/api/v1/manufacturing/mes-sync/";

    public async Task<int> PullAsync(TenantId tenant, RefResource resource, CancellationToken ct)
    {
        var applied = 0;
        var resetOnce = false;
        var cursor = await store.GetCursorAsync(tenant, resource, ct).ConfigureAwait(false);

        while (true)
        {
            using var req = new HttpRequestMessage(HttpMethod.Get, $"{Prefix}{resource.Slug()}?after_seq={cursor}&limit={options.Value.PageSize}");
            req.Headers.Add("X-Tenant-Id", tenant.ToString());
            using var resp = await http.SendAsync(req, ct).ConfigureAwait(false);

            if (resp.StatusCode == HttpStatusCode.Gone)
            {
                if (resetOnce)
                {
                    throw new InvalidOperationException($"Laravel keeps answering 410 for {resource.Slug()} after a cursor reset.");
                }

                // The server compacted history past our cursor: our mirror may have missed deletes/changes.
                // Reset to 0 and re-bootstrap; revision guards make re-applying existing rows harmless.
                logger.LogWarning("410 Gone for {Resource} at seq {Seq}; resetting cursor and re-bootstrapping", resource.Slug(), cursor);
                await store.ResetCursorAsync(tenant, resource, ct).ConfigureAwait(false);
                cursor = 0;
                resetOnce = true;
                continue;
            }

            resp.EnsureSuccessStatusCode();
            var page = await resp.Content.ReadFromJsonAsync<FeedPage>(ct).ConfigureAwait(false) ?? throw new InvalidOperationException("Empty feed page.");

            if (page.Data.Count > 0)
            {
                var records = page.Data.Select(i => new RefRecord(i.Id, i.Seq, i.Revision, i.Deleted, i.Payload.ValueKind == JsonValueKind.Undefined ? "{}" : i.Payload.GetRawText())).ToList();
                applied += await store.UpsertAsync(tenant, resource, records, ct).ConfigureAwait(false);
                var maxSeq = records.Max(r => r.Seq);
                await store.SetCursorAsync(tenant, resource, maxSeq, ct).ConfigureAwait(false);
                cursor = Math.Max(cursor, maxSeq);
            }

            if (!page.HasMore || page.Data.Count == 0)
            {
                // next_seq lets the server advance us past rows we are not entitled to see (filtered feed).
                if (page.NextSeq > cursor)
                {
                    await store.SetCursorAsync(tenant, resource, page.NextSeq, ct).ConfigureAwait(false);
                }

                return applied;
            }
        }
    }

    public async Task ApplyPushAsync(RefEvent evt, CancellationToken ct)
    {
        var cursor = await store.GetCursorAsync(evt.TenantId, evt.Resource, ct).ConfigureAwait(false);
        if (evt.Seq <= cursor)
        {
            return; // replay or already pulled
        }

        if (evt.Seq == cursor + 1 && (evt.PayloadJson is not null || evt.Deleted))
        {
            await store.UpsertAsync(evt.TenantId, evt.Resource, [new RefRecord(evt.Id, evt.Seq, evt.Revision, evt.Deleted, evt.PayloadJson ?? "{}")], ct).ConfigureAwait(false);
            await store.SetCursorAsync(evt.TenantId, evt.Resource, evt.Seq, ct).ConfigureAwait(false);
            return;
        }

        // A hole in the sequence (lost push): never apply out of order, let the authoritative feed fill the gap.
        await PullAsync(evt.TenantId, evt.Resource, ct).ConfigureAwait(false);
    }

    public async Task<ReconcileReport> ReconcileAsync(TenantId tenant, RefResource resource, CancellationToken ct)
    {
        using var req = new HttpRequestMessage(HttpMethod.Get, $"{Prefix}{resource.Slug()}/manifest");
        req.Headers.Add("X-Tenant-Id", tenant.ToString());
        using var resp = await http.SendAsync(req, ct).ConfigureAwait(false);
        resp.EnsureSuccessStatusCode();
        var manifest = await resp.Content.ReadFromJsonAsync<Manifest>(ct).ConfigureAwait(false) ?? throw new InvalidOperationException("Empty manifest.");

        var remote = manifest.Items.ToDictionary(i => i.Id, i => i.Revision);
        var local = await store.ListRevisionsAsync(tenant, resource, ct).ConfigureAwait(false);

        var missing = remote.Count(kv => !local.ContainsKey(kv.Key));
        var stale = remote.Count(kv => local.TryGetValue(kv.Key, out var l) && l < kv.Value);
        var orphans = local.Where(kv => !remote.ContainsKey(kv.Key)).ToList();

        if (missing == 0 && stale == 0 && orphans.Count == 0)
        {
            return new ReconcileReport(resource, 0, 0, 0, Repaired: false);
        }

        logger.LogWarning("Drift on {Resource}: missing={Missing} stale={Stale} orphaned={Orphaned}; repairing", resource.Slug(), missing, stale, orphans.Count);

        // Orphans exist locally but not remotely (missed delete): tombstone with revision+1 so the revision guard accepts it.
        if (orphans.Count > 0)
        {
            await store.UpsertAsync(tenant, resource, orphans.Select(o => new RefRecord(o.Key, 0, o.Value + 1, true, "{}")).ToList(), ct).ConfigureAwait(false);
        }

        if (missing > 0 || stale > 0)
        {
            await store.ResetCursorAsync(tenant, resource, ct).ConfigureAwait(false);
            await PullAsync(tenant, resource, ct).ConfigureAwait(false); // re-bootstrap; guarded upserts only change what is behind
        }

        return new ReconcileReport(resource, missing, stale, orphans.Count, Repaired: true);
    }
}
