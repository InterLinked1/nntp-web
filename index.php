<?php
/*
 * -- nntp-web -- simple, stateless web-based NNTP client
 *
 * Copyright (C) 2026, Naveen Albert <nntpweb@phreaknet.org>
 *
 * This program is free software, distributed under the terms of
 * the GNU General Public License Version 2. See the LICENSE file
 * at the top of the source tree.
 *
 */

/* Defaults, can be overridden in the config */
$recentTime = 14400;
$recentLimit = 100;
$hideEmptyThreshold = 5000;
$modeReaderNeeded = true;

/* --- User config goes in config.php --- */

if (file_exists('config.php')) {
	require('config.php');
}

if (!isset($hostname, $port, $tls)) {
	http_response_code(500);
	die("Missing requried config.");
}

if (($requireCredentials && !isset($username, $password)) || isset($_GET['login'])) {
	if ($requireCredentials && !$allowUserAuthentication) {
		http_response_code(500);
		die("Missing credentials.");
	}
	/* Prompt for credentials using Basic Auth */
	if (!isset($_SERVER['PHP_AUTH_PW'])) {
		header("HTTP/1.1 401 Unauthorized");
		header("WWW-Authenticate: Basic realm=\"nntp\"");
		die("Please authenticate to access this news server.");
	}
	$username = $_SERVER['PHP_AUTH_USER'];
	$password = $_SERVER['PHP_AUTH_PW'];
} else if (isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
	$username = $_SERVER['PHP_AUTH_USER'];
	$password = $_SERVER['PHP_AUTH_PW'];
} else {
	$username = $password = null;
}

$lastResponse;
$baseURL = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$mbAvailable = function_exists('mb_decode_mimeheader');

$sock = nntp_connect($hostname, $port, $tls, $username, $password);

/* If MODE READER is needed, send it now */
if ($modeReaderNeeded && $port === 119) {
	fprintf($sock, "MODE READER\r\n");
	$code = nntp_read_code($sock);
	if ($code !== 200 && $code !== 201) {
		printf("Expected 200/201, got: %s\n", $lastResponse);
		die();
	}
}

