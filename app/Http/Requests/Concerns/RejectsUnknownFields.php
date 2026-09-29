<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/**
 * For API Form Requests: any input key that rules() does not name is a
 * validation error instead of being silently ignored. A client can never
 * slip in role, abilities, company_id or station_id.
 */
trait RejectsUnknownFields
{
    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $allowed = array_unique(array_map(
                    fn (string $rule): string => Str::before($rule, '.'),
                    array_keys($this->rules()),
                ));

                foreach (array_keys($this->all()) as $field) {
                    if (! in_array((string) $field, $allowed, true)) {
                        $validator->errors()->add((string) $field, 'This field is not allowed.');
                    }
                }
            },
        ];
    }
}
