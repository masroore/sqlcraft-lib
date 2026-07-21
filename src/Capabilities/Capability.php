<?php

declare(strict_types=1);

namespace SQLCraft\Capabilities;

enum Capability: string
{
    case Table = 'table';
    case View = 'view';
    case MaterializedView = 'materializedview';
    case Sequence = 'sequence';
    case Type = 'type';
    case Scheme = 'scheme';
    case Columns = 'columns';
    case Comment = 'comment';
    case Charset = 'charset';
    case Collation = 'collation';
    case Compression = 'compression';
    case GeneratedColumns = 'generated';
    case Indexes = 'indexes';
    case ForeignKeys = 'fkeys';
    case CheckConstraints = 'check';
    case PartialIndexes = 'partial_indexes';
    case DescendingIndexes = 'descidx';
    case Copy = 'copy';
    case InsertUpdate = 'insert_update';
    case DropColumn = 'drop_col';
    case MoveColumn = 'move_col';
    case Database = 'database';
    case Routine = 'routine';
    case Procedure = 'procedure';
    case Trigger = 'trigger';
    case ViewTrigger = 'view_trigger';
    case Event = 'event';
    case Status = 'status';
    case Variables = 'variables';
    case Processlist = 'processlist';
    case Kill = 'kill';
    case Privileges = 'privileges';
    case Sql = 'sql';
    case QueryTimeout = 'query_timeout';
    case UserManagement = 'user_management';
    case PrivilegeManagement = 'privilege_management';
    case CrossTableSearch = 'cross_table_search';
    case BlobStreaming = 'blob_streaming';
    case Dump = 'dump';
    case Partitions = 'partitions';
}
