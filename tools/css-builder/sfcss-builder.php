<?php

/**
 * SFCSS Builder - Generates CSS from config.json
 * Usage: php sfcss-builder.php > sfcss.css
 */

$configFile = __DIR__ . '/sfcss.config.json';

if (!is_file($configFile)) {
    fwrite(STDERR, "Error: sfcss.config.json not found\n");
    exit(1);
}

$config = json_decode(file_get_contents($configFile), true);

if ($config === null) {
    fwrite(STDERR, "Error: Invalid sfcss.config.json\n");
    exit(1);
}

$css = generateCss($config);

// Generate both full and minified versions
$outputPath = __DIR__ . '/../../public/assets/css/sfcss.css';
$minOutputPath = __DIR__ . '/../../public/assets/css/sfcss.min.css';

file_put_contents($outputPath, $css);

// Minify CSS
$minified = minifyCss($css);
file_put_contents($minOutputPath, $minified);

echo "✓ Generated: $outputPath (" . filesize($outputPath) . " bytes)\n";
echo "✓ Generated: $minOutputPath (" . filesize($minOutputPath) . " bytes)\n";

function generateCss(array $config): string
{
    $css = "/* SFCSS - Generated from config.json */\n\n";

    $css .= ":root {\n";

    // Colors
    foreach ($config['colors'] as $name => $color) {
        $css .= "  --{$name}: {$color};\n";
    }

    $css .= "\n";

    // Spacing
    foreach ($config['spacing'] as $name => $value) {
        $css .= "  --{$name}: {$value};\n";
    }

    $css .= "\n";

    // Typography
    $css .= "  --font-family: {$config['typography']['fontFamily']};\n";

    foreach ($config['typography']['sizes'] as $name => $value) {
        $name = str_replace('2xl', 'size-2xl', $name);
        $name = str_replace('3xl', 'size-3xl', $name);
        $css .= "  --font-size-{$name}: {$value};\n";
    }

    $css .= "  --line-height: {$config['typography']['lineHeight']};\n";

    foreach ($config['typography']['weights'] as $name => $weight) {
        $css .= "  --font-weight-{$name}: {$weight};\n";
    }

    $css .= "\n";

    // Border
    $css .= "  --border-radius: {$config['border']['radius']};\n";
    $css .= "  --border-color: {$config['border']['color']};\n";
    $css .= "  --border: {$config['border']['width']} solid var(--border-color);\n";

    $css .= "\n";

    // Shadows
    foreach ($config['shadows'] as $name => $shadow) {
        $name = $name === 'base' ? '' : "-{$name}";
        $css .= "  --shadow{$name}: {$shadow};\n";
    }

    $css .= "\n";

    // Transitions
    $css .= "  --transition: {$config['transition']};\n";

    $css .= "}\n\n";

    // Generate color palette classes (Tailwind-style)
    if (isset($config['colorPalettes'])) {
        $css .= "/* Color Palettes - Tailwind Style */\n";
        foreach ($config['colorPalettes'] as $colorName => $shades) {
            foreach ($shades as $shade => $color) {
                $css .= ".text-{$colorName}-{$shade} { color: {$color}; }\n";
                $css .= ".bg-{$colorName}-{$shade} { background-color: {$color}; }\n";
                $css .= ".border-{$colorName}-{$shade} { border-color: {$color}; }\n";
            }
        }
        $css .= "\n";
    }

    // Include base styles
    $css .= file_get_contents(__DIR__ . '/sfcss-base.css');

    return $css;
}

function minifyCss(string $css): string
{
    // Remove comments
    $css = preg_replace('!/\*[^*]*\*+(?:[^/*][^*]*\*+)*/!', '', $css);

    // Remove whitespace
    $css = preg_replace('/\s+/', ' ', $css);

    // Remove spaces around special characters
    $css = preg_replace('/ ([{}:;,>+~]) /', '$1', $css);
    $css = preg_replace('/([:;,>+~])\s/', '$1', $css);

    // Remove trailing semicolons before closing brace
    $css = preg_replace('/;(?=\})/', '', $css);

    return trim($css);
}