if (isset($_GET['message'])) {
	/* Display an article, by Message-ID */
	$messageID = preg_replace('/[^A-Za-z0-9.$@_<>-]/', '', $_GET['message']);
	nntp_article($sock, $messageID, 0, $headers, $body);
	displayArticle($headers, $body, $_GET['message'], NULL, 0);
} else if (isset($_GET['group'])) {
	$group = preg_replace('/[^A-Za-z0-9.+-]/', '', $_GET['group']);
	if (isset($_GET['article'])) {
		/* Display an article */
		$articleNumber = (int) $_GET['article'];
		if ($articleNumber <= 0) {
			printf("Invalid article number: %s\n", $_GET['article']);
			die();
		}
		nntp_group($sock, $group, $low, $high, $count);
		nntp_article($sock, NULL, $articleNumber, $headers, $body);
		displayArticle($headers, $body, NULL, $group, $articleNumber);
	} else {
		/* List articles in the group (paginated) */
		$pageSize = isset($_GET['pagesize']) ? ((int) $_GET['pagesize']) : 100;
		$page = isset($_GET['page']) ? ((int) $_GET['page']) : 1;
		nntp_group($sock, $group, $low, $high, $grpCount);
		if (($high - $low) === ($grpCount - 1)) {
			/* If there the watermarks and counts do not suggest gaps, assume there are no gaps. This is common enough that it is a worthwhile optimization. */
			$articleIDs = range($low, $high);
			$count = count($articleIDs);
			if ($count !== $grpCount) {
				/* If the server lied, fall back to getting the article IDs explicitly */
				nntp_listgroup($sock, $group, $articleIDs);
			}
		} else {
			nntp_listgroup($sock, $group, $articleIDs);
		}
		$articleIDs = array_reverse($articleIDs); /* Sort articles newest to oldest */
		$count = count($articleIDs);
		if (!$count) {
			http_response_code(404);
			die("Group is empty");
		}
		$startIndex = $pageSize * ($page - 1);
		$endIndex = $pageSize * $page - 1;
		if ($startIndex >= $count) {
			http_response_code(404);
			die("Article range exceeded");
		}
		$start = $articleIDs[$startIndex];
		$end = 1;
		if ($endIndex < $count) {
			$end = $articleIDs[$endIndex];
		}

		nntp_overview($sock, $start, $end, $articles);
		$articles = array_reverse($articles); /* Reverse for newest first */
		$articleCount = count($articles);
		start_page($group);
		printf("<p><a href='%s'>Groups</a></p>", $baseURL);
		printf("<h2>%s</h2>", $group);
		printf("<p><b>%d</b> total article%s (%d - %d)", $count, ($count === 1 ? "" : "s"), $low, $high);
		$newerArticlesExist = $page > 1;
		$olderArticlesExist = $endIndex < $count;
		$totalPages = floor(($count + ($pageSize - 1)) / $pageSize);
		if ($newerArticlesExist || $olderArticlesExist) {
			printf(" | ");
			show_page_nav_links($group, $newerArticlesExist, $olderArticlesExist, $page, $totalPages, $start, $end);
		}
		printf("<table><tr><th>%s</th><th>%s</th><th>%s</th><th class='datecol'>%s</th><th>%s</th><th>%s</th></tr>", "#", "Subject", "From", "Date", "Lines", "Size");
		foreach($articles as $a) {
			$artLink = sprintf("?group=%s&article=%d", urlencode($group), $a['number']);
			$dateFmt = date('Y-m-d H:i', $a['date']);
			if (strlen($a['subject']) > 55) {
				$a['subject'] = "<span class='small'>" . $a['subject'] . "</span>";
			}
			if (strlen($a['from']) > 55) {
				$a['from'] = "<span class='small'>" . $a['from'] . "</span>";
			}
			printf("<tr><td class='right'><a href='%s'>%d</a></td><td><a href='%s'>%s</a></td><td>%s</td><td>%s</td><td class='right'>%d</td><td class='right'>%d</td></tr>\n",
				$artLink, $a['number'], $artLink, $a['subject'], $a['from'], $dateFmt, $a['lines'], $a['bytes']);
		}
		printf("</table>");
		/* Show links after as well for easier navigation */
		if ($newerArticlesExist || $olderArticlesExist) {
			show_page_nav_links($group, $newerArticlesExist, $olderArticlesExist, $page, $totalPages, $start, $end);
		}
	}
} else if (isset($_GET['recent'])) {
	/* Use NEWNEWS to view recent articles */
	/* Note that ordering is not guaranteed here if the server does not return NEWNEWS response in order of arrival */
	nntp_newnews($sock, time() - $recentTime, $messages);
	$messages = array_slice($messages, -$recentLimit);
	foreach($messages as $msgid) {
		/* Note: This relies on the OVER MSGID capability */
		fprintf($sock, "OVER %s\r\n", $msgid);
	}
	$articles = array();
	foreach($messages as $msgid) {
		if (nntp_expect_code_return($sock, 224)) {
			die("OVER MSGID failed for $msgid: $lastResponse");
		}
		$s = nntp_read_line($sock);
		$s2 = nntp_read_line($sock);
		if ($s2 !== ".") {
			http_response_code(500);
			die("Unexpected line: $s2");
		}
		$fields = explode("\t", $s); /* TAB must be double quoted! */
		if (!isset($fields[7])) {
			http_response_code(500);
			die("Invalid OVER response");
		}
		$msgid2 = $fields[4];
		if ($msgid2 !== $msgid) {
			http_response_code(500);
			die("Mismatching message ID: $msgid !== $msgid2");
		}
		$articles[$msgid] = $fields;
	}
	$articles = array_reverse($articles);
	$recentGroups = array();
	start_page("Recent Articles");
	printf("<p><a href='%s'>Groups</a></p>", $baseURL);
	printf("<table>");
	printf("<tr><th>%s</th><th>%s</th><th>%s</th><th>%s</th><th>%s</th><th>%s</th></tr>", "Subject", "From", "Xref", "Date", "Lines", "Size");
	foreach($articles as $msgid => $fields) {
		$subject = htmlspecialchars(decode_encoded_header($fields[1]));
		$from = htmlspecialchars(decode_encoded_header($fields[2]));
		$date = date('Y-m-d H:i', strtotime($fields[3]));
		$bytes = (int) $fields[6];
		$lines = (int) $fields[7];
		$xref = null;
		if (isset($fields[8])) {
			if (str_starts_with($fields[8], "Xref:")) {
				$fields[8] = substr($fields[8], 5);
				$fields[8] = ltrim($fields[8]);
			}
			$xref = xref_format($fields[8], $recentGroups);
			$x = explode(' ', $xref);
			array_shift($x);
			$xref = "<span>" . implode(' ', $x) . "</span>"; /* Remove hostname from Xref */
		}
		if (strlen($subject) > 80) {
			$subject = sprintf("<span class='small'>%s</span>", $subject);
		}
		if (strlen($from) > 80) {
			$from = sprintf("<span class='small'>%s</span>", $from);
		}
		if (strlen($xref) > 240) {
			$xref = sprintf("<span class='small'>%s</span>", $xref);
		}
		printf("<tr class='small'><td><a href='?message=%s'>%s</a></td><td>%s</td><td>%s</td><td>%s</td><td class='right'>%d</td><td class='right'>%d</td></tr>\n",
			urlencode($msgid), $subject, $from, $xref, $date, $lines, $bytes);
	}
	printf("</table>");

	/* Display the group representation of recent articles, in order of articles per group (including crossposts) */
	arsort($recentGroups);
	printf("<hr><b>Groups with recent articles</b><table class='small'>");
	foreach ($recentGroups as $grp => $count) {
		printf("<tr><th><a href='?group=%s'>%s</a></th><td class='right'>%d</td></tr>", urlencode($grp), htmlentities($grp), $count);
	}
	printf("</table>");
} else {
	/* List all groups carried by the server */
	nntp_list_counts($sock, $groups); /* LIST COUNTS gives us everything LIST ACTIVE does (and more), so no need to do LIST ACTIVE first */
	nntp_list_active_times($sock, $groups);
	nntp_list_newsgroups($sock, $groups);

	$groupCount = count($groups);
	$totalArticleCount = array_sum(array_column($groups, 'count'));
	$sortBy = null;
	$columns = array("group", "low", "high", "count", "status", "description", "created", "creator");

	if (isset($_GET['sort'])) {
		$sortBy = $_GET['sort'];
		$desc = isset($_GET['desc']);
		if ($sortBy === "group") {
			uasort($groups, function ($a, $b) {
				return strcasecmp($a['name'], $b['name']);
			});
		} else if (in_array($sortBy, array("low", "high", "count", "created"))) {
			uasort($groups, function ($a, $b) use ($sortBy) {
				return($a[$sortBy] > $b[$sortBy] ? 1 : -1);
			});
		} else if (in_array($sortBy, $columns)) {
			uasort($groups, function ($a, $b) use ($sortBy) {
				return strcasecmp($a[$sortBy], $b[$sortBy]);
			});
		}
		if ($desc) {
			$groups = array_reverse($groups);
		}
	}

	/* Extract the unique top-level hierarchies */
	$hierarchies = array();
	foreach ($groups as $group) {
		$topHier = explode('.', $group['name'])[0];
		if (!isset($hierarchies[$topHier])) {
			$hierarchies[$topHier] = 1;
		} else {
			$hierarchies[$topHier] += 1;
		}
	}

	start_page("Groups");
	printf("<h2><a href='%s'>Newsgroups</a></h2>", $baseURL);
	printf("<p><b>$groupCount</b> group%s, %s article%s || <a href='?recent'>view recent</a>", ($groupCount === 1 ? "" : "s"), $totalArticleCount, ($totalArticleCount === 1 ? "" : "s"));
	if ($allowUserAuthentication) {
		if (isset($_SERVER['PHP_AUTH_USER'])) {
			printf(" || <span class='right small'><i>Logged in as <b>%s</b></i></span>", $username);
		} else {
			printf(" || <span class='right small'><i><a href='?login'>Log in</a></i></span>");
		}
	}
	if (!isset($_GET['sort'])) {
		printf("<p class='small'><b>Hierarchies:</b> ");
		$c = 0;
		foreach ($hierarchies as $h => $hCount) {
			printf("%s<a href='#hier-%s'>%s</a> (%d)", $c++ > 0 ? " | " : "", urlencode($h), $h, $hCount);
		}

		/* Generate a compact wildmat that encompasses all these groups */
		$hPatterns = array();
		foreach ($hierarchies as $h => $hCount) {
			$hGroups = array();
			/* Get all the groups in this hierarchy */
			foreach ($groups as $g) {
				if (!strchr($g['name'], '.') && $g['name'] === $h) {
					$hGroups[] = $g['name']; /* Single hierarchy group, e.g. junk */
				} else if (str_starts_with($g['name'], $h . '.')) {
					$hGroups[] = $g['name'];
				}
			}
			/* Find the longest common prefix of all these groups */
			$hCount = count($hGroups);
			if ($hCount === 1) {
				$hPatterns[] = $hGroups[0];
			} else { /* $hCount > 1 */
				sort($hGroups);
				$first = $hGroups[0];
				$last = $hGroups[$hCount - 1];
				$len = min(strlen($first), strlen($last));
				for ($i = 0; $i < $len && $first[$i] == $last[$i]; $i++); /* Compare first/last string in sorted array. If indexed character same in both strings, increment index */
				$prefix = substr($first, 0, $i);
				$hPatterns[] = $prefix . '*'; /* $prefix already ends in '.' */
			}
		}
		printf("<br><span class='small'><b>Wildmat:</b> <span class='small'>%s</span></span>", implode(',', $hPatterns));
		printf("</p>");
	}

	if ($groupCount > $hideEmptyThreshold && !isset($_GET['showempty'])) {
		/* There are likely a lot of dead groups in this case, don't show them all by default */
		$empty = 0;
		foreach ($groups as $g) {
			if ($g['count'] === 0 && $g['high'] === 0) {
				unset($groups[$g['name']]);
				$empty++;
			}
		}
		if ($empty > 0) {
			$groupCount = count($groups);
			printf("<br>$empty empty %s been hidden (<a href='?showall'>show all</a>), $groupCount group%s listed below", $empty === 1 ? "group has" : "groups have", $groupCount === 1 ? "" : "s");
		}
	}
	echo "<table><tr>";
	foreach ($columns as $col) {
		$colName = ucfirst($col);
		$sortLink = "?sort=" . $col . (isset($_GET['desc']) ? "" : "&desc");
		printf("<th><a href='%s'>%s</a></th>", $sortLink, $colName);
	}
	echo "</tr>";
	$lastHier = null;
	foreach($groups as $g) {
		$topHier = explode('.', $g['name'])[0];
		if ($lastHier !== $topHier) {
			$lastHier = $topHier;
			printf("<tr id='hier-%s'>", urlencode($topHier));
		} else {
			echo "<tr>";
		}
		foreach ($columns as $col) {
			if ($col === "group") {
				$val = sprintf("<a href='?group=%s'>%s</a>", urlencode($g['name']), $g['name']);
			} else if ($col === "created") {
				$fullDate = date('Y-m-d H:i', $g[$col]);
				$val = sprintf("<span title='%s'>%s</span>", $fullDate, date('Y-M', $g[$col]));
			} else {
				$val = $g[$col];
			}
			$smallCols = array("description", "created", "creator");
			if (in_array($col, $smallCols) || ($col === "group" && strlen($g['name']) > 50)) {
				printf("<td class='small'>%s</td>", $val);
			} else {
				printf("<td>%s</td>", $val);
			}
		}
		echo "</tr>";
	}
	printf("</table>");
	/* Add a brief footer on the homepage */
	printf("<hr><center><i class='small'>Powered by <a href='https://github.com/InterLinked1/nntp-web' target='_blank'>nntp-web</a></i></center>");
}

