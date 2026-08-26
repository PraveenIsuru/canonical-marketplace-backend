<?php

declare(strict_types=1);

/**
 * Guards the shared documentation folder against drift between the two repositories.
 *
 * development-docs/shared/ must be byte identical here and in the frontend repository.
 * This repository owns the contract, so a difference means either the copy step was
 * skipped or the frontend edited a file it does not own. Both are worth failing over,
 * because building against a contract nobody implements is the exact failure the
 * shared folder exists to prevent.
 *
 * Deliberately a unit test. It touches only the filesystem, so it must not depend on a
 * database connection or on the application booting.
 *
 * See development-docs/shared/integration-protocol.md.
 */

/**
 * @return array<string, string> filename => sha256 of its normalised contents
 */
function hashSharedDocs(string $directory): array
{
    $hashes = [];

    foreach (glob($directory.'/*.md') ?: [] as $path) {
        /*
         * Line endings are normalised before hashing, and this is not cosmetic.
         *
         * This repository carries a .gitattributes with eol=lf while the frontend does
         * not, so on Windows the two checkouts of a byte identical document differ by
         * one byte per line. Hashing raw bytes would report drift on every commit
         * forever, and a check that cries wolf is a check people learn to ignore.
         *
         * What matters is that the content agrees. Line endings are a platform
         * artifact, not a contract difference.
         */
        $contents = (string) file_get_contents($path);
        $normalised = str_replace(["\r\n", "\r"], "\n", $contents);

        $hashes[basename($path)] = hash('sha256', $normalised);
    }

    ksort($hashes);

    return $hashes;
}

it('keeps the shared docs folder identical to the frontend repository', function (): void {
    $repositoryRoot = dirname(__DIR__, 2);
    $local = $repositoryRoot.'/development-docs/shared';
    $sibling = $repositoryRoot.'/../frontend/development-docs/shared';

    if (! is_dir($sibling)) {
        $this->markTestSkipped(
            'Frontend repository not found at ../frontend. Skipping the shared docs sync check.'
        );
    }

    if (! is_dir($local)) {
        $this->fail('development-docs/shared is missing from this repository.');
    }

    $localHashes = hashSharedDocs($local);
    $siblingHashes = hashSharedDocs($sibling);

    $missingInFrontend = array_keys(array_diff_key($localHashes, $siblingHashes));
    $missingInBackend = array_keys(array_diff_key($siblingHashes, $localHashes));

    $differing = array_keys(array_filter(
        array_intersect_key($localHashes, $siblingHashes),
        fn (string $hash, string $name): bool => $hash !== $siblingHashes[$name],
        ARRAY_FILTER_USE_BOTH
    ));

    $problems = [];

    if ($missingInFrontend !== []) {
        $problems[] = 'Missing from the frontend: '.implode(', ', $missingInFrontend);
    }

    if ($missingInBackend !== []) {
        $problems[] = 'Missing from the backend: '.implode(', ', $missingInBackend);
    }

    if ($differing !== []) {
        $problems[] = 'Contents differ: '.implode(', ', $differing);
    }

    if ($problems !== []) {
        $this->fail(
            "development-docs/shared has drifted between the two repositories.\n"
            .implode("\n", $problems)
            ."\n\nThis repository owns the contract. Resolve by copying from here:\n"
            ."  cp -r development-docs/shared/. ../frontend/development-docs/shared/\n"
            .'Then commit both repositories.'
        );
    }

    expect($localHashes)->toBe($siblingHashes);
});
