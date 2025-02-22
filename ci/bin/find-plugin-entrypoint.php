<?php

if ($argc < 4) {
    fwrite(STDERR, "Usage: php script.php <parent_directory> <slug> <type>\n");
    exit(1);
}

$parent_directory = rtrim($argv[1], DIRECTORY_SEPARATOR);
$slug = strtolower($argv[2]);
$type = strtolower($argv[3]);

echo "Starting extraction process...\n";
echo "Parent Directory: $parent_directory\n";
echo "Slug: $slug\n";
echo "Type: $type\n";

if (!is_dir($parent_directory)) {
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

// Function to find a plugin entry point (returns only the filename)
function find_plugin_entry_point(string $directory): ?string {
    echo "Searching for plugin entry point in: $directory\n";
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

    foreach ($files as $file) {
        if ($file->isFile() && preg_match('/\.php$/', $file->getFilename())) {
            $content = file_get_contents($file->getPathname());

            if (preg_match('/^\s*\*?\s*Plugin Name\s*:/mi', $content)) {
                echo "Found plugin entry point: " . $file->getFilename() . "\n";
                return $file->getFilename(); // Return only the filename
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
    if (!preg_match('/^\s*\*?\s*Theme Name\s*:/mi', $contents)) {
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
    fwrite(STDERR, "Error: No valid entry point found.\n");
    exit(1);
}

echo "Final Results:\n";
echo "Plugin/Theme Directory: $plugin_directory\n";
echo "Entry Point: $entry_point\n";

// Correct way to set outputs in GitHub Actions
$github_output = getenv('GITHUB_OUTPUT');

if ($github_output) {
    passthru("echo \"plugin_directory=$plugin_directory\" >> \"$github_output\"");
    passthru("echo \"entry_point=$entry_point\" >> \"$github_output\"");
}

echo "Script execution completed successfully.\n";
exit(0);