# Boxlet's Opengrep rules

The security rules Boxlet keeps, written so a machine checks them on every push
(`.github/workflows/security.yml`). Each rule is a `.yaml` file in `rules/`, with a `.php` file of
the same name beside it: the lines marked `// ruleid:` it must find, the lines marked `// ok:` it
must leave.

| Rule | What it refuses |
| --- | --- |
| `boxlet-superglobals` | `$_GET`, `$_POST`, `$_COOKIE`, `$_REQUEST`, `$_FILES` outside `App\Core\Request` |
| `boxlet-no-shell` | `exec` and its kin, `eval`, `create_function`, `unserialize` |
| `boxlet-admin-route` | an `/admin` route without `$requireAdmin`, but for logging in and resetting |
| `boxlet-sql-built` | SQL text built from variables, but for a list of `?` named `$marks` or `$placeholders` |
| `boxlet-request-to-file` | a value from the request reaching a file path |
| `boxlet-request-to-redirect` | a value from the request deciding where a redirect goes |
| `boxlet-request-to-html` | a value from the request written into HTML unescaped |
| `boxlet-ip-stored` | a visitor's IP address stored without hashing it |

## Running them

With [Opengrep](https://github.com/opengrep/opengrep/releases) (the version the workflow pins):

```sh
opengrep test tools/opengrep/rules
opengrep scan -f tools/opengrep/rules --taint-intrafile --error app public config bin
```

## A finding that is not one

Say why on the line above, then silence that rule for the next line only:

```php
// Table names from the literal list in the loop.
// nosemgrep: boxlet-sql-built
$db->query("DELETE FROM {$table}");
```

A reason is never left out: the next person to read the line has to be able to check it.
