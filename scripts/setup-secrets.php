<?php
// Generate credentials for a NEW installation. Never print the secrets.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$envPath = $root . '/.env';
$secretDir = $root . '/.local-secrets';
foreach ([$envPath, $secretDir] as $path) {
    if (file_exists($path) || is_link($path)) {
        fwrite(STDERR, "Local configuration already exists; refusing to overwrite credentials.\n");
        exit(1);
    }
}

umask(0077);
$values = [
    'WEB_PORT' => '8080', 'DB_PORT' => '33066',
    'DB_NAME' => 'testdb', 'DB_USER' => 'testdb',
    'DB_PASSWORD' => bin2hex(random_bytes(32)),
    'DB_ROOT_PASSWORD' => bin2hex(random_bytes(32)),
    'AES_KEY' => bin2hex(random_bytes(32)),
];
$env = '';
foreach ($values as $name => $value) {
    $env .= $name . '=' . $value . "\n";
}
$password = bin2hex(random_bytes(24)) . "\n";
$createdFiles = [];
$createdDir = false;
try {
    if (!@mkdir($secretDir, 0700)) {
        throw new RuntimeException('Could not create private directory.');
    }
    $createdDir = true;
    foreach ([$envPath => $env, $secretDir . '/admin-password' => $password] as $path => $contents) {
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            throw new RuntimeException('Could not create private file; existing files are never overwritten.');
        }
        $createdFiles[] = $path;
        $written = fwrite($handle, $contents);
        $closed = fclose($handle);
        if ($written !== strlen($contents) || !$closed) {
            throw new RuntimeException('Could not finish writing private file.');
        }
    }
} catch (Throwable $error) {
    foreach ($createdFiles as $path) {
        @unlink($path);
    }
    if ($createdDir) {
        @rmdir($secretDir);
    }
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
echo "Created .env and .local-secrets/admin-password (private, ignored by Git).\n";
