# vitals — Vital PHP String Format Toolkit (Specification v0.1.2)

## 1. Purpose

A userland component providing a uniform **build / parse / validate** API for every string-based mini-language in the PHP ecosystem, closing the gaps left by the native API (no `url_build()`, no `ini_emit()`, no `serialize_validate()`, …).

## 2. Package identity


| Item            | Value                                                                     |
| --------------- | ------------------------------------------------------------------------- |
| Package name    | `dseguy/vitals`                                                           |
| Type            | library                                                                   |
| License         | MIT                                                                       |
| PHP requirement | >= 8.2                                                                    |
| Dependencies    | none required; suggests `ext-intl`, `ext-mbstring`, `ext-iconv`       |
| Autoload        | PSR-4 `Vitals\\` → `src/`                                                 |
| CI              | GitHub Actions: matrix 8.2–8.5 (8.6 when available), PHPUnit + static analysis (Mago) |


## 3. Core API model

Every format is a class implementing one or more of three contracts:

```php
interface Builder {
    /** @param array<string|int, mixed> $parts structured representation */
    public function build(array $parts): string;
}

interface Parser {
    /** @return array<string|int, mixed> structured representation */
    public function parse(string $input): array;
}

interface Validator {
    public function validate(string $input): bool;
    public function check(string $input): ?Violation;
}

final readonly class Violation {
    public function __construct(
        public ViolationCode $code,
        public string $message,
        public ?int $offset = null,   // byte offset; some violations have no position
    ) {}
}

```

Conventions:

- **Structured representation is an array**, not a format-specific object. This allows round-trip pipelines (`parse(build(parse($x))) == parse($x)`).
- **Strictness is explicit**: every class takes a `Flags` enum values in its constructor; default is the strictest sane mode.
- **Immutable objects**: created validation and format objects are immutable.
- **Input Limits**: set maximum input limits by default, lifted by explicit configuration. Default at 10.
- **Encoding**: encoding is UTF-8, except for binary payloads. 
- **Errors**: `parse()` throws `Vitals\Exception\FormatException` on malformed input and never returns partial arrays silently; `validate()` never throws on malformed input; `build()` throws `Vitals\Exception\BuildException` on impossible representations, such as URL port > 65535.
- **Delegation over duplication**: when a native function exists and behaves correctly, the class wraps it (e.g. JSON trio) and only adds the missing piece.
- **Round-trip guarantee**: `parse(build(parse($s)))` must produce a semantically equal parsed string; property-test this in CI. `build(parse($s)) == $s` byte-equal where the format is canonical, semantic-equality otherwise. The impossible round trips should be documented, and possible round trips should be constructed if possible.  
- **Configuration**: when needed, directives are passed at the constructor call. They should get a default value as much as possible. 
- **Warnings**: native PHP warnings are caught with a scoped error handler, and turned into exceptions. No usage of `@`. validate() and check() turn those exceptions into a false result or a Violation.
- **Missing ext-intl**: throw an exception, that is not supported.

## 4. Class catalog & phases

### Phase 1 — high-demand gaps (first release)


| Class                 | Wraps / implements                                                      | Notes                                                                                                                           |
| --------------------- | ----------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| `Format\Url`          | parse: `parse_url()` (fixed for partial failures); build + validate new | RFC 3986 validation by default incl. port range, IDN via `ext-intl` when available (or throw exception). Using constructor to choose WhatWG. On older versions, RFC 3986 and Whatwg throw exceptions.  Write RFC 3986 parsing in pure PHP for PHP 8.2 to 8.4 versions.                                       |
| `Format\Ini`          | build own parser.                   | `ini_emit()`-equivalent, section-aware, with configuration for build(), to configure the form of true, null and key[] writing (one configuration for each type)                                                                                  |
| `Format\QueryString`  | `http_build_query()` / Rebuild `parse_str()` to validate                         | normalizes `[]` array keys.                                                                                                       |
| `Format\ByteSize`     | `ini_parse_quantity()`                                | `128M` <-> int bytes. Multiplier is 1024.                                                                                                            |
| `Format\Serialize`    | `serialize()`; new `validate()`                                         | unserialize() is never called on untrusted input, set nesting-depth and length limits. References are not supported. NAN does not support the round trip test.    |
| `Format\Pattern\Pcre` | new validate (compile without match), No AST.                       | `preg_match($p, '')` under a scoped error handler  |


