<?php

/**
 * Utility functions for view rendering, asset management, and data validation
 *
 * @package SfphpProject
 */

 /**
  * Renders a partial view with the provided data
  *
  * @param string $view The name of the partial view to render
  * @param array $data The data to pass to the view
  * @throws \Exception If the view file does not exist
  * @return void
  */
function partial($view, $data = [])
{
    extract($data);
    $path = __DIR__ . "/../app/resources/views/partials/$view.php";
    if (!file_exists($path)) {
        throw new \Exception("Partial $view not found");
    }
    require_once $path;
}

/**
 * Outputs the URL for a given asset
 *
 * @param string $asset The name of the asset
 * @return void
 */
function assets($asset) 
{
    echo "/assets/$asset";
}

/**
 * Validates data against specified rules and returns errors if any
 *
 * @param array $data The data to validate
 * @param array $rules The validation rules
 * @param array $errorMessages Custom error messages for validation failures
 * @return array The original data if valid, or an array containing errors
 */
function validate(
    $data, 
    $rules, 
    $errorMessages = []
) {
    $errors = [];

    foreach ($rules as $field => $ruleSet) {
        $rulesArray = explode('|', $ruleSet);
        $value = $data[$field] ?? null;

        foreach ($rulesArray as $rule) {
            if (str_starts_with($rule, 'min:')) {
                $min = explode(':', $rule)[1];
                if (strlen($value) < $min) {
                    $errors[$field][] = $errorMessages[$field]['min'] ?? "$field must be at least $min characters long.";
                }
            } elseif (str_starts_with($rule, 'max:')) {
                $max = explode(':', $rule)[1];
                if (strlen($value) > $max) {
                    $errors[$field][] = $errorMessages[$field]['max'] ?? "$field must be at most $max characters long.";
                }
            } elseif ($rule === 'required' && empty($value)) {
                $errors[$field][] = $errorMessages[$field]['required'] ?? "$field is required.";
            } elseif ($rule === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[$field][] = $errorMessages[$field]['email'] ?? "$field must be a valid email.";
            } elseif ($rule === 'alpha' && !ctype_alpha($value)) {
                $errors[$field][] = $errorMessages[$field]['alpha'] ?? "$field must contain only letters.";
            } elseif ($rule === 'alphanum' && !ctype_alnum($value)) {
                $errors[$field][] = $errorMessages[$field]['alphanum'] ?? "$field must contain only letters and numbers.";
            } elseif ($rule === 'number' && !ctype_digit($value)) {
                $errors[$field][] = $errorMessages[$field]['number'] ?? "$field must contain only numbers.";
            }
        }
    }

    return empty($errors) ? $data : ['errors' => $errors];
}

