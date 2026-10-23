<?php
require __DIR__.'/../src/Support/PackageVersion.php';
$version = \Chattrn\Connector\Support\PackageVersion::VERSION;
if (! preg_match('/^\d+\.\d+\.\d+$/', $version)) { fwrite(STDERR, "Invalid release version\n"); exit(1); }
if (getenv('GITHUB_REF_TYPE') === 'tag' && getenv('GITHUB_REF_NAME') !== 'v'.$version) { fwrite(STDERR, "Tag must match PackageVersion::VERSION\n"); exit(1); }
echo "Package version: ".$version.PHP_EOL;
