<?php

namespace Helpers;

class Validator
{
    private array $data;
    private array $files;
    private array $errors = [];
    private array $validated = [];

    public function __construct(array $data, array $files = [])
    {
        $this->data = $data;
        $this->files = $files;
    }

    public function required(string $field, ?string $label = null): self
    {
        if (!isset($this->data[$field]) || trim((string)$this->data[$field]) === '') {
            $this->addError($field, ($label ?? $field) . " is required.");
        } else {
            $this->validated[$field] = $this->data[$field];
        }
        return $this;
    }

    public function string(string $field, int $min = 0, int $max = 255): self
    {
        if (isset($this->data[$field])) {
            $val = (string)$this->data[$field];
            $len = strlen($val);
            if ($len < $min || $len > $max) {
                $this->addError($field, "{$field} must be between {$min} and {$max} characters.");
            } else {
                $this->validated[$field] = $val;
            }
        }
        return $this;
    }

    public function email(string $field): self
    {
        if (isset($this->data[$field])) {
            if (!filter_var($this->data[$field], FILTER_VALIDATE_EMAIL)) {
                $this->addError($field, "Invalid email format.");
            } else {
                $this->validated[$field] = $this->data[$field];
            }
        }
        return $this;
    }

    public function phone(string $field): self
    {
        if (isset($this->data[$field])) {
            $val = $this->data[$field];
            if (!preg_match('/^(0|\+234)[0-9]{9,10}$/', $val)) {
                $this->addError($field, "Invalid phone number format.");
            } else {
                $this->validated[$field] = $val;
            }
        }
        return $this;
    }

    public function nin(string $field): self
    {
        if (isset($this->data[$field])) {
            $val = $this->data[$field];
            if (!preg_match('/^[0-9]{11}$/', $val)) {
                $this->addError($field, "NIN must be exactly 11 digits.");
            } else {
                $this->validated[$field] = $val;
            }
        }
        return $this;
    }

    public function bvn(string $field): self
    {
        if (isset($this->data[$field])) {
            $val = $this->data[$field];
            if (!preg_match('/^[0-9]{11}$/', $val)) {
                $this->addError($field, "BVN must be exactly 11 digits.");
            } else {
                $this->validated[$field] = $val;
            }
        }
        return $this;
    }

    public function numeric(string $field): self
    {
        if (isset($this->data[$field])) {
            if (!is_numeric($this->data[$field])) {
                $this->addError($field, "{$field} must be numeric.");
            } else {
                $this->validated[$field] = $this->data[$field];
            }
        }
        return $this;
    }

    public function date(string $field): self
    {
        if (isset($this->data[$field])) {
            $val = $this->data[$field];
            if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $val)) {
                $this->addError($field, "{$field} must be in YYYY-MM-DD format.");
            } else {
                $d = \DateTime::createFromFormat('Y-m-d', $val);
                if ($d && $d->format('Y-m-d') === $val) {
                    $this->validated[$field] = $val;
                } else {
                    $this->addError($field, "Invalid date.");
                }
            }
        }
        return $this;
    }

    public function password(string $field, int $min = 8): self
    {
        if (isset($this->data[$field])) {
            if (strlen($this->data[$field]) < $min) {
                $this->addError($field, "Password must be at least {$min} characters.");
            } else {
                $this->validated[$field] = $this->data[$field];
            }
        }
        return $this;
    }

    public function pin(string $field): self
    {
        if (isset($this->data[$field])) {
            if (!preg_match('/^[0-9]{4}$/', $this->data[$field])) {
                $this->addError($field, "PIN must be exactly 4 digits.");
            } else {
                $this->validated[$field] = $this->data[$field];
            }
        }
        return $this;
    }

    public function in(string $field, array $allowed): self
    {
        if (isset($this->data[$field])) {
            if (!in_array($this->data[$field], $allowed, true)) {
                $this->addError($field, "{$field} must be one of: " . implode(', ', $allowed) . ".");
            } else {
                $this->validated[$field] = $this->data[$field];
            }
        }
        return $this;
    }

    public function notEmpty(string $field): self
    {
        if (isset($this->data[$field])) {
            if (empty($this->data[$field]) && $this->data[$field] !== '0') {
                $this->addError($field, "{$field} cannot be empty.");
            } else {
                $this->validated[$field] = $this->data[$field];
            }
        }
        return $this;
    }

    public function fails(): bool
    {
        return !empty($this->errors);
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function validated(): array
    {
        return $this->validated;
    }

    private function addError(string $field, string $message): void
    {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = [];
        }
        $this->errors[$field][] = $message;
    }
}


