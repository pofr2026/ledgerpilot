<?php
/**
 * PHPUnit bootstrap for the LedgerPilot module.
 *
 * Scope: we unit-test the PURE engine classes (normalizer, retriever, matcher,
 * planners) with zero Dolibarr coupling — exactly like bankimport's pure helpers
 * (FeeSplitter, EntryPlan, …). Classes that touch Dolibarr (DoliDB / native objects)
 * are exercised in the live container instead, not here.
 *
 * Autoloading: prefer composer's PSR-4 map once vendor/ has been built; otherwise
 * fall back to a hand-rolled PSR-4 loader so the suite runs even before
 * `composer install` (the dev container ships no composer — see README/spec).
 */

$autoload = __DIR__.'/../vendor/autoload.php';
if (is_file($autoload)) {
	require_once $autoload;
	return;
}

// Hand-rolled PSR-4 fallback. The more specific Tests\ prefix is checked first.
spl_autoload_register(static function (string $class): void {
	$prefixes = array(
		'LedgerPilot\\Tests\\' => __DIR__.'/',
		'LedgerPilot\\'        => __DIR__.'/../core/class/',
	);
	foreach ($prefixes as $prefix => $baseDir) {
		if (strncmp($class, $prefix, strlen($prefix)) === 0) {
			$relative = substr($class, strlen($prefix));
			$file = $baseDir.str_replace('\\', '/', $relative).'.php';
			if (is_file($file)) {
				require_once $file;
			}
			return;
		}
	}
});
