<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Binario de ffmpeg
    |--------------------------------------------------------------------------
    |
    | Ruta absoluta al ejecutable con el que se comprime el video que se pasa
    | del tope de WhatsApp. En el Hostinger no hay ffmpeg del sistema: se subió
    | un build estático a `~/opt/ffmpeg` y se apunta aquí con FFMPEG_PATH.
    |
    | Si está vacío o el archivo no es ejecutable, no se optimiza nada y el
    | panel vuelve a rechazar de entrada lo que no quepa. Es a propósito:
    | preferimos avisar al subir antes que guardar algo que no se puede enviar.
    |
    */

    'ffmpeg' => env('FFMPEG_PATH'),

    /*
    |--------------------------------------------------------------------------
    | Tope de subida cuando SÍ se puede optimizar
    |--------------------------------------------------------------------------
    |
    | Con ffmpeg disponible se acepta un video muy por encima del límite de
    | WhatsApp, porque se va a recomprimir. Este techo existe solo para que un
    | archivo disparatado no se coma el disco ni tenga a la cola un cuarto de
    | hora: no es el límite de WhatsApp, es el de sentido común.
    |
    */

    'max_upload_video' => 200 * 1024 * 1024,

];
