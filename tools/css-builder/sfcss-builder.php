<?php

/**
 * SFCSS builder — turns a config of design decisions into a stylesheet.
 *
 *   php sfcss-builder.php [config.json] [output directory]
 *
 * The config holds only decisions: colours, scales, breakpoints, options. Every
 * value that follows from a decision is computed here rather than written down
 * a second time — the text colour that stays readable on a button, the hover
 * shade, the pale background of an alert, the same colour for a dark theme —
 * because a value written twice is a value that drifts. The stylesheet that
 * shipped before this had three spacing scales that disagreed, and a palette
 * in which "emerald" was a copy of "teal".
 *
 * The config and the output directory can both be given, because a project that
 * installed the framework cannot edit the copy inside vendor/ — the next
 * composer update would throw the edit away. It keeps its own config and builds
 * into its own public directory. That config is merged over the defaults, so it
 * holds only what the project changes.
 */

$defaultsFile = __DIR__ . '/sfcss.config.json';
$configFile = $argv[1] ?? $defaultsFile;
$outputDirectory = rtrim($argv[2] ?? __DIR__ . '/../../resources/assets/css', '/');

try {
    $config = loadConfig($configFile, $defaultsFile);
} catch (RuntimeException $exception) {
    fwrite(STDERR, 'Error: ' . $exception->getMessage() . "\n");
    exit(1);
}

$warnings = [];
$css = generateCss($config, $warnings);

foreach ($warnings as $warning) {
    fwrite(STDERR, "Warning: {$warning}\n");
}

/*
 * resources/, not public/, by default. SFCSS is a tool the framework ships, like
 * SFJS, so it has to be inside what a composer require delivers — and public/ is
 * export-ignored, because a consumer's vendor/ has no business holding a front
 * controller. ./sfphp assets:publish copies it into a project's public/.
 */
if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0755, true) && !is_dir($outputDirectory)) {
    fwrite(STDERR, "Error: could not create {$outputDirectory}\n");
    exit(1);
}

$outputPath = $outputDirectory . '/sfcss.css';
$minOutputPath = $outputDirectory . '/sfcss.min.css';

file_put_contents($outputPath, $css);
file_put_contents($minOutputPath, minifyCss($css));

echo "✓ Generated: $outputPath (" . filesize($outputPath) . " bytes)\n";
echo "✓ Generated: $minOutputPath (" . filesize($minOutputPath) . " bytes)\n";

/* -------------------------------------------------------------------------
 * Config
 * ---------------------------------------------------------------------- */

/**
 * Read the defaults, and the project's config over them.
 *
 * A project config used to have to be a full copy of the default one: leaving
 * out "spacing" to change only "colors.primary" broke the build. Maps merge
 * key by key, so a project file can be three lines long; lists and scalars
 * replace what they override.
 *
 * @throws RuntimeException When a file is missing or is not valid JSON
 */
function loadConfig(string $configFile, string $defaultsFile): array
{
    $read = static function (string $file): array {
        if (!is_file($file)) {
            throw new RuntimeException("config not found at {$file}");
        }

        $data = json_decode((string) file_get_contents($file), true);

        if (!is_array($data)) {
            throw new RuntimeException("{$file} is not valid JSON: " . json_last_error_msg());
        }

        return $data;
    };

    $defaults = $read($defaultsFile);

    if (realpath($configFile) === realpath($defaultsFile)) {
        return $defaults;
    }

    return mergeConfig($defaults, $read($configFile));
}

/**
 * Merge a config over another, map by map.
 */
function mergeConfig(array $base, array $override): array
{
    foreach ($override as $key => $value) {
        $isMap = is_array($value) && !array_is_list($value);

        if ($isMap && isset($base[$key]) && is_array($base[$key]) && !array_is_list($base[$key])) {
            $base[$key] = mergeConfig($base[$key], $value);
        } else {
            $base[$key] = $value;
        }
    }

    return $base;
}

/**
 * One option, with its default.
 */
function option(array $config, string $name, mixed $default): mixed
{
    return $config['options'][$name] ?? $default;
}

/* -------------------------------------------------------------------------
 * Stylesheet
 * ---------------------------------------------------------------------- */

/**
 * Build the whole stylesheet.
 *
 * The order is the cascade: tokens, the reset, components, then utilities, so
 * a utility written on a component always wins without !important — that is
 * the point of writing "m-0" on a card.
 *
 * @param list<string> $warnings Filled with anything the reader should hear
 */
function generateCss(array $config, array &$warnings): string
{
    $theme = themeTokens($config, $warnings);

    $css = "/* SFCSS — generated from sfcss.config.json by tools/css-builder/sfcss-builder.php */\n\n";
    $css .= generateTokens($config, $theme);
    $css .= generateContainer($config);

    $base = (string) file_get_contents(__DIR__ . '/sfcss-base.css');

    if (option($config, 'components', true) === false) {
        // Keep only the reset and the accessibility baseline.
        $base = explode('/* @components */', $base)[0];
    }

    $css .= str_replace('/* @components */', '', $base);

    if (option($config, 'components', true) !== false) {
        $css .= generateThemeVariants($config, $theme);
        $css .= generateResponsiveComponents($config);
    } else {
        // Without components the role colours are still utilities people use.
        $css .= generateRoleColours($config, $theme);
    }

    /*
     * The palette comes after the components, like every other utility: it
     * came first before, so class="card bg-blue-50" kept the card's own
     * background, and a utility that loses to the element it is written on
     * is not a utility.
     */
    $css .= generatePalette($config);

    $css .= generateUtilities($config, $warnings);
    $css .= generateOptions($config);

    $prefix = (string) option($config, 'prefix', '');

    return $prefix === '' ? $css : prefixVariables($css, $prefix);
}

/**
 * The :root custom properties, and the dark theme's.
 */
