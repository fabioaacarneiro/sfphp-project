<?php
/*
 * partial() executa o arquivo dentro do escopo da própria função, então as
 * variáveis da view não são herdadas: o que o partial precisa tem de ser
 * passado explicitamente no segundo argumento.
 */
?>
<?php partial("header", ["title" => $title]); ?>

<?php partial("content"); ?>

<?php partial("footer"); ?>
