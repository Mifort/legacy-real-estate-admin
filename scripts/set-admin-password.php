<?php
// CLI only. Password is supplied through stdin, never a command-line argument.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../php/config.php';
require __DIR__ . '/../php/functions.php';
$password = rtrim(stream_get_contents(STDIN), "\r\n");
if (strlen($password) < 20) {
    fwrite(STDERR, "Password must contain at least 20 bytes.\n");
    exit(1);
}
db_connect();
$result = db_query("SELECT COUNT(*) AS n FROM NL_USER WHERE NL_USER_LOGIN = 'admin'");
if (!$result || (int)db_fetch_assoc($result)['n'] !== 1) {
    fwrite(STDERR, "Expected exactly one admin account.\n");
    exit(1);
}
$query = "UPDATE NL_USER SET NL_USER_PASSWORD = AES_ENCRYPT(" . db_quote($password)
    . ", " . db_quote(AESKEY) . ") WHERE NL_USER_LOGIN = 'admin'";
if (!db_query($query)) {
    // Do not log a query containing the password/key.
    fwrite(STDERR, "Could not update admin password.\n");
    exit(1);
}
db_disconnect();
echo "Admin password updated.\n";