### Phase 2 — date & numeric


| Class                     | Notes                                                                                              |
| ------------------------- | -------------------------------------------------------------------------------------------------- |
| `Format\DateSpec`         | validate a `date()` format string. Use DateTime::format() token docs                     |
| `Format\DateIntervalSpec` | ISO-8601 duration `P1Y2M...` build/parse/validate (constructor wraps)                              |
| `Format\SprintfSpec`      | validate conversion specifiers;                      |
| `Format\Numeric`          | numeric-string lint (PHP 8 rules, leading and trailing whitespace, `NAN`/`INF`)                                 |
| `Format\Locale`           | wraps intl `Locale::composeLocale`/`parseLocale` when available; use ICU locale |


### Phase 3 — encodings & markup


| Class                 | Notes                                                                 |
| --------------------- | --------------------------------------------------------------------- |
| `Format\Charset`      | name validation against `mb_list_encodings()`/iconv (configuration, default to mb_string)                   |
| `Format\LdapDn`       | build/parse with full `\,+"><;` escaping. RFC 4514, including a leading #, leading and trailing spaces, and \XX hex escapes. |
| `Format\StreamUri`    | build/parse/validate PHP stream wrapper URIs: `php://*`, `data:`, `compress.zlib://`, `compress.bzip2://`, `zip://`, `phar://`, `glob://`. URL-shaped wrappers are delegated to `Format\Url`. Validation allowlists wrappers and filters (security). `php://*` and `data:` first; archive wrappers may follow in a later release |
| `Format\Glob`         | glob pattern builder (escapes meta chars, depending on platform). Omit GLOB_BRACE option. n Windows, `build()` throws BuildException when a literal segment contains `*`, `?` or `[`, because Windows has no escape character.    |


## 5. Parsed data shapes

The array returned by `parse()` and accepted by `build()`, per format, in array-shape notation.

Common rules:

- **Keys are always present** in `parse()` output. An absent component is `null`, a present-but-empty one is `''` (or `[]`), so both survive a round trip.
- **`build()` accepts missing keys** and treats them as `null`. Unknown keys throw `BuildException`.
- **Values are scalars, `null`, or arrays** of the same.
- **Values are not decoded further**: each format decodes its own escaping layer (percent-encoding in a query string, \XX in LDAP, quotes in INI) and nothing more; Url components stay percent-encoded exactly as in the input.

### Phase 1

#### `Format\Url`

```php
array{
    scheme:   ?string,  // lowercased, without ':'
    user:     ?string,
    pass:     ?string,
    host:     ?string,  // IPv6 literal kept with its brackets
    port:     ?int,     // 0–65535
    path:     string,   // always present, '' when empty
    query:    ?string,  // raw, without '?'; feed it to Format\QueryString
    fragment: ?string,  // without '#'
}
```

Same keys as `parse_url()`, in the three modes (RFC 3986, WhatWG, `parse_url`).

#### `Format\Ini`

```php
array{
    global:   array<string, IniValue>,                 // directives before the first section
    sections: array<string, array<string, IniValue>>,  // section name => directives, in file order
}

// IniValue
string | array<int|string, string>   // key[] = v  and  key[name] = v
```

`parse()` only yields strings and uses raw semantics: no type conversion, no `${VAR}` or constant expansion. `build()` also accepts `bool`, `null`, `int` and `float` as values, written according to the constructor configuration.

`global` and `sections` are separate keys because the native merged array cannot tell a section from a `key[]` directive of the same name.

