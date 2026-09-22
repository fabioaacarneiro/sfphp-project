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
/*
 * resources/, not public/. SFCSS is a tool the framework ships, like SFJS, so
 * it has to be inside what a composer require delivers — and public/ is
 * export-ignored, because a consumer's vendor/ has no business holding a front
 * controller. ./sfphp assets:publish copies it into a project's public/.
 */
$outputPath = __DIR__ . '/../../resources/assets/css/sfcss.css';
$minOutputPath = __DIR__ . '/../../resources/assets/css/sfcss.min.css';

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
    $css .= "  --font-family-mono: {$config['typography']['monoFamily']};\n";

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

    $css .= generateStateVariants($config);
    $css .= generateExtraUtilities();
    $css .= generateResponsiveVariants($config);

    return $css;
}

/**
 * Generate :hover variants for the colour palette.
 *
 * The homepage and the documentation both used "hover:text-blue-500" style
 * classes that the builder never emitted, so links simply did not change
 * colour. The palette is already in the config; these are the same values
 * behind a pseudo-class.
 */
function generateStateVariants(array $config): string
{
    if (!isset($config['colorPalettes'])) {
        return '';
    }

    $css = "\n/* State variants - hover */\n";

    foreach ($config['colorPalettes'] as $name => $shades) {
        foreach ($shades as $shade => $color) {
            $css .= ".hover\\:text-{$name}-{$shade}:hover { color: {$color}; }\n";
            $css .= ".hover\\:bg-{$name}-{$shade}:hover { background-color: {$color}; }\n";
            $css .= ".hover\\:border-{$name}-{$shade}:hover { border-color: {$color}; }\n";
        }
    }

    return $css;
}

/**
 * Generate utilities the base stylesheet declared only partially.
 *
 * opacity stopped at 25-point steps, border widths had no numeric scale, and
 * the corner-specific radii did not exist at all, although all three were
 * used by the pages shipped with the framework.
 */
function generateExtraUtilities(): string
{
    $css = "\n/* Opacity scale */\n";
    for ($value = 0; $value <= 100; $value += 5) {
        $css .= ".opacity-{$value} { opacity: " . ($value / 100) . "; }\n";
    }

    $css .= "\n/* Border widths */\n";
    foreach ([0, 1, 2, 4, 8] as $width) {
        $css .= ".border-{$width} { border-width: {$width}px; border-style: solid; }\n";
        $css .= ".border-t-{$width} { border-top-width: {$width}px; border-top-style: solid; }\n";
        $css .= ".border-r-{$width} { border-right-width: {$width}px; border-right-style: solid; }\n";
        $css .= ".border-b-{$width} { border-bottom-width: {$width}px; border-bottom-style: solid; }\n";
        $css .= ".border-l-{$width} { border-left-width: {$width}px; border-left-style: solid; }\n";
    }

    /*
     * The base stylesheet only ships the "d-" prefixed display helpers, but
     * both the documentation and the utility-first convention use the bare
     * names. Both spellings are emitted so either reads correctly.
     */
    $css .= "\n/* Display */\n";
    foreach (['none', 'block', 'inline', 'inline-block', 'flex', 'inline-flex', 'grid', 'inline-grid'] as $display) {
        $css .= ".{$display} { display: {$display}; }\n";
    }
    $css .= ".hidden { display: none; }\n";

    $css .= "\n/* Named border colours */\n";
    $css .= ".border-white { border-color: #ffffff; }\n";
    $css .= ".border-black { border-color: #000000; }\n";
    $css .= ".border-transparent { border-color: transparent; }\n";

    $radii = [
        'none' => '0',
        'sm' => '0.125rem',
        'md' => '0.375rem',
        'lg' => '0.5rem',
        'xl' => '0.75rem',
        '2xl' => '1rem',
        'full' => '9999px',
    ];

    $corners = [
        't' => ['top-left', 'top-right'],
        'r' => ['top-right', 'bottom-right'],
        'b' => ['bottom-right', 'bottom-left'],
        'l' => ['top-left', 'bottom-left'],
        'tl' => ['top-left'],
        'tr' => ['top-right'],
        'br' => ['bottom-right'],
        'bl' => ['bottom-left'],
    ];

    $css .= "\n/* Corner radii */\n";
    foreach ($corners as $side => $properties) {
        foreach ($radii as $name => $value) {
            $declarations = [];
            foreach ($properties as $property) {
                $declarations[] = "border-{$property}-radius: {$value};";
            }

            $css .= ".rounded-{$side}-{$name} { " . implode(' ', $declarations) . " }\n";
        }
    }

    return $css;
}

