<?php
// wp-image-checker.php

// Asegúrate de que el script se ejecute desde la línea de comandos
if (php_sapi_name() !== 'cli') {
    exit("Este script debe ejecutarse desde la línea de comandos.\n");
}

// Incluye el archivo wp-load.php para acceder a las funciones de WordPress
require_once(dirname(__FILE__) . '/wp-load.php');

// Variables para almacenar el prefijo de la tabla
global $wpdb;
$table_prefix = $wpdb->prefix;

// Extensiones de imágenes a buscar
$image_extensions = ['jpg', 'jpeg', 'png', 'avif', 'webp'];

// Obtener todos los posts y páginas publicados
$posts = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT ID, post_content FROM {$table_prefix}posts 
         WHERE (post_type = %s OR post_type = %s) 
         AND post_status = %s",
        'post',
        'page',
        'publish'
    ),
    OBJECT
);

echo "Total de posts y páginas a procesar: " . count($posts) . "\n";

// Contador opcional de accesos a la base de datos (sin implementar)
$db_access_count = null; // Placeholder si deseas implementarlo en el futuro

foreach ($posts as $post) {
    $content = $post->post_content;
    $original_content = $content;
    $updated = false;

    // Expresión regular para encontrar URLs de imágenes
    // Considera URLs absolutas (HTTP/HTTPS)
    $pattern = '/https?:\/\/[^\s"\'<>]+\.(jpg|jpeg|png|avif|webp)/i';

    // Encuentra todas las coincidencias
    if (preg_match_all($pattern, $content, $matches)) {
        $unique_urls = array_unique($matches[0]); // Evita procesar la misma URL múltiples veces

        foreach ($unique_urls as $url) {
            // Verificar si la URL original existe
            $exists_original = verificar_url($url);
            if ($exists_original) {
                // La imagen original existe, no hacer nada
                continue;
            }

            $replacement_found = false;
            $new_url = $url; // Por defecto, no cambiar

            // Intentar añadiendo .avif
            $url_avif_added = $url . '.avif';
            if (verificar_url($url_avif_added)) {
                $new_url = $url_avif_added;
                $replacement_found = true;
            } else {
                // Intentar añadiendo .webp
                $url_webp_added = $url . '.webp';
                if (verificar_url($url_webp_added)) {
                    $new_url = $url_webp_added;
                    $replacement_found = true;
                }
            }

            // Si no se encontró añadiendo, intentar reemplazar la extensión
            if (!$replacement_found) {
                $parsed_url = parse_url($url);
                if (!isset($parsed_url['path'])) {
                    continue; // URL inválida
                }

                $path = $parsed_url['path'];
                $dir = dirname($path);
                $filename = basename($path);
                $extension = pathinfo($filename, PATHINFO_EXTENSION);
                $filename_without_ext = pathinfo($filename, PATHINFO_FILENAME);

                // Reemplazar la extensión por .avif
                $avif_path = $dir . '/' . $filename_without_ext . '.avif';
                $avif_url = (isset($parsed_url['scheme']) ? $parsed_url['scheme'] . '://' : '') .
                            (isset($parsed_url['host']) ? $parsed_url['host'] : '') .
                            $avif_path;

                if (verificar_url($avif_url)) {
                    $new_url = $avif_url;
                    $replacement_found = true;
                } else {
                    // Reemplazar la extensión por .webp
                    $webp_path = $dir . '/' . $filename_without_ext . '.webp';
                    $webp_url = (isset($parsed_url['scheme']) ? $parsed_url['scheme'] . '://' : '') .
                                (isset($parsed_url['host']) ? $parsed_url['host'] : '') .
                                $webp_path;

                    if (verificar_url($webp_url)) {
                        $new_url = $webp_url;
                        $replacement_found = true;
                    }
                }
            }

            if ($replacement_found && $new_url !== $url) {
                // Reemplazar todas las ocurrencias de la URL antigua por la nueva en el contenido
                $content = str_replace($url, $new_url, $content);
                $updated = true;
                echo "Post/Página ID {$post->ID}: Reemplazada URL {$url} por {$new_url}\n";
            }
        }

        // Si el contenido ha sido modificado, actualizar el post o página
        if ($updated && $content !== $original_content) {
            $resultado = $wpdb->update(
                "{$table_prefix}posts",
                ['post_content' => $content],
                ['ID' => $post->ID],
                ['%s'],
                ['%d']
            );

            if ($resultado !== false) {
                echo "Post/Página ID {$post->ID} actualizado correctamente.\n";
            } else {
                echo "Error al actualizar el Post/Página ID {$post->ID}.\n";
            }
        }
    }
}

// Mostrar el prefijo de la tabla
echo "\nPrefijo de la tabla: {$table_prefix}\n";
// Mostrar información adicional si se implementa el contador de accesos
// echo "Número total de accesos a la base de datos: {$db_access_count}\n";

echo "Proceso completado.\n";

/**
 * Función para verificar si una URL existe mediante una solicitud HTTP HEAD.
 *
 * @param string $url La URL a verificar.
 * @return bool Verdadero si la URL devuelve un código HTTP 200, falso en caso contrario.
 */
function verificar_url($url) {
    $ch = curl_init($url);

    // Configurar cURL para realizar una solicitud HEAD
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Seguir redirecciones
    curl_setopt($ch, CURLOPT_TIMEOUT, 10); // Tiempo de espera de 10 segundos
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Ignorar verificación SSL
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($http_code === 200);
}
