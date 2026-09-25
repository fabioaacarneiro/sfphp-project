<?php

namespace SfphpProject\src\Database;

use LogicException;

/**
 * An outer transaction reached its end after a nested one had failed.
 *
 * The nested failure marked the transaction for rollback; the outer callback
 * caught it and carried on, and committing then would have kept the work the
 * inner call was supposed to undo. The whole transaction is rolled back and
 * this is thrown instead.
 */
final class NestedTransactionFailed extends LogicException
{
}
