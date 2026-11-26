<?php

namespace Smith89k\PostMcp\Tests\Fixtures\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRichPostRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'seo_title' => ['nullable', 'string', 'max:70'],
            'seo_description' => 'nullable|string|max:160',
            'body' => ['required', 'string', 'min:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.min' => 'The body needs at least 20 characters.',
        ];
    }
}
