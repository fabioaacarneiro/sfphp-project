<?php
use SfphpProject\src\View;

/** @var string $title */

/*
 * View::partial() executa o arquivo dentro do escopo do método, então as
 * variáveis da view não são herdadas: o que o partial precisa tem de ser
 * passado explicitamente no segundo argumento.
 */
?>
<?php View::partial("header", ["title" => $title]); ?>

<?php View::partial("content"); ?>

<?php View::partial("footer"); ?>
