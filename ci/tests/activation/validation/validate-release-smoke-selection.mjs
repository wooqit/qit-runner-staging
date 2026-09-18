import assert from 'node:assert/strict';
import path from 'node:path';
import {spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const validationDirectory = path.dirname(fileURLToPath(import.meta.url));
const packageRoot = path.resolve(validationDirectory, '../test-package');
const npx = process.platform === 'win32' ? 'npx.cmd' : 'npx';

function listTitles(grep) {
    const argumentsList = ['playwright', 'test', '--list', '--reporter=json'];
    if (grep) {
        argumentsList.push(`--grep=${grep}`);
    }

    const result = spawnSync(npx, argumentsList, {
        cwd: packageRoot,
        encoding: 'utf8',
    });

    if (result.status !== 0) {
        process.stderr.write(result.stderr);
        process.stdout.write(result.stdout);
        throw new Error(`Playwright test selection failed with exit code ${result.status}`);
    }

    const jsonStart = result.stdout.indexOf('{');
    assert.notEqual(jsonStart, -1, 'Playwright JSON reporter did not emit a JSON object');
    const report = JSON.parse(result.stdout.slice(jsonStart));
    const titles = [];

    function collectSpecs(suite) {
        for (const spec of suite.specs || []) {
            titles.push(spec.title);
        }
        for (const childSuite of suite.suites || []) {
            collectSpecs(childSuite);
        }
    }

    for (const suite of report.suites || []) {
        collectSpecs(suite);
    }

    return titles;
}

const releaseSmokeTitles = listTitles('@release-smoke');
assert.deepEqual(
    releaseSmokeTitles.sort(),
    ['Activate Plugins', 'Verify global request resilience'],
    'The release-smoke selector must contain only activation and the resilience verifier'
);

for (const [variation, titles] of [
    ['default/full', listTitles()],
    ['basic', listTitles('@basic')],
]) {
    assert.equal(titles.includes('Activate Plugins'), true, `${variation} must activate the plugin`);
    assert.equal(
        titles.includes('Verify global request resilience'),
        true,
        `${variation} must enforce the resilience verifier`
    );
}

const hostPlanTitles = listTitles('@host-plan');
assert.equal(hostPlanTitles.includes('Activate Plugins'), true, 'host-plan must collect activation evidence');
assert.equal(
    hostPlanTitles.includes('Verify global request resilience'),
    false,
    'host-plan must not enforce the resilience verifier'
);

console.log(`Release-smoke selection verified: ${releaseSmokeTitles.join(', ')}`);
console.log('Default/full and basic enforce the verifier; host-plan collects evidence without enforcing it.');
