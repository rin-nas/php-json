# JSON encode and decode on PHP
Since PHP/5.2.0 there is a feature json_encode() and json_decode(), but the choice of this class is preferable.

## JSON::encode() features

* Not only works with UTF-8, but also with any other single-byte encodings (eg: windows-1251, koi8-r)
* Is able to convert numbers represented a string data type into the corresponding numeric data types (optional)
* Non-ASCII characters leave as is, and does not convert to \uXXXX.

## JSON::decode() features

Converts malformed JSON to well-formed JSON.
Hint: normalizes a "dirty" JSON string, coming from JavaScript (smart behavior -- only if necessary)

Project was exported from http://code.google.com/p/php-json