#### `Format\QueryString`

```php
array<int|string, QueryValue>

// QueryValue
string | array<int|string, QueryValue>
```

Keys are the decoded parameter names, in order of appearance. `a[]=1&a[]=2` yields `['a' => ['1', '2']]`; `a[b]=1` yields `['a' => ['b' => '1']]`. A parameter without `=` has the value `''`. Nesting throws FormatException at the input limit.

Repeated keys without [] are overwriting the previous values: only the last is kept. This means the round trip is not possible.

For build, it uses the RFC 3986 with %20 for spaces, or RFC 1738 with a `+` for space, with constructor configuration.

`.` and spaces in keys are converted, as per PHP rules. This will not be reversible.

#### `Format\ByteSize`

```php
array{
    bytes: int,               // value * multiplier(unit)
    value: int,               // number as written
    unit:  ''|'K'|'M'|'G',    // uppercased; '' for plain bytes
}
```

`build()` uses `value` and `unit`; when only `bytes` is given, it emits the largest unit that divides it exactly. `bytes` conflicting with `value` and `unit` throws `BuildException`.

Negative numbers are supported. `0x`/`0o`/`0b` prefixes are rejected, and that overflow beyond PHP_INT_MAX throws.

Trailing garbage, such as `12Mx` throws FormatException.

#### `Format\Serialize`

A tree of nodes, each tagged by `type`:

```php
// SerializeNode
array{type: 'null'}
| array{type: 'bool',   value: bool}
| array{type: 'int',    value: int}
| array{type: 'float',  value: float}
| array{type: 'string', value: string}
| array{type: 'array',  items: list<array{key: int|string, value: SerializeNode}>}
| array{type: 'object', class: string, properties: list<array{
      name:       string|int,                          // without the \0 mangling
      visibility: 'public'|'protected'|'private',
      value:      SerializeNode,
      declaringClass: ?string, //`declaringClass` is required for private properties and null otherwise
  }>}
| array{type: 'custom', class: string, data: string}   // C: Serializable payload, kept opaque
| array{type: 'enum',   class: string, case: string}   // E:
```

`items` and `properties` are lists of pairs, not maps, to keep the original order and the int/string distinction of keys. `R:` and `r:` references throw `FormatException` and are unsupported.

This is a canonical format within PHP `serialize_precision`.

#### `Format\Pattern\Pcre`

```php
array{
    delimiter: string,     // opening delimiter; the closing one is derived: ( ) [ ] { } < >
    pattern:   string,     // between the delimiters, verbatim
    modifiers: string,     // e.g. 'iu', in order of appearance
}
```

### Phase 2

#### `Format\DateIntervalSpec`

```php
array{
    years: int, months: int, weeks: int, days: int,
    hours: int, minutes: int, seconds: int,
}
```

All values are `>= 0`; a component missing from the string is `0`.

Fractional seconds (PT1.5S, valid ISO 8601 but rejected by DateInterval) and negative intervals are rejected.

#### `Format\Locale`

```php
array{
    language:   string,
    script:     ?string,
    region:     ?string,
    variants:   list<string>,
    keywords: array<string,string>,
}
```

Use ICU locales.

### Phase 3

#### `Format\LdapDn`

```php
list<                      // RDNs, left to right
    list<array{            // one entry per attribute; more than one for multi-valued RDNs (a=1+b=2)
        type:  string,     // attribute name or OID
        value: string,     // unescaped; hex digits when hex is true
        hex:   bool,       // true for '#'-prefixed values
    }>
>
```

#### `Format\StreamUri`

A tagged union on `wrapper`:

