<?php

/**
 * Por qué no llegó una subida, leído por quien rellena el formulario.
 *
 * PHP informa de esto como enteros UPLOAD_ERR_*, y la distinción importa a
 * quien sube: "el archivo es demasiado grande" es algo sobre lo que puede
 * actuar, y "el servidor no tiene directorio temporal" no lo es — por eso no
 * son el mismo mensaje.
 */

return [
    'too_large' => 'El archivo es mayor de lo que acepta el servidor.',
    'incomplete' => 'El archivo se subió solo en parte. Vuelve a intentarlo.',
    'missing' => 'No se envió ningún archivo.',
    'cannot_store' => 'El archivo no se pudo almacenar. Inténtalo de nuevo en un momento.',
    'refused' => 'El servidor rechazó el archivo.',
    'failed' => 'No se pudo subir el archivo.',
];
