const fs = require("node:fs");
const path = require("node:path");
const assert = require("node:assert/strict");
const { Parser } = require("@dbml/core");
const root = path.resolve(__dirname, "../..");
const cases = [
    ["core", "core/schema.catalog.json", "core/Core_v2.dbml"],
    [
        "platform_runtime",
        "core/schema.catalog.json",
        "runtime/Platform_Runtime_v1.dbml",
    ],
    [
        "commercial",
        "commercial/schema.catalog.json",
        "commercial/Commercial_ControlPlane_v2.dbml",
    ],
    ["education", "education/schema.catalog.json", "education/Education_v1.dbml"],
];
for (const [schema, catalogFile, dbmlFile] of cases) {
    const catalog = JSON.parse(
        fs.readFileSync(path.join(root, "docs/database", catalogFile), "utf8"),
    );
    const db = Parser.parse(
        fs.readFileSync(path.join(root, "docs/database", dbmlFile), "utf8"),
        "dbml",
    );
    const parsed = db.schemas.flatMap((s) =>
        s.tables.map((t) => ({ schema: s.name, table: t })),
    );
    const expected = catalog.tables.filter((t) => t.schema === schema);
    assert.equal(parsed.length, expected.length, dbmlFile + " table count");
    for (const table of expected) {
        const actual = parsed.find(
            (t) => t.schema === schema && t.table.name === table.name,
        )?.table;
        assert.ok(actual, "Missing " + schema + "." + table.name);
        assert.deepEqual(
            actual.fields.map((f) => f.name),
            table.columns.map((c) => c.name),
            table.name + " columns",
        );
        for (const column of table.columns) {
            const field = actual.fields.find((f) => f.name === column.name);
            const label = schema + "." + table.name + "." + column.name;
            assert.equal(
                Boolean(field.not_null),
                !column.nullable,
                label + " nullability",
            );
            const type =
                (field.type.schemaName ? field.type.schemaName + "." : "") +
                field.type.type_name;
            assert.equal(
                type,
                column.is_enum
                    ? column.type_schema + "." + column.type_name
                    : column.type,
                label + " PostgreSQL type",
            );
            assert.equal(
                field.dbdefault?.value ?? null,
                column.default,
                label + " default",
            );
        }
        const primary =
            table.constraints.find((c) => c.kind === "p")?.columns ?? [];
        const parsedPrimary =
            actual.indexes.find((i) => i.pk)?.columns.map((c) => c.value) ??
            actual.fields.filter((f) => f.pk).map((f) => f.name);
        assert.deepEqual(parsedPrimary, primary, table.name + " primary key");
        const names = new Set(table.columns.map((c) => c.name));
        const represented = table.indexes.filter(
            (i) =>
                !i.primary &&
                !i.predicate &&
                i.key_count === i.attribute_count &&
                ["btree", "hash"].includes(i.method) &&
                i.keys.every((k) => names.has(k)),
        );
        assert.equal(
            actual.indexes.filter((i) => !i.pk).length,
            represented.length,
            table.name + " representable indexes",
        );
        for (const index of represented) {
            const parsedIndex = actual.indexes.find(
                (i) => i.name === index.name,
            );
            assert.ok(parsedIndex, table.name + " index " + index.name);
            assert.equal(
                Boolean(parsedIndex.unique),
                index.unique,
                index.name + " uniqueness",
            );
            assert.equal(
                parsedIndex.type,
                index.method,
                index.name + " method",
            );
            assert.deepEqual(
                parsedIndex.columns.map((c) => c.value),
                index.keys,
                index.name + " columns",
            );
        }
    }
    const refs = db.schemas.flatMap((s) => s.refs);
    const foreignKeys = expected.flatMap((t) =>
        t.constraints
            .filter((c) => c.kind === "f")
            .map((c) => ({ table: t.name, constraint: c })),
    );
    assert.equal(refs.length, foreignKeys.length, dbmlFile + " foreign keys");
    for (const { table, constraint: c } of foreignKeys) {
        const actions = {
            a: "no action",
            r: "restrict",
            c: "cascade",
            n: "set null",
            d: "set default",
        };
        assert.ok(
            refs.some(
                (r) =>
                    r.endpoints.some(
                        (e) =>
                            e.relation === "*" &&
                            e.schemaName === schema &&
                            e.tableName === table &&
                            JSON.stringify(e.fieldNames) ===
                                JSON.stringify(c.columns),
                    ) &&
                    r.endpoints.some(
                        (e) =>
                            e.relation === "1" &&
                            e.schemaName === c.target_schema &&
                            e.tableName === c.target_table &&
                            JSON.stringify(e.fieldNames) ===
                                JSON.stringify(c.target_columns),
                    ) &&
                    r.onDelete === actions[c.on_delete] &&
                    r.onUpdate === actions[c.on_update],
            ),
            table + " FK " + c.name + " and referential actions",
        );
    }
    for (const enumType of catalog.enums.filter((e) => e.schema === schema)) {
        const actual = db.schemas
            .find((s) => s.name === schema)
            ?.enums.find((e) => e.name === enumType.name);
        assert.ok(actual, schema + "." + enumType.name + " enum");
        assert.deepEqual(
            actual.values.map((v) => v.name),
            enumType.values,
            enumType.name + " enum values",
        );
    }
    console.log(
        "PASS",
        dbmlFile,
        expected.length + " tables",
        foreignKeys.length + " physical foreign keys",
    );
}
