<?php

// Corpus for Format\ByteSize: valid inputs, and invalid inputs with their violation code.
return [
    'valid' => ['0', '1', '-1', '128M', '128m', '1K', '2G', '1024', '9223372036854775807', '-9223372036854775808', '8589934591G'],
    'invalid' => [
        '' => 'common.empty_input',
        ' 1' => 'bytesize.invalid_number',
        'M' => 'bytesize.invalid_number',
        '-' => 'bytesize.invalid_number',
        '1.5M' => 'bytesize.invalid_unit',
        '010' => 'bytesize.invalid_prefix',
        '0x10' => 'bytesize.invalid_prefix',
        '0b1' => 'bytesize.invalid_prefix',
        '0o7' => 'bytesize.invalid_prefix',
        '1T' => 'bytesize.invalid_unit',
        '12Mx' => 'common.trailing_data',
        '1 ' => 'bytesize.invalid_unit',
        '9223372036854775808' => 'bytesize.overflow',
        '8589934592G' => 'bytesize.overflow',
    ],
];
