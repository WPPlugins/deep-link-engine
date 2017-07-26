<?php
/*
	Copyright (C) 2009,2010 GungHo Technologies LLC
	released under GPLv3 - please refer to the file copyright.txt
*/

if(!class_exists("Pingbacker_Core"))
{
	// Core class with utility functions
	class Pingbacker_Core
	{
		// Log file
		private $logfp;

		function __construct()
		{
			// Open log file
			if(defined("PB11_DEBUG"))
			{
				$this->logfp = @fopen(dirname(__FILE__) . "/logfile.txt", "a+");
			}
		}

		function __destruct()
		{
			if(defined("PB11_DEBUG") and $this->logfp)
			{
				// Close log file
				fclose($this->logfp);
				unset($this->logfp);
			}
		}

		protected function debug($message)
		{
			if(defined("PB11_DEBUG"))
			{
				$bt = debug_backtrace();
				array_shift($bt);
				$caller = array_shift($bt);
				fputs($this->logfp, date('Y-m-d H:i:s') . " " .
						$caller["function"] . ": " . $message . "\n");
			}
		}

		private function decode_headers($input)
		{
			$this->debug("Paring headers:\n$input");	
			$part = preg_split("/\r\n/", $input, -1, PREG_SPLIT_NO_EMPTY);
			$out = array();
			for($h = 0; $h < sizeof($part); $h++)
			{
				if($h != 0)
				{
					$pos = strpos($part[$h], ':');
					$k = strtolower(str_replace(' ', '', substr($part[$h], 0, $pos)));
					$v = trim(substr($part[$h], ($pos + 1)));
				}
				else
				{
					$k = "status";
					$v = explode(' ', $part[$h]);
					$v = $v[1];
				}
				if($k == "set-cookie")
				{
					$out["cookies"][] = $v;
				}
				else if($k == "content-type")
				{
					if(($cs = strpos($v, ';')) !== false)
					{
						$out[$k] = substr($v, 0, $cs);
					}
					else
					{
						$out[$k] = $v;
					}
				}
				else
				{
					$out[$k] = $v;
				}
			}
			return $out;
		}

		private function decode_body($h, $d)
		{
			if(isset($h['transfer-encoding']) && $h['transfer-encoding'] == 'chunked')
			{
				$this->debug('decoding chunks');
				$fp = 0;
				$outData = "";
				while ($fp < strlen($d)) {
					$rawnum = substr($d, $fp, strpos(substr($d, $fp), "\r\n") + 2);
					$num = hexdec(trim($rawnum));
					$fp += strlen($rawnum);
					$chunk = substr($d, $fp, $num);
					$outData .= $chunk;
					$fp += strlen($chunk);
				}
				$d = $outData;
			}
			if(isset($h['content-encoding']) && $h['content-encoding'] == 'gzip') {
				$this->debug('unzipping');
				$d = @gzinflate(substr($d,10));
			}
			return $d;
		}

		protected function get_uri($uri, $method = "GET", $postcontent = NULL)
		{
			$uri_a = parse_URL($uri);
			$sock = @fsockopen($uri_a["host"], 80);
			if(!$sock)
			{
				$this->debug("error opening socket to {$uri_a["host"]}");
				return false;
			}
			$query = '';
			if(isset($uri_a['query']) && trim($uri_a["query"]) != "")
			{
				$query = "?{$uri_a['query']}";
			}
			$h[] = "$method {$uri_a['path']}$query HTTP/1.1";
			$h[] = "Host: {$uri_a['host']}";
			$h[] = "Accept-Encoding: gzip";
			$h[] = "User-Agent: Mozilla/5.0 (X11; U; Linux x86_64; en-US; rv:1.9.1.6) Gecko/20091228 Gentoo Firefox/3.5.6";
			$h[] = "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8";
			$h[] = "Accept-Language: en-us";
			$h[] = "Accept-Charset: ISO-8859-1,utf-8;q=0.7,*;q=0.7";
			if(isset($postcontent))
			{
				$h[] = "Content-Type: application/x-www-form-urlencoded";
				$h[] = "Content-Length: ".strlen($postcontent);
			}
			$h[] = "Connection: close";
			$headers = implode("\r\n", $h);
			fputs($sock, $headers."\r\n\r\n".$postcontent);
			stream_set_blocking($sock, true);
			stream_set_timeout($sock, 5);
			$this->debug("\n$headers");
			unset($headers);
			$headers = '';
			do
			{
				$headers .= fgets($sock, 4096);
				$info = stream_get_meta_data($sock);
				if($info["timed_out"])
				{
					$this->debug("Socket read has timed out");
					return false;
				}
			} while(strpos($headers, "\r\n\r\n") === false);
			$head = $this->decode_headers($headers);
			if(intval($head["status"]) != 200)
			{
				// no redirection supported
				return false;
			}
			$this->debug("\n".print_r($head, true));
			$all = '';
			while(!feof($sock))
			{
				$all .= fread($sock, 4096);
				$info = stream_get_meta_data($sock);
				if($info["timed_out"])
				{
					$this->debug("Socket read has timed out");
					return false;
				}
			}
			fclose($sock);
			$content = $this->decode_body($head, $all);
			return $content;
		}

		protected function json_decode($content)
		{
			if(function_exists("json_decode"))
			{
				return json_decode($content);
			}
			return false;
		}

		protected function test_xml()
		{
			if(!function_exists("xml_parser_create"))
			{
				$this->debug("function xml_parser_create not defined!");
				return false;
			}
			return true;
		}

		protected function xml2array($contents)
		{
			if($this->test_xml() === false)
			{
				return false;
			}
			$xml_values = array();
			$parser = xml_parser_create('');
			if(!$parser)
				return false;

			xml_parser_set_option($parser, XML_OPTION_TARGET_ENCODING, 'UTF-8');
			xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0);
			xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 1);
			xml_parse_into_struct($parser, trim($contents), $xml_values);
			xml_parser_free($parser);
			if (!$xml_values)
			{
				return false;
			}

			$xml_array = array();
			$last_tag_ar =& $xml_array;
			$parents = array();
			$last_counter_in_tag = array(1=>0);
			foreach ($xml_values as $data)
			{
				switch($data['type'])
				{
					case 'open':
						$last_counter_in_tag[$data['level']+1] = 0;
						$new_tag = array('name' => $data['tag']);
						if(isset($data['attributes']))
							$new_tag['attributes'] = $data['attributes'];
						if(isset($data['value']) && trim($data['value']))
							$new_tag['value'] = trim($data['value']);
						$last_tag_ar[$last_counter_in_tag[$data['level']]] =
							$new_tag;
						$parents[$data['level']] =& $last_tag_ar;
						$last_tag_ar =&
							$last_tag_ar[$last_counter_in_tag[$data['level']]++];
						break;
					case 'complete':
						$new_tag = array('name' => $data['tag']);
						if(isset($data['attributes']))
							$new_tag['attributes'] = $data['attributes'];
						if(isset($data['value']) && trim($data['value']))
							$new_tag['value'] = trim($data['value']);

						$last_count = count($last_tag_ar)-1;
						$last_tag_ar[$last_counter_in_tag[$data['level']]++] =
							$new_tag;
						break;
					case 'close':
						$last_tag_ar =& $parents[$data['level']];
						break;
					default:
						break;
				};
			}
			return $xml_array;
		}
	}
}

?>
