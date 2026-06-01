<?php

namespace Futurello\MoodBoard\Http\Requests;

use Futurello\MoodBoard\Services\Ai\Exceptions\AiHttpException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация payload для POST /api/v2/ai/{provider}/chat.
 *
 * Контракт 1:1 с server/src/utils/schema.js:parseChatPayload — фронт
 * (npm-пакет moodboard) ничего не знает о смене бэкенда.
 *
 * При невалидном теле бросает AiHttpException(400), которую контроллер
 * приведёт к { error, details } — единому формату ошибок AI-роутов.
 */
class AiChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'messages' => ['required', 'array', 'min:1'],
            'messages.*.role' => ['required', 'string', 'in:system,user,assistant'],
            'messages.*.content' => ['required', 'string'],
            'stream' => ['sometimes', 'boolean'],
            'temperature' => ['sometimes', 'numeric'],
            'maxTokens' => ['sometimes', 'integer', 'min:1'],
            'system' => ['sometimes', 'string'],
            'model' => ['sometimes', 'string'],
        ];
    }

    /**
     * @return array{
     *   messages: list<array{role: string, content: string}>,
     *   stream: bool,
     *   temperature: float|null,
     *   maxTokens: int|null,
     *   model: string|null,
     * }
     */
    public function normalized(): array
    {
        $messages = array_values(array_map(
            static fn (array $m): array => ['role' => (string) $m['role'], 'content' => (string) $m['content']],
            (array) $this->input('messages', []),
        ));

        $system = (string) $this->input('system', '');
        if ($system !== '') {
            $hasSystem = false;
            foreach ($messages as $m) {
                if ($m['role'] === 'system') {
                    $hasSystem = true;
                    break;
                }
            }
            if (! $hasSystem) {
                array_unshift($messages, ['role' => 'system', 'content' => $system]);
            }
        }

        $model = $this->input('model');
        $modelString = (is_string($model) && $model !== '') ? $model : null;

        return [
            'messages' => $messages,
            'stream' => (bool) $this->input('stream', false),
            'temperature' => $this->has('temperature') ? (float) $this->input('temperature') : null,
            'maxTokens' => $this->has('maxTokens') ? (int) $this->input('maxTokens') : null,
            'model' => $modelString,
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $first = $validator->errors()->first() ?: 'Invalid chat payload';
        throw new AiHttpException(400, $first, $validator->errors()->toArray());
    }
}
