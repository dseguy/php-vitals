# vitals — Vital PHP String Format Toolkit (Specification v0.1)

## 1. Purpose

A userland component providing a uniform **build / parse / validate** API for every string-based mini-language in the PHP ecosystem, closing the gaps left by the native API (no `url_build()`, no DSN handling, no `ini_emit()`, no `serialize_validate()`, …).

## 2. Package identity


| Item            | Value                                                                     |
| --------------- | ------------------------------------------------------------------------- |
| Package name    | `dseguy/vitals` (adjust vendor)                                           |
| Type            | library                                                                   |
| License         | MIT                                                                       |
| PHP requirement | &gt;= 8.1                                                                 |
| Dependencies    | none required; suggests `ext-intl`, `ext-yaml`, `symfony/polyfill-*`      |
| Autoload        | PSR-4 `Vitals\\` → `src/`                                                 |
| CI              | GitHub Actions: matrix 8.1–8.4, PHPUnit + static analysis (Psalm/PHPStan) |


## 3. Core API model

Every format is a class implementing one or more of three contracts:

```php
interface Builder {
    /** @param array<string,mixed> $parts structured representation */
    public function build(array $parts): string;
}

interface Parser {
    /** @return array<string,mixed> structured representation */
    public function parse(string $input): array;
}

interface Validator {
    public function validate(string $input): bool;
}
```

Conventions:

- **Structured representation is an array**, not a format-specific object. This allows round-trip pipelines (`parse(build(parse($x))) === parse($x)`) and easy JSON export of any format's state.
- **Strictness is explicit**: every class takes a `Flags` bitmask (or an options array) in its constructor; default is the strictest sane mode.
- **Errors**: `parse()` throws `Vitals\FormatException` on malformed input (never returns partial arrays silently); `validate()` never throws on malformed input; `build()` throws `Vitals\BuildException` on impossible representations (e.g. URL port &gt; 65535).
- **Delegation over duplication**: when a native function exists and behaves correctly, the class wraps it (e.g. JSON trio) and only adds the missing piece.
- **Round-trip guarantee**: for every format, `build(parse($s))` must produce a semantically equal string; property-test this in CI (`build(parse($s)) == $s` byte-equal where the format is canonical, semantic-equality otherwise).

## 4. Class catalog &amp; phases

### Phase 1 — high-demand gaps (first release)


| Class                 | Wraps / implements                                                      | Notes                                                                                                                           |
| --------------------- | ----------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| `Format\Url`          | parse: `parse_url()` (fixed for partial failures); build + validate new | RFC 3986 validation incl. scheme whitelist, port range, IDN via `ext-intl` optional                                             |
| `Format\Dsn`          | nothing native                                                          | Grammar: `scheme:key=value;...` with driver-specific option maps (pdo-mysql, pdo-pgsql, mongodb, redis, amqp, smtp)             |
| `Format\Ini`          | parse: `parse_ini_string(INI_SCANNER_TYPED)`; build new                 | `ini_emit()`-equivalent, section-aware                                                                                          |
| `Format\QueryString`  | `http_build_query()` / `parse_str()` + validate                         | normalizes `[]` array keys                                                                                                      |
| `Format\ByteSize`     | `ini_parse_quantity()` polyfill + build                                 | `128M` &lt;-&gt; int bytes                                                                                                      |
| `Format\Serialize`    | `serialize()`; new `validate()`                                         | No-op validation to detect truncated/corrupt payloads without object instantiation                                              |
| `Format\Pattern\Pcre` | new validate (compile without match) + simple AST                       | `preg_validate($p)`: try `/^(?:$p)$/` on empty subject with error capture? No — must compile standalone via cache of last error |


### Phase 2 — date &amp; numeric


| Class                     | Notes                                                                                              |
| ------------------------- | -------------------------------------------------------------------------------------------------- |
| `Format\DateSpec`         | validate a `date()` format string (spec tokens), build/parse timestamp strings                     |
| `Format\DateIntervalSpec` | ISO-8601 duration `P1Y2M...` build/parse/validate (constructor wraps)                              |
| `Format\SprintfSpec`      | validate conversion specifiers; safe `sprintf` with positional-arg awareness                       |
| `Format\Numeric`          | numeric-string lint (PHP 8 rules, leading whitespace, `NAN`/`INF`)                                 |
| `Format\Locale`           | wraps intl `Locale::composeLocale`/`parseLocale` when available; pure-PHP fallback (BCP 47 subset) |


### Phase 3 — encodings &amp; markup


| Class                 | Notes                                                                 |
| --------------------- | --------------------------------------------------------------------- |
| `Format\HtmlFragment` | libxml-based well-formedness check; escape/unescape wrappers          |
| `Format\Charset`      | name validation against `mb_list_encodings()`/iconv                   |
| `Format\LdapDn`       | build/parse with full `\,+"><;` escaping (mirror `ldap_explode_dn()`) |
| `Format\StreamUri`    | `php://filter/read=<filters>/resource=...` build/parse                |
| `Format\Glob`         | glob pattern builder (escapes meta chars) + explain                   |


## 5. Package layout

```
src/
  Exception/FormatException.php
  Exception/BuildException.php
  Builder.php  Parser.php  Validator.php
  Format/
    Url.php  Dsn.php  Ini.php  QueryString.php
    ByteSize.php  Serialize.php  Pattern/Pcre.php
tests/
  Unit/  Property/   (round-trip property tests)
  fixtures/          (corpus of valid/invalid samples per format)
```

## 6. Testing strategy

1. **Unit tests** per format against curated valid/invalid fixture corpora.
2. **Round-trip property tests**: `parse(build($x)) == $x` for generated `$x`; byte-equality when format is canonical (DSN, query string with sorted keys).
3. **Differential tests vs native**: when wrapping native functions, assert same outputs on corpus, and document *intentional* divergences (e.g. `parse_url()` quirk fixes).
4. **Fuzzing** (optional phase 2): malformed inputs never throw anything but `FormatException`.

## 7. Non-goals

- No full HTML/XML parser (use DOM).
- No SQL building (query builders exist).
- No gettext/ICU message catalogs (belongs to intl wrappers ecosystem).