using System.Text.Json;
using Mes.Application.Abstractions;
using Mes.Domain.Common;
using Mes.Domain.Counters;
using Mes.Domain.Machines;
using Mes.Domain.Oee;

namespace Mes.Infrastructure.Persistence;

/// <summary>
/// Builds a <see cref="MachineProfile"/> from the mirrored <c>ref_machine.payload</c>. Assumed payload shape (to be
/// confirmed against Laravel <c>modules/30_Manufacturing</c> when the mes-sync endpoints land):
/// <code>
/// { "tag_roles": {"run":"Running","alarm":"Alarm","count":"TotalCounter"}, "ideal_cycle_ms": 1200,
///   "debounce_ms": 3000, "stale_after_s": 30,
///   "counter": {"rollover_modulus": 65536, "max_delta_per_sample": 500},
///   "oee_policy": {"version": 1, "setup": "AvailabilityLoss", "gap": "ExcludeFromPlanned"} }
/// </code>
/// Profiles are cached briefly; a revision bump on the machine is picked up at the next refresh.
/// </summary>
public sealed class RefMachineProfileProvider(PgSession session, TimeProvider time) : IMachineProfileProvider
{
    private readonly Dictionary<(TenantId, MachineId), (DateTimeOffset At, MachineProfile? Profile)> _cache = [];
    private readonly Lock _gate = new();
    private static readonly TimeSpan Ttl = TimeSpan.FromSeconds(30);

    public async Task<MachineProfile?> GetAsync(TenantId tenant, MachineId machine, CancellationToken ct)
    {
        lock (_gate)
        {
            if (_cache.TryGetValue((tenant, machine), out var hit) && time.GetUtcNow() - hit.At < Ttl)
            {
                return hit.Profile;
            }
        }

        string? json;
        await using (var scope = await session.OpenAsync(tenant, ct).ConfigureAwait(false))
        {
            await using var cmd = scope.Command("SELECT payload::text FROM ref_machine WHERE tenant_id=@t AND id=@m AND NOT deleted");
            cmd.Parameters.AddWithValue("t", tenant.Value);
            cmd.Parameters.AddWithValue("m", machine.Value);
            json = await cmd.ExecuteScalarAsync(ct).ConfigureAwait(false) as string;
        }

        var profile = json is null ? null : Parse(tenant, machine, json);
        lock (_gate)
        {
            _cache[(tenant, machine)] = (time.GetUtcNow(), profile);
        }

        return profile;
    }

    public static MachineProfile Parse(TenantId tenant, MachineId machine, string json)
    {
        using var doc = JsonDocument.Parse(json);
        var root = doc.RootElement;

        var roles = new Dictionary<TagId, TagRole>();
        if (root.TryGetProperty("tag_roles", out var tr))
        {
            foreach (var p in tr.EnumerateObject())
            {
                roles[new TagId(p.Name)] = Enum.Parse<TagRole>(p.Value.GetString()!, true);
            }
        }

        double D(string name, double dflt) => root.TryGetProperty(name, out var e) && e.TryGetDouble(out var v) ? v : dflt;

        var counter = root.TryGetProperty("counter", out var c)
            ? new CounterPolicy(
                c.TryGetProperty("rollover_modulus", out var m) && m.ValueKind == JsonValueKind.Number ? m.GetDecimal() : null,
                c.TryGetProperty("max_delta_per_sample", out var md) ? md.GetDecimal() : 10_000m)
            : new CounterPolicy(null, 10_000m);

        var policy = OeePolicyDefaults.V1;
        if (root.TryGetProperty("oee_policy", out var op))
        {
            policy = new OeePolicySnapshot(
                op.TryGetProperty("version", out var v) ? v.GetInt32() : 1,
                op.TryGetProperty("setup", out var s) ? Enum.Parse<SetupClassification>(s.GetString()!, true) : SetupClassification.AvailabilityLoss,
                op.TryGetProperty("gap", out var g) ? Enum.Parse<GapPolicy>(g.GetString()!, true) : GapPolicy.ExcludeFromPlanned);
        }

        var ideal = D("ideal_cycle_ms", 1000);
        if (ideal <= 0)
        {
            throw new InvalidOperationException($"Machine {machine}: ideal_cycle_ms must be > 0.");
        }

        return new MachineProfile(
            tenant,
            machine,
            roles,
            ideal,
            counter,
            TimeSpan.FromMilliseconds(D("debounce_ms", 3000)),
            TimeSpan.FromSeconds(D("stale_after_s", 30)),
            policy,
            LineCode: root.TryGetProperty("line_code", out var lc) && lc.ValueKind == JsonValueKind.String ? lc.GetString() : null);
    }
}
