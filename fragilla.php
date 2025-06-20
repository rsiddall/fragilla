<?php
#
# Simple YAML-driven web scraper, uses chrome-php to run a Chromium-based browser and Twig to expand YAML fields
#

require_once('./vendor/autoload.php');

function usage($reason = '') {
	echo "$reason\n";
	echo "fragilla.php [-d] [-h] [-o <file>] [-f <json|csv>] [-n] <yaml-file>\n";
	echo "where:\n";
	echo "-d = debug\n";
	echo "-h = help\n";
	echo "-f <json|csv> = Output as JSON or CSV\n";
	echo "-n = do not use network, read from files\n";
	echo "-o <file> = Output to file instead of screen\n";
	exit(0);
}

$nextarg = 0;
$options = getopt("dhf:no:", [], $nextarg);

$yaml = array();
if (count($argv) != $nextarg + 1) {
	usage("Missing YAML file");
} else if (($yaml = yaml_parse_file($argv[$nextarg])) === false) {
	usage();
}

# Copy CLI options over settings in YAML
foreach (array('f' => 'format', 'o' => 'output', 'n' => 'network', 'd' => 'debug') as $opt => $key) {
	if (array_key_exists($opt, $options)) {
		$yaml[$key] = $options[$opt];
	}
}

if (array_key_exists('debug', $yaml)) {
	print_r($yaml);
}

if (!array_key_exists('format', $yaml)) {
	$yaml['format'] = 'csv';
} else if (!in_array($yaml['format'], array('csv', 'json'))) {
	usage("invalid output format {$yaml['format']}");
}

$out = 'php://output';

if (array_key_exists('output', $yaml)) {
	$out = $yaml['output'];
}

$data = fetch_data($yaml);

#if ($yaml['format'] === 'json') {
#	file_put_contents($out, json_encode($data));
#} else if ($yaml['format'] === 'csv') {
#	write_csv($out, $data, true);
#}

function flatten_csv_row($fields, $prefix = null) {
	$rslt = array();

	foreach ($fields as $key => $value) {
		if (is_array($value)) {
			foreach (flatten_csv_row($value) as $k => $v) {
				$rslt["$key-$k"] = $v;
			}
		} else {
			$rslt[$key] = $value;
		}
	}
	return $rslt;
}

function write_csv($fname, $list, $headers = false) {
	if (($fp = fopen($fname, 'w')) !== false) {
		foreach ($list as $fields) {
			$row = flatten_csv_row($fields);
			if ($headers) {
				fputcsv($fp, array_keys($row));
				$headers = false;
			}
			fputcsv($fp, $row);
		}
		fclose($fp);
	}
}

function extract_element_attribute($html, $query, $attr, $what) {

	$codes = extract_element_attribute_multi($html, $query, $attr, $what);
	if (count($codes) === 0) {
		echo "Could not retrieve $what - not found\n";
		exit(0);
	} else if (count($codes) > 1) {
		echo "Could not retrieve $what - not single valued\n";
		exit(0);
	}
	return $codes[0];
}

function extract_element_attribute_multi($html, $query, $attr, $what) {
	//Create a new DOM document
	$dom = new DOMDocument;
 
	//Parse the HTML. The @ is used to suppress any parsing errors
	//that will be thrown if the $html string isn't valid XHTML.
	@$dom->loadHTML($html);

	// Create a DOMXpath for navigation
	$xpath = new DOMXpath($dom);

	$links = $xpath->query($query);

	$locs = array();

	//Iterate over the extracted divs and find ones that wrap locations
	foreach ($links as $link){
		if ($link->hasAttribute($attr)) {
			$locs[] = $link->getAttribute($attr);
		} else {
			echo "Could not retrieve $what - element has no $attr attribute\n";
			exit(0);
		}
	}

	return $locs;
}

function extract_fields($xpath, $base, $items) {

	$details = array();

	foreach ($items as $index => $query) {
		if (is_array($query)) {
			if (($rebase = $xpath->query($query['base'], $base)) !== false && $rebase->count() > 0) {
				if ($rebase->count() === 1) {
					$details[$index] = extract_fields($xpath, $rebase->item(0), $query['fields']);
				} else {
					$details[$index] = array();
					for ($i = 0; $i < $rebase->count(); $i++) {
						$details[$index][] = extract_fields($xpath, $rebase->item($i), $query['fields']);
					}
				}
			} else {
				echo "Could not rebase to {$query['base']} for '$index'\n";
				exit(0);
			}
		} else if (($data = $xpath->query($query, $base)) !== false && $data->count() > 0) {
			if ($data->count() === 1) {
				$details[$index] = $data->item(0)->textContent;
			} else {
				$details[$index] = array();
				for ($i = 0; $i < $data->count(); $i++) {
					$details[$index][] = $data->item($i)->textContent;
				}
			}
		} else {
			echo "Could not extract '$index' - inserting blank field\n";
			$details[$index] = '';
#			exit(0);
		}
	}
	return $details;
}

function extract_details($html, $items) {
	//Create a new DOM document
	$dom = new DOMDocument;
 
	//Parse the HTML. The @ is used to suppress any parsing errors
	//that will be thrown if the $html string isn't valid XHTML.
	@$dom->loadHTML($html);

	// Create a DOMXpath for navigation
	$xpath = new DOMXpath($dom);

	return extract_fields($xpath, null, $items);
}

function fetch_html($page, $url, $flag = null, $file = null, $what = null) {

	if ($what != null) {
		$dest = $file !== null ? " to $file" : '';
		echo "Fetching $what from $url$dest\n";
	}
	$page->navigate($url)->waitForNavigation();
	if ($flag !== null) {
		$page->waitUntilContainsElement($flag);
	}
	$text = $page->getHtml();

	if ($file !== null) {
		file_put_contents($file, $text);
	}

	return $text;
}

