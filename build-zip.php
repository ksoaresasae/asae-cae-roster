<?php
/**
 * Builds the release zip with forward slashes for WordPress compatibility.
 *
 * Run from the plugin root directory:
 *   cd asae-cae-roster && php build-zip.php
 *
 * Output: releases/asae-cae-roster.zip
 *
 * IMPORTANT: Do NOT use PowerShell's Compress-Archive — it writes backslash
 * path separators which break the WordPress plugin installer.
 *
 * @package ASAE_CAE_Roster
 */

$pluginDir = __DIR__;
$zipPath   = $pluginDir . DIRECTORY_SEPARATOR . 'releases' . DIRECTORY_SEPARATOR . 'asae-cae-roster.zip';

$exclude = [ '.git', '.claude', 'releases', 'instructions', 'node_modules', '.gitignore', 'build-zip.php' ];

$zip = new ZipArchive();
if ( $zip->open( $zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
	echo "ERROR: Could not create zip at: $zipPath\n";
	exit( 1 );
}

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $pluginDir, RecursiveDirectoryIterator::SKIP_DOTS ),
	RecursiveIteratorIterator::SELF_FIRST
);

foreach ( $iterator as $file ) {
	$relative = 'asae-cae-roster/' . str_replace( '\\', '/', $iterator->getSubPathname() );
	$parts    = explode( '/', $relative );

	$skip = false;
	foreach ( $parts as $part ) {
		if ( in_array( $part, $exclude, true ) ) {
			$skip = true;
			break;
		}
	}
	if ( $skip ) {
		continue;
	}

	if ( $file->isDir() ) {
		$zip->addEmptyDir( $relative . '/' );
	} else {
		$zip->addFile( $file->getPathname(), $relative );
	}
}

echo 'Files in zip: ' . $zip->numFiles . "\n";
$zip->close();
echo "Release zip built: $zipPath\n";