function generateTokens(array $config, array $theme): string
{
    $css = ":root {\n";

    foreach ($theme['light'] as $name => $value) {
        $css .= "  --{$name}: {$value};\n";
    }

    foreach ($config['spacing'] as $name => $value) {
        $css .= '  --spacing-' . variableKey((string) $name) . ": {$value};\n";
    }

    $css .= "  --font-family: {$config['typography']['fontFamily']};\n";
    $css .= "  --font-family-mono: {$config['typography']['monoFamily']};\n";

    foreach ($config['typography']['sizes'] as $name => $value) {
        $css .= "  --font-size-{$name}: {$value};\n";
    }

    $css .= "  --line-height: {$config['typography']['lineHeight']};\n";

    foreach ($config['typography']['weights'] as $name => $weight) {
        $css .= "  --font-weight-{$name}: {$weight};\n";
    }

    $rounded = option($config, 'rounded', true) !== false;

    foreach ($config['radii'] as $name => $value) {
        $key = $name === 'DEFAULT' ? 'radius' : "radius-{$name}";
        $css .= "  --{$key}: " . ($rounded || $name === 'full' ? $value : '0') . ";\n";
    }

    $css .= '  --border-radius: ' . ($rounded ? $config['border']['radius'] : '0') . ";\n";
    $css .= "  --border-width: {$config['border']['width']};\n";
    $css .= "  --border: var(--border-width) solid var(--border-color);\n";

    $shadows = option($config, 'shadows', true) !== false;

    foreach ($config['shadows'] as $name => $shadow) {
        $key = $name === 'base' ? 'shadow' : "shadow-{$name}";
        $css .= "  --{$key}: " . ($shadows ? $shadow : 'none') . ";\n";
    }

    $css .= '  --transition: ' . (option($config, 'transitions', true) !== false ? $config['transition'] : 'none') . ";\n";
    $css .= "}\n\n";

    if (option($config, 'darkMode', true) === false) {
        return $css;
    }

    /*
     * The dark theme is opt-in, and that is the important part. An earlier
     * version applied it from prefers-color-scheme alone, so a page that had
     * never asked for a dark theme got dark cards around light alerts. A page
     * says which it wants on the root element:
     *
     *     <html data-theme="dark">    always dark
     *     <html data-theme="light">   always light
     *     <html data-theme="auto">    follow the reader's system setting
     *
     * Saying nothing stays light. The block is generated once and emitted
     * twice, so the two ways of asking cannot disagree.
     */
    $dark = '';
    foreach ($theme['dark'] as $name => $value) {
        $dark .= "  --{$name}: {$value};\n";
    }

    $css .= ":root[data-theme=\"dark\"] {\n  color-scheme: dark;\n{$dark}}\n\n";
    $css .= "@media (prefers-color-scheme: dark) {\n  :root[data-theme=\"auto\"] {\n    color-scheme: dark;\n"
        . preg_replace('/^/m', '  ', rtrim($dark)) . "\n  }\n}\n\n";

    return $css;
}

/**
 * Every colour token, for the light theme and the dark one.
 *
 * For each colour in config.colors the builder derives what a component needs
 * of it, in both themes:
 *
 *   --primary            the colour itself
 *   --primary-rgb        its channels, for rgb(var(--primary-rgb) / 0.5)
 *   --primary-contrast   the text that stays readable on it
 *   --primary-hover      and -active: the pressed shades of a button
 *   --primary-subtle     a pale background (an alert, a toast)
 *   --primary-border     the border that goes with that background
 *   --primary-emphasis   text that reads on the pale background
 *   --primary-text       the colour as text on the page itself
 *
 * Contrast is computed, not hoped for. "Readable" means WCAG AA, 4.5:1 unless
 * options.minContrast says otherwise, and a colour that cannot reach it with
 * either text colour is reported at build time.
 *
 * @param list<string> $warnings
 * @return array{light: array<string, string>, dark: array<string, string>}
 */
function themeTokens(array $config, array &$warnings): array
{
    $minimum = (float) option($config, 'minContrast', 4.5);
    $surfaces = $config['surfaces'];
    $light = $surfaces['light'];
    $dark = $surfaces['dark'];

    $contrastLight = $config['contrast']['light'] ?? '#ffffff';
    $contrastDark = $config['contrast']['dark'] ?? '#111827';

    $tokens = ['light' => [], 'dark' => []];

    foreach ($light as $name => $value) {
        $tokens['light'][$name] = $value;
    }

    foreach ($dark as $name => $value) {
        $tokens['dark'][$name] = $value;
    }

    $tokens['light']['border-color'] = $config['border']['color'];
    $tokens['dark']['border-color'] = $dark['surface-border'] ?? $config['border']['color'];

    foreach ($config['colors'] as $name => $colour) {
        if (!isHex($colour)) {
            $tokens['light'][$name] = $colour;
            continue;
        }

        $colour = normaliseHex($colour);
        $tokens['light'][$name] = $colour;
        $tokens['light']["{$name}-rgb"] = implode(' ', hexToRgb($colour));

        if (in_array($name, ['white', 'black'], true)) {
            continue;
        }

        $contrast = readableOn($colour, $contrastLight, $contrastDark, $minimum);

        if ($contrast['ratio'] < $minimum) {
            $warnings[] = sprintf(
                'colors.%s (%s): neither %s nor %s reaches %.1f:1 on it (best %.2f:1).',
                $name,
                $colour,
                $contrastLight,
                $contrastDark,
                $minimum,
                $contrast['ratio']
            );
        }

        // A dark text on the colour means the colour is light: press it darker
        // by less, or the hover shade looks like a different colour.
        $step = $contrast['text'] === $contrastLight ? 0.15 : 0.1;

        $tokens['light']["{$name}-contrast"] = $contrast['text'];
        $tokens['light']["{$name}-hover"] = mix('#000000', $colour, $step);
        $tokens['light']["{$name}-active"] = mix('#000000', $colour, $step + 0.05);

        $subtle = mix('#ffffff', $colour, 0.88);
        $tokens['light']["{$name}-subtle"] = $subtle;
        $tokens['light']["{$name}-border"] = mix('#ffffff', $colour, 0.65);
        $tokens['light']["{$name}-emphasis"] = ensureContrast(mix('#000000', $colour, 0.55), $subtle, $minimum, '#000000');
        $tokens['light']["{$name}-text"] = ensureContrast($colour, $light['surface'], $minimum, '#000000');

        $darkSurface = $dark['surface'];
        $darkSubtle = mix($darkSurface, $colour, 0.78);
        $tokens['dark']["{$name}-subtle"] = $darkSubtle;
        $tokens['dark']["{$name}-border"] = mix($darkSurface, $colour, 0.45);
        $tokens['dark']["{$name}-emphasis"] = ensureContrast(mix('#ffffff', $colour, 0.45), $darkSubtle, $minimum, '#ffffff');
        $tokens['dark']["{$name}-text"] = ensureContrast($colour, $darkSurface, $minimum, '#ffffff');
    }

    $primary = $tokens['light']['primary-rgb'] ?? '37 99 235';
    $tokens['light']['focus-ring'] = "0 0 0 0.25rem rgb({$primary} / 0.35)";
    $tokens['light']['focus-ring-color'] = $tokens['light']['primary-text'] ?? $tokens['light']['primary'] ?? '#2563eb';
    $tokens['dark']['focus-ring-color'] = $tokens['dark']['primary-text'] ?? $tokens['light']['focus-ring-color'];

    return $tokens;
}

/**
 * The palette: 20 families of fixed shades.
 *
 * These keep their value in both themes on purpose — bg-blue-50 is a colour,
 * not a role. A design that has to follow the theme uses the role colours
 * (bg-primary-subtle, text-body, ...), which the dark theme redefines.
 */