/**
 * Generate breakpoint-prefixed variants.
 *
 * sfcss.config.json has declared sm/md/lg/xl breakpoints from the start, but
 * the builder never read them: the stylesheet shipped with eight hand-written
 * responsive classes, so documented utilities such as "lg:grid-cols-3" did
 * nothing.
 *
 * Only layout utilities are generated per breakpoint. Emitting every utility
 * at every breakpoint would multiply the stylesheet several times over for
 * classes nobody writes responsively.
 */
function generateResponsiveVariants(array $config): string
{
    $breakpoints = $config['breakpoints'] ?? [];
    if ($breakpoints === []) {
        return '';
    }

    $utilities = [];

    foreach ([1, 2, 3, 4, 5, 6, 12] as $columns) {
        $utilities["grid-cols-{$columns}"] = "grid-template-columns: repeat({$columns}, minmax(0, 1fr));";
    }

    foreach (['none', 'block', 'inline', 'inline-block', 'flex', 'inline-flex', 'grid'] as $display) {
        $utilities[$display] = "display: {$display};";
        $utilities["d-{$display}"] = "display: {$display};";
    }

    $utilities['flex-row'] = 'flex-direction: row;';
    $utilities['flex-column'] = 'flex-direction: column;';
    $utilities['flex-wrap'] = 'flex-wrap: wrap;';
    $utilities['flex-nowrap'] = 'flex-wrap: nowrap;';

    $spacing = [0, 1, 2, 3, 4, 5, 6, 8, 10, 12, 16, 20, 24];
    $step = 0.25;
    foreach ($spacing as $size) {
        $value = ($size * $step) . 'rem';
        $utilities["p-{$size}"] = "padding: {$value};";
        $utilities["px-{$size}"] = "padding-left: {$value}; padding-right: {$value};";
        $utilities["py-{$size}"] = "padding-top: {$value}; padding-bottom: {$value};";
        $utilities["m-{$size}"] = "margin: {$value};";
        $utilities["mx-{$size}"] = "margin-left: {$value}; margin-right: {$value};";
        $utilities["my-{$size}"] = "margin-top: {$value}; margin-bottom: {$value};";
        $utilities["gap-{$size}"] = "gap: {$value};";
    }

    $fontSizes = [
        'xs' => '0.75rem', 'sm' => '0.875rem', 'base' => '1rem', 'lg' => '1.125rem',
        'xl' => '1.25rem', '2xl' => '1.5rem', '3xl' => '1.875rem',
        '4xl' => '2.25rem', '5xl' => '3rem',
    ];
    foreach ($fontSizes as $name => $value) {
        $utilities["text-{$name}"] = "font-size: {$value};";
    }

    $widths = ['full' => '100%', 'auto' => 'auto', '1/2' => '50%', '1/3' => '33.333333%',
               '2/3' => '66.666667%', '1/4' => '25%', '3/4' => '75%'];
    foreach ($widths as $name => $value) {
        $utilities["w-{$name}"] = "width: {$value};";
    }

    $css = "\n/* Responsive variants */\n";

    foreach ($breakpoints as $prefix => $minWidth) {
        $css .= "\n@media (min-width: {$minWidth}) {\n";

        foreach ($utilities as $name => $declaration) {
            $escaped = str_replace('/', '\\/', $name);
            $css .= "  .{$prefix}\\:{$escaped} { {$declaration} }\n";
        }

        $css .= "}\n";
    }

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
