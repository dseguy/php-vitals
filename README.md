# vitals

Build, parse and validate PHP's string mini-languages with one API.
See [specs.md](specs.md) for the full specification.

```bash
composer require dseguy/vitals
```

Phase 1 formats: `Format\Url`, `Format\Ini`, `Format\QueryString`, `Format\ByteSize`,
`Format\Serialize`, `Format\Pattern\Pcre`.

```php
use Vitals\Format\ByteSize;
use Vitals\Format\Serialize;
use Vitals\Flag\SerializeFlag;

$size = new ByteSize();
$size->parse('128M');                 // ['bytes' => 134217728, 'value' => 128, 'unit' => 'M']
$size->build(['bytes' => 1536]);      // '1536'
$size->validate('0x10');              // false

$violation = $size->check('12Mx');    // Vitals\Violation
$violation->code->value;              // 'common.trailing_data'
$violation->offset;                   // 3

// validate untrusted serialize() payloads without instantiating anything
(new Serialize(SerializeFlag::NoObjects))->validate($payload);
```

- `parse()` throws `Vitals\Exception\FormatException`, which carries the same `Violation` as `check()`.
- `build()` throws `Vitals\Exception\BuildException`.
- Constructors take flags and options, and throw `Vitals\Exception\ConfigurationException` when they are invalid.

## Development

```bash
composer update
vendor/bin/phpunit
vendor/bin/mago lint && vendor/bin/mago analyze
```
