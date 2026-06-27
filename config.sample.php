<?php
/* Rename/copy this sample to config.php to adjust active configuration */

/* Server connection info */
$hostname = "127.0.0.1";
$port = 119; /* Typically either 119 or 563 if using implicit TLS on reader port */
$tls = false; /* Whether to use implicit TLS. STARTTLS is not supported. */
$modeReaderNeeded = true; /* Whether to send MODE READER if connecting to port 119 (ignored for other ports) */
$listActiveAll = true; /* Whether the non-standardized LIST ACTIVE.ALL command is available. Set to false if not to avoid unnecessary fallback delay. As of now, only LBBS supports it. */

/* User authentication */

/* Whether credentials are required to access server */
$requireCredentials = false;

/* Whether end users (to this application) are allowed to log in by providing a username and password. Useful if there is default access and users can log in for full access. */
$allowUserAuthentication = true;

/* If $requireCredentials is true and $allowUserAuthentication you MUST provide credentials here to access the NNTP server.
 * Otherwise you MAY provide credentials to force those credentials to be used. */
$username = "username";
$password = "password";

/* Group/article display */
$recentTime = 14400; /* Max interval for NEWNEWS command */
$recentLimit = 100; /* Max number of recent messages to show */
$hideEmptyThreshold = 5000; /* Max # of total groups beyond which empty groups are hidden by default */
?>