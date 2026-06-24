<?php

namespace Futurello\MoodBoard\Http\Requests;

use Futurello\MoodBoard\Services\Ai\Exceptions\AiHttpException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация payload для POST /api/v2/ai/{provider}/video.
 *
 * Контракт 1:1 с server/src/utils/schema.js:parseVideoPayload.
 */
class AiVideoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'min:1'],
            'negativePrompt' => ['sometimes', 'nullable', 'string'],
            'model' => ['sometimes', 'nullable', 'string'],
            'ratio' => ['sometimes', 'nullable', 'string'],
            'resolution' => ['sometimes', 'nullable', 'string'],
            'duration' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'seed' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'audio' => ['sometimes', 'boolean'],
            'watermark' => ['sometimes', 'boolean'],
            'cfgScale' => ['sometimes', 'nullable', 'numeric'],
            'personGeneration' => ['sometimes', 'nullable', 'string'],
            'referenceImages' => ['sometimes', 'array'],
            'referenceImages.*.mimeType' => ['required_with:referenceImages.*', 'string'],
            'referenceImages.*.data' => ['required_with:referenceImages.*', 'string'],
        ];
    }

    /**
     * @return array{
     *   prompt: string,
     *   negativePrompt: string|null,
     *   model: string|null,
     *   ratio: string|null,
     *   resolution: string|null,
     *   duration: int|null,
     *   seed: int|null,
     *   audio: bool|null,
     *   watermark: bool|null,
     *   cfgScale: float|null,
     *   personGeneration: string|null,
     *   referenceImages?: list<array{mimeType: string, data: string}>,
     * }
     */
    public function normalized(): array
    {
        $result = [
            'prompt' => trim((string) $this->input('prompt')),
            'negativePrompt' => $this->optionalString('negativePrompt'),
            'model' => $this->optionalString('model'),
            'ratio' => $this->optionalString('ratio'),
            'resolution' => $this->optionalString('resolution'),
            'duration' => $this->has('duration') && $this->input('duration') !== null ? (int) $this->input('duration') : null,
            'seed' => $this->has('seed') && $this->input('seed') !== null ? (int) $this->input('seed') : null,
            'audio' => $this->has('audio') ? (bool) $this->input('audio') : null,
            'watermark' => $this->has('watermark') ? (bool) $this->input('watermark') : null,
            'cfgScale' => $this->has('cfgScale') && $this->input('cfgScale') !== null ? (float) $this->input('cfgScale') : null,
            'personGeneration' => $this->optionalString('personGeneration'),
        ];

        $refs = $this->input('referenceImages');
        if (is_array($refs) && count($refs) > 0) {
            $result['referenceImages'] = $refs;
        }

        return $result;
    }

    private function optionalString(string $key): ?string
    {
        $value = $this->input($key);

        return (is_string($value) && trim($value) !== '') ? trim($value) : null;
    }

    protected function failedValidation(Validator $validator): void
    {
        $first = $validator->errors()->first() ?: 'Invalid video payload';
        throw new AiHttpException(400, $first, $validator->errors()->toArray());
    }
}