fclose($sock);

/* Helper functions */

function show_page_nav_links(String $group, bool $newerArticlesExist, bool $olderArticlesExist, int $page, int $totalPages, $start, $end) {
	$eGroup = urlencode($group);
	if ($newerArticlesExist) {
		$pageSizeQuery = (isset($_GET['pagesize']) ? "&pagesize=" . htmlspecialchars((int) $_GET['pagesize']) : "");
		printf("<a href='?group=%s&page=%d%s'>&larr; Newest</a>", $eGroup, 1, $pageSizeQuery);
		$prevPage = $page - 1;
		printf("&nbsp;&nbsp;<a href='?group=%s&page=%d%s'>&larr; Newer</a>", $eGroup, $prevPage, $pageSizeQuery);
	}
	printf("&nbsp;&nbsp;[Page %d of %d <span class='small'>(articles %d-%d)</span>]&nbsp;&nbsp;", $page, $totalPages, $end, $start);
	if ($olderArticlesExist) {
		$nextPage = $page + 1;
		$pageSizeQuery = (isset($_GET['pagesize']) ? "&pagesize=" . htmlspecialchars((int) $_GET['pagesize']) : "");
		printf("<a href='?group=%s&page=%d%s'>Older &rarr;</a>", $eGroup, $nextPage, $pageSizeQuery);
		printf("&nbsp;&nbsp;<a href='?group=%s&page=%d%s'>Oldest &rarr;</a>", $eGroup, $totalPages, $pageSizeQuery);
	}
}

