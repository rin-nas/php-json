<?php
/**
 * Encode/decode a JSON data
 *
 * Since PHP/5.2.0 there is a feature json_encode() and json_decode(),
 * but the choice of this class is preferable.
 *
 * JSON::encode() features
 *   * Not only works with UTF-8, but also with any other single-byte encodings (eg: windows-1251, koi8-r)
 *   * Is able to convert numbers represented a string data type into the corresponding numeric data types (optional)
 *   * Non-ASCII characters leave as is, and does not convert to \uXXXX.
 *
 * JSON::decode() features
 *   * Normalizes a "dirty" JSON string, coming from JavaScript (smart behavior -- only if necessary)
 *
 * @link     http://code.google.com/p/php-json/
 * @license  http://creativecommons.org/licenses/by-sa/3.0/
 * @author   Nasibullin Rinat
 * @version  2.1.1
 */
class JSON
{
	#calling the methods of this class only statically!
	private function __construct() {}

	/**
	 * @link http://json.org/
	 * @var array
	 */
	protected static $escape_table = array(
		"\x5c" => '\\\\', #reverse solidus
		"\x22" => '\"',   #quotation mark
		"\x2f" => '\/',   #solidus
		"\x08" => '\b',   #backspace
		"\x0c" => '\f',   #formfeed
		"\x0a" => '\n',   #new line
		"\x0d" => '\r',   #carriage return
		"\x09" => '\t',   #horizontal tab
	);

	/**
	 * Specially additions coming from JavaScript
	 * @var array
	 */
	protected static $spec_escape_table = array(
		"\x27" => "\'", #single quotation mark
	);

	/**
	 * Converts objects to array recursive
	 *
	 * @param   mixed  $object
	 * @return  mixed
	 */
	public static function objectToArray($object)
	{
		if ($object instanceof Traversable)
		{
			$vars = array();
			foreach ($object as $k => $v) $vars[$k] = $v;
			$object = $vars;
		}
		elseif (is_object($object)) $object = get_object_vars($object); #$object = (array)$object;
		if (is_array($object)) foreach ($object as $k => $v) $object[$k] = self::objectToArray($v);
		return $object;
	}

	/**
	 * Encodes to a JSON string
	 *
	 * @link    http://www.json.org/
	 * @link    http://php.net/json_encode
	 * @param   scalar|object|array|null  $a
	 * @param   string                    $quote               Quote char: '"', "'" or '' (empty)
	 * @param   bool                      $is_convert_numeric  Convert numbers represented a string data type in the corresponding numeric data types
	 * @param   bool                      $_is_key             Private for recursive calling
	 * @return  string|bool               Returns FALSE if error occured
	 */
	public static function encode($a, $quote = '"', $is_convert_numeric = false, $_is_key = false)
	{
		if (! ReflectionTypeHint::isValid()) return false;

		if ($a === null)  return 'null';
		if ($a === false) return 'false';
		if ($a === true)  return 'true';
		if (is_scalar($a))
		{
			if (is_int($a))
			{
				$a = strval($a);
				if (! $_is_key) return $a;
				return $quote . $a . $quote;
			}
			if (is_float($a))
			{
				$a = str_replace(',', '.', strval($a)); #always use "." for floats
				if (! $_is_key) return $a;
				return $quote . $a . $quote;
			}
			#string:
			if (! $_is_key && $is_convert_numeric && (ctype_digit($a) || preg_match('/^-?+\d+(?>\.\d+)?+(?>[eE]\d+)?$/sSX', $a))) return $a;
			$escape_table = self::$escape_table + ($quote !== '"' ? self::$spec_escape_table : array());
			return $quote . strtr($a, $escape_table) . $quote;
		}
		if (is_object($a)) $a = self::objectToArray($a);
		elseif (! is_array($a))
		{
			trigger_error('An any type (except a resource) expected, ' . gettype($a) . ' given!', E_USER_WARNING);
			return false;
		}
		$is_list = true;
		for ($i = 0, reset($a); $i < count($a); $i++, next($a))
		{
			if (key($a) !== $i)
			{
				$is_list = false;
				break;
			}
		}
		$result = array();
		if ($is_list)
		{
			foreach ($a as $v)
			{
				$s = self::encode($v, $quote, $is_convert_numeric);
				if ($s === false) return false;
				$result[] = $s;
			}
			return '[' . implode(',', $result) . ']';
		}
		foreach ($a as $k => $v)
		{
			#it is impossible put the numbers as keys of the hash, use only string (hi, IE-5.01 browser)
			$k = self::encode($k, $quote !== '' ? $quote : '"', $is_convert_numeric, true);
			if ($k === false) return false;
			$v = self::encode($v, $quote, $is_convert_numeric);
			if ($v === false) return false;
			$result[] =  $k . ':' . $v;
		}
		return '{' . implode(',', $result) . '}';
	}

