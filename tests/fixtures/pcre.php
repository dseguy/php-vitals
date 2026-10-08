<?php

// Corpus for Format\Pattern\Pcre.
return [
    'valid' => ['/a+/', '/a+/i', '#^\d{3}$#', '~x~imsxADSUXJun', '(a(b)c)', '{a{1,2}}', '[a]', '<a>', '/a\/b/', '/é/u', '//', '%(?<name>x)\k<name>%'],
    'invalid' => [
        '' => 'common.empty_input',
        'abc' => 'pcre.invalid_delimiter',
        ' /a/' => 'pcre.invalid_delimiter',
        '\\a\\' => 'pcre.invalid_delimiter',
        '/abc' => 'pcre.missing_end_delimiter',
        '(a(b)' => 'pcre.missing_end_delimiter',
        '/a/e' => 'pcre.unknown_modifier',
        '/a/b/' => 'pcre.unknown_modifier',
        '/a/ i' => 'pcre.unknown_modifier',
        '/(a/' => 'pcre.compile_error',
        '/a{2,1}/' => 'pcre.compile_error',
        '/[z-a]/' => 'pcre.compile_error',
        "/\xff/u" => 'pcre.compile_error',
    ],
];