function start_page($title) {
	echo '<html><head><style>
	.right { text-align: right; }
	.small { font-size: 0.85em; }
	table { border-collapse: collapse; }
	th, td {
		border: 1px solid black;
		padding: 1px 5px;
	}
	.datecol { min-width: 120px; }
	</style>';
	printf("<title>%s</title>", $title);
	echo '</head><body>';
}

function displayArticle($headers, $body, $messageID, $group, $articleNumber) {
	global $baseURL;

	$headers = str_replace(array("\r\n ", "\r\n\t"), " ", $headers); /* Unfold multi-line headers */
	extract_headers($headers, array("From", "Organization", "Subject", "Newsgroups", "Distribution", "References", "Date", "Message-ID", "Xref"), $outputHeaders);
	if ($messageID) {
		$pageTitle = isset($outputHeaders['Subject']) ? $outputHeaders['Subject'] : "Article $messageID";
	} else {
		$pageTitle = isset($outputHeaders['Subject']) ? $outputHeaders['Subject'] : "Article $articleNumber";
	}
	start_page($pageTitle);
	if ($messageID) {
		printf("<p><a href='%s'>Groups</a></p>", $baseURL);
	} else {
		printf("<p><a href='%s'>Groups</a> > <a href='?group=%s'>%s</a></p>", $baseURL, urlencode($group), $group);
	}
	printf("<h2>%s</h2>", $pageTitle);
	echo "<table>";
	foreach ($outputHeaders as $hdr => $val) {
		$fmt = "";
		if ($hdr === "References") {
			$fmt = " class='small'";
		}
		printf("<tr><th>%s</th><td%s>%s</td></tr>\n", $hdr, $fmt, $val);
	}
	echo "</table>";
	if ($messageID) {
		printf("<p class='small'><a href='?message=%s&raw' target='_blank'>View raw</a></p>", urlencode($messageID), $messageID);
	} else {
		printf("<p class='small'><a href='?group=%s&article=%d&raw' target='_blank'>View raw</a></p>", urlencode($group), $articleNumber);
	}
	echo "<hr>\n";
	$body = quoted_printable_decode($body);
	$body = str_replace(" \r\n>", "\r\n>", $body); /* Ignore format=flowed, preserve folding for quotes so we don't have super long lines */
	$body = str_replace(" \r\n", " ", $body); /* Unwrap folded lines (with format=flowed) */
	$body = htmlspecialchars($body);

	if (str_contains($headers, "Content-Transfer-Encoding: base64")) {
		$b64 = base64_decode($body, false);
		if ($b64 !== false) {
			$body = $b64;
		}
	}

	/* Could use pre tags, but then hyperlinks won't work... */
	$body = str_replace("\r\n", "<br>\r\n", $body); /* Preserve the line endings themselves to make looking at the page source easier */
	/* Automatically hyperlink any links: */
	$body = preg_replace('/((http|ftp|https):\/\/[\w-]+(\.[\w-]+)+([\w.,@?^=%&amp;:\/~+#-]*[\w@?^=%&amp;\/~+#-])?)/', '<a href="\1" target="_blank">\1</a>', $body);
	/* Present it as if it were using pre tags */
	echo "<code>" . $body . "</code>\n";
	echo "<hr>";
	if ($articleNumber) {
		if ($articleNumber > 1) { /* Not the same as the low water mark, but if the article number is 1, there is definitely no older article */
			printf("<a href='?group=%s&article=%d'>&larr; Previous</a>&nbsp;&nbsp;|&nbsp;&nbsp;", urlencode($group), $articleNumber - 1);
		}
		printf("<a href='?group=%s&article=%d'>Next &rarr;</a>", urlencode($group), $articleNumber + 1);
	}
}

