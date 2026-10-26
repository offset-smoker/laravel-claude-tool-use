<?php

declare(strict_types=1);

it('pins every GitHub Action in CI to a full commit SHA with a version comment', function () {
    $workflows = glob(dirname(__DIR__, 2).'/.github/workflows/*.yml') ?: [];

    expect($workflows)->not->toBeEmpty();

    foreach ($workflows as $workflow) {
        preg_match_all('/^\s*(?:-\s*)?uses:\s*(\S+)(.*)$/m', (string) file_get_contents($workflow), $uses, PREG_SET_ORDER);

        expect($uses)->not->toBeEmpty();

        foreach ($uses as [, $action, $rest]) {
            expect($action)->toMatch('/^[\w.-]+\/[\w.\/-]+@[0-9a-f]{40}$/', basename($workflow).": {$action} is not pinned to a commit SHA")
                ->and($rest)->toMatch('/^\s+#\s*v?\d+\.\d+\.\d+\s*$/', basename($workflow).": {$action} has no version comment");
        }
    }
});
