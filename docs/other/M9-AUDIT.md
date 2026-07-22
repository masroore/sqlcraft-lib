# M9 Security & Events Audit

Date: 2026-07-21

M9 treats the event catalog as **25 domain events plus two event base types**
(`ObservabilityEvent` and `InterceptionEvent`), which is the source of the
roadmap's “27-event” count.

## Event checklist

| Surface | Events | Emitter / seam | Verification |
|---|---|---|---|
| Connection | `BeforeConnectionOpened`, `ConnectionOpenedEvent`, `ConnectionFailedEvent`, `ConnectionClosedEvent` | `PdoConnectionFactory`, `PdoConnection`, `ConnectionEventDispatcher` | `ConnectionLifecycleEventsTest` |
| Query | `BeforeQueryExecuted`, `AfterQueryExecuted`, `QueryFailedEvent`, `SlowQueryDetectedEvent` | `QueryExecutor` | `QueryExecutorTest` |
| DDL | `BeforeDdlExecuted`, `AfterDdlExecuted` | `DdlManager`, `SchemaEventDispatcher` | `DdlManagerTest` |
| Transactions | `BeforeTransactionBegan`, `TransactionBeganEvent`, `TransactionCommittedEvent`, `TransactionRolledBackEvent` | `PdoConnection`, `Transaction`, `ConnectionEventDispatcher` | `ConnectionLifecycleEventsTest` |
| Schema | `BeforeSchemaChange`, `SchemaChangedEvent`, `MetadataFetchedEvent` | `DdlManager`, `SchemaManager`, `SchemaEventDispatcher` | `SchemaEventDispatcherTest`, `SchemaManagerTest` |
| Import | `ImportStartedEvent`, `ImportProgressEvent`, `ImportFinishedEvent`, `ImportFailedEvent` | `Importer`, `CsvImporter` | `ImporterTest`, `CsvImporterTest` |
| Export | `ExportStartedEvent`, `ExportProgressEvent`, `ExportFinishedEvent` | `Exporter` | `ExportOrchestrationTest` |
| Capability | `CapabilityNotSupportedEvent` | `CapabilitySet`, `PlatformCapabilityResolver`, `AbstractPlatform` | `CapabilityTest`, platform golden tests |

All observability events implement `SQLCraftEventInterface`; interception events
support cancellation and propagation stopping. Connection and schema event ports
keep the connection/platform layers compliant with `deptrac.yaml`.

## Security checklist

- Identifier values are validated by value objects and quoted by platform.
- Operators and aggregate names are allowlisted.
- Data type names reject blank, null-byte, and statement-injection syntax.
- Values remain bound parameters in query rendering.
- Pagination validates positive values and `Paginator` enforces its configurable
  10,000-row maximum.
- Batch execution enforces its configurable 1,000-statement maximum.
- DSN password options are redacted before connection exceptions store them.
- Stringified SQLCraft exceptions omit native previous-exception text.
- Connection passwords are marked with `#[SensitiveParameter]`.

## Verification

```text
composer run ci — green
```

Oracle remains intentionally deferred; SQL Server is the supported M8 extension.
