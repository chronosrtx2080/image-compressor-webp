<?php
/*
Plugin Name: Image Compressor and WebP Converter
Plugin URI: https://licbrito.com/
Description: This plugin compresses images and converts them to WebP format upon upload, with options for batch conversion and maintaining original images.
Version: 1.4
Author: ThedarkCont
Author URI: https://licbrito.com/
License: GPL2
*/

// Hook para realizar la compresión y conversión a WebP al subir una imagen
add_filter('wp_handle_upload', 'secure_compress_and_convert_image_to_webp');

function secure_compress_and_convert_image_to_webp($upload) {
    $image_path = $upload['file'];
    $image_mime = $upload['type'];

    // Verificar si las librerías necesarias están habilitadas
    if (!extension_loaded('gd') && !extension_loaded('imagick')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error is-dismissible">
                    <p>Error: No se encontraron las librerías GD o Imagick. El plugin "Image Compressor and WebP Converter" no puede funcionar sin ellas.</p>
                  </div>';
        });
        return $upload; // No realizar nada si no están disponibles.
    }

    // Obtener dimensiones de la imagen
    list($width, $height) = getimagesize($image_path);

    // Si la imagen es demasiado grande, redimensionarla
    if ($width > 2560 || $height > 2560) {
        resize_image($image_path, 2560, 2560, $image_mime);
    }

    // Verificar si el archivo es una imagen válida y su extensión es permitida
    $check_file = wp_check_filetype_and_ext($image_path, $image_path);
    if (!$check_file['ext'] || !in_array($check_file['type'], ['image/jpeg', 'image/png', 'image/gif', 'image/tiff'])) {
        return $upload; // Si no es una imagen válida, no procesarla.
    }

    // Comprimir la imagen
    if (!compress_image($image_path, $image_mime)) {
        return $upload; // Si falla la compresión, retorna el original
    }

    // Convertir la imagen a WebP
    if (function_exists('imagewebp')) {
        $webp_image_path = convert_image_to_webp($image_path, $image_mime);

        if ($webp_image_path) {
            // Cambiar el archivo de la imagen por el WebP si la conversión fue exitosa
            $upload['file'] = $webp_image_path;
            $upload['type'] = 'image/webp';
        }
    }

    return $upload;
}

// Compresión de la imagen
function compress_image($image_path, $image_mime) {
    try {
        switch ($image_mime) {
            case 'image/jpeg':
                $image = imagecreatefromjpeg($image_path);
                imagejpeg($image, $image_path, 85); // Calidad de compresión
                break;
            case 'image/png':
                $image = imagecreatefrompng($image_path);
                imagepng($image, $image_path, 7); // Nivel de compresión
                break;
            case 'image/gif':
                $image = imagecreatefromgif($image_path);
                imagegif($image, $image_path);
                break;
            case 'image/tiff':
                // Imagick soporte para TIFF
                $imagick = new Imagick($image_path);
                $imagick->setImageCompression(Imagick::COMPRESSION_LZW);
                $imagick->writeImage($image_path);
                $imagick->clear();
                $imagick->destroy();
                break;
        }

        if (isset($image)) {
            imagedestroy($image); // Liberar memoria
        }
        return true;
    } catch (Exception $e) {
        error_log("Error al comprimir la imagen: " . $e->getMessage());
        return false; // En caso de error, retornar false
    }
}

// Convertir a WebP
function convert_image_to_webp($image_path, $image_mime) {
    try {
        $webp_image_path = preg_replace('/\.(jpg|jpeg|png|gif|tiff)$/i', '.webp', $image_path);

        switch ($image_mime) {
            case 'image/jpeg':
                $image = imagecreatefromjpeg($image_path);
                break;
            case 'image/png':
                $image = imagecreatefrompng($image_path);
                break;
            case 'image/gif':
                $image = imagecreatefromgif($image_path);
                break;
            case 'image/tiff':
                $imagick = new Imagick($image_path);
                $imagick->setImageFormat('webp');
                $imagick->writeImage($webp_image_path);
                $imagick->clear();
                $imagick->destroy();
                return $webp_image_path;
        }

        if (isset($image) && function_exists('imagewebp')) {
            imagewebp($image, $webp_image_path);
            imagedestroy($image); // Liberar memoria
            return $webp_image_path;
        }
    } catch (Exception $e) {
        error_log("Error al convertir la imagen a WebP: " . $e->getMessage());
    }
    return false;
}