function generatePalette(array $config): string
{
    if (!isset($config['colorPalettes'])) {
        return '';
    }

    $css = "/* Palette */\n";
    $hover = option($config, 'hoverVariants', true) !== false;
    $states = '';

    foreach ($config['colorPalettes'] as $family => $shades) {
        foreach ($shades as $shade => $colour) {
            $css .= ".text-{$family}-{$shade} { color: {$colour}; }\n";
            $css .= ".bg-{$family}-{$shade} { background-color: {$colour}; }\n";
            $css .= ".border-{$family}-{$shade} { border-color: {$colour}; }\n";

            if ($hover) {
                $states .= ".hover\\:text-{$family}-{$shade}:hover { color: {$colour}; }\n";
                $states .= ".hover\\:bg-{$family}-{$shade}:hover { background-color: {$colour}; }\n";
                $states .= ".hover\\:border-{$family}-{$shade}:hover { border-color: {$colour}; }\n";
            }
        }
    }

    /*
     * Gradients: a direction, and the two ends from the palette.
     *
     *   <header class="bg-gradient-to-r from-indigo-600 to-blue-600">
     *
     * from- and to- set variables, so the direction class can come in any
     * order and a gradient with only from- fades to transparent.
     */
    $directions = [
        't' => 'to top', 'tr' => 'to top right', 'r' => 'to right', 'br' => 'to bottom right',
        'b' => 'to bottom', 'bl' => 'to bottom left', 'l' => 'to left', 'tl' => 'to top left',
    ];
    $gradients = "\n/* Gradients */\n";
    foreach ($directions as $name => $direction) {
        $gradients .= ".bg-gradient-to-{$name} { background-image: linear-gradient({$direction}, var(--gradient-from, transparent), var(--gradient-to, transparent)); }\n";
    }
    // Only the shades gradients are drawn with: each class is a colour of
    // its own, and all 200 of them in two roles would weigh 3KB compressed.
    $gradientShades = array_map('strval', (array) option($config, 'gradientShades', ['500', '600', '700']));

    foreach ($config['colorPalettes'] as $family => $shades) {
        foreach (array_intersect_key($shades, array_flip($gradientShades)) as $shade => $colour) {
            $gradients .= ".from-{$family}-{$shade} { --gradient-from: {$colour}; }\n";
            $gradients .= ".to-{$family}-{$shade} { --gradient-to: {$colour}; }\n";
        }
    }
    foreach (['white' => '#ffffff', 'black' => '#000000', 'transparent' => 'transparent'] as $name => $colour) {
        $gradients .= ".from-{$name} { --gradient-from: {$colour}; }\n.to-{$name} { --gradient-to: {$colour}; }\n";
    }

    // Emitted after the plain classes so hover:bg-x beats bg-y on the same element.
    return $css . $states . $gradients . "\n";
}

/**
 * The container's maximum width at each breakpoint.
 *
 * It used to be written by hand at 640/768/1024/1280px while the config's
 * breakpoints said 480px for sm, so the container and every sm: class
 * switched at different widths. Both read the same numbers now.
 */
function generateContainer(array $config): string
{
    $css = "/* Container */\n";
    $css .= ".container, .container-fluid {\n  width: 100%;\n  margin-inline: auto;\n  padding-inline: var(--container-padding, 1rem);\n}\n";

    foreach ($config['breakpoints'] ?? [] as $prefix => $minWidth) {
        $css .= "\n@media (min-width: {$minWidth}) {\n";

        // .container-md is fluid below md and a fixed column from md up.
        $selectors = ['.container'];
        foreach (array_keys($config['breakpoints']) as $candidate) {
            $selectors[] = ".container-{$candidate}";
            if ($candidate === $prefix) {
                break;
            }
        }

        $css .= '  ' . implode(', ', $selectors) . " {\n    max-width: {$minWidth};\n  }\n}\n";
    }

    foreach (array_keys($config['breakpoints'] ?? []) as $prefix) {
        $css .= ".container-{$prefix} { width: 100%; margin-inline: auto; padding-inline: var(--container-padding, 1rem); }\n";
    }

    return $css . "\n";
}

/**
 * The per-colour classes: utilities and component variants.
 *
 * Every colour in config.colors gets its full set, so adding "brand" to the
 * config gives .btn-brand, .alert-brand and the rest without a line of CSS.
 * Before this, a new colour produced a custom property and nothing else, and
 * each variant existed only where someone had copied a line by hand.
 *
 * A variant sets the component's own variables and nothing more; the
 * component's rules read them. That is also what makes a variant themeable:
 * the dark theme changes the variables and the variants follow.
 */
function generateThemeVariants(array $config, array $theme): string
{
    $css = generateRoleColours($config, $theme);
    $css .= "\n/* Component variants — generated for every entry in config.colors */\n";

    foreach ($config['colors'] as $name => $colour) {
        if (!isset($theme['light']["{$name}-contrast"])) {
            continue;
        }

        $css .= ".btn-{$name} { --btn-bg: var(--{$name}); --btn-color: var(--{$name}-contrast); --btn-border-color: var(--{$name});"
            . " --btn-hover-bg: var(--{$name}-hover); --btn-hover-color: var(--{$name}-contrast); --btn-hover-border-color: var(--{$name}-hover);"
            . " --btn-active-bg: var(--{$name}-active); --btn-focus-rgb: var(--{$name}-rgb); }\n";

        $css .= ".btn-outline-{$name} { --btn-bg: transparent; --btn-color: var(--{$name}-text); --btn-border-color: var(--{$name});"
            . " --btn-hover-bg: var(--{$name}); --btn-hover-color: var(--{$name}-contrast); --btn-hover-border-color: var(--{$name});"
            . " --btn-active-bg: var(--{$name}-hover); --btn-focus-rgb: var(--{$name}-rgb); }\n";

        $css .= ".badge-{$name} { --badge-bg: var(--{$name}); --badge-color: var(--{$name}-contrast); }\n";
        $css .= ".badge-{$name}-subtle { --badge-bg: var(--{$name}-subtle); --badge-color: var(--{$name}-emphasis); }\n";

        $css .= ".alert-{$name} { --alert-bg: var(--{$name}-subtle); --alert-color: var(--{$name}-emphasis);"
            . " --alert-border-color: var(--{$name}-border); --alert-accent: var(--{$name}); }\n";

        $css .= ".toast-{$name} { --toast-accent: var(--{$name}); }\n";

        $css .= ".list-group-item-{$name} { --list-group-bg: var(--{$name}-subtle); --list-group-color: var(--{$name}-emphasis);"
            . " --list-group-hover-bg: var(--{$name}-border); }\n";

        $css .= ".table-{$name} { --table-bg: var(--{$name}-subtle); --table-color: var(--{$name}-emphasis); --table-border-color: var(--{$name}-border); }\n";

        $css .= ".progress-bar-{$name} { --progress-bar-bg: var(--{$name}); --progress-bar-color: var(--{$name}-contrast); }\n";
        $css .= ".spinner-{$name} { color: var(--{$name}-text); }\n";
    }

    return $css;
}

/**
 * Components that change shape at a breakpoint, one class per breakpoint.
 *
 *   .navbar-expand-md      stacked below md, a row from md up
 *   .table-responsive-lg   scrolls sideways below lg only
 */
