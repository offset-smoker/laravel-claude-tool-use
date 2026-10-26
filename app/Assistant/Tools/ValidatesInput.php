<?php

declare(strict_types=1);

namespace App\Assistant\Tools;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ValidatedInput;

trait ValidatesInput
{
    /**
     * Tool input is model output, so it is validated like any request body.
     *
     * @param  array<array-key, mixed>  $input
     * @param  array<string, mixed>  $rules
     *
     * @throws ToolInputInvalid
     */
    private function validated(array $input, array $rules): ValidatedInput
    {
        $validator = Validator::make($input, $rules);

        if ($validator->fails()) {
            throw new ToolInputInvalid('Invalid input: '.implode(' ', $validator->errors()->all()));
        }

        return $validator->safe();
    }
}
