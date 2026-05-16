<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Minimal rule-based validator.
 * Rules: required|string|int|numeric|email|min:n|max:n|in:a,b|date
 */
final class Validator
{
    private array $errors = [];
    private array $clean = [];

    public function __construct(private array $data, private array $rules) {}

    public static function make(array $data, array $rules): self
    {
        $v = new self($data, $rules);
        $v->run();
        return $v;
    }

    private function run(): void
    {
        foreach ($this->rules as $field => $ruleStr) {
            $value = $this->data[$field] ?? null;
            $rules = explode('|', $ruleStr);
            $required = in_array('required', $rules, true);

            if (!$required && ($value === null || $value === '')) {
                $this->clean[$field] = $value;
                continue;
            }

            foreach ($rules as $rule) {
                [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);
                $ok = match ($name) {
                    'required' => $value !== null && $value !== '',
                    'string'   => is_string($value) || is_numeric($value),
                    'int'      => filter_var($value, FILTER_VALIDATE_INT) !== false,
                    'numeric'  => is_numeric($value),
                    'email'    => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
                    'min'      => is_numeric($value) ? $value >= (float) $param : mb_strlen((string) $value) >= (int) $param,
                    'max'      => is_numeric($value) ? $value <= (float) $param : mb_strlen((string) $value) <= (int) $param,
                    'in'       => in_array((string) $value, explode(',', (string) $param), true),
                    'date'     => strtotime((string) $value) !== false,
                    default    => true,
                };
                if (!$ok) {
                    $this->errors[$field][] = $this->message($field, $name, $param);
                }
            }
            $this->clean[$field] = $value;
        }
    }

    private function message(string $field, string $rule, ?string $param): string
    {
        return match ($rule) {
            'required' => "$field is required",
            'email'    => "$field must be a valid email",
            'int', 'numeric' => "$field must be a number",
            'min'      => "$field is below minimum ($param)",
            'max'      => "$field exceeds maximum ($param)",
            'in'       => "$field must be one of: $param",
            'date'     => "$field must be a valid date",
            default    => "$field is invalid",
        };
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function validated(): array
    {
        return $this->clean;
    }
}
