<?php

// Fails the build of the image of the functions when PHP lacks an extension the application requires: the
// ext-* packages of the require section of composer.json, which a polyfill cannot stand in for here (the
// extension must be loaded, not just provided by a package).
//
//   php check-extensions.php composer.json

$composer = json_decode((string) file_get_contents($argv[1] ?? 'composer.json'), true, 512, JSON_THROW_ON_ERROR);
$required = array_values(array_filter(array_keys($composer['require'] ?? []), fn (string $package): bool => str_starts_with($package, 'ext-')));
$missing = array_values(array_filter($required, fn (string $package): bool => ! extension_loaded(substr($package, 4))));

if ($required === []) {
    fwrite(STDERR, "composer.json requires no PHP extension: nothing to check.\n");
    exit(1);
}

if ($missing !== []) {
    fwrite(STDERR, 'PHP '.PHP_VERSION.' of this image lacks extensions the application requires: '.implode(', ', $missing).".\n");
    exit(1);
}

echo 'PHP '.PHP_VERSION.' loads the '.count($required).' extensions the application requires: '.implode(', ', array_map(fn (string $package): string => substr($package, 4), $required)).".\n";
