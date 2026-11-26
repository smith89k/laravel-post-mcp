<?php

namespace Smith89k\PostMcp\Tests\Fixtures\Requests;

class UpdateRichPostRequest extends StoreRichPostRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'title' => ['sometimes', 'required', 'string', 'max:120'],
            'body' => ['sometimes', 'required', 'string', 'min:20'],
        ];
    }
}
