<?php
	require_once $_SERVER["DOCUMENT_ROOT"] ."/lib/ratelimit.php";
	require_once $_SERVER["DOCUMENT_ROOT"] ."/lib/belibrary.php";
	require_once $_SERVER["DOCUMENT_ROOT"] ."/lib/cache.php";

	define("PAGE_TYPE", "API");

	$default = Array(
        "global" => Array(
			"confirmed" => 0,
			"deaths" => 0,
			"recovered" => 0,
			"last" => Array(
				"confirmed" => 0,
				"deaths" => 0,
				"recovered" => 0,
			),
			"update" => 0
		),

		"vietnam" => Array(
			"list" => Array(),
			"confirmed" => 0,
			"deaths" => 0,
			"recovered" => 0,
			"update" => 0
		)
	);

	$cache = new cache("api.data", $default);
	$cache -> setAge(30);
		
	if ($cache -> validate()) {
		$returnData = $cache -> getData();
		stop(0, "Fetched from Cache", 200, $returnData, true);
	}

	$url = "https://corona-api.kompa.ai/graphql";
	$postData = '{"operationName":"countries","variables":{},"query":"\nquery countries {\ncountries {\nCountry_Region\nConfirmed\nDeaths\nRecovered\nLast_Update\n__typename\n}\n\nprovinces {\nProvince_Name\nProvince_Id\nConfirmed\nDeaths\nRecovered\nLast_Update\n__typename\n}\n\ntotalConfirmedLast\ntotalDeathsLast\ntotalRecoveredLast\n}\n"}';
	
	$header = Array(
		":authority: corona-api.kompa.ai",
		":method: POST",
		":path: /graphql",
		":scheme: https",
		"Accept: */*",
		"Accept-Encoding: gzip, deflate, br",
		"Accept-Language: vi,en-US;q=0.9,en;q=0.8,vi-VN;q=0.7",
		"Cache-Control: no-cache",
		"Connection: keep-alive",
		"Content-Length: ". strlen($postData),
		"content-type: application/json",
		"Origin: https://corona.kompa.ai",
		"Pragma: no-cache",
		"Referer: https://corona.kompa.ai/",
		"Sec-Fetch-Dest: empty",
		"Sec-Fetch-Mode: cors",
		"Sec-Fetch-Site: same-site",
		"User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/80.0.3987.132 Safari/537.36"
	);

	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_ENCODING, "");
	curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
	curl_setopt($ch, CURLOPT_TIMEOUT, 30);
	curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
	curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
	curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

	$response = curl_exec($ch);

	if ($code = curl_errno($ch))
		stop($code, "Request Error: ". curl_error($ch), 500);

	$kompaData = json_decode($response, true);

	// Worldometer Data

	$wom = file_get_contents("https://www.worldometers.info/coronavirus/");
	$womRe = '/(?:<span>(\d+,\d+)<\/span>|<span style="color:#aaa">(\d+,\d+,\d+|\d+,\d+,\d+,\d+)(?:\s+|)<\/span>)/m';
	$womUpdateRe = '/text-align:center">Last updated: (.+)<\/div>/m';
	preg_match_all($womRe, $wom, $womMatch, PREG_SET_ORDER, 0);
	preg_match_all($womUpdateRe, $wom, $womUpdateMatch, PREG_SET_ORDER, 0);

	$womData = Array(
		"confirmed" => (int)str_replace(",", "", trim($womMatch[0][2])),
		"deaths" => (int)str_replace(",", "", trim($womMatch[1][1])),
		"recovered" => (int)str_replace(",", "", trim($womMatch[2][1])),
		"update" => (int)str_replace(",", "", strtotime(trim($womUpdateMatch[0][1])))
	);

	if ($kompaData["data"]) {
		$global = $default["global"];
		$vietnam = $default["vietnam"];

		// GLOBAL DATA

		$global["last"]["confirmed"] = (int)$kompaData["data"]["totalConfirmedLast"];
		$global["last"]["deaths"] = (int)$kompaData["data"]["totalDeathsLast"];
		$global["last"]["recovered"] = (int)$kompaData["data"]["totalRecoveredLast"];

		foreach ($kompaData["data"]["countries"] as $value) {
			$global["confirmed"] += (int)$value["Confirmed"];
			$global["deaths"] += (int)$value["Deaths"];
			$global["recovered"] += (int)$value["Recovered"];
			$lu = ((int)$value["Last_Update"]) / 1000;

			if ($lu > $global["update"])
				$global["update"] = $lu;
		}

		$global["confirmed"] = max($global["confirmed"], $womData["confirmed"]);
		$global["deaths"] = max($global["deaths"], $womData["deaths"]);
		$global["recovered"] = max($global["recovered"], $womData["recovered"]);
		$global["update"] = max($global["update"], $womData["update"]);

		// VIETNAM DATA

		foreach ($kompaData["data"]["provinces"] as $value) {
			$vietnam["confirmed"] += (int)$value["Confirmed"];
			$vietnam["deaths"] += (int)$value["Deaths"];
			$vietnam["recovered"] += (int)$value["Recovered"];
			$lu = ((int)$value["Last_Update"]) / 1000;

			if ($lu > $vietnam["update"])
				$vietnam["update"] = $lu;

			$item = Array(
				"name" => $value["Province_Name"],
				"update" => ((int)$value["Last_Update"]) / 1000,
				"confirmed" => (int)$value["Confirmed"],
				"deaths" => (int)$value["Deaths"],
				"recovered" => (int)$value["Recovered"]
			);

			array_push($vietnam["list"], $item);
		}

		usort($vietnam["list"], function($a, $b) {
			$a = $a["confirmed"];
			$b = $b["confirmed"];
		
			if ($a === $b)
				return 0;
	
			return ($a > $b) ? -1 : 1;
		});

		$kompaData = Array( "global" => $global, "vietnam" => $vietnam );
		$cache -> save($kompaData);
	}

	stop(0, "Request Completed", curl_getinfo($ch, CURLINFO_HTTP_CODE), $kompaData);