# Homepage domain tests

Runs the Homepage Manager's domain and application rules with **no WordPress and no database** —
which is the whole payoff of the ports design. `wp-stubs.php` supplies the handful of WordPress
functions the sanitiser touches; everything else is the real module code.

```bash
php tests/homepage-domain/run.php
```

Exit code 0 = every assertion passed. What it proves:

- a key the schema does not declare never reaches storage
- `javascript:` URLs, SVG attachments and out-of-range numbers are refused
- a field the actor may not write keeps its stored value **and is reported back**
- `max_instances` holds, a partial reorder is refused, a duplicate gets a new id
- publishing is the only thing that changes what a visitor sees
- an **empty document is valid** — the backward-compatibility guarantee