function generateResponsiveComponents(array $config): string
{
    $css = "\n/* Responsive components */\n";
    $css .= ".navbar-expand .navbar-toggler { display: none; }\n";
    $css .= ".navbar-expand .navbar-nav { flex-direction: row; gap: 1rem; }\n";
    $css .= ".navbar-expand .navbar-collapse, .navbar-expand .navbar-collapse[hidden] { display: flex !important; flex-basis: auto; }\n";

    foreach ($config['breakpoints'] ?? [] as $prefix => $minWidth) {
        // The collapse is shown above the breakpoint even while it carries
        // [hidden] from the toggle, which otherwise wins with !important.
        $css .= "@media (min-width: {$minWidth}) {\n"
            . "  .navbar-expand-{$prefix} { flex-wrap: nowrap; }\n"
            . "  .navbar-expand-{$prefix} .navbar-toggler { display: none; }\n"
            . "  .navbar-expand-{$prefix} .navbar-nav { flex-direction: row; gap: 1rem; }\n"
            . "  .navbar-expand-{$prefix} .navbar-collapse,\n"
            . "  .navbar-expand-{$prefix} .navbar-collapse[hidden] { display: flex !important; flex-basis: auto; }\n"
            . "}\n";

        $css .= "@media (max-width: " . maxWidthBelow($minWidth) . ") {\n"
            . "  .table-responsive-{$prefix} { overflow-x: auto; -webkit-overflow-scrolling: touch; }\n"
            . "}\n";
    }

    return $css;
}

/**
 * The last width below a breakpoint: 768px gives 767.98px.
 *
 * Fractional, so a window at exactly 767.5px (zoomed, or on a high-density
 * screen) is not in both ranges or in neither.
 */
function maxWidthBelow(string $minWidth): string
{
    if (preg_match('/^([\d.]+)px$/', $minWidth, $match) === 1) {
        return ((float) $match[1] - 0.02) . 'px';
    }

    return "calc({$minWidth} - 0.02px)";
}

/**
 * The role-colour utilities: the colour itself, its pale and emphasis forms,
 * and the page's own surfaces and text.
 *
 * white and black get only text-, bg- and border-: they carry no contrast
 * text, hover shade or pale form, so there is nothing more to derive.
 */
function generateRoleColours(array $config, array $theme): string
{
    $css = "\n/* Role colours — generated for every entry in config.colors */\n";

    /*
     * Text takes the readable form, --{name}-text, which the builder darkens
     * or lightens until it passes AA against the page. The raw colour is made
     * for backgrounds: .text-warning in #f59e0b on white is 2.15:1, which the
     * documentation promised it was not. white and black have no -text form
     * and are their own.
     */
    foreach ($config['colors'] as $name => $_) {
        $text = isset($theme['light']["{$name}-text"]) ? "{$name}-text" : $name;
        $css .= ".text-{$name} { color: var(--{$text}); }\n";
        $css .= ".bg-{$name} { background-color: var(--{$name}); }\n";
        $css .= ".border-{$name} { border-color: var(--{$name}); }\n";
    }

    foreach ($config['colors'] as $name => $_) {
        if (!isset($theme['light']["{$name}-contrast"])) {
            continue;
        }

        $css .= ".text-{$name}-emphasis { color: var(--{$name}-emphasis); }\n";
        $css .= ".bg-{$name}-subtle { background-color: var(--{$name}-subtle); }\n";
        $css .= ".border-{$name}-subtle { border-color: var(--{$name}-border); }\n";
        $css .= ".text-bg-{$name} { color: var(--{$name}-contrast); background-color: var(--{$name}); }\n";
        $css .= ".link-{$name} { color: var(--{$name}-text); }\n";
    }

    $css .= ".text-muted { color: var(--body-color-muted); }\n";
    $css .= ".text-body { color: var(--body-color); }\n";
    $css .= ".bg-body { background-color: var(--surface); }\n";
    $css .= ".bg-body-raised { background-color: var(--surface-raised); }\n";
    $css .= ".bg-body-sunken { background-color: var(--surface-sunken); }\n";

    return $css;
}

/* -------------------------------------------------------------------------
 * Utilities
 * ---------------------------------------------------------------------- */

/**
 * The utility map: every utility class, declared as data.
 *
 * Each entry says which property a class sets, from which values, and which
 * variants it gets:
 *
 *   'class'      the class prefix ("m" gives m-3); '' uses the value's name alone
 *   'property'   one CSS property, or a list for classes that set several
 *   'values'     a map of name => value, or "$spacing", "$sizing", "$radii",
 *                "$fontSizes" to read a scale from the config
 *   'responsive' also emit sm:, md:, lg:, xl: versions — true for every
 *                value, or a list of the value names that get them
 *   'states'     pseudo-class variants, e.g. ['hover', 'focus']
 *   'print'      also emit a print: version
 *   'selector'   a pattern for classes that are not a plain ".name", with
 *                {class} standing for the escaped class (space-y-4 > * + *)
 *
 * A value named DEFAULT gives the bare class ("rounded"). A project adds to or
 * replaces entries through config.utilities, and removes one by setting it to
 * false — the same map, so its classes get variants like the built-in ones.
 *
 * @return array<string, array<string, mixed>>
 */
