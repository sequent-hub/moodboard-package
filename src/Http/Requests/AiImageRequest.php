<?php

namespace Futurello\MoodBoard\Http\Requests;

use Futurello\MoodBoard\Services\Ai\Exceptions\AiHttpException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация payload для POST /api/v2/ai/yandex-art/image.
 *
 * Контракт 1:1 с server/src/utils/schema.js:parseImagePayload.
 */
class AiImageRequest extends FormRequest
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
            'widthRatio' => ['sometimes', 'integer', 'min:1'],
            'heightRatio' => ['sometimes', 'integer', 'min:1'],
            'seed' => ['sometimes', 'integer', 'min:0'],
            'mimeType' => ['sometimes', 'string'],
            'model' => ['sometimes', 'string'],
            'referenceImages' => ['sometimes', 'array'],
            'referenceImages.*.mimeType' => ['required_with:referenceImages.*', 'string'],
            'referenceImages.*.data' => ['required_with:referenceImages.*', 'string'],
        ];
    }

    /**
     * @return array{
     *   prompt: string,
     *   negativePrompt: string|null,
     *   widthRatio: int,
     *   heightRatio: int,
     *   seed: int|null,
     *   mimeType: string|null,
     *   model: string|null,
     *   referenceImages?: list<array{mimeType: string, data: string}>,
     * }
     */
    public function normalized(): array
    {
        $negative = $this->input('negativePrompt');
        $negativeString = (is_string($negative) && trim($negative) !== '') ? trim($negative) : null;

        $mime = $this->input('mimeType');
        $mimeString = (is_string($mime) && $mime !== '') ? $mime : null;

        $model = $this->input('model');
        $modelString = (is_string($model) && $model !== '') ? $model : null;

        $result = [
            'prompt' => trim((string) $this->input('prompt')),
            'negativePrompt' => $negativeString,
            'widthRatio' => (int) $this->input('widthRatio', 1),
            'heightRatio' => (int) $this->input('heightRatio', 1),
            'seed' => $this->has('seed') ? (int) $this->input('seed') : null,
            'mimeType' => $mimeString,
            'model' => $modelString,
        ];

        $refs = $this->input('referenceImages');
        if (is_array($refs) && count($refs) > 0) {
            $result['referenceImages'] = $refs;
        }

        return $result;
    }

    protected function failedValidation(Validator $validator): void
    {
        $first = $validator->errors()->first() ?: 'Invalid image payload';
        throw new AiHttpException(400, $first, $validator->errors()->toArray());
    }
}
