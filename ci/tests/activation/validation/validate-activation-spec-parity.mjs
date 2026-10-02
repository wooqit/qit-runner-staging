import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const validationDirectory = path.dirname(fileURLToPath(import.meta.url));
const activationRoot = path.resolve(validationDirectory, '..');
const repositoryRoot = path.resolve(activationRoot, '../../..');
const packageSpec = path.join(activationRoot, 'test-package/tests/activation.spec.js');
const legacySpec = path.join(repositoryRoot, 'ci/tests/activation-legacy/custom-test/activation.spec.js');
const requiredBlocks = [
    'activation-test-tag',
    'initial-sut-match',
    'activation-probe-state',
    'sut-match',
    'baseline-probe',
    'post-probe-evidence',
    'unsupported-fallback',
    'verifier',
    'sort-sut-match',
];

function extractParityBlocks(file) {
    const source = fs.readFileSync(file, 'utf8');
    const pattern = /\/\/ QIT_HOOK_RESILIENCE_PARITY_START ([a-z-]+)\n([\s\S]*?)\n\s*\/\/ QIT_HOOK_RESILIENCE_PARITY_END \1/g;
    const blocks = new Map();

    for (const match of source.matchAll(pattern)) {
        assert.equal(blocks.has(match[1]), false, `Duplicate parity block "${match[1]}" in ${file}`);
        blocks.set(match[1], match[2].trim());
    }

    assert.deepEqual(
        Array.from(blocks.keys()).sort(),
        [...requiredBlocks].sort(),
        `Unexpected activation hook-resilience parity blocks in ${file}`
    );

    return blocks;
}

const packageBlocks = extractParityBlocks(packageSpec);
const legacyBlocks = extractParityBlocks(legacySpec);

for (const block of requiredBlocks) {
    assert.equal(
        packageBlocks.get(block),
        legacyBlocks.get(block),
        `Activation hook-resilience block "${block}" differs between packaged and legacy suites`
    );
}

console.log(`Activation spec parity verified across ${requiredBlocks.length} enforcement blocks.`);
