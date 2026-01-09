# Database Access Pattern

## CRITICAL: Always Use QueryUtils

**NEVER use direct SQL functions from `sql.inc.php`**

All database operations must go through the `QueryUtils` class from OpenEMR's common library.

## ALWAYS DO:

```php
use OpenEMR\Common\Database\QueryUtils;

// Execute query and get all results
$records = QueryUtils::fetchRecords($sql, $binds);

// Execute query and get single row
$record = QueryUtils::querySingleRow($sql, $binds);

// Execute INSERT/UPDATE/DELETE (throws exception on error)
QueryUtils::sqlStatementThrowException($sql, $binds);

// Execute query without throwing (returns statement handle)
$result = QueryUtils::sqlStatement($sql, $binds);
```

## NEVER DO:

```php
// Direct SQL functions from sql.inc.php
$result = sqlStatement($sql, $binds);
$row = sqlFetchArray($result);
$result = sqlQuery($sql, $binds);
sqlInsert($sql);
sqlBind($sql, $binds);

// These should NEVER appear in module code!
```

## QueryUtils Methods Reference

| Method | Purpose | Returns | Throws |
|--------|---------|---------|--------|
| `fetchRecords($sql, $binds)` | Get all rows as array | `array<int, array<string, mixed>>` | On error |
| `querySingleRow($sql, $binds)` | Get single row | `array<string, mixed>` | On error |
| `sqlStatementThrowException($sql, $binds)` | Execute statement (INSERT/UPDATE/DELETE) | Statement handle | On error |
| `sqlStatement($sql, $binds)` | Execute without throwing | Statement handle | No |

## Examples

**Fetching multiple records:**
```php
$sql = "SELECT * FROM oce_sinch_faxes WHERE status = ? ORDER BY created_at DESC LIMIT ?";
$faxes = QueryUtils::fetchRecords($sql, ['COMPLETED', 50]);

foreach ($faxes as $fax) {
    echo $fax['sinch_fax_id'];
}
```

**Fetching a single record:**
```php
$sql = "SELECT * FROM oce_sinch_faxes WHERE id = ?";
$fax = QueryUtils::querySingleRow($sql, [$faxId]);

if ($fax) {
    echo $fax['status'];
}
```

**Executing INSERT/UPDATE/DELETE:**
```php
$sql = "UPDATE oce_sinch_faxes SET status = ?, updated_at = NOW() WHERE id = ?";
QueryUtils::sqlStatementThrowException($sql, ['COMPLETED', $faxId]);
```

## Why QueryUtils?

1. **Consistency** - Single interface for all database operations
2. **Error Handling** - Proper exception throwing with context
3. **Security** - Prepared statements with parameter binding
4. **Maintainability** - Easier to test and refactor
5. **Type Safety** - Better static analysis support

If you use direct SQL functions, they will fail the Composer Require Checker because they shouldn't be in the whitelist.
