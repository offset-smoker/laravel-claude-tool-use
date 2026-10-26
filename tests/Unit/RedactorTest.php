<?php

declare(strict_types=1);

use App\Actions\Redactor;

it('replaces every occurrence of each passage with its reason', function () {
    $redacted = (new Redactor)->apply('Call 555-0101. Again: 555-0101.', [['text' => '555-0101', 'reason' => 'personal_contact']]);

    expect($redacted)->toBe('Call [REDACTED: personal_contact]. Again: [REDACTED: personal_contact].');
});

it('applies the longest passage first so no fragment of it survives', function () {
    $redacted = (new Redactor)->apply('Applicant: Casey Testperson, casey.testperson@example.org.', [
        ['text' => 'Casey', 'reason' => 'personal_identifier'],
        ['text' => 'casey.testperson@example.org', 'reason' => 'personal_contact'],
        ['text' => 'Casey Testperson', 'reason' => 'personal_identifier'],
    ]);

    expect($redacted)->toBe('Applicant: [REDACTED: personal_identifier], [REDACTED: personal_contact].');
});

it('returns the body unchanged when there is nothing to redact', function () {
    expect((new Redactor)->apply('Plain text.', []))->toBe('Plain text.');
});
