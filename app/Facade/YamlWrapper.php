<?php
namespace App\Facade;

use Symfony\Component\Yaml\Yaml;

class YamlWrapper
{
	public static function load($filename, $flags = 0)
	{
		return self::parse(file_get_contents($filename), $flags);
	}
	public static function parse($input, $flags = 0)
	{
		return Yaml::parse($input, $flags);
	}
	public static function dump($array, $inline = 2, $indent = 4, $flags = 0)
	{
		return Yaml::dump($array, $inline, $indent, $flags);
	}
}
?>