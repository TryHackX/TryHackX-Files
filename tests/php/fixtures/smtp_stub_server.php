<?php

/**
 * A one-connection SMTP server for the outbox transport tests.
 *
 * It binds an ephemeral loopback port, publishes it, speaks just enough of the protocol for one
 * message, and writes what it received as JSON. `rcptReply` lets a test make the server refuse a
 * recipient so the failure path can assert on the server's own words.
 *
 * usage: smtp_stub_server.php <portPath> <transcriptPath> [rcptReply]
 */

if (PHP_SAPI !== 'cli' || $argc < 3 || $argc > 4) {
	exit(64);
}

[$script, $portPath, $transcriptPath] = $argv;
$rcptReply = $argc === 4 ? (string) $argv[3] : '250 2.1.5 Ok';

$errno = 0;
$error = '';
$server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!is_resource($server)) {
	exit(65);
}
$name = (string) stream_socket_get_name($server, false);
$port = (int) substr($name, (int) strrpos($name, ':') + 1);
if ($port <= 0 || file_put_contents($portPath, (string) $port, LOCK_EX) === false) {
	exit(66);
}

$client = @stream_socket_accept($server, 15);
if (!is_resource($client)) {
	exit(67);
}
stream_set_timeout($client, 15);

$transcript = ['commands' => [], 'mail_from' => '', 'rcpt_to' => '', 'data' => ''];
fwrite($client, "220 stub.example.test ESMTP\r\n");
while (($line = fgets($client, 4096)) !== false) {
	$command = rtrim($line, "\r\n");
	$transcript['commands'][] = $command;
	$verb = strtoupper(substr($command, 0, 4));
	if ($verb === 'EHLO' || $verb === 'HELO') {
		fwrite($client, "250-stub.example.test\r\n250 8BITMIME\r\n");
		continue;
	}
	if ($verb === 'MAIL') {
		$transcript['mail_from'] = $command;
		fwrite($client, "250 2.1.0 Ok\r\n");
		continue;
	}
	if ($verb === 'RCPT') {
		$transcript['rcpt_to'] = $command;
		fwrite($client, $rcptReply . "\r\n");
		continue;
	}
	if ($verb === 'DATA') {
		fwrite($client, "354 End data with <CR><LF>.<CR><LF>\r\n");
		$body = '';
		while (($dataLine = fgets($client, 8192)) !== false) {
			if (rtrim($dataLine, "\r\n") === '.') {
				break;
			}
			// Undo the dot stuffing the client applied.
			if (str_starts_with($dataLine, '..')) {
				$dataLine = substr($dataLine, 1);
			}
			$body .= $dataLine;
		}
		$transcript['data'] = $body;
		fwrite($client, "250 2.0.0 Ok: queued as STUB\r\n");
		continue;
	}
	if ($verb === 'QUIT') {
		fwrite($client, "221 2.0.0 Bye\r\n");
		break;
	}
	if ($verb === 'RSET' || $verb === 'NOOP') {
		fwrite($client, "250 2.0.0 Ok\r\n");
		continue;
	}
	fwrite($client, "502 5.5.2 Not implemented\r\n");
}

fclose($client);
fclose($server);
if (file_put_contents($transcriptPath, json_encode($transcript), LOCK_EX) === false) {
	exit(68);
}
exit(0);
