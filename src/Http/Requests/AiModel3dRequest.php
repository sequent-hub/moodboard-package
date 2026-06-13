<?php

namespace Futurello\MoodBoard\Http\Requests;

use Futurello\MoodBoard\Services\Ai\Exceptions\AiHttpException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация payload для POST /api/v2/ai/{provider}/model3d.
 *
 * Контракт 1:1 с AiClient.submit3dModel:
 *   { image: { mimeType, data }, faceCount, type, pbr }
 *
 * type из либы: 'geometry' | 'geometry+texture'.
 */
class AiModel3dRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'image' => ['required', 'array'],
            'image.mimeType' => ['required', 'string'],
            'image.data' => ['required', 'string', 'min:1'],
            'faceCount' => ['sometimes', 'nullable', 'integer'],
            'type' => ['sometimes', 'nullable', 'string'],
            'pbr' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array{imageBase64: string, imageMime: string, faceCount: int|null, generateType: string, enablePbr: bool}
     */
    public function normalized(): array
    {
        $type = (string) $this->input('type', 'geometry+texture');
        // 'geometry' (без текстур) → Geometry (только GLB, EnablePBR игнорируется).
        // всё остальное → Normal (геометрия + текстуры).
        $generateType = $type === 'geometry' ? 'Geometry' : 'Normal';

        $faceCount = $this->input('faceCount');
        if ($faceCount !== null) {
            // Tencent допускает 3000..1500000.
            $faceCount = max(3000, min(1500000, (int) $faceCount));
        }

        return [
            'imageBase64' => (string) $this->input('image.data'),
            'imageMime' => (string) $this->input('image.mimeType'),
            'faceCount' => $faceCount,
            'generateType' => $generateType,
            'enablePbr' => (bool) $this->input('pbr', false),
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $first = $validator->errors()->first() ?: 'Invalid model3d payload';
        throw new AiHttpException(400, $first, $validator->errors()->toArray());
    }
}
