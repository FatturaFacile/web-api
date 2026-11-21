using FluentAssertions;
using NetArchTest.Rules;
using Xunit;

namespace Mes.Tests;

/// <summary>
/// Enforces the inward-only layering and the edge/core split:
/// Domain ← Application ← Infrastructure.* ← Presentation, and Edge.Gateway holds NO business logic (no OEE, no state machine).
/// </summary>
public class ArchitectureTests
{
    private static readonly System.Reflection.Assembly Domain = typeof(Mes.Domain.Common.TenantId).Assembly;
    private static readonly System.Reflection.Assembly Application = typeof(Mes.Application.Abstractions.IConnector).Assembly;
    private static readonly System.Reflection.Assembly Connectors = typeof(Mes.Infrastructure.Connectors.ConnectorBase).Assembly;
    private static readonly System.Reflection.Assembly Persistence = typeof(Mes.Infrastructure.Persistence.PgSession).Assembly;
    private static readonly System.Reflection.Assembly LaravelSync = typeof(Mes.Infrastructure.LaravelSync.Hmac).Assembly;
    private static readonly System.Reflection.Assembly Edge = typeof(Edge.Gateway.GatewayConfig).Assembly;
    private static readonly System.Reflection.Assembly Presentation = typeof(Mes.Presentation.TenantAccess).Assembly;

    private static void AssertNoDependency(System.Reflection.Assembly assembly, params string[] forbidden)
    {
        var result = Types.InAssembly(assembly).ShouldNot().HaveDependencyOnAny(forbidden).GetResult();
        result.IsSuccessful.Should().BeTrue($"forbidden dependency in: {string.Join(", ", result.FailingTypeNames ?? [])}");
    }

    [Fact]
    public void Domain_depends_on_nothing_but_the_BCL() =>
        AssertNoDependency(Domain, "Mes.Application", "Mes.Infrastructure", "Mes.Presentation", "Edge.Gateway", "Npgsql", "StackExchange", "Microsoft.AspNetCore", "Microsoft.Extensions", "Opc.Ua", "MQTTnet", "Polly", "Google.Protobuf");

    [Fact]
    public void Application_does_not_know_infrastructure_or_presentation() =>
        AssertNoDependency(Application, "Mes.Infrastructure", "Mes.Presentation", "Edge.Gateway", "Npgsql", "StackExchange", "Microsoft.AspNetCore", "Opc.Ua", "MQTTnet", "Polly");

    [Fact]
    public void Connectors_are_independent_of_persistence_sync_and_web() =>
        AssertNoDependency(Connectors, "Mes.Infrastructure.Persistence", "Mes.Infrastructure.LaravelSync", "Mes.Presentation", "Edge.Gateway", "Npgsql", "StackExchange", "Microsoft.AspNetCore");

    [Fact]
    public void Persistence_is_independent_of_other_infrastructure_and_web() =>
        AssertNoDependency(Persistence, "Mes.Infrastructure.Connectors", "Mes.Infrastructure.LaravelSync", "Mes.Presentation", "Edge.Gateway", "Opc.Ua", "MQTTnet", "Microsoft.AspNetCore");

    [Fact]
    public void LaravelSync_is_independent_of_other_infrastructure_and_web() =>
        AssertNoDependency(LaravelSync, "Mes.Infrastructure.Connectors", "Mes.Infrastructure.Persistence", "Mes.Presentation", "Edge.Gateway", "Npgsql", "StackExchange", "Opc.Ua", "MQTTnet");

    [Fact]
    public void Edge_gateway_contains_no_oee_or_state_machine_logic() =>
        AssertNoDependency(
            Edge,
            "Mes.Domain.Oee",
            "Mes.Domain.State",
            "Mes.Domain.Counters",
            "Mes.Domain.Energy",
            "Mes.Application.Commands",
            "Mes.Application.Processing",
            "Mes.Infrastructure.Persistence",
            "Mes.Infrastructure.LaravelSync",
            "Mes.Presentation",
            "Npgsql",
            "StackExchange.Redis");

    [Fact]
    public void Connectors_used_by_the_edge_also_stay_free_of_business_logic() =>
        AssertNoDependency(Connectors, "Mes.Domain.Oee", "Mes.Domain.State", "Mes.Domain.Counters", "Mes.Domain.Energy", "Mes.Application.Commands", "Mes.Application.Processing");

    [Fact]
    public void Nothing_references_the_presentation_layer()
    {
        foreach (var a in new[] { Domain, Application, Connectors, Persistence, LaravelSync, Edge })
        {
            AssertNoDependency(a, "Mes.Presentation");
        }

        Presentation.Should().NotBeNull();
    }

    [Fact]
    public void Handlers_and_commands_live_in_application()
    {
        var r = Types.InAssembly(Application).That().HaveNameEndingWith("Handler").Should().ResideInNamespace("Mes.Application.Commands").GetResult();
        r.IsSuccessful.Should().BeTrue(string.Join(", ", r.FailingTypeNames ?? []));
    }
}
