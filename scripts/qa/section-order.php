<?php
/**
 *  +----------------------------------------------------------------------+
 *  | PHP Version 8                                                        |
 *  +----------------------------------------------------------------------+
 *  | Copyright (c) 1997-2021 The PHP Group                                |
 *  +----------------------------------------------------------------------+
 *  | This source file is subject to version 3.0 of the PHP license,       |
 *  | that is bundled with this package in the file LICENSE, and is        |
 *  | available through the world-wide-web at the following url:           |
 *  | http://www.php.net/license/3_0.txt.                                  |
 *  | If you did not receive a copy of the PHP license and are unable to   |
 *  | obtain it through the world-wide-web, please send a note to          |
 *  | license@php.net so we can mail you a copy immediately.               |
 *  +----------------------------------------------------------------------+
 *  | Authors:    Kim Hallberg <work@hallberg.kim>                         |
 *  |             George Peter Banyard <girgias@php.net>                   |
 *  +----------------------------------------------------------------------+
 *  | Description: This file parses the manual and checks that the         |
 *  |              necessary sections are present, and that their order    |
 *  |              is correct.                                             |
 *  +----------------------------------------------------------------------+
 */

const SKIP_FOLDER = [
    /* DateTimeImmutable docs refer to DateTime docs */
    'datetimeimmutable',
    /* Directory class refers to usual dir/ functions */
    'directory',
    /* We don't care about a tutorial doc in this script */
    'tutorial',
    /* Skip predefined variables folder */
    'variables',
];

/* Files which should be skipped, needs a *good* reason */
const SKIP_FILE = [
    /* Helper page to refer to unset() or unlink() */
    'reference/filesystem/functions/delete.xml',
    /* Helper page in case docref fails in a PHP diagnostic
     * (needs to be fixed in php-src in that case) */
    'reference/info/functions/main.xml',
    /* die() is equivalent to the language construct exit() */
    'reference/misc/functions/die.xml',
    /* This old alias was split into two functions and current
     * alias detection doesn't find it... so add it here */
    'reference/oci8/oldaliases/ocifetchinto.xml',
];

const VALID_SECTION_ROLES = [
    'description',
    'parameters',
    'returnvalues',
    'errors',
    'changelog',
    'examples',
    'notes',
    'seealso',
];

define('DOCROOT_EN', dirname(__DIR__, 3) . '/en/');

$fileCount = 0;

/* Path to the root of EN extension reference tree */

$directoryToSearch = DOCROOT_EN . 'reference';

foreach (new DirectoryIterator($directoryToSearch) as $docs) {
    if ($docs->isDot() || !$docs->isDir() || !$docs->isReadable()) {
        continue;
    }

    $fileCount += checkExtension($docs->getPathname());
}

/* Check section order in predefined classes */
$fileCount += checkExtension(DOCROOT_EN . 'language/predefined');

//echo "\n\e[0;32mFound {$fileCount} files with issues.\n";
echo "\nFound {$fileCount} files with issues.\n";

exit((bool) $fileCount);

function checkExtension($dirname)
{
    $fileCount = 0;

    $docdir = new RecursiveDirectoryIterator($dirname, FilesystemIterator::SKIP_DOTS);

    foreach ($docdir as $base) {
        if (!$base->isDir() || !$base->isReadable()) {
            continue;
        }

        // Skip folder dirs which refer to another folder
        if (\in_array($base->getBasename(), SKIP_FOLDER)) {
            continue;
        }

        foreach (getXMLFiles($dirname . '/' . $base->getFilename()) as $path => $file) {
            if (!file_exists($path)) {
                continue;
            }

            if ($errors = checkSectionErrors($path)) {
                $fileCount++;
                foreach ($errors as $error) {
                    //echo "\e[0;31mFile \e[0m{$path}\e[0;31m $error.\n";
                    echo "File {$path} $error.\n";
                }
            }
        }
    }

    return $fileCount;
}

function getXMLFiles(string $dirname)
{
    $directory = new RecursiveDirectoryIterator($dirname, FilesystemIterator::SKIP_DOTS);

    $files = [];

    foreach ($directory as $dir) {
        if ($dir->isDir() || !$dir->isReadable()) {
            continue;
        }

        if ($dir->getExtension() !== 'xml') {
            continue;
        }
        if (str_starts_with($dir->getFilename(), 'entities')) {
            continue;
        }

        /* Skip certain files */
        $pathnameFromRoot = str_replace(DOCROOT_EN, '', $dir->getPathname());
        if (in_array($pathnameFromRoot, SKIP_FILE)) {
            continue;
        }

        $files[$dir->getPathname()] = $dir->getFilename();
    }

    return $files;
}

/** Section order
 * - description
 * - parameters
 * - returnvalues
 * - errors
 * - changelog
 * - examples
 * - notes
 * - seealso
 */