function nntp_connect(String $hostname, int $port, bool $tls, $username = null, $password = null) {
	global $lastResponse, $allowUserAuthentication;
	$hostString = ($tls ? "tls://" : "") . $hostname;
	$sock = fsockopen($hostString, $port, $errno, $errstr, 10);
	if (!$sock) {
		printf("Failed to establish NNTP connection to $hostname:$port: %s\n", $errstr);
		die();
	}

	/* Check banner */
	$code = nntp_read_code($sock);
	if ($code !== 200 && $code !== 201) {
		printf("Expected 200/201, got: %s\n", $lastResponse);
		die();
	}

	/* Log in if needed */
	if ($username && $password) {
		fprintf($sock, "AUTHINFO USER %s\r\n", $username);
		nntp_expect_code($sock, 381);
		fprintf($sock, "AUTHINFO PASS %s\r\n", $password);
		$code = nntp_read_code($sock);
		if ($code !== 281) {
			if (isset($_SERVER['PHP_AUTH_USER']) && $allowUserAuthentication) {
				/* Reprompt */
				header("HTTP/1.1 401 Unauthorized");
				header("WWW-Authenticate: Basic realm=\"nntp\"");
			}
			printf("Authentication failed, got %s\n", $lastResponse);
			die();
		}
	}
	return $sock;
}

