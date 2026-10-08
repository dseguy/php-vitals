<?php

// Corpus for Format\QueryString.
return [
    'valid' => ['', 'a=1', 'a=1&b=2', 'a', 'a=', 'a=1&&b=2', 'a[]=1&a[]=2', 'a[x]=1&a[y][z]=2', 'a+b=c+d', 'a%20b=%C3%A9', 'a.b=1', '0=x&1=y', 'a[01]=1', 'a=1&a=2', 'a[0]=1&a[1]=2', ' a=1', 'a=%2B', 'a=b=c'],
    'invalid' => [
        '=1' => 'qs.empty_key',
        'a=1&=2' => 'qs.empty_key',
        '[x]=1' => 'qs.empty_key',
        'a[b=1' => 'qs.unbalanced_brackets',
        'a]=1' => 'qs.unbalanced_brackets',
        'a[b[c]]=1' => 'qs.unbalanced_brackets',
        'a[b]c=1' => 'common.unexpected_character',
        'a=1&a[]=2' => 'qs.mixed_value_types',
        'a[]=1&a=2' => 'qs.mixed_value_types',
        'a[x]=1&a[x][y]=2' => 'qs.mixed_value_types',
        'a=%zz' => 'qs.invalid_percent_encoding',
        'a=%' => 'qs.invalid_percent_encoding',
        'a=%4' => 'qs.invalid_percent_encoding',
        '%g1=1' => 'qs.invalid_percent_encoding',
        'a[1][2][3][4][5][6][7][8][9][10][11]=1' => 'common.too_deep',
    ],
];
