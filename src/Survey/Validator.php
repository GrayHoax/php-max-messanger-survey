<?php

declare(strict_types=1);

namespace MaxBot\Feedback\Survey;

class Validator
{
    /**
     * Validate user input against a question definition.
     *
     * Returns null on success, or an error message string on failure.
     */
    public static function validate(array $question, string $input): ?string
    {
        $type  = $question['type'];
        $value = trim($input);

        switch ($type) {
            case 'text':
                if ($value === '') {
                    return 'Ответ не может быть пустым.';
                }
                return null;

            case 'rating':
                if (!is_numeric($value)) {
                    return $question['error_message'] ?? 'Пожалуйста, введите число.';
                }
                $num = (int) $value;
                $min = (int) ($question['min'] ?? 1);
                $max = (int) ($question['max'] ?? 10);
                if ($num < $min || $num > $max) {
                    return $question['error_message'] ?? "Введите число от {$min} до {$max}.";
                }
                return null;

            case 'choice':
                $choices = $question['choices'] ?? [];
                if (!in_array($value, $choices, true)) {
                    $list = implode(', ', array_map(fn($c) => '"' . $c . '"', $choices));
                    return "Выберите один из вариантов: {$list}.";
                }
                return null;

            case 'boolean':
                $yes = ['да', 'yes', '1', 'true', 'y'];
                $no  = ['нет', 'no', '0', 'false', 'n'];
                $lower = mb_strtolower($value);
                if (!in_array($lower, $yes, true) && !in_array($lower, $no, true)) {
                    return 'Пожалуйста, нажмите кнопку "Да" или "Нет".';
                }
                return null;

            case 'email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return $question['error_message'] ?? 'Введите корректный e-mail адрес.';
                }
                return null;

            case 'phone':
                // Phone is collected via requestContact button — if we get here
                // it means user typed text. Accept E.164-like formats as fallback.
                $digits = preg_replace('/\D/', '', $value);
                if (strlen($digits) < 7 || strlen($digits) > 15) {
                    return $question['error_message']
                        ?? 'Воспользуйтесь кнопкой "Поделиться контактом" или введите номер в формате +71234567890.';
                }
                return null;
        }

        return null;
    }

    /**
     * Normalise a boolean answer to canonical "Да" / "Нет".
     */
    public static function normaliseBoolean(string $input): string
    {
        $yes = ['да', 'yes', '1', 'true', 'y'];
        return in_array(mb_strtolower(trim($input)), $yes, true) ? 'Да' : 'Нет';
    }
}