function nntp_read_line($sock) {
	$s = fgets($sock);
	if ($s) {
		$s = rtrim($s, "\r\n");
	}
	return $s;
}

function nntp_read_code($sock) {
	global $lastResponse;
	$lastResponse = fgets($sock);
	$code = (int) $lastResponse;
	return $code;
}

function nntp_expect_code($sock, $expectedCode) {
	global $lastResponse;
	$code = nntp_read_code($sock);
	if ($code !== $expectedCode) {
		printf("Expected %d, got %s\n", $expectedCode, $lastResponse);
		die();
	}
}

function nntp_expect_code_return($sock, $expectedCode) {
	$code = nntp_read_code($sock);
	if ($code != $expectedCode) {
		return true;
	}
	return false;
}

function nntp_list_counts($sock, &$groups) {
	fprintf($sock, "LIST COUNTS\r\n");
	nntp_expect_code($sock, 215);
	$groups = array();
	for (;;) {
		$s = nntp_read_line($sock);
		if ($s === ".") {
			break;
		}
		$fields = explode(' ', $s);
		if (!isset($fields[3])) {
			error_log("Unexpected LIST COUNTS response: " . $s, 0);
			http_response_code(500);
			die();
		}
		$g = [
			'name' => $fields[0],
			'high' => (int) $fields[1],
			'low' => (int) $fields[2],
			'count' => (int) $fields[3],
			'status' => $fields[4],
			'creator' => '',
			'created' => 0,
			'description' => '',
		];
		$groups[$fields[0]] = $g;
	}
}

