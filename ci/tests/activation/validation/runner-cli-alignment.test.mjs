import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import {fileURLToPath} from 'node:url';

const validationDirectory = path.dirname(fileURLToPath(import.meta.url));
const repositoryRoot = path.resolve(validationDirectory, '../../../..');
const workflowPath = path.join(repositoryRoot, '.github/workflows/ci-runner-activation.yml');

function getActivationRunnerDirectory() {
    const workflow = fs.readFileSync(workflowPath, 'utf8');
    const stepName = '      - name: Run Plugin Activation Test & Notify Manager';
    const stepStart = workflow.indexOf(stepName);

    assert.notEqual(stepStart, -1, 'Activation workflow runner step was not found');

    const nextStep = workflow.indexOf('\n      - name:', stepStart + stepName.length);
    const step = workflow.slice(stepStart, nextStep === -1 ? undefined : nextStep);
    const directoryMatch = step.match(/^\s+working-directory:\s+(\S+)\s*$/m);

    assert.notEqual(directoryMatch, null, 'Activation workflow runner step has no working directory');

    return directoryMatch[1];
}

function assertRunnerCliCompatibility(runnerDirectory) {
    const runnerRoot = path.join(repositoryRoot, runnerDirectory);
    const runnerScript = fs.readFileSync(path.join(runnerRoot, 'run-activation-test.php'), 'utf8');
    const qitPhar = fs.readFileSync(path.join(runnerRoot, 'qit')).toString('latin1');

    const cliSupportsPassthroughTarget = qitPhar.includes("addOption('passthrough_target'");
    const cliSupportsPwTestTag = qitPhar.includes("addOption('pw_test_tag'");
    const runnerUsesPassthroughTarget = runnerScript.includes(
        '--passthrough_target=woocommerce/activation'
    );
    const runnerUsesPwTestTag = runnerScript.includes('--pw_test_tag');

    assert.equal(
        cliSupportsPassthroughTarget || cliSupportsPwTestTag,
        true,
        `${runnerDirectory} bundled QIT CLI exposes no supported test-variation syntax`
    );
    assert.notEqual(
        runnerUsesPassthroughTarget,
        runnerUsesPwTestTag,
        'Activation runner must use exactly one supported test-variation syntax'
    );
    assert.equal(
        (runnerUsesPassthroughTarget && cliSupportsPassthroughTarget)
            || (runnerUsesPwTestTag && cliSupportsPwTestTag),
        true,
        `${runnerDirectory} uses a test-variation syntax unsupported by its bundled QIT CLI`
    );
}

test('activation workflow selects a runner compatible with its bundled QIT CLI', () => {
    assertRunnerCliCompatibility(getActivationRunnerDirectory());
});

test('all bundled activation runners remain compatible with their QIT CLI', () => {
    for (const runnerDirectory of ['ci/tests/activation', 'ci/tests/activation-legacy']) {
        assertRunnerCliCompatibility(runnerDirectory);
    }
});
