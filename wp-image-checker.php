<?php
// Configuración de la base de datos
$db_host = 'localhost';      // Cambia esto si tu base de datos está en otro host
$db_user = 'usuario_db';     // Tu usuario de la base de datos
$db_pass = 'contraseña_db';  // Tu contraseña de la base de datos
$db_name = 'nombre_db';      // El nombre de tu base de datos de WordPress

// Conectar a la base de datos
$mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);

// Verificar conexión
if ($mysqli->connect_error) {
    die('Error de Conexión (' . $mysqli->connect_errno . ') ' . $mysqli->connect_error);
}

// Establecer el conjunto de caracteres a UTF-8
$mysqli->set_charset("utf8");

// Consulta para obtener todos los posts publicados
$query = "SELECT ID, post_content FROM wp_posts WHERE post_type = 'post' AND post_status = 'publish'";
$result = $mysqli->query($query);

if (!$result) {
    die('Error en la consulta: ' . $mysqli->error);
}

// Función para convertir URL a ruta del servidor
function url_a_ruta($url) {
    // Asumiendo que las imágenes están en el directorio wp-content/uploads
    // Ajusta esto según la estructura de tu sitio
    $parsed_url = parse_url($url);
    $path = $_SERVER['DOCUMENT_ROOT'] . $parsed_url['path'];
    return $path;
}

// Expresión regular para encontrar URLs de imágenes
$regex = '/https?:\/\/[^\s"\'<>]+\.(jpg|jpeg|png|avif|webp)/i';

while ($post = $result->fetch_assoc()) {
    $content = $post['post_content'];
    $id_post = $post['ID'];
    $cambios_realizados = false;

    // Encontrar todas las URLs de imágenes en el contenido
    if (preg_match_all($regex, $content, $matches)) {
        $imagenes = array_unique($matches[0]); // Evitar procesar la misma imagen varias veces

        foreach ($imagenes as $imagen_url) {
            // Convertir la URL a ruta del servidor
            $ruta_imagen = url_a_ruta($imagen_url);

            if (!file_exists($ruta_imagen)) {
                // Extraer la extensión y la ruta base
                $info = pathinfo($ruta_imagen);
                $extension_original = strtolower($info['extension']);
                $ruta_base = $info['dirname'] . '/' . $info['filename'];

                $nueva_imagen = '';
                
                // Si la extensión es jpg o png, intentar avif y webp
                if (in_array($extension_original, ['jpg', 'jpeg', 'png'])) {
                    $ruta_avif = $ruta_base . '.avif';
                    $ruta_webp = $ruta_base . '.webp';

                    if (file_exists($ruta_avif)) {
                        $nueva_imagen = str_replace($info['extension'], 'avif', $imagen_url);
                    } elseif (file_exists($ruta_webp)) {
                        $nueva_imagen = str_replace($info['extension'], 'webp', $imagen_url);
                    }
                }

                // Si no se encontró aún, intentar reemplazar la extensión por avif y webp
                if (empty($nueva_imagen)) {
                    $ruta_avif = $ruta_base . '.avif';
                    $ruta_webp = $ruta_base . '.webp';

                    if (file_exists($ruta_avif)) {
                        $nueva_imagen = str_replace('.' . $extension_original, '.avif', $imagen_url);
                    } elseif (file_exists($ruta_webp)) {
                        $nueva_imagen = str_replace('.' . $extension_original, '.webp', $imagen_url);
                    }
                }

                // Si se encontró una imagen alternativa, reemplazar en el contenido
                if (!empty($nueva_imagen)) {
                    $content = str_replace($imagen_url, $nueva_imagen, $content);
                    $cambios_realizados = true;
                    echo "Post ID $id_post: Reemplazada $imagen_url por $nueva_imagen\n";
                } else {
                    echo "Post ID $id_post: No se encontró una imagen alternativa para $imagen_url\n";
                }
            }
        }

        // Si se realizaron cambios, actualizar el contenido del post
        if ($cambios_realizados) {
            // Preparar la consulta para actualizar
            $stmt = $mysqli->prepare("UPDATE wp_posts SET post_content = ? WHERE ID = ?");
            if ($stmt) {
                $stmt->bind_param('si', $content, $id_post);
                $stmt->execute();
                $stmt->close();
                echo "Post ID $id_post: Contenido actualizado.\n";
            } else {
                echo "Error al preparar la consulta para el Post ID $id_post: " . $mysqli->error . "\n";
            }
        }
    }
}

$mysqli->close();

echo "Proceso completado.\n";
