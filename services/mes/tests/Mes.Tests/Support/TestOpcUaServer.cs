#pragma warning disable CS0618 // SDK 1.5.378 obsolete security-configuration overload; test-only
using Opc.Ua;
using Opc.Ua.Configuration;
using Opc.Ua.Server;

namespace Mes.Tests.Support;

/// <summary>
/// Minimal in-process OPC UA server (OPCFoundation SDK, security policy None, loopback). Exposes two variables under
/// <c>Plant</c> in the namespace <see cref="NamespaceUri"/> so tests can drive value changes and kill/restart the server.
/// </summary>
public sealed class TestOpcUaServer : IAsyncDisposable
{
    public const string NamespaceUri = "urn:mes:test:plant";
    private static readonly ITelemetryContext Telemetry = DefaultTelemetry.Create(_ => { });

    private readonly string _pki = Path.Combine(Path.GetTempPath(), "mes-opc-" + Guid.NewGuid().ToString("N"));
    private ApplicationInstance? _app;
    private Node? _node;
    private TestServer? _server;

    public TestOpcUaServer(int? port = null)
    {
        Port = port ?? FreePort.Get();
        Url = $"opc.tcp://localhost:{Port}/mes-test";
    }

    public int Port { get; }

    public string Url { get; }

    public int SessionCount => _server?.CurrentInstance.SessionManager.GetSessions().Count ?? 0;

    public async Task StartAsync()
    {
        _app = new ApplicationInstance(Telemetry) { ApplicationName = "MesTestServer", ApplicationType = ApplicationType.Server };
        await _app.Build("urn:localhost:MesTestServer", "urn:mes:test")
            .AsServer([Url])
            .AddUnsecurePolicyNone()
            .AddSecurityConfiguration("CN=MesTestServer, O=Test", _pki)
            .SetAutoAcceptUntrustedCertificates(true)
            .CreateAsync();
        await _app.CheckApplicationInstanceCertificatesAsync(true);

        _node = new Node();
        _server = new TestServer(_node);
        await _app.StartAsync(_server);
    }

    public async Task StopAsync()
    {
        if (_app is { } app)
        {
            await app.StopAsync();
            _app = null;
            _server = null;
        }
    }

    /// <summary>Sets a variable ("Temp" | "Count") and notifies subscribers.</summary>
    public void Set(string name, double value) => _node!.Set(name, value);

    public async ValueTask DisposeAsync()
    {
        await StopAsync();
        try
        {
            Directory.Delete(_pki, true);
        }
        catch (IOException)
        {
            // best effort
        }
    }

    private sealed class TestServer(Node node) : StandardServer
    {
        protected override MasterNodeManager CreateMasterNodeManager(IServerInternal server, ApplicationConfiguration configuration) =>
            new(server, configuration, null, node.Create(server, configuration));
    }

    private sealed class Node
    {
        private NodeManager? _manager;

        public INodeManager Create(IServerInternal server, ApplicationConfiguration configuration) => _manager = new NodeManager(server, configuration);

        public void Set(string name, double value) => _manager!.Set(name, value);
    }

    private sealed class NodeManager : CustomNodeManager2
    {
        private readonly Dictionary<string, BaseDataVariableState> _vars = [];

        public NodeManager(IServerInternal server, ApplicationConfiguration configuration)
            : base(server, configuration, NamespaceUri)
        {
        }

        public override void CreateAddressSpace(IDictionary<NodeId, IList<IReference>> externalReferences)
        {
            lock (Lock)
            {
                if (!externalReferences.TryGetValue(ObjectIds.ObjectsFolder, out var refs))
                {
                    externalReferences[ObjectIds.ObjectsFolder] = refs = [];
                }

                var folder = new FolderState(null)
                {
                    NodeId = new NodeId("Plant", NamespaceIndex),
                    BrowseName = new QualifiedName("Plant", NamespaceIndex),
                    DisplayName = "Plant",
                    TypeDefinitionId = ObjectTypeIds.FolderType,
                    EventNotifier = EventNotifiers.None,
                };
                folder.AddReference(ReferenceTypeIds.Organizes, true, ObjectIds.ObjectsFolder);
                refs.Add(new NodeStateReference(ReferenceTypeIds.Organizes, false, folder.NodeId));

                foreach (var name in new[] { "Temp", "Count" })
                {
                    var v = new BaseDataVariableState(folder)
                    {
                        NodeId = new NodeId(name, NamespaceIndex),
                        BrowseName = new QualifiedName(name, NamespaceIndex),
                        DisplayName = name,
                        DataType = DataTypeIds.Double,
                        ValueRank = ValueRanks.Scalar,
                        AccessLevel = AccessLevels.CurrentRead,
                        UserAccessLevel = AccessLevels.CurrentRead,
                        Historizing = false,
                        Value = 0.0,
                        StatusCode = StatusCodes.Good,
                        Timestamp = DateTime.UtcNow,
                    };
                    folder.AddChild(v);
                    _vars[name] = v;
                }

                AddPredefinedNode(SystemContext, folder);
            }
        }

        public void Set(string name, double value)
        {
            lock (Lock)
            {
                var v = _vars[name];
                v.Value = value;
                v.Timestamp = DateTime.UtcNow;
                v.ClearChangeMasks(SystemContext, false);
            }
        }
    }
}