function utilityMap(array $config): array
{
    $cols = [];
    foreach (range(1, 12) as $n) {
        $cols[(string) $n] = "repeat({$n}, minmax(0, 1fr))";
    }
    $cols['none'] = 'none';

    $spans = ['auto' => 'auto', 'full' => '1 / -1'];
    foreach (range(1, 12) as $n) {
        $spans[(string) $n] = "span {$n} / span {$n}";
    }

    $starts = ['auto' => 'auto'];
    foreach (range(1, 13) as $n) {
        $starts[(string) $n] = (string) $n;
    }

    $fractions = [
        '1/2' => '50%', '1/3' => '33.333333%', '2/3' => '66.666667%', '1/4' => '25%', '3/4' => '75%',
        '1/5' => '20%', '2/5' => '40%', '3/5' => '60%', '4/5' => '80%', '1/6' => '16.666667%', '5/6' => '83.333333%',
    ];

    $widthKeywords = ['auto' => 'auto', 'full' => '100%', 'screen' => '100vw', 'min' => 'min-content', 'max' => 'max-content', 'fit' => 'fit-content'];
    $heightKeywords = ['auto' => 'auto', 'full' => '100%', 'screen' => '100vh', 'min' => 'min-content', 'max' => 'max-content', 'fit' => 'fit-content'];

    $opacity = [];
    for ($value = 0; $value <= 100; $value += 5) {
        $opacity[(string) $value] = (string) ($value / 100);
    }

    $borderWidths = ['0' => '0', '1' => '1px', '2' => '2px', '4' => '4px', '8' => '8px'];

    $maxWidths = [
        'xs' => '20rem', 'sm' => '24rem', 'md' => '28rem', 'lg' => '32rem', 'xl' => '36rem', '2xl' => '42rem',
        '3xl' => '48rem', '4xl' => '56rem', '5xl' => '64rem', '6xl' => '72rem', '7xl' => '80rem',
        'full' => '100%', 'none' => 'none', 'prose' => '65ch',
    ];

    $sides = [
        '' => [''], 't' => ['-top'], 'b' => ['-bottom'], 'l' => ['-left'], 'r' => ['-right'],
        'x' => ['-left', '-right'], 'y' => ['-top', '-bottom'],
        // start and end follow the writing direction, so a layout written for
        // English mirrors itself in Arabic or Hebrew without a second stylesheet.
        's' => ['-inline-start'], 'e' => ['-inline-end'],
    ];

    $map = [];

    foreach ($sides as $side => $suffixes) {
        $map[$side === '' ? 'margin' : "margin-{$side}"] = [
            'class' => "m{$side}",
            'property' => array_map(fn (string $s): string => "margin{$s}", $suffixes),
            'values' => '$spacing',
            'extra' => ['auto' => 'auto'],
            // Breakpoint variants for the sides people change per breakpoint;
            // md:ml-3 and md:me-3 would be several kilobytes nobody writes.
            'responsive' => in_array($side, ['', 'x', 'y', 't', 'b'], true),
        ];
        $map[$side === '' ? 'padding' : "padding-{$side}"] = [
            'class' => "p{$side}",
            'property' => array_map(fn (string $s): string => "padding{$s}", $suffixes),
            'values' => '$spacing',
            'responsive' => in_array($side, ['', 'x', 'y', 't', 'b'], true),
        ];
    }

    $map += [
        'gap' => ['class' => 'gap', 'property' => 'gap', 'values' => '$spacing', 'responsive' => true],
        'gap-x' => ['class' => 'gap-x', 'property' => 'column-gap', 'values' => '$spacing'],
        'gap-y' => ['class' => 'gap-y', 'property' => 'row-gap', 'values' => '$spacing'],
        'space-y' => ['class' => 'space-y', 'property' => 'margin-top', 'values' => '$spacing', 'selector' => '{class} > * + *'],
        'space-x' => ['class' => 'space-x', 'property' => 'margin-inline-start', 'values' => '$spacing', 'selector' => '{class} > * + *'],

        'display' => ['class' => 'd', 'property' => 'display', 'responsive' => true, 'print' => true, 'values' => [
            'none' => 'none', 'block' => 'block', 'inline' => 'inline', 'inline-block' => 'inline-block', 'flex' => 'flex',
            'inline-flex' => 'inline-flex', 'grid' => 'grid', 'inline-grid' => 'inline-grid', 'contents' => 'contents',
            'table' => 'table', 'table-cell' => 'table-cell', 'table-row' => 'table-row',
        ]],
        'display-bare' => ['class' => '', 'property' => 'display', 'responsive' => true, 'print' => true, 'values' => [
            'block' => 'block', 'inline' => 'inline', 'inline-block' => 'inline-block', 'flex' => 'flex',
            'inline-flex' => 'inline-flex', 'grid' => 'grid', 'inline-grid' => 'inline-grid', 'hidden' => 'none', 'none' => 'none',
        ]],

        'flex-direction' => ['class' => 'flex', 'property' => 'flex-direction', 'responsive' => true, 'values' => [
            'row' => 'row', 'row-reverse' => 'row-reverse', 'column' => 'column', 'column-reverse' => 'column-reverse', 'col' => 'column',
        ]],
        'flex-wrap' => ['class' => 'flex', 'property' => 'flex-wrap', 'responsive' => true, 'values' => [
            'wrap' => 'wrap', 'nowrap' => 'nowrap', 'wrap-reverse' => 'wrap-reverse',
        ]],
        'flex' => ['class' => 'flex', 'property' => 'flex', 'responsive' => true, 'values' => [
            '1' => '1 1 0%', 'auto' => '1 1 auto', 'initial' => '0 1 auto', 'none' => 'none', 'fill' => '1 1 auto',
        ]],
        'flex-grow' => ['class' => 'flex-grow', 'property' => 'flex-grow', 'values' => ['0' => '0', '1' => '1']],
        'flex-shrink' => ['class' => 'flex-shrink', 'property' => 'flex-shrink', 'values' => ['0' => '0', '1' => '1']],
        'justify' => ['class' => 'justify', 'property' => 'justify-content', 'responsive' => true, 'values' => [
            'start' => 'flex-start', 'center' => 'center', 'end' => 'flex-end', 'between' => 'space-between',
            'around' => 'space-around', 'evenly' => 'space-evenly', 'stretch' => 'stretch',
        ]],
        'justify-items' => ['class' => 'justify-items', 'property' => 'justify-items', 'values' => [
            'start' => 'start', 'center' => 'center', 'end' => 'end', 'stretch' => 'stretch',
        ]],
        'items' => ['class' => 'items', 'property' => 'align-items', 'responsive' => true, 'values' => [
            'start' => 'flex-start', 'center' => 'center', 'end' => 'flex-end', 'stretch' => 'stretch', 'baseline' => 'baseline',
        ]],
        'content' => ['class' => 'content', 'property' => 'align-content', 'values' => [
            'start' => 'flex-start', 'center' => 'center', 'end' => 'flex-end', 'between' => 'space-between',
            'around' => 'space-around', 'stretch' => 'stretch',
        ]],
        'self' => ['class' => 'self', 'property' => 'align-self', 'values' => [
            'auto' => 'auto', 'start' => 'flex-start', 'center' => 'center', 'end' => 'flex-end', 'stretch' => 'stretch', 'baseline' => 'baseline',
        ]],
        'place' => ['class' => 'place', 'property' => 'place-items', 'values' => ['center' => 'center', 'start' => 'start', 'end' => 'end']],
        'order' => ['class' => 'order', 'property' => 'order', 'responsive' => true, 'values' => [
            'first' => '-9999', 'last' => '9999', 'none' => '0', '1' => '1', '2' => '2', '3' => '3', '4' => '4', '5' => '5',
        ]],

        'grid-cols' => ['class' => 'grid-cols', 'property' => 'grid-template-columns', 'responsive' => true, 'values' => $cols],
        'grid-auto' => ['class' => 'grid-auto', 'property' => 'grid-template-columns', 'responsive' => true, 'values' => [
            // Columns as wide as they can be and as many as fit: a card grid
            // that needs no breakpoint at all. --grid-min sets the narrowest.
            'fit' => 'repeat(auto-fit, minmax(min(var(--grid-min, 16rem), 100%), 1fr))',
            'fill' => 'repeat(auto-fill, minmax(min(var(--grid-min, 16rem), 100%), 1fr))',
        ]],
        'col-span' => ['class' => 'col-span', 'property' => 'grid-column', 'responsive' => true, 'values' => $spans],
        'col-start' => ['class' => 'col-start', 'property' => 'grid-column-start', 'values' => $starts],
        'row-span' => ['class' => 'row-span', 'property' => 'grid-row', 'values' => [
            '1' => 'span 1 / span 1', '2' => 'span 2 / span 2', '3' => 'span 3 / span 3', 'full' => '1 / -1',
        ]],

        // Widths change per breakpoint as fractions (md:w-1/2), not as sizes.
        'width' => ['class' => 'w', 'property' => 'width', 'responsive' => array_keys($widthKeywords + $fractions), 'values' => '$sizing', 'extra' => $widthKeywords + $fractions],
        'height' => ['class' => 'h', 'property' => 'height', 'values' => '$sizing', 'extra' => $heightKeywords + array_slice($fractions, 0, 5, true)],
        'min-width' => ['class' => 'min-w', 'property' => 'min-width', 'values' => ['0' => '0', 'full' => '100%', 'min' => 'min-content', 'max' => 'max-content', 'fit' => 'fit-content']],
        'max-width' => ['class' => 'max-w', 'property' => 'max-width', 'values' => $maxWidths],
        'min-height' => ['class' => 'min-h', 'property' => 'min-height', 'values' => '$sizing', 'extra' => ['full' => '100%', 'screen' => '100vh', 'fit' => 'fit-content']],
        'max-height' => ['class' => 'max-h', 'property' => 'max-height', 'values' => '$sizing', 'extra' => ['full' => '100%', 'screen' => '100vh', 'none' => 'none', 'fit' => 'fit-content']],
        'size' => ['class' => 'size', 'property' => ['width', 'height'], 'values' => '$sizing', 'extra' => ['full' => '100%']],

        'font-size' => ['class' => 'text', 'property' => 'font-size', 'responsive' => true, 'values' => '$fontSizes'],
        'text-align' => ['class' => 'text', 'property' => 'text-align', 'responsive' => true, 'values' => [
            'left' => 'left', 'center' => 'center', 'right' => 'right', 'justify' => 'justify', 'start' => 'start', 'end' => 'end',
        ]],
        'font-weight' => ['class' => 'font', 'property' => 'font-weight', 'values' => [
            'light' => '300', 'normal' => '400', 'medium' => '500', 'semibold' => '600', 'bold' => '700', 'extrabold' => '800',
        ]],
        'font-family' => ['class' => 'font', 'property' => 'font-family', 'values' => ['sans' => 'var(--font-family)', 'mono' => 'var(--font-family-mono)']],
        'font-style' => ['class' => '', 'property' => 'font-style', 'values' => ['italic' => 'italic', 'not-italic' => 'normal']],
        'leading' => ['class' => 'leading', 'property' => 'line-height', 'values' => [
            'none' => '1', 'tight' => '1.25', 'snug' => '1.375', 'normal' => '1.5', 'relaxed' => '1.625', 'loose' => '2',
        ]],
        'tracking' => ['class' => 'tracking', 'property' => 'letter-spacing', 'values' => [
            'tight' => '-0.025em', 'normal' => '0', 'wide' => '0.025em', 'wider' => '0.05em', 'widest' => '0.1em',
        ]],
        'text-decoration' => ['class' => '', 'property' => 'text-decoration-line', 'states' => ['hover'], 'values' => [
            'underline' => 'underline', 'no-underline' => 'none', 'line-through' => 'line-through',
        ]],
        'underline-offset' => ['class' => 'underline-offset', 'property' => 'text-underline-offset', 'values' => ['1' => '1px', '2' => '2px', '4' => '4px', '8' => '8px']],
        'text-transform' => ['class' => '', 'property' => 'text-transform', 'values' => [
            'uppercase' => 'uppercase', 'lowercase' => 'lowercase', 'capitalize' => 'capitalize', 'normal-case' => 'none',
        ]],
        'whitespace' => ['class' => 'whitespace', 'property' => 'white-space', 'values' => [
            'normal' => 'normal', 'nowrap' => 'nowrap', 'pre' => 'pre', 'pre-line' => 'pre-line',
            'pre-wrap' => 'pre-wrap', 'break-spaces' => 'break-spaces',
        ]],
        'word-break' => ['class' => 'break', 'property' => ['overflow-wrap', 'word-break'], 'values' => [
            'normal' => 'normal', 'words' => ['break-word', 'normal'], 'all' => ['normal', 'break-all'],
        ]],
        'text-wrap' => ['class' => 'text', 'property' => 'text-wrap', 'values' => ['balance' => 'balance', 'pretty' => 'pretty', 'nowrap' => 'nowrap', 'wrap' => 'wrap']],
        'vertical-align' => ['class' => 'align', 'property' => 'vertical-align', 'values' => [
            'baseline' => 'baseline', 'top' => 'top', 'middle' => 'middle', 'bottom' => 'bottom', 'text-top' => 'text-top', 'text-bottom' => 'text-bottom',
        ]],

        'opacity' => ['class' => 'opacity', 'property' => 'opacity', 'states' => ['hover'], 'values' => $opacity],
        'shadow' => ['class' => 'shadow', 'property' => 'box-shadow', 'states' => ['hover'], 'values' => [
            'DEFAULT' => 'var(--shadow)', 'sm' => 'var(--shadow-sm)', 'lg' => 'var(--shadow-lg)', 'none' => 'none',
        ]],
        'rounded' => ['class' => 'rounded', 'property' => 'border-radius', 'values' => '$radii'],
        'rounded-corners' => ['class' => 'rounded', 'property' => null, 'custom' => 'corners'],

        'border' => ['class' => 'border', 'property' => 'border', 'values' => ['DEFAULT' => 'var(--border)', '0' => '0']],
        'border-side' => ['class' => 'border', 'property' => null, 'custom' => 'borderSides'],
        'border-width' => ['class' => 'border', 'property' => ['border-width', 'border-style'], 'values' => array_map(fn (string $w): array => [$w, 'solid'], array_diff_key($borderWidths, ['0' => true]))],
        'border-colour' => ['class' => 'border', 'property' => 'border-color', 'values' => [
            'transparent' => 'transparent', 'current' => 'currentColor',
        ]],
        'background' => ['class' => 'bg', 'property' => 'background-color', 'values' => [
            'transparent' => 'transparent', 'current' => 'currentColor',
        ]],

        'position' => ['class' => '', 'property' => 'position', 'values' => [
            'static' => 'static', 'fixed' => 'fixed', 'absolute' => 'absolute', 'relative' => 'relative', 'sticky' => 'sticky',
        ]],
        'inset' => ['class' => 'inset', 'property' => 'inset', 'values' => ['0' => '0', 'auto' => 'auto']],
        'top' => ['class' => 'top', 'property' => 'top', 'values' => ['0' => '0', '50' => '50%', '100' => '100%', 'auto' => 'auto']],
        'bottom' => ['class' => 'bottom', 'property' => 'bottom', 'values' => ['0' => '0', '50' => '50%', '100' => '100%', 'auto' => 'auto']],
        'start' => ['class' => 'start', 'property' => 'inset-inline-start', 'values' => ['0' => '0', '50' => '50%', '100' => '100%', 'auto' => 'auto']],
        'end' => ['class' => 'end', 'property' => 'inset-inline-end', 'values' => ['0' => '0', '50' => '50%', '100' => '100%', 'auto' => 'auto']],
        'z' => ['class' => 'z', 'property' => 'z-index', 'values' => ['0' => '0', '10' => '10', '20' => '20', '30' => '30', '40' => '40', '50' => '50', 'auto' => 'auto']],
        'overflow' => ['class' => 'overflow', 'property' => 'overflow', 'values' => ['auto' => 'auto', 'hidden' => 'hidden', 'visible' => 'visible', 'scroll' => 'scroll', 'clip' => 'clip']],
        'overflow-x' => ['class' => 'overflow-x', 'property' => 'overflow-x', 'values' => ['auto' => 'auto', 'hidden' => 'hidden', 'scroll' => 'scroll']],
        'overflow-y' => ['class' => 'overflow-y', 'property' => 'overflow-y', 'values' => ['auto' => 'auto', 'hidden' => 'hidden', 'scroll' => 'scroll']],
        'visibility' => ['class' => '', 'property' => 'visibility', 'values' => ['visible' => 'visible', 'invisible' => 'hidden']],
        'object-fit' => ['class' => 'object', 'property' => 'object-fit', 'values' => ['contain' => 'contain', 'cover' => 'cover', 'fill' => 'fill', 'none' => 'none', 'scale-down' => 'scale-down']],
        'aspect' => ['class' => 'aspect', 'property' => 'aspect-ratio', 'values' => ['auto' => 'auto', 'square' => '1 / 1', 'video' => '16 / 9', '4/3' => '4 / 3', '21/9' => '21 / 9']],

        'cursor' => ['class' => 'cursor', 'property' => 'cursor', 'values' => [
            'auto' => 'auto', 'default' => 'default', 'pointer' => 'pointer', 'wait' => 'wait', 'text' => 'text',
            'move' => 'move', 'not-allowed' => 'not-allowed', 'grab' => 'grab', 'help' => 'help',
        ]],
        'pointer-events' => ['class' => 'pointer-events', 'property' => 'pointer-events', 'values' => ['none' => 'none', 'auto' => 'auto']],
        'select' => ['class' => 'select', 'property' => 'user-select', 'values' => ['none' => 'none', 'text' => 'text', 'all' => 'all', 'auto' => 'auto']],
        'transition' => ['class' => 'transition', 'property' => 'transition', 'values' => ['DEFAULT' => 'var(--transition)', 'none' => 'none']],
    ];

    foreach ($config['utilities'] ?? [] as $name => $entry) {
        if ($entry === false) {
            unset($map[$name]);
        } elseif (is_array($entry)) {
            $map[$name] = isset($map[$name]) ? mergeConfig($map[$name], $entry) : $entry;
        }
    }

    return $map;
}