	/**
	 * Decodes a JSON string
	 *
	 * @param   string  $s         The json string being decoded
	 * @param   bool    $is_assoc  When TRUE, returned objects will be converted into associative arrays
	 * @param   int     $depth     User specified recursion depth
	 * @return  object|array|null  Returns an object or if the optional $is_assoc parameter is TRUE, an associative array is instead returned.
	 *                             NULL is returned if the json cannot be decoded or if the encoded data is deeper than the recursion limit.
	 */
	public static function decode($s, $is_assoc = false, $depth = 512)
	{
		if (! ReflectionTypeHint::isValid()) return null;

		$ret = @json_decode($s, $is_assoc, $depth);
		if (json_last_error() !== JSON_ERROR_SYNTAX) return $ret;
		$s = self::normalize($s);
		if (! is_string($s)) return null;
		return json_decode($s, $is_assoc, $depth);
	}

	/**
	 * Converts malformed JSON to well-formed JSON
	 * Hint: normalizes a "dirty" JSON string, coming from JavaScript
	 *
	 * @param   string|null      $s The json string being normalized
	 * @return  string|bool|null    Returns FALSE if error occured
	 */
	public static function normalize($s)
	{
		if (! ReflectionTypeHint::isValid()) return false;
		if (is_null($s)) return $s;

		$s = preg_replace_callback('~(?>
										#comments
										  (\/\*  .*?  \*\/)                     #1 multi line comment
										| (\/\/  [^\r\n]*+)                     #2 single line comment
										#spaces
										| (\s++)                                #3
										#strings
										| "    ((?>[^"\\\\]+ |\\\\.)*)   "      #4
										| \'   ((?>[^\'\\\\]+|\\\\.)*)  \'      #5
										#trailing commas
										| (,)                                   #6
										  (?>	\/\*  .*?  \*\/		#multi line comment
											|	\/\/  [^\r\n]*+		#single line comment
											|	\s++
										  )*+
										  (?=[\]}])
										#keys
										| ([a-zA-Z\d_]++)                       #7
										  (?>	\/\*  .*?  \*\/		#multi line comment
											|	\/\/  [^\r\n]*+		#single line comment
											|	\s++
										  )*+
										  (?=:)
									 )
                                    ~sxuSX', array('self', '_normalize'), $s);
		if (! is_string($s)) return false;
		return $s;
	}

	private static function _normalize(array $m)
	{
		#d($m);
		if (isset($m[1]) && $m[1] !== '') return ''; #multi line comment
		if (isset($m[2]) && $m[2] !== '') return ''; #single line comment
		if (isset($m[3]) && $m[3] !== '') return ''; #spaces
		if (isset($m[4]) && $m[4] !== '') $m[5] = $m[4]; #string
		if (isset($m[5]) && $m[5] !== '')
		{
			#decode:
			$unescape_table = array_flip(self::$escape_table);
			$s = preg_replace_callback(
				'~(?> \\\\u([\da-fA-F]{4}) #1
					| \\\\(.)              #2
					| .
					)~sxSX',
				function(array $m) use ($unescape_table)
				{
					if (isset($m[1]) && $m[1] !== '')
					{
						$codepoint = hexdec($m[1]);
						return UTF8::chr($codepoint);
					}
					if (isset($m[2]) && $m[2] !== '')
					{
						if (array_key_exists($m[0], $unescape_table)) return $unescape_table[$m[0]];
						return $m[2];
					}
					return $m[0];
				},
				$m[5]);
			#encode:
			$s = strtr($s, self::$escape_table);
			return '"' . $s . '"';
		}
		if (isset($m[6]) && $m[6] !== '') return ''; #trailing commas
		if (isset($m[7]) && $m[7] !== '') return '"' . $m[7] . '"';  #keys
		return $m[0];
	}

	public static function tests()
	{
		assert_options(ASSERT_ACTIVE,   true);
		assert_options(ASSERT_BAIL,     true);
		assert_options(ASSERT_WARNING,  true);
		assert_options(ASSERT_QUIET_EVAL, false);

		$s0 = '{"1":2,"33":-44.11,"<tag>":"&bar&","null":null,"true":true,"false":false,"c":"d","e":"f","\/":"\/","ПРИВЕТ":"привет","new\r\nline":"new\nline","\'":"\\"","g":[1,"a","b","c"]}';
		$s1 = '{
			  //single line comment
			  1:2,
			  33:-44.11,
			  "\u003Ctag\u003E" : "\u0026bar\u0026",
			  /*multi
			    line
				comment*/
			  null: null,
			  true : true,
			  false
			    :
			      false
				    ,
			  \'c\' :\'d\',
			  "e" : "f",
			  "/": "\/",
			  "ПРИВЕТ": \'привет\' ,
			  "new\r\nline" : "new\\
line",
			  "\\\'" : \'"\',
			  g:[1,\'a\' ,\'b\', \'c\' , ],
			}';
		$a = array(
			'self::normalize($s1) === $s0',
			'gettype(self::decode($s1, true)) === "array"',
			'self::encode(self::decode($s1, true)) === $s0',
		);
		foreach ($a as $k => $v) if (! assert($v)) return false;
		return true;
	}

}