// Redimensionar imagen
function resize_image($image_path, $max_width, $max_height, $image_mime) {
    try {
        list($orig_width, $orig_height) = getimagesize($image_path);
        
        // Calcular proporciones
        $ratio = min($max_width / $orig_width, $max_height / $orig_height);
        $new_width = intval($orig_width * $ratio);
        $new_height = intval($orig_height * $ratio);
        
        // Crear una nueva imagen redimensionada
        $new_image = imagecreatetruecolor($new_width, $new_height);
        
        switch ($image_mime) {
            case 'image/jpeg':
                $image = imagecreatefromjpeg($image_path);
                break;
            case 'image/png':
                $image = imagecreatefrompng($image_path);
                break;
            case 'image/gif':
                $image = imagecreatefromgif($image_path);
                break;
        }

        imagecopyresampled($new_image, $image, 0, 0, 0, 0, $new_width, $new_height, $orig_width, $orig_height);

        // Guardar la nueva imagen sobre la original
        switch ($image_mime) {
            case 'image/jpeg':
                imagejpeg($new_image, $image_path, 85);
                break;
            case 'image/png':
                imagepng($new_image, $image_path, 7);
                break;
            case 'image/gif':
                imagegif($new_image, $image_path);
                break;
        }

        // Liberar memoria
        imagedestroy($new_image);
        imagedestroy($image);
    } catch (Exception $e) {
        error_log("Error al redimensionar la imagen: " . $e->getMessage());
    }
}

// Función para la conversión en lote de imágenes existentes
function batch_convert_images_to_webp() {
    $uploads_dir = wp_upload_dir();
    $image_files = glob($uploads_dir['basedir'] . '/*.{jpg,jpeg,png,gif,tiff}', GLOB_BRACE);

    foreach ($image_files as $image_file) {
        $mime_type = mime_content_type($image_file);
        convert_image_to_webp($image_file, $mime_type);
    }
}

// Crear un menú para la conversión en lote
add_action('admin_menu', 'batch_convert_menu');
function batch_convert_menu() {
    add_submenu_page(
        'tools.php', // Aparece en la sección de Herramientas
        'Conversión en Lote a WebP',
        'Conversión a WebP',
        'manage_options',
        'batch-convert-webp',
        'batch_convert_page'
    );
}

function batch_convert_page() {
    ?>
    <div class="wrap">
        <h1>Conversión en Lote de Imágenes a WebP</h1>
        <p>Este proceso convertirá todas las imágenes existentes a formato WebP.</p>
        <form method="post" action="">
            <input type="submit" name="batch_convert" class="button-primary" value="Convertir Imágenes" />
        </form>
    </div>
    <?php
    if (isset($_POST['batch_convert'])) {
        batch_convert_images_to_webp();
        echo '<p>Conversión completada.</p>';
    }
}

// Hook para mostrar un aviso si la función imagewebp no está disponible
add_action('admin_notices', 'check_for_webp_support');
function check_for_webp_support() {
    if (!function_exists('imagewebp')) {
        echo '<div class="notice notice-warning is-dismissible">
                <p>Advertencia: Tu servidor no soporta la conversión a WebP. El plugin "Image Compressor and WebP Converter" no funcionará correctamente.</p>
             </div>';
    }
}

// Panel de configuración en el administrador
add_action('admin_menu', 'image_compressor_settings_menu');
function image_compressor_settings_menu() {
    add_options_page(
        'Configuración del Compresor de Imágenes',
        'Compresor de Imágenes',
        'manage_options',
        'image-compressor-settings',
        'image_compressor_settings_page'
    );
}

function image_compressor_settings_page() {
    ?>
    <div class="wrap">
        <h1>Configuración del Compresor de Imágenes y Conversor a WebP</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('image_compressor_settings_group');
            do_settings_sections('image-compressor-settings');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

add_action('admin_init', 'image_compressor_settings_init');
function image_compressor_settings_init() {
    register_setting('image_compressor_settings_group', 'compress_quality');
    register_setting('image_compressor_settings_group', 'resize_images');

    add_settings_section(
        'image_compressor_section',
        'Opciones de Compresión de Imágenes',
        null,
        'image-compressor-settings'
    );

    add_settings_field(
        'compress_quality',
        'Calidad de Compresión (0-100)',
        'compress_quality_render',
        'image-compressor-settings',
        'image_compressor_section'
    );

    add_settings_field(
        'resize_images',
        'Redimensionar imágenes grandes (sí/no)',
        'resize_images_render',
        'image-compressor-settings',
        'image_compressor_section'
    );
}

function compress_quality_render() {
    $quality = get_option('compress_quality', 85);
    echo "<input type='number' name='compress_quality' value='$quality' />";
}

function resize_images_render() {
    $resize = get_option('resize_images', 'yes');
    echo "<input type='checkbox' name='resize_images' value='yes' " . checked($resize, 'yes', false) . "/>";
}
?>