/**
 * Expand the utility map into classes, state variants and breakpoint variants.
 *
 * @param list<string> $warnings
 */
function generateUtilities(array $config, array &$warnings): string
{
    $map = utilityMap($config);
    $important = option($config, 'importantUtilities', false) === true ? ' !important' : '';
    $hover = option($config, 'hoverVariants', true) !== false;
    $responsive = option($config, 'responsiveVariants', true) !== false;

    $plain = '';
    $states = '';
    $print = '';
    $breakpoints = array_fill_keys(array_keys($config['breakpoints'] ?? []), '');
    $seen = [];

    foreach ($map as $key => $entry) {
        $rules = utilityRules($entry, $config, $key);

        $responsiveValues = $entry['responsive'] ?? false;

        foreach ($rules as [$name, $declarations, $valueName]) {
            $selectorFor = static function (string $class) use ($entry): string {
                $pattern = $entry['selector'] ?? '{class}';

                return str_replace('{class}', '.' . escapeClass($class), $pattern);
            };

            $body = implode(' ', array_map(fn (string $d): string => rtrim($d, ';') . "{$important};", $declarations));

            if (isset($seen[$name])) {
                $warnings[] = "utility class .{$name} is produced by both \"{$seen[$name]}\" and \"{$key}\"; the later one wins.";
            }
            $seen[$name] = $key;

            $plain .= $selectorFor($name) . " { {$body} }\n";

            if ($hover) {
                foreach ($entry['states'] ?? [] as $state) {
                    $states .= $selectorFor("{$state}:{$name}") . ":{$state} { {$body} }\n";
                }
            }

            if (!empty($entry['print'])) {
                $print .= '  ' . $selectorFor("print:{$name}") . " { {$body} }\n";
            }

            $isResponsive = is_array($responsiveValues)
                ? in_array($valueName, array_map('strval', $responsiveValues), true)
                : $responsiveValues === true;

            if ($responsive && $isResponsive) {
                foreach ($breakpoints as $prefix => $_) {
                    $breakpoints[$prefix] .= '  ' . $selectorFor("{$prefix}:{$name}") . " { {$body} }\n";
                }
            }
        }
    }

    $css = "\n/* Utilities — expanded from the utility map */\n{$plain}";
    $css .= $states === '' ? '' : "\n/* State variants */\n{$states}";

    foreach ($breakpoints as $prefix => $rules) {
        if ($rules !== '') {
            $css .= "\n@media (min-width: {$config['breakpoints'][$prefix]}) {\n{$rules}}\n";
        }
    }

    if ($print !== '') {
        $css .= "\n@media print {\n{$print}}\n";
    }

    return $css;
}