function nntp_list_active_times($sock, &$groups) {
	fprintf($sock, "LIST ACTIVE.TIMES\r\n");
	nntp_expect_code($sock, 215);
	for (;;) {
		$s = nntp_read_line($sock);
		if ($s === ".") {
			break;
		}
		$fields = explode(' ', $s);
		if (!isset($fields[2])) {
			error_log("Unexpected LIST ACTIVE.TIMES response: " . $s, 0);
			http_response_code(500);
			die();
		}
		$group = $fields[0];
		if (isset($groups[$group])) {
			$groups[$group]['created'] = (int) $fields[1];
			$groups[$group]['creator'] = $fields[2];
		}
	}
}

function nntp_list_newsgroups($sock, &$groups) {
	fprintf($sock, "LIST NEWSGROUPS\r\n");
	nntp_expect_code($sock, 215);
	for (;;) {
		$s = nntp_read_line($sock);
		if ($s === ".") {
			break;
		}
		$fields = explode("\t", $s); /* TAB must be double quoted! */
		if (!isset($fields[1])) {
			error_log("Unexpected LIST NEWSGROUPS response: " . $s, 0);
			http_response_code(500);
			die();
		}
		$group = $fields[0];
		if (isset($groups[$group])) {
			if ($fields[1] !== "No description.") {
				$groups[$group]['description'] = $fields[1];
			}
		}
	}
}

function nntp_group($sock, $group, &$low, &$high, &$count) {
	global $lastResponse;
	fprintf($sock, "GROUP %s\r\n", $group);
	$code = nntp_read_code($sock);
	if ($code !== 211) {
		http_response_code(404);
		die("Couldn't select group " . htmlspecialchars($group) . ": " . $lastResponse);
	}
	$g = explode(' ', $lastResponse);
	$high = (int) $g[1];
	$low = (int) $g[2];
	$count = (int) $g[3];
}

function nntp_listgroup($sock, $group, &$articleIDs) {
	fprintf($sock, "LISTGROUP %s\r\n", $group);
	$code = nntp_read_code($sock);
	if ($code !== 211) {
		http_response_code(404);
		die("No such group");
	}
	$articleIDs = array();
	for (;;) {
		$s = fgets($sock);
		if ($s === ".\r\n") {
			break;
		}
		$articleIDs[] = (int) $s;
	}
}

function nntp_newnews($sock, $since, &$articles) {
	$articles = array();
	$dt = new DateTime('@' . $since, new DateTimeZone('Etc/UTC'));
	$datetime = $dt->format('Ymd His');
	fprintf($sock, "NEWNEWS * %s GMT\r\n", $datetime);
	nntp_expect_code($sock, 230);
	for (;;) {
		$s = nntp_read_line($sock);
		if ($s === ".") {
			break;
		}
		$articles[] = $s;
	}
}

function nntp_overview($sock, $start, $end, &$articles) {
	fprintf($sock, "OVER %d-%d\r\n", $end, $start);
	if (nntp_expect_code_return($sock, 224)) {
		error_log("OVER $end-$start failed: $lastResponse", 0);
		die();
	}
	$articles = array();
	for (;;) {
		$s = nntp_read_line($sock);
		if ($s === ".") {
			break;
		}
		$fields = explode("\t", $s);
		if (!isset($fields[7])) {
			error_log("Unexpected response: " . $s, 0);
			break;
		}
		$a = [
			'number' => htmlspecialchars($fields[0]),
			'subject' => htmlspecialchars(decode_encoded_header($fields[1])),
			'from' => htmlspecialchars(decode_encoded_header($fields[2])),
			'date' => strtotime($fields[3]),
			'messageid' => htmlspecialchars($fields[4]),
			'references' => htmlspecialchars($fields[5]),
			'bytes' => htmlspecialchars($fields[6]),
			'lines' => htmlspecialchars($fields[7]),
			'xref' => null,
		];
		if (isset($fields[8]) && str_starts_with($fields[8], "Xref: ")) {
			$a['xref'] = substr($fields[8], 6);
		}
		$articles[] = $a;
	}
}

