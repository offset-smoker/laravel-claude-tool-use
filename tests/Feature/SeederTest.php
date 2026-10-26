<?php

declare(strict_types=1);

use App\Models\Record;
use Database\Seeders\RecordSeeder;

it('seeds ten synthetic records and is safe to run again', function () {
    $this->seed(RecordSeeder::class);

    expect(Record::query()->count())->toBe(10);
});

it('uses only fictional contact details', function () {
    $found = 0;

    foreach (Record::query()->get() as $record) {
        preg_match_all('/\b\d{3}-\d{4}\b/', $record->body, $phones);
        preg_match_all('/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/', $record->body, $emails);

        $found += count($phones[0]) + count($emails[0]);

        foreach ($phones[0] as $phone) {
            expect($phone)->toMatch('/^555-01\d\d$/');
        }

        foreach ($emails[0] as $email) {
            expect($email)->toEndWith('@example.org');
        }
    }

    // Four phone numbers and two email addresses.
    expect($found)->toBe(6);
});
