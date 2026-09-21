<?php

namespace SfphpProject\src\Database;

use RuntimeException;

/**
 * Raised when a model is filled without declaring what may be filled.
 *
 * This is a development-time error, not something an attacker triggers: it
 * fires the first time the model is used, before any request reaches
 * production.
 */
final class MassAssignmentException extends RuntimeException {}
