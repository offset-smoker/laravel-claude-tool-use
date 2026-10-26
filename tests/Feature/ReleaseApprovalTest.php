<?php

declare(strict_types=1);

use App\Assistant\Tools\ToolRegistry;
use App\Enums\AnswerStatus;
use App\Enums\ReleaseStatus;
use App\Models\PendingRelease;
use App\Models\Record;

function proposePavilionRelease(): PendingRelease
{
    claude('pavilion_get', 'pavilion_propose', 'pavilion_final');
    ask('Please release the Willowbend pavilion booking.');

    return PendingRelease::query()->sole();
}

it('saves a proposal as pending and releases nothing', function () {
    claude('pavilion_get', 'pavilion_propose', 'pavilion_final');

    $result = ask('Please release the Willowbend pavilion booking.');
    $pending = PendingRelease::query()->sole();

    expect($result->status)->toBe(AnswerStatus::Verified)
        ->and($result->pendingReleaseIds)->toBe([$pending->id])
        ->and($pending->status)->toBe(ReleaseStatus::Pending)
        ->and($pending->released_body)->toBeNull()
        ->and($pending->reviewed_by)->toBeNull()
        ->and($pending->requested_in_run)->toBe($result->runId)
        ->and($pending->redactions)->toHaveCount(4)
        ->and($pending->record_body_sha256)->toBe(Record::query()->findOrFail('REC-0004')->bodyHash());
});

it('tells the model the proposal is not a release', function () {
    $transport = claude('pavilion_get', 'pavilion_propose', 'pavilion_final');

    ask('Please release the Willowbend pavilion booking.');

    expect(sentMessages($transport, 2)[4]['content'][0]['content'])
        ->toContain('Nothing has been released: a human reviewer must approve it first.');
});

it('offers the model no tool that approves or releases', function () {
    $names = array_column(app(ToolRegistry::class)->definitions(), 'name');

    expect($names)->toBe(['search_records', 'get_record', 'propose_redaction']);
});

it('rejects a redaction that does not quote the record exactly', function () {
    $transport = claude('pavilion_get', 'pavilion_bad_quote', 'pavilion_final');

    ask('Please release the Willowbend pavilion booking.');

    $result = sentMessages($transport, 2)[4]['content'][0];

    expect($result['is_error'])->toBeTrue()
        ->and($result['content'])->toContain('does not quote the text of REC-0004 exactly')
        ->and(PendingRelease::query()->count())->toBe(0);
});

it('refuses a second open proposal for the same record', function () {
    $first = proposePavilionRelease();
    $transport = claude('pavilion_propose', 'pavilion_final');

    ask('Release the pavilion booking again.');

    // Requests 0 to 2 were the first run; request 4 carries the second run's tool result.
    $result = sentMessages($transport, 4)[2]['content'][0];

    expect($result['is_error'])->toBeTrue()
        ->and($result['content'])->toContain("already has a proposal awaiting review ({$first->id})")
        ->and(PendingRelease::query()->count())->toBe(1);
});

it('lists pending proposals, and says so when there are none', function () {
    $this->artisan('records:pending')
        ->expectsOutput('No releases are waiting for review.')
        ->assertSuccessful();

    $pending = proposePavilionRelease();

    $this->artisan('records:pending')
        ->expectsTable(
            ['Proposal', 'Record', 'Title', 'Redactions', 'Proposed'],
            [[$pending->id, 'REC-0004', 'Park pavilion reservation, Willowbend Park', 4, $pending->created_at->toDateTimeString()]],
        )
        ->assertSuccessful();
});

it('releases the redacted text only after a person confirms', function () {
    $pending = proposePavilionRelease();

    $this->artisan('records:approve', ['proposal' => $pending->id, '--reviewer' => 'Reviewer Sample'])
        ->expectsOutputToContain('Redact (personal_contact): "phone 555-0177"')
        ->expectsOutputToContain('Pavilion B reserved for a family reunion on 2026-06-20 by [REDACTED: personal_identifier]')
        ->expectsConfirmation('Release REC-0004 with 4 redaction(s), approved by Reviewer Sample? This cannot be undone.', 'yes')
        ->expectsOutputToContain('Released REC-0004')
        ->assertSuccessful();

    $pending->refresh();

    expect($pending->status)->toBe(ReleaseStatus::Approved)
        ->and($pending->reviewed_by)->toBe('Reviewer Sample')
        ->and($pending->reviewed_at)->not->toBeNull()
        ->and($pending->released_body)->toBe(
            'Pavilion B reserved for a family reunion on 2026-06-20 by [REDACTED: personal_identifier], '
            .'[REDACTED: personal_contact], [REDACTED: personal_contact]. Deposit of $50 received. '
            .'The applicant noted that [REDACTED: medical] and asked for the accessible route to stay open.'
        )
        ->and($pending->released_body)->not->toContain('555-0177');
});

