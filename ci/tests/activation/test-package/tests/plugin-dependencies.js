/**
 * Turns the text after "Requires:" on a plugins.php row into dependency names.
 *
 * WordPress joins the names with commas as plain text, and a name can contain
 * commas itself, so the parts are rejoined into the longest installed plugin
 * name they spell before falling back to a single part.
 *
 * @param {string} requiresText The row's "Requires:" paragraph text.
 * @param {string[]} installedPluginNames Names of every plugin listed on the page.
 * @returns {string[]}
 */
export function parseRequiredPluginNames(requiresText, installedPluginNames) {
    const cleanText = requiresText.replace(/^.*?Requires:\s*/i, '').trim();
    const parts = cleanText.split(',');
    const installed = new Set(installedPluginNames);
    const dependencies = [];

    for (let start = 0; start < parts.length;) {
        let end = parts.length;
        while (end > start + 1 && !installed.has(parts.slice(start, end).join(',').trim())) {
            end--;
        }
        dependencies.push(parts.slice(start, end).join(',').trim());
        start = end;
    }

    return dependencies.filter(dep => dep.length > 0);
}

/**
 * Replaces each plugin's raw "Requires:" text with its parsed dependency names.
 * A plugin's own row is left out of its candidates: a plugin named "Alpha, Beta"
 * that requires "Alpha" and "Beta" would otherwise resolve to itself.
 *
 * @param {Array<{name: string, requiresText: string}>} plugins Rows read from plugins.php.
 * @returns {Array<{name: string, dependencies: string[]}>}
 */
export function withRequiredPluginNames(plugins) {
    return plugins.map(({requiresText, ...plugin}, index) => ({
        ...plugin,
        dependencies: parseRequiredPluginNames(
            requiresText,
            plugins.filter((_, otherIndex) => otherIndex !== index).map(other => other.name),
        ),
    }));
}
