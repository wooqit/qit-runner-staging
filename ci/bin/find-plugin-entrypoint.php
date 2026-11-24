<?php

use CI_CLI\FileHeaderMatcher;

require_once __DIR__ . '/../cli/src/FileHeaderMatcher.php';

if ($argc < 4) {
    fwrite(STDERR, "Usage: php script.php <parent_directory> <slug> <type>\n");
    exit(1);
}

$parent_directory = rtrim($argv[1], DIRECTORY_SEPARATOR);
$slug = strtolower($argv[2]);
$type = strtolower($argv[3]);

// Mirrors CI_CLI\Commands\DownloadPluginCommand::write_failure(): first recorded failure wins.
function write_structured_failure(string $code, string $message): void {
    $path = getenv('QIT_FAILURE_OUTPUT');
    if (!is_string($path) || $path === '') {
        return;
    }

    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
        return;
    }

    $json = json_encode(['stage' => 'setup', 'code' => $code, 'message' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return;
    }

    $handle = @fopen($path, 'x');
    if ($handle === false) {
        return;
    }

    @fwrite($handle, $json . PHP_EOL);
    fclose($handle);
}

/**
 * Append outputs without invoking a shell. Output values are single-line paths;
 * rejecting line breaks prevents additional workflow commands from being injected.
 *
 * @param array<string,string> $outputs
 */
function write_github_outputs(string $path, array $outputs): bool {
    $lines = [];

    foreach ($outputs as $name => $value) {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) || strpbrk($value, "\r\n") !== false) {
            return false;
        }

        $lines[] = $name . '=' . $value;
    }

    return file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL, FILE_APPEND | LOCK_EX) !== false;
}

echo "Starting extraction process...\n";
echo "Parent Directory: $parent_directory\n";
echo "Slug: $slug\n";
echo "Type: $type\n";

if (!is_dir($parent_directory)) {
    write_structured_failure('sut_parent_directory_not_found', "The parent directory for extracted $type $slug does not exist.");
    fwrite(STDERR, "Error: Parent directory does not exist.\n");
    exit(1);
}

// Function to find a directory in a case-insensitive way
function find_extracted_directory(string $parent_directory, string $slug): ?string {
    echo "Searching for extracted directory matching slug: $slug\n";
    $directories = scandir($parent_directory);
    foreach ($directories as $directory) {
        if ($directory === '.' || $directory === '..') {
            continue;
        }

        if (is_dir($parent_directory . DIRECTORY_SEPARATOR . $directory) && strtolower($directory) === $slug) {
            echo "Found extracted directory: $directory\n";
            return $directory; // Return only the directory name
        }
    }
    echo "No matching directory found.\n";
    return null;
}

// Function to find a plugin entry point (returns the path relative to the plugin directory)
function find_plugin_entry_point(string $directory): ?string {
    echo "Searching for plugin entry point in: $directory\n";

    // Check root-level PHP files first — the main plugin file is almost always here.
    // Recursing immediately can match bundled libraries (e.g. vendor/) that also have Plugin Name headers.
    foreach (glob($directory . '/*.php') as $file) {
        $content = file_get_contents($file);

        if (is_string($content) && FileHeaderMatcher::contains_plugin_header($content)) {
            $filename = basename($file);
            echo "Found plugin entry point in root: $filename\n";
            return $filename;
        }
    }

    // Fall back to recursive search if nothing found in root.
    echo "No entry point in root, searching subdirectories...\n";
    $directory_prefix = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

    foreach ($files as $file) {
        if ($file->isFile() && preg_match('/\.php$/', $file->getFilename())) {
            $content = file_get_contents($file->getPathname());

            if (is_string($content) && FileHeaderMatcher::contains_plugin_header($content)) {
                $relative_path = substr($file->getPathname(), strlen($directory_prefix));
                $relative_path = str_replace(DIRECTORY_SEPARATOR, '/', $relative_path);
                echo "Found plugin entry point: $relative_path\n";
                return $relative_path;
            }
        }
    }
    echo "No plugin entry point found.\n";
    return null;
}

// Function to find a theme entry point (returns only the filename)
function find_theme_entry_point(string $directory): ?string {
    echo "Searching for theme entry point in: $directory\n";

    $style_css_path = $directory . DIRECTORY_SEPARATOR . 'style.css';

    if (!file_exists($style_css_path)) {
        fwrite(STDERR, "Error: Missing style.css in theme directory.\n");
        return null;
    }

    echo "Found style.css, checking for Theme Name header...\n";
    $contents = file_get_contents($style_css_path);
    if (!is_string($contents) || !FileHeaderMatcher::contains_theme_header($contents)) {
        fwrite(STDERR, "Error: style.css does not contain a valid Theme Name header.\n");
        return null;
    }

    echo "Valid Theme Name found in style.css.\n";

    echo "Theme entry point validation passed.\n";
    return 'style.css';
}

// Find extracted directory
$plugin_directory = find_extracted_directory($parent_directory, $slug);
if (!$plugin_directory) {
    write_structured_failure('sut_directory_not_found', "The extracted $type directory for $slug was not found after archive extraction.");
    fwrite(STDERR, "Error: Extracted directory not found.\n");
    exit(1);
}

// Determine the correct entry point based on type
if ($type === 'plugin') {
    echo "Processing as a plugin...\n";
    $entry_point = find_plugin_entry_point($parent_directory . DIRECTORY_SEPARATOR . $plugin_directory);
} else {
    echo "Processing as a theme...\n";
    $entry_point = find_theme_entry_point($parent_directory . DIRECTORY_SEPARATOR . $plugin_directory);
}

if (!$entry_point) {
    write_structured_failure('sut_entry_point_not_found', "No valid $type entry point was found in the extracted $slug directory.");
    fwrite(STDERR, "Error: No valid entry point found.\n");
    exit(1);
}

echo "Final Results:\n";
echo "Plugin/Theme Directory: $plugin_directory\n";
echo "Entry Point: $entry_point\n";

$github_output = getenv('GITHUB_OUTPUT');

if (is_string($github_output) && $github_output !== '') {
    $outputs_written = write_github_outputs($github_output, [
        'plugin_directory' => $plugin_directory,
        'entry_point'      => $entry_point,
    ]);

    if (!$outputs_written) {
        write_structured_failure('sut_entry_point_output_failed', "The detected $type entry point for $slug could not be published to the workflow.");
        fwrite(STDERR, "Error: Could not write entry point workflow outputs.\n");
        exit(1);
    }
}

echo "Script execution completed successfully.\n";
exit(0);