function render_string($s, $yaml) {
	while (str_contains($s, '{{')) {
		$loader = new \Twig\Loader\ArrayLoader([
			'string' => $s,
		]);
		$twig = new \Twig\Environment($loader);

		$s = $twig->render('string', $yaml);
	}
	return $s;
}

function expand_string($key, $ar, $yaml) {
	if (array_key_exists($key, $ar)) {
		return render_string($ar[$key], $yaml);
	}
	return null;
}

use HeadlessChromium\Dom\Selector\XPathSelector;

function do_operation($page, $job, $yaml) {

	if ($job['operation'] === 'dump') {
		print_r($yaml);
		return $yaml;
	} else if ($job['operation'] === 'message') {
		echo expand_string('message', $job, $yaml) . "\n";
		return $yaml;
	} else if ($job['operation'] === 'sort') {
		if (array_key_exists('into', $job) && array_key_exists($job['into'], $yaml)) {
			sort($yaml[$job['into']]);
		}
		return $yaml;
	} else if ($job['operation'] === 'output') {
		if (array_key_exists('debug', $yaml)) {
			print_r($yaml);
		}
		$file = expand_string('output', $yaml, $yaml); # Top level override takes precedence over job
		if ($file === null) {
			$file = expand_string('output', $job, $yaml);
			if ($file === null) {
				$file = 'php://output';
			}
		}
		$format = 'csv';
		if (array_key_exists('format', $yaml)) {
			$format = $yaml['format'];
		} else if (array_key_exists('format', $job)) {
			$format = $job['format'];
		}
		$data = $yaml[$job['from']];
		if ($format === 'json') {
			file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
		} else if ($format === 'csv') {
			write_csv($file, $data, true);
		}
		return $yaml;
	}

	$extract = $job['extract'];

	$file = expand_string('file', $job, $yaml);

	if (array_key_exists('local', $yaml) && $yaml['local'] === 'y') {
		$html = file_get_contents($file);
	} else {
		$ready = expand_string('ready', $job, $yaml);
		if ($ready !== null) {
			$ready = new XPathSelector($ready);
		}
		$html = fetch_html($page,
			expand_string('url', $job, $yaml),
			$ready,
			$file,
			expand_string('what', $job, $yaml)
		);
	}

	if ($job['operation'] === 'attribute') {
		$rslt = extract_element_attribute($html,
						$extract['xpath'],
						$extract['attribute'],
						expand_string('what', $extract, $yaml)
						);
		$yaml[$extract['into']] = $rslt;
	} else if ($job['operation'] === 'attributes') {
		$rslt = extract_element_attribute_multi($html,
						$extract['xpath'],
						$extract['attribute'],
						expand_string('what', $extract, $yaml)
						);
		if (!array_key_exists($extract['into'], $yaml)) {
			$yaml[$extract['into']] = array();
		}
		foreach ($rslt as $attr) {
			$yaml[$extract['into']][] = $attr;
		}
	} else if ($job['operation'] === 'items') {
		$rslt = extract_details($html, $extract['items']);
		if (!array_key_exists($extract['into'], $yaml)) {
			$yaml[$extract['into']] = array();
		}
		$yaml[$extract['into']][] = $rslt;
	} else {
		echo "Unknown operation {$job['operation']}\n";
		exit(0);
	}
	return $yaml;
}

function do_operations($page, $list, $job, $yaml, $keys) {

	if (array_key_exists('debug', $yaml)) {
		print_r($yaml);
		print_r($keys); # Show keys at start of each loop through data
		print_r($list);
	}
	$level = array_shift($keys);
	$key = $level['name'];
	$source = $level['key'];
	if (array_key_exists($source, $yaml)) {
		$from = $yaml[$source];
	} else if (array_key_exists($source, $list)) {
		$from = $list[$source];
	} else if (array_key_exists($source, $job['data'])) {
		$from = $job['data'][$source];
	} else if (array_key_exists($source, $job)) {
		$from = $job[$source];
	} else {
		echo "Unable to find data source $source for $key in {$job['name']}\n";
		exit(0);
	}

	foreach ($from as $item) {
		if (array_key_exists('debug', $yaml)) {
			print_r($item);
		}
		$yaml[$key] = $item;
		if (count($keys) > 0 && is_array($item)) {
			$yaml = do_operations($page, $item, $job, $yaml, $keys);
		} else if (is_string($item) && array_key_exists($item, $yaml)) {
			$yaml = do_operations($page, $yaml[$item], $job, $yaml, $keys);
		} else {
			$yaml = do_operation($page, $job, $yaml);
		}
	}
	return $yaml;
}

function do_jobs($page, $yaml) {
	foreach ($yaml['jobs'] as $job) {
		// Does the job iterate through lists of possibly nested items?
		if (array_key_exists('data', $job)) {
			$yaml = do_operations($page, $job['data'], $job, $yaml, $job['keys']);
		} else {
			$yaml = do_operation($page, $job, $yaml);
		}
	}
	return $yaml;
}

use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Cookies\Cookie;

function fetch_data($yaml) {

	$browserFactory = new BrowserFactory();

	// starts headless Chrome
	$browser = $browserFactory->createBrowser();

	try {
		// creates a new page and navigate to an URL
		$page = $browser->createPage();

		// Clear cookiejar for this session?
		$page->setCookies([])->await();

		if (array_key_exists('jobs', $yaml)) {
			return do_jobs($page, $yaml);
		}

	} finally {
		// bye
		$browser->close();
	}
	return $yaml;
}