/**
 * The classes one map entry produces, as [name, declarations] pairs.
 *
 * @return list<array{0: string, 1: list<string>, 2: string}>
 */
function utilityRules(array $entry, array $config, string $key): array
{
    if (($entry['custom'] ?? null) === 'borderSides') {
        $rules = [];
        foreach (['t' => 'top', 'r' => 'right', 'b' => 'bottom', 'l' => 'left', 's' => 'inline-start', 'e' => 'inline-end'] as $side => $property) {
            $rules[] = ["border-{$side}", ["border-{$property}: var(--border)"], 'DEFAULT'];
            $rules[] = ["border-{$side}-0", ["border-{$property}: 0"], '0'];
            foreach (['1', '2', '4', '8'] as $width) {
                $rules[] = ["border-{$side}-{$width}", ["border-{$property}-width: {$width}px", "border-{$property}-style: solid"], $width];
            }
        }

        return $rules;
    }

    if (($entry['custom'] ?? null) === 'corners') {
        $corners = [
            't' => ['top-left', 'top-right'], 'r' => ['top-right', 'bottom-right'],
            'b' => ['bottom-right', 'bottom-left'], 'l' => ['top-left', 'bottom-left'],
            'tl' => ['top-left'], 'tr' => ['top-right'], 'br' => ['bottom-right'], 'bl' => ['bottom-left'],
            // Logical corners, which follow the writing direction.
            's' => ['start-start', 'end-start'], 'e' => ['start-end', 'end-end'],
        ];
        $rules = [];
        foreach ($corners as $side => $properties) {
            foreach (radiusVariables($config['radii']) as $name => $value) {
                $suffix = $name === 'DEFAULT' ? '' : "-{$name}";
                $rules[] = [
                    "rounded-{$side}{$suffix}",
                    array_map(fn (string $corner): string => "border-{$corner}-radius: {$value}", $properties),
                    (string) $name,
                ];
            }
        }

        return $rules;
    }

    $values = $entry['values'] ?? [];

    if (is_string($values)) {
        $values = match ($values) {
            '$spacing' => $config['spacing'],
            '$sizing' => $config['sizing'],
            // The radius classes read the variables, so options.rounded (which
            // zeroes the variables) squares the utilities too, not only the
            // components.
            '$radii' => radiusVariables($config['radii']),
            '$fontSizes' => $config['typography']['sizes'],
            default => throw new RuntimeException("utility \"{$key}\" refers to an unknown scale {$values}"),
        };
    }

    $values += $entry['extra'] ?? [];
    $properties = (array) $entry['property'];
    $class = (string) ($entry['class'] ?? $key);
    $rules = [];

    foreach ($values as $valueName => $value) {
        $valueName = (string) $valueName;
        $name = match (true) {
            $valueName === 'DEFAULT' => $class,
            $class === '' => $valueName,
            default => "{$class}-{$valueName}",
        };

        $declarations = [];
        foreach ($properties as $index => $property) {
            $declarations[] = "{$property}: " . (is_array($value) ? $value[$index] : $value);
        }

        $rules[] = [$name, $declarations, $valueName];
    }

    return $rules;
}

