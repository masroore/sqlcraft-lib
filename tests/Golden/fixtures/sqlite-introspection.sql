getDatabasesSql: SELECT 'main' AS name UNION ALL SELECT 'temp' AS name
getSchemasSql: 
getTypesSql: UNSUPPORTED: Capability not supported: type on sqlite.
getTablesSql: SELECT name AS table_name, type AS table_type, CASE WHEN type = 'view' THEN 1 ELSE 0 END AS is_view FROM "main".sqlite_master WHERE type IN ('table', 'view') AND name NOT LIKE 'sqlite_%' ORDER BY name
getColumnsSql: PRAGMA table_info("users")
getAllColumnsSql: SELECT schema_object.name AS table_name, table_column.name AS column_name, table_column.type AS data_type, CASE WHEN table_column."notnull" = 0 THEN 'YES' ELSE 'NO' END AS is_nullable, table_column.dflt_value AS column_default, table_column.pk AS pk FROM "main".sqlite_master schema_object JOIN pragma_table_info(schema_object.name) table_column WHERE schema_object.type = 'table' AND schema_object.name NOT LIKE 'sqlite_%' ORDER BY schema_object.name, table_column.cid
getAllIndexesSql: SELECT schema_object.name AS table_name, table_index.name AS index_name, table_index.[unique] AS is_unique, table_index.partial, index_column.name AS column_name FROM "main".sqlite_master schema_object JOIN pragma_index_list(schema_object.name) table_index LEFT JOIN pragma_index_info(table_index.name) index_column WHERE schema_object.type = 'table' AND schema_object.name NOT LIKE 'sqlite_%' ORDER BY schema_object.name, table_index.name, index_column.seqno
getAllForeignKeysSql: SELECT schema_object.name AS table_name, 'fk_' || schema_object.name || '_' || foreign_key.id AS constraint_name, foreign_key."from" AS source_column, foreign_key."table" AS target_table, foreign_key."to" AS target_column FROM "main".sqlite_master schema_object JOIN pragma_foreign_key_list(schema_object.name) foreign_key WHERE schema_object.type = 'table' AND schema_object.name NOT LIKE 'sqlite_%' ORDER BY schema_object.name, foreign_key.id, foreign_key.seq
getTableStatusSql: SELECT name AS table_name, type AS table_type, CASE WHEN type = 'view' THEN 1 ELSE 0 END AS is_view FROM "main".sqlite_master WHERE type IN ('table', 'view') AND name NOT LIKE 'sqlite_%' AND name = 'users' ORDER BY name
getViewsSql: SELECT name AS view_name, NULL AS table_schema, sql AS view_definition, 0 AS materialized FROM main.sqlite_master WHERE type = 'view' ORDER BY name
getViewDefinitionSql: SELECT sql AS definition FROM main.sqlite_master WHERE type = 'view' AND name = 'users'
getIndexesSql: PRAGMA index_list("users")
getForeignKeysSql: PRAGMA foreign_key_list("users")
getReferencingForeignKeysSql: UNSUPPORTED: Capability not supported: fkeys on sqlite.
getTriggersSql: SELECT name, sql FROM sqlite_master WHERE type = 'trigger' AND tbl_name = 'users' ORDER BY name
getRoutinesSql: SELECT name, sql FROM sqlite_master WHERE type IN ('trigger', 'view') ORDER BY name
getCheckConstraintsSql: UNSUPPORTED: Capability not supported: check on sqlite.
