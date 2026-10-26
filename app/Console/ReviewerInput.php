<?php

declare(strict_types=1);

namespace App\Console;

use Illuminate\Support\Facades\Validator;

final class ReviewerInput
{
    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $rules
     * @return list<string> error messages, empty when the input is valid
     */
    public static function errors(array $input, array $rules): array
    {
        return array_values(Validator::make($input, $rules)->errors()->all());
    }

    /** @return array<string, list<string>> */
    public static function reviewerRule(): array
    {
        return ['reviewer' => ['required', 'string', 'min:2', 'max:100']];
    }
}