function checkSectionErrors(string $path): array
{
    $isConstructorPage = false;
    $content = file_get_contents($path);

    /* Skip class definitions */
    if (str_contains($content, '<phpdoc:classref')) {
        return [];
    }

    /* Skip aliases */
    if (str_contains($content, '&info.function.alias;')
        || str_contains($content, '&Alias;')
        || str_contains($content, "&info.method.alias;")
    ) {
        return [];
    }
    /* Skip undocumented functions (for now) */
    if (str_contains($content, '&warn.undocumented.func;')) {
        return [];
    }

    /* Constructors are special */
    if (str_contains($content, '::__construct</')) {
        if (!str_contains($content, '<constructorsynopsis>')) {
            // This generates a lot of errors leave for later
            //$errors[] = "Constructors should use <constructorsynopsis> instead of <methodsynopsis>";
        }
        $isConstructorPage = true;
        //return checkSectionErrorsConstructors($content);
    }

    $dom = new DOMDocument();
    /* Load as HTML as to not verify entities */
    @$dom->loadHTML($content);

    return checkCommonSectionOrder($dom, $isConstructorPage);
}

function checkCommonSectionOrder(DOMDocument $document, bool $isConstructorPage): array
{
    $errors = [];
    $elements = [];

    foreach ($document->getElementsByTagName('include') as $node) {
        $pointer = $node->getAttribute('xpointer');

        preg_match('/refsect1\[@role=\'([\S]+)\']/i', $pointer, $matches);
        if (isset($matches[1])) {
            $role = $matches[1];

            if (!in_array($role, VALID_SECTION_ROLES)) {
                $errors[] = "Invalid section role: '$role'";
                continue;
            }
            if (in_array($role, $elements)) {
                $errors[] = "Duplicate section: '$role'";
                continue;
            }
            
            $elements[] = $role;
        }
    }

    foreach ($document->getElementsByTagName('refsect1') as $node) {
        $role = $node->getAttribute('role');
        if (!in_array($role, VALID_SECTION_ROLES)) {
            $errors[] = "Invalid section role: '$role'";
            continue;
        }
        if (in_array($role, $elements)) {
            $errors[] = "Duplicate section: '$role'";
            continue;
        }
        $elements[] = $role;
    }

    if ($elements === []) {
        $errors[] = "No sections";
        return $errors;
    }
    if (!in_array('description', $elements)) {
        $errors[] = "No description sections";
    }
    if (!in_array('parameters', $elements)) {
        $errors[] = "No parameters sections";
    }
    if (!in_array('returnvalues', $elements)) {
        // Constructor pages should not have a return value section
        if (!$isConstructorPage) {
            $errors[] = "No returnvalues sections";
        }
    } else {
        /* Constructors might share page with procedural,
         * bail out for now */
        if ($isConstructorPage) {
            return $errors;
        }
    }

    /* There are bigger issues then section order so return early */
    if ($errors) return $errors;

    if ($elements[0] !== 'description') {
        $errors[] = "Description sections is not first";
    }
    if ($elements[1] !== 'parameters') {
        $errors[] = "Parameters sections is not second";
    }

    // Check only for non constructor pages
    if (!$isConstructorPage && $elements[2] !== 'returnvalues') {
        $errors[] = "Return values sections is not third";
    }
    /* if an error section is present it must be the 4th element
     * if the page is a constructor it must be the 3rd element */
    if (in_array('errors', $elements) && $elements[3-$isConstructorPage] !== 'errors') {
        $errors[] = "Errors sections is not fourth";
    }
    /* if an See Also section is present it must be the last element */
    if (in_array('seealso', $elements) && $elements[array_key_last($elements)] !== 'seealso') {
        $errors[] = "See also sections is not last";
    }

    $flipped = array_flip($elements);
    if (in_array('errors', $elements) && in_array('changelog', $elements)) {
        if ($flipped['errors'] > $flipped['changelog']) {
            $errors[] = "Changelog section before errors";
        }
    }

    /* Check example section is in correct position */
    if (in_array('changelog', $elements) && in_array('examples', $elements)) {
        if ($flipped['changelog'] > $flipped['examples']) {
            $errors[] = "Example section before changelog";
        }
    }
    if (in_array('errors', $elements) && in_array('examples', $elements)) {
        if ($flipped['errors'] > $flipped['examples']) {
            $errors[] = "Examples section before errors";
        }
    }

    /* Check notes section is in correct position */
    if (in_array('changelog', $elements) && in_array('notes', $elements)) {
        if ($flipped['changelog'] > $flipped['notes']) {
            $errors[] = "Notes section before changelog";
        }
    }
    if (in_array('errors', $elements) && in_array('notes', $elements)) {
        if ($flipped['errors'] > $flipped['notes']) {
            $errors[] = "Notes section before errors";
        }
    }
    if (in_array('examples', $elements) && in_array('notes', $elements)) {
        if ($flipped['examples'] > $flipped['notes']) {
            $errors[] = "Notes section before examples";
        }
    }

    return $errors;
}