```php
// php://
  array{wrapper: 'php', target: 'stdin'|'stdout'|'stderr'|'input'|'output'|'memory'}
| array{wrapper: 'php', target: 'fd',     fd: int}                    // php://fd/3
| array{wrapper: 'php', target: 'temp',   maxMemory: ?int}            // php://temp/maxmemory:1048576, in bytes
| array{wrapper: 'php', target: 'filter',
      read:     list<string>,   // read=a|b
      write:    list<string>,   // write=a|b
      both:     list<string>,   // filters given without read= or write=
      resource: string,         // after resource=, verbatim
  }

// data: (RFC 2397), with or without '//'
| array{wrapper: 'data', mediaType: string, parameters: array<string, string>, base64: bool, data: string}

// compression and archives
| array{wrapper: 'compress.zlib'|'compress.bzip2', resource: string}  // compress.zlib://file.gz
| array{wrapper: 'zip',  archive: string, entry: ?string}             // zip://archive.zip#dir/entry.txt
| array{wrapper: 'phar', archive: string, path: string}               // phar://app.phar/src/x.php
| array{wrapper: 'glob', pattern: string}                             // glob://*.txt; pattern follows Format\Glob

// any other wrapper: extension ones (ssh2.*, rar, expect…) or registered with stream_wrapper_register()
| array{wrapper: string, target: string}                              // target kept opaque
```

Rules:

- **URL-shaped wrappers** (`file://`, `http(s)://`, `ftp(s)://`) are not parsed here: use `Format\Url`. `StreamUri` still validates them against the wrapper allowlist.
- **Nesting**: `resource`, `archive` and `pattern` are plain strings. A nested stream URI, such as `php://filter/read=string.rot13/resource=compress.zlib://phar://a.phar/x.gz`, is parsed by calling `parse()` again on that string. Validation follows the whole chain, within the nesting-depth limit.
- **Filter names** are URL-decoded, as PHP does.
- **phar split**: `archive` ends at the first path segment containing `.phar`. Archives whose name has no `.phar` (aliases) can't be split, and are documented as a known limitation.
- **`data:` payload**: `data` is the payload as written (still base64 when `base64` is true); decoding is left to the caller.

Validation configuration (constructor), because `php://filter` chains and `phar://` are common attack vectors (file inclusion turned into code execution, deserialization of phar metadata):

| Directive          | Default                                   |
| ------------------ | ----------------------------------------- |
| allowed wrappers   | `file` only (plain paths and `file://`)   |
| allowed filters    | none; upper bound is `stream_get_filters()` |
| max filter chain   | 5 filters                                 |
| max nesting depth  | 3 wrappers                                |

### Formats without a parsed shape

`Format\DateSpec`, `Format\SprintfSpec`, `Format\Numeric` and `Format\Charset` only validate. `Format\Glob` only builds, from a `list<array{literal: string} | array{pattern: string}>`: `literal` segments are escaped, `pattern` segments are emitted verbatim.

## 6. Package layout

This is the list for phase 1. More later. 

```
src/
  Exception/FormatException.php // extends UnexpectedValueException
  Exception/BuildException.php  // extends InvalidArgumentException
  Exception/VitalsException.php // interface for both exceptions
  Builder.php  Parser.php  Validator.php Violation.php ViolationCode.php
  Format/
    Url.php Ini.php  QueryString.php
    ByteSize.php  Serialize.php  Pattern/Pcre.php
tests/
  Unit/  Property/   (round-trip property tests)
  fixtures/          (corpus of valid/invalid samples per format)
```



## 7. Testing strategy

1. **Unit tests** per format against curated valid/invalid fixture corpora.
2. **Round-trip property tests**: `parse(build(parse($x))) == parse($x)` for generated `$x`; byte-equality when format is canonical.
3. **Differential tests vs native**: when wrapping native functions, assert same outputs on corpus, and document *intentional* divergences (e.g. `parse_url()` quirk fixes).
4. **Fuzzing** (optional phase 2): malformed inputs never throw anything but `FormatException`.

## 8. Non-goals

- No HTML/XML parser.
- No DSN handling.
- No SQL building (query builders exist).
- No gettext/ICU message catalogs (belongs to intl wrappers ecosystem).
- No function helpers.