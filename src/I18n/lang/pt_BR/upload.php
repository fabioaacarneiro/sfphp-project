<?php

/**
 * Por que um upload não chegou, lido por quem está preenchendo o formulário.
 *
 * O PHP reporta isso como inteiros UPLOAD_ERR_*, e a distinção importa para
 * quem está enviando: "o arquivo é grande demais" é algo sobre o que a pessoa
 * pode agir, e "o servidor não tem diretório temporário" não é — por isso as
 * duas não são a mesma mensagem.
 */

return [
    'too_large' => 'O arquivo é maior do que o servidor aceita.',
    'incomplete' => 'O arquivo foi enviado só em parte. Tente novamente.',
    'missing' => 'Nenhum arquivo foi enviado.',
    'cannot_store' => 'O arquivo não pôde ser armazenado. Tente novamente em instantes.',
    'refused' => 'O arquivo foi recusado pelo servidor.',
    'failed' => 'Não foi possível enviar o arquivo.',
];
