<?php

namespace App\Http\Requests;

use App\Pixelforge\PixelforgeClient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGenerationRequest extends FormRequest
{
    public function rules(PixelforgeClient $client): array
    {
        $pricing = $client->pricing();

        // Mirrors Pixelforge's own limits so users get instant feedback instead of a rejected job.
        return [
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', Rule::in($pricing->sizes())],
            'quality' => ['required', Rule::in($pricing->qualities())],
            'reference_images' => ['array', 'max:4'],
            'reference_images.*' => ['required', 'url:http,https', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'reference_images.max' => 'You can add up to 4 reference images.',
            'reference_images.*.url' => 'Each reference image must be an http(s) URL.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Ignore blank URL inputs the user left empty.
        $this->merge([
            'reference_images' => array_values(array_filter(
                array_map('trim', (array) $this->input('reference_images', [])),
                fn ($url) => $url !== '',
            )),
        ]);
    }
}