function nntp_article($sock, $messageID, $articleNumber, &$headers, &$body) {
	if ($messageID) {
		fprintf($sock, "ARTICLE %s\r\n", $messageID);
	} else {
		fprintf($sock, "ARTICLE %d\r\n", $articleNumber);
	}
	if (nntp_expect_code_return($sock, 220)) {
		http_response_code(404);
		if (isset($_GET['raw'])) {
			die();
		} else {
			$messageID = htmlspecialchars($messageID);
			die("Article <b>$messageID</b> not found");
		}
	}
	$headers = "";
	$body = "";
	for (;;) {
		$s = fgets($sock);
		if ($s === "\r\n") {
			break;
		}
		if ($s === ".\r\n") {
			die("Ill-formed article (no body)");
		}
		$headers .= $s;
	}
	for (;;) {
		$s = fgets($sock);
		if ($s === ".\r\n") {
			break;
		}
		$body .= $s;
	}
	if (isset($_GET['raw'])) {
		header("Content-Type: text/plain");
		echo $headers . "\r\n" . $body;
		die;
	}
}

/* Decode RFC 2047 encoded header values */
function decode_encoded_header($str) {
	global $mbAvailable;
	if ($str) {
		if ($mbAvailable) {
			$str = mb_decode_mimeheader($str); /* This works better, so use it if available */
		} else {
			$str = iconv_mime_decode($str, 2, 'utf8');
		}
	}
	return $str;
}

function xref_format(String $hdrval, &$groupCounts = null) {
	$xrefs = explode(' ', $hdrval);
	$s = "";
	$c = 0;
	foreach ($xrefs as $xref) {
		if ($c > 0) {
			[$ngrp, $artnum] = explode(':', $xref);
			$s .= sprintf(" <a href='?group=%s&article=%d'>%s:%d</a>", urlencode($ngrp), $artnum, $ngrp, $artnum);
			if ($groupCounts !== null) {
				if (array_key_exists($ngrp, $groupCounts)) {
					$groupCounts[$ngrp] += 1;
				} else {
					$groupCounts[$ngrp] = 1;
				}
			}
		} else {
			$s .= $xref; /* Server name */
		}
		$c++;
	}
	return $s;
}

function extract_headers($headers, $wantedHeaders, &$outputHeaders) {
	$outputHeaders = array();
	$hdr = strtok($headers, "\r\n");
	while ($hdr !== false) {
		/* When displayed in HTML, extra spaces are generally ingored anyways so we don't need to trim here */
		[$hdrname, $hdrval] = explode(':', $hdr, 2);
		if (in_array($hdrname, $wantedHeaders)) {
			$s = "";
			$c = 0;
			$hdrval = trim($hdrval);
			if ($hdrname === "Date") {
				$epoch = strtotime($hdrval);
				$outputHeaders[$hdrname] = date('r', $epoch);
			} else if ($hdrname === "Newsgroups") {
				$ngrps = explode(',', $hdrval);
				foreach ($ngrps as $ngrp) {
					$ngrp = trim($ngrp);
					$s .= sprintf("%s<a href='?group=%s'>%s</a>", $c > 0 ? ", " : "", urlencode($ngrp), $ngrp);
					$c++;
				}
				$outputHeaders[$hdrname] = $s;
			} else if ($hdrname === "References" || $hdrname === "Message-ID") {
				$refs = explode(' ', $hdrval);
				foreach ($refs as $ref) {
					$ref = trim($ref);
					$s .= sprintf("%s<a href='?message=%s'>%s</a>", $c > 0 ? " " : "", urlencode($ref), htmlentities($ref));
					$c++;
				}
				$outputHeaders[$hdrname] = $s;
			} else if ($hdrname === "Xref") {
				$outputHeaders[$hdrname] = xref_format($hdrval);
			} else {
				if ($hdrname === "From" || $hdrname === "Subject") {
					$hdrval = decode_encoded_header($hdrval);
				}
				$outputHeaders[$hdrname] = htmlspecialchars($hdrval);
			}
		}
		$hdr = strtok("\r\n");
	}
	/* Ensure the headers are ordered in the same way each time, regardless of the order in the article */
	$outputHeaders = array_intersect_key(
		array_merge(array_flip($wantedHeaders), $outputHeaders), $outputHeaders
	);
}
?>