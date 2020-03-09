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
		
		header("Access-Control-Allow-Origin: *");
		stop(0, "Fetched from Cache", 200, $returnData, true);
	}

	$url = "https://corona-api.kompa.ai/graphql";
	$data = '{"operationName":"countries","variables":{},"query":"\nquery countries {\ncountries {\nCountry_Region\nConfirmed\nDeaths\nRecovered\nLast_Update\n__typename\n}\n\nprovinces {\nProvince_Name\nProvince_Id\nConfirmed\nDeaths\nRecovered\nLast_Update\n__typename\n}\n\ntotalConfirmedLast\ntotalDeathsLast\ntotalRecoveredLast\n}\n"}';
	
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
		"Content-Length: ". strlen($data),
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
	curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

	$response = curl_exec($ch);

	if ($code = curl_errno($ch))
		stop($code, "Request Error: ". curl_error($ch), 500);

	$data = json_decode($response, true);

	if ($data["data"]) {
		$global = $default["global"];
		$vietnam = $default["vietnam"];

		// GLOBAL DATA

		$global["last"]["confirmed"] += (int)$data["data"]["totalConfirmedLast"];
		$global["last"]["deaths"] += (int)$data["data"]["totalDeathsLast"];
		$global["last"]["recovered"] += (int)$data["data"]["totalRecoveredLast"];

		foreach ($data["data"]["countries"] as $value) {
			$global["confirmed"] += (int)$value["Confirmed"];
			$global["deaths"] += (int)$value["Deaths"];
			$global["recovered"] += (int)$value["Recovered"];
			$lu = ((int)$value["Last_Update"]) / 1000;

			if ($lu > $global["update"])
				$global["update"] = $lu;
		}

		// VIETNAM DATA

		foreach ($data["data"]["provinces"] as $value) {
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

		$data = Array( "global" => $global, "vietnam" => $vietnam );
		$cache -> save($data);
	}

	header("Access-Control-Allow-Origin: *");
	stop(0, "Request Completed", curl_getinfo($ch, CURLINFO_HTTP_CODE), $data);