# vitals — Vital PHP String Format Toolkit (Specification v0.1)

## 1. Purpose

A userland component providing a uniform **build / parse / validate** API for every string-based mini-language in the PHP ecosystem, closing the gaps left by the native API (no `url_build()`, no DSN handling, no `ini_emit()`, no `serialize_validate()`, …).

## 2. Package identity


| Item            | Value                                                                     |
| --------------- | ------------------------------------------------------------------------- |
| Package name    | `dseguy/vitals`                                                           |
| Type            | library                                                                   |
| License         | MIT                                                                       |
| PHP requirement | >= 8.2                                                                    |
| Dependencies    | none required; suggests `ext-intl`, `ext-yaml`,       |
| Autoload        | PSR-4 `Vitals\\` → `src/`                                                 |
| CI              | GitHub Actions: matrix 8.2–8.4, PHPUnit + static analysis (Psalm/PHPStan/Mago) |


## 3. Core API model

Every format is a class implementing one or more of three contracts:

```php
interface Builder {
    /** @param mixed $parts structured representation, depends on the underlying type */
    public function build(mixed $parts): string;
}

interface Parser {
    /** @return mixed structured representation */
    public function parse(string $input): mixed;
}

interface Validator {
    public function validate(string $input): bool;
    public function get_last_error(): ?string;
}
```

Conventions:

- **Structured representation is an array**, not a format-specific object. This allows round-trip pipelines (`parse(build(parse($x))) === parse($x)`) and easy JSON export of any format's state.
- **Strictness is explicit**: every class takes a `Flags` bitmask or enum values in its constructor; default is the strictest sane mode.
- **Immutable objects**: created validation objects are immutable.
- **Input Limits**: set maximum input limits by default, lifted by explicit configuration. For example, nested 
- **Encoding**: encoding is UTF-8. 
- **Errors**: `parse()` throws `Vitals\Exception\FormatException` on malformed input and never returns partial arrays silently; `validate()` never throws on malformed input; `build()` throws `Vitals\BuildException` on impossible representations, such as URL port > 65535.
- **Delegation over duplication**: when a native function exists and behaves correctly, the class wraps it (e.g. JSON trio) and only adds the missing piece.
- **Round-trip guarantee**: `build(parse($s))` should produce a semantically equal string; property-test this in CI. `build(parse($s)) == $s` byte-equal where the format is canonical, semantic-equality otherwise. The impossible round trips should be documented, and possible round trips should be constructed if possible.  
- **Configuration**: when needed, directives are passed at the constructor call. They should get a default value as much as possible. 
- **Warnings**: native PHP warnings are catch with a scoped error handler, and turned into exceptions. No usage of `@`

## 4. Class catalog & phases

### Phase 1 — high-demand gaps (first release)


| Class                 | Wraps / implements                                                      | Notes                                                                                                                           |
| --------------------- | ----------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| `Format\Url`          | parse: `parse_url()` (fixed for partial failures); build + validate new | RFC 3986 validation incl. port range, IDN via `ext-intl` optional                                             |
| `Format\Dsn`          | nothing native                                                          | Grammar: `scheme:key=value;...` with driver-specific option maps (pdo-mysql, pdo-pgsql, mongodb, redis, amqp, smtp)             |
| `Format\Ini`          | parse: `parse_ini_string(INI_SCANNER_RAW)`; build new                   | `ini_emit()`-equivalent, section-aware, with configuration for build()                                                                                  |
| `Format\QueryString`  | `http_build_query()` / Rebuild `parse_str()` to validate                         | normalizes `[]` array keys.                                                                                                       |
| `Format\ByteSize`     | `ini_parse_quantity()` polyfill + build                                 | `128M` <-> int bytes. Multiplier is 1024. No garbage with warning.                                                                                                            |
| `Format\Serialize`    | `serialize()`; new `validate()`                                         | unserialize() is never called on untrusted input, set nesting-depth and length limits, and say how objects and references map into the array.     |
| `Format\Pattern\Pcre` | new validate (compile without match) + simple AST                       | `preg_match($p, '')` : for example `/^(?:$p)$/` on empty subject with error capture?                                            |


### Phase 2 — date & numeric


| Class                     | Notes                                                                                              |
| ------------------------- | -------------------------------------------------------------------------------------------------- |
| `Format\DateSpec`         | validate a `date()` format string, like for DateTime                     |
| `Format\DateIntervalSpec` | ISO-8601 duration `P1Y2M...` build/parse/validate (constructor wraps)                              |
| `Format\SprintfSpec`      | validate conversion specifiers; safe `sprintf` with positional-arg awareness                       |
| `Format\Numeric`          | numeric-string lint (PHP 8 rules, leading whitespace, `NAN`/`INF`)                                 |
| `Format\Locale`           | wraps intl `Locale::composeLocale`/`parseLocale` when available; pure-PHP fallback |


### Phase 3 — encodings & markup


| Class                 | Notes                                                                 |
| --------------------- | --------------------------------------------------------------------- |
| `Format\Charset`      | name validation against `mb_list_encodings()`/iconv (configuration, default to mb_string)                   |
| `Format\LdapDn`       | build/parse with full `\,+"><;` escaping. RFC 4514, including a leading #, leading and trailing spaces, and \XX hex escapes. |
| `Format\StreamUri`    | `php://filter/read=<filters>/resource=...` build/parse                |
| `Format\Glob`         | glob pattern builder (escapes meta chars). Omit GLOB_BRACE option                   |


## 5. Package layout

This is the list for phase 1. More later. 

```
src/
  Exception/FormatException.php // extends ValueError
  Exception/BuildException.php
  Exception/VitalsException.php // interface
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

- No HTML/XML parser.
- No SQL building (query builders exist).
- No gettext/ICU message catalogs (belongs to intl wrappers ecosystem).
- No function helpers (RFU)