it('releases nothing when the reviewer declines the confirmation', function () {
    $pending = proposePavilionRelease();

    $this->artisan('records:approve', ['proposal' => $pending->id, '--reviewer' => 'Reviewer Sample'])
        ->expectsConfirmation('Release REC-0004 with 4 redaction(s), approved by Reviewer Sample? This cannot be undone.', 'no')
        ->expectsOutput('Nothing was released.')
        ->assertFailed();

    expect($pending->refresh()->status)->toBe(ReleaseStatus::Pending)
        ->and($pending->released_body)->toBeNull();
});

it('requires a reviewer name to approve', function () {
    $pending = proposePavilionRelease();

    $this->artisan('records:approve', ['proposal' => $pending->id])
        ->expectsOutputToContain('The reviewer field is required.')
        ->assertExitCode(2);

    expect($pending->refresh()->status)->toBe(ReleaseStatus::Pending);
});

it('refuses to approve a proposal that is unknown or already reviewed', function () {
    $this->artisan('records:approve', ['proposal' => '01JZZZZZZZZZZZZZZZZZZZZZZZ', '--reviewer' => 'Reviewer Sample'])
        ->expectsOutputToContain('No proposal has that id.')
        ->assertFailed();

    $pending = proposePavilionRelease();
    $this->artisan('records:reject', ['proposal' => $pending->id, '--reviewer' => 'Reviewer Sample', '--reason' => 'Needs a second look.'])
        ->assertSuccessful();

    $this->artisan('records:approve', ['proposal' => $pending->id, '--reviewer' => 'Reviewer Sample'])
        ->expectsOutputToContain("Proposal {$pending->id} is already rejected.")
        ->assertFailed();
});

it('refuses to approve once the record has changed since the proposal', function () {
    $pending = proposePavilionRelease();
    Record::query()->whereKey('REC-0004')->update(['body' => 'Rewritten after the proposal. Jordan Placeholder, phone 555-0177, home address 12 Fictional Way.']);

    $this->artisan('records:approve', ['proposal' => $pending->id, '--reviewer' => 'Reviewer Sample'])
        ->expectsConfirmation('Release REC-0004 with 4 redaction(s), approved by Reviewer Sample? This cannot be undone.', 'yes')
        ->expectsOutputToContain('REC-0004 has changed since this proposal was made.')
        ->assertFailed();

    expect($pending->refresh()->status)->toBe(ReleaseStatus::Pending);
});

it('warns the reviewer when a proposal redacts nothing', function () {
    claude('injection_get', 'injection_obeyed_propose', 'injection_obeyed_final');
    ask('What does the budget hearing comment card say?');
    $pending = PendingRelease::query()->sole();

    $this->artisan('records:approve', ['proposal' => $pending->id, '--reviewer' => 'Reviewer Sample'])
        ->expectsOutputToContain('This proposal redacts nothing. The full record text would be released.')
        ->expectsConfirmation('Release REC-0007 with 0 redaction(s), approved by Reviewer Sample? This cannot be undone.', 'no')
        ->assertFailed();
});

it('rejects a proposal with a reason and releases nothing', function () {
    $pending = proposePavilionRelease();

    $this->artisan('records:reject', ['proposal' => $pending->id, '--reviewer' => 'Reviewer Sample', '--reason' => 'The deposit amount should stay redacted too.'])
        ->expectsOutputToContain("Rejected proposal {$pending->id} for REC-0004. Nothing was released.")
        ->assertSuccessful();

    $pending->refresh();

    expect($pending->status)->toBe(ReleaseStatus::Rejected)
        ->and($pending->review_note)->toBe('The deposit amount should stay redacted too.')
        ->and($pending->released_body)->toBeNull();
});

it('requires a reason to reject, and refuses to reject twice', function () {
    $pending = proposePavilionRelease();

    $this->artisan('records:reject', ['proposal' => $pending->id, '--reviewer' => 'Reviewer Sample'])
        ->expectsOutputToContain('The reason field is required.')
        ->assertExitCode(2);

    $this->artisan('records:reject', ['proposal' => $pending->id, '--reviewer' => 'Reviewer Sample', '--reason' => 'Not now.'])
        ->assertSuccessful();

    $this->artisan('records:reject', ['proposal' => $pending->id, '--reviewer' => 'Reviewer Sample', '--reason' => 'Not now.'])
        ->expectsOutputToContain('is already rejected')
        ->assertFailed();

    $this->artisan('records:reject', ['proposal' => 'not-a-proposal', '--reviewer' => 'Reviewer Sample', '--reason' => 'Not now.'])
        ->expectsOutputToContain('No proposal has the id not-a-proposal.')
        ->assertFailed();
});
