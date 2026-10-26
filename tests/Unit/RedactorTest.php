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

it('redacts the union of two passages that overlap without nesting', function () {
    $redacted = (new Redactor)->apply('Jordan Placeholder, phone 555-0177.', [
        ['text' => 'Jordan Placeholder, phone 555', 'reason' => 'personal_identifier'],
        ['text' => 'phone 555-0177', 'reason' => 'personal_contact'],
    ]);

    expect($redacted)->toBe('[REDACTED: personal_identifier, personal_contact].')
        ->not->toContain('0177');
});

it('never matches a passage inside a placeholder another passage produced', function () {
    $redacted = (new Redactor)->apply('Call 555-0119 for contact.', [
        ['text' => '555-0119', 'reason' => 'personal_contact'],
        ['text' => 'contact', 'reason' => 'security_sensitive'],
    ]);

    expect($redacted)->toBe('Call [REDACTED: personal_contact] for [REDACTED: security_sensitive].');
});

it('merges adjacent passages into one placeholder', function () {
    $redacted = (new Redactor)->apply('ab', [
        ['text' => 'a', 'reason' => 'personal_identifier'],
        ['text' => 'b', 'reason' => 'personal_identifier'],
    ]);

    expect($redacted)->toBe('[REDACTED: personal_identifier]');
});

it('keeps multibyte text around a redaction intact', function () {
    $redacted = (new Redactor)->apply('Café Ünïcode 555-0101 ✓', [['text' => '555-0101', 'reason' => 'personal_contact']]);

    expect($redacted)->toBe('Café Ünïcode [REDACTED: personal_contact] ✓');
});

it('ignores an empty passage', function () {
    expect((new Redactor)->apply('Plain text.', [['text' => '', 'reason' => 'personal_contact']]))->toBe('Plain text.');
});
