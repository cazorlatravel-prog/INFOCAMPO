<?php
/**
 * Genera iconos PNG para la PWA.
 * Ejecutar una sola vez: php generate_icons.php
 * Requiere extensión GD.
 */

function generateIcon(int $size, string $outputPath): void
{
    $img = imagecreatetruecolor($size, $size);
    imagesavealpha($img, true);

    // Fondo azul redondeado
    $bg = imagecolorallocate($img, 79, 110, 247);    // #4f6ef7
    $white = imagecolorallocate($img, 255, 255, 255);

    imagefill($img, 0, 0, $bg);

    // Pin blanco (triángulo + círculo)
    $cx = (int)($size / 2);
    $pinTop = (int)($size * 0.15);
    $pinBottom = (int)($size * 0.72);
    $pinWidth = (int)($size * 0.25);
    $circleR = (int)($size * 0.15);

    // Cuerpo del pin (triángulo)
    $points = [
        $cx, $pinBottom,
        $cx - $pinWidth, $pinTop + $circleR,
        $cx + $pinWidth, $pinTop + $circleR,
    ];
    imagefilledpolygon($img, $points, 3, $white);

    // Cabeza del pin (círculo)
    imagefilledellipse($img, $cx, $pinTop + $circleR, $pinWidth * 2, $circleR * 2, $white);

    // Punto azul interior
    $innerR = (int)($circleR * 0.5);
    imagefilledellipse($img, $cx, $pinTop + $circleR, $innerR * 2, $innerR * 2, $bg);

    // Texto "GPS"
    $fontSize = (int)($size * 0.13);
    $textY = (int)($size * 0.88);

    // Usar fuente built-in
    $font = 5; // largest built-in
    $text = 'GPS';
    $textWidth = imagefontwidth($font) * strlen($text);
    $textX = ($size - $textWidth) / 2;
    $textYBuiltin = (int)($size * 0.82);
    imagestring($img, $font, (int)$textX, $textYBuiltin, $text, $white);

    imagepng($img, $outputPath);
    imagedestroy($img);
}

$dir = __DIR__;
generateIcon(192, $dir . '/icon-192.png');
generateIcon(512, $dir . '/icon-512.png');

echo "Icons generated successfully!\n";
echo "- {$dir}/icon-192.png\n";
echo "- {$dir}/icon-512.png\n";