/**
 * The options that change behaviour rather than values.
 */
function generateOptions(array $config): string
{
    if (option($config, 'reducedMotion', true) === false) {
        return '';
    }

    /*
     * A reader who asked the system for less motion gets it. Motion is not
     * decoration for everybody: parallax, sliding panels and spinning icons
     * trigger nausea and migraines in people with vestibular disorders. The
     * durations are near zero rather than none, so transitionend and
     * animationend still fire for code that waits on them.
     */
    return "\n@media (prefers-reduced-motion: reduce) {\n"
        . "  *, *::before, *::after {\n"
        . "    animation-duration: 0.01ms !important;\n"
        . "    animation-iteration-count: 1 !important;\n"
        . "    transition-duration: 0.01ms !important;\n"
        . "    scroll-behavior: auto !important;\n"
        . "  }\n\n"
        // A spinner that stops looks like a page that froze, so it keeps
        // turning — slowly, which is what the setting asks for.
        . "  .spinner-border, .spinner-grow {\n"
        . "    animation-duration: 1.5s !important;\n"
        . "    animation-iteration-count: infinite !important;\n"
        . "  }\n}\n";
}

/**
 * Put a prefix on every custom property: --primary becomes --sf-primary.
 *
 * For a page that also loads another stylesheet with a --primary of its own.
 * Classes keep their names; only the variables move out of the way.
 */
function prefixVariables(string $css, string $prefix): string
{
    $prefix = rtrim($prefix, '-') . '-';

    /*
     * --sf-anchor is left alone whatever the prefix: SFJS writes it on the
     * popovers it positions, under that name, and renaming it here would
     * leave every dropdown and tooltip unanchored.
     */
    return (string) preg_replace('/(?<![\w-])--(?!' . preg_quote($prefix, '/') . '|sf-anchor\b)([a-zA-Z][\w-]*)/', "--{$prefix}$1", $css);
}

/**
 * The radius scale as references to its variables: md → var(--radius-md).
 *
 * @param array<string, string> $radii
 * @return array<string, string>
 */
function radiusVariables(array $radii): array
{
    $values = [];

    foreach ($radii as $name => $_) {
        $values[$name] = $name === 'DEFAULT' ? 'var(--radius)' : "var(--radius-{$name})";
    }

    return $values;
}

/**
 * A scale key as it can appear in a custom property name: 0.5 → 0_5.
 */
function variableKey(string $key): string
{
    return str_replace('.', '_', $key);
}

/**
 * Escape a class name for use in a selector: md:w-1/2 → md\:w-1\/2.
 */
function escapeClass(string $class): string
{
    return (string) preg_replace('/([:\/.\[\]%#()])/', '\\\\$1', $class);
}

/* -------------------------------------------------------------------------
 * Colour
 * ---------------------------------------------------------------------- */

function isHex(mixed $colour): bool
{
    return is_string($colour) && preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $colour) === 1;
}

function normaliseHex(string $hex): string
{
    $hex = strtolower($hex);

    if (strlen($hex) === 4) {
        $hex = '#' . $hex[1] . $hex[1] . $hex[2] . $hex[2] . $hex[3] . $hex[3];
    }

    return $hex;
}

/**
 * @return array{0: int, 1: int, 2: int}
 */
function hexToRgb(string $hex): array
{
    $hex = normaliseHex($hex);

    return [(int) hexdec(substr($hex, 1, 2)), (int) hexdec(substr($hex, 3, 2)), (int) hexdec(substr($hex, 5, 2))];
}

function rgbToHex(array $rgb): string
{
    return '#' . implode('', array_map(
        fn (float|int $channel): string => str_pad(dechex((int) round(max(0, min(255, $channel)))), 2, '0', STR_PAD_LEFT),
        $rgb
    ));
}

/**
 * Mix two colours: $weight of $a, the rest of $b. mix(white, c, 0.9) is a tint.
 */
function mix(string $a, string $b, float $weight): string
{
    $x = hexToRgb($a);
    $y = hexToRgb($b);

    return rgbToHex([
        $x[0] * $weight + $y[0] * (1 - $weight),
        $x[1] * $weight + $y[1] * (1 - $weight),
        $x[2] * $weight + $y[2] * (1 - $weight),
    ]);
}

/**
 * The WCAG 2 relative luminance of a colour.
 */
function luminance(string $hex): float
{
    $channels = array_map(static function (int $channel): float {
        $c = $channel / 255;

        return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }, hexToRgb($hex));

    return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
}

/**
 * The WCAG 2 contrast ratio between two colours, from 1 to 21.
 */
function contrastRatio(string $a, string $b): float
{
    [$light, $dark] = [max(luminance($a), luminance($b)), min(luminance($a), luminance($b))];

    return ($light + 0.05) / ($dark + 0.05);
}

/**
 * Pick the text colour for a background: the light one if it is readable,
 * otherwise whichever of the two reads better.
 *
 * The light one is preferred because a coloured button with white text is what
 * people expect; it only loses when it would actually be hard to read.
 *
 * @return array{text: string, ratio: float}
 */
function readableOn(string $background, string $light, string $dark, float $minimum): array
{
    $onLight = contrastRatio($background, $light);

    if ($onLight >= $minimum) {
        return ['text' => $light, 'ratio' => $onLight];
    }

    $onDark = contrastRatio($background, $dark);

    return $onDark >= $onLight ? ['text' => $dark, 'ratio' => $onDark] : ['text' => $light, 'ratio' => $onLight];
}

/**
 * Move a colour toward $toward until it reads on $background.
 *
 * Used for the colour as text: warning-yellow on white is 2.1:1, so the text
 * form of warning is the same hue, darker, at the first step that reaches the
 * minimum. The hue stays recognisable and the text stays readable.
 */
function ensureContrast(string $colour, string $background, float $minimum, string $toward): string
{
    for ($step = 0.0; $step <= 1.0; $step += 0.05) {
        $candidate = mix($toward, $colour, $step);

        if (contrastRatio($candidate, $background) >= $minimum) {
            return $candidate;
        }
    }

    return $toward;
}

/* -------------------------------------------------------------------------
 * Output
 * ---------------------------------------------------------------------- */

function minifyCss(string $css): string
{
    // Remove comments
    $css = preg_replace('!/\*[^*]*\*+(?:[^/*][^*]*\*+)*/!', '', $css);

    // Remove whitespace
    $css = preg_replace('/\s+/', ' ', $css);

    /*
     * Only the transformations that cannot change meaning. A space before ":"
     * is a descendant combinator (".card :first-child"), and the spaces around
     * "+" are required inside calc(), so neither is touched; the colon after a
     * property name is the only one that is safe to tighten.
     */
    $css = preg_replace('/ ?([{};,]) ?/', '$1', $css);
    $css = preg_replace('/([{;])(--[\w-]+|[a-z-]+): /i', '$1$2:', $css);

    // Remove trailing semicolons before closing brace
    $css = preg_replace('/;(?=\})/', '', $css);

    return trim($css);
}
