<?php

declare(strict_types=1);

namespace Vitals;

/**
 * Stable identifiers of validation failures. The value is the public identifier;
 * new cases may be added in minor versions, so do not match on this enum exhaustively.
 */
enum ViolationCode: string
{
    // common
    case CommonEmptyInput = 'common.empty_input';
    case CommonInputTooLong = 'common.input_too_long';
    case CommonTooDeep = 'common.too_deep';
    case CommonTooManyItems = 'common.too_many_items';
    case CommonInvalidUtf8 = 'common.invalid_utf8';
    case CommonUnexpectedCharacter = 'common.unexpected_character';
    case CommonUnexpectedEnd = 'common.unexpected_end';
    case CommonTrailingData = 'common.trailing_data';
    case CommonNativeError = 'common.native_error';

    // url
    case UrlMissingScheme = 'url.missing_scheme';
    case UrlInvalidScheme = 'url.invalid_scheme';
    case UrlInvalidUserinfo = 'url.invalid_userinfo';
    case UrlInvalidHost = 'url.invalid_host';
    case UrlInvalidIpv4 = 'url.invalid_ipv4';
    case UrlInvalidIpv6 = 'url.invalid_ipv6';
    case UrlInvalidIdn = 'url.invalid_idn';
    case UrlInvalidPort = 'url.invalid_port';
    case UrlPortOutOfRange = 'url.port_out_of_range';
    case UrlInvalidPath = 'url.invalid_path';
    case UrlInvalidCharacter = 'url.invalid_character';
    case UrlInvalidPercentEncoding = 'url.invalid_percent_encoding';
    case UrlDisallowedScheme = 'url.disallowed_scheme';

    // ini
    case IniUnterminatedSection = 'ini.unterminated_section';
    case IniInvalidSectionName = 'ini.invalid_section_name';
    case IniMissingEquals = 'ini.missing_equals';
    case IniInvalidKey = 'ini.invalid_key';
    case IniReservedKey = 'ini.reserved_key';
    case IniInvalidArrayKey = 'ini.invalid_array_key';
    case IniUnterminatedQuote = 'ini.unterminated_quote';
    case IniMixedValueTypes = 'ini.mixed_value_types';
    case IniDuplicateKey = 'ini.duplicate_key';
    case IniDuplicateSection = 'ini.duplicate_section';

    // query string
    case QsEmptyKey = 'qs.empty_key';
    case QsUnbalancedBrackets = 'qs.unbalanced_brackets';
    case QsMixedValueTypes = 'qs.mixed_value_types';
    case QsInvalidPercentEncoding = 'qs.invalid_percent_encoding';

    // byte size
    case BytesizeInvalidNumber = 'bytesize.invalid_number';
    case BytesizeInvalidPrefix = 'bytesize.invalid_prefix';
    case BytesizeInvalidUnit = 'bytesize.invalid_unit';
    case BytesizeOverflow = 'bytesize.overflow';

    // serialize
    case SerializeUnknownType = 'serialize.unknown_type';
    case SerializeMissingTerminator = 'serialize.missing_terminator';
    case SerializeLengthMismatch = 'serialize.length_mismatch';
    case SerializeCountMismatch = 'serialize.count_mismatch';
    case SerializeInvalidInt = 'serialize.invalid_int';
    case SerializeInvalidFloat = 'serialize.invalid_float';
    case SerializeInvalidBool = 'serialize.invalid_bool';
    case SerializeInvalidKey = 'serialize.invalid_key';
    case SerializeInvalidClassName = 'serialize.invalid_class_name';
    case SerializeInvalidPropertyName = 'serialize.invalid_property_name';
    case SerializeInvalidEnum = 'serialize.invalid_enum';
    case SerializeReferenceUnsupported = 'serialize.reference_unsupported';
    case SerializeDisallowedClass = 'serialize.disallowed_class';

    // pcre
    case PcreInvalidDelimiter = 'pcre.invalid_delimiter';
    case PcreMissingEndDelimiter = 'pcre.missing_end_delimiter';
    case PcreUnknownModifier = 'pcre.unknown_modifier';
    case PcreCompileError = 'pcre.compile_error';
}
