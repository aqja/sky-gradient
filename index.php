<?php
require_once 'horizon.php';

/**
 * Sanitize CSS values to prevent injection attacks
 */
function sanitizeCSSValue(string $value): string {
    // Remove any potentially dangerous characters for CSS context
    return preg_replace('/[^\w\s\-\.\(\)\,\%\#]/', '', $value);
}

/**
 * Validate and sanitize RGB values
 */
function sanitizeRGBValues(array $rgb): array {
    return [
        max(0, min(255, intval($rgb[0]))),
        max(0, min(255, intval($rgb[1]))),
        max(0, min(255, intval($rgb[2])))
    ];
}

/**
 * Get coordinates from environment or use defaults with validation
 */
function getValidatedCoordinates(): array {
    // Try to get from environment variables first
    $lat = floatval($_ENV['SKY_LATITUDE'] ?? $_GET['lat'] ?? SkyDefaults::DEFAULT_LATITUDE);
    $lon = floatval($_ENV['SKY_LONGITUDE'] ?? $_GET['lon'] ?? SkyDefaults::DEFAULT_LONGITUDE);
    
    // Validate coordinates
    if (!is_finite($lat) || $lat < -90 || $lat > 90) {
        error_log('Invalid latitude provided: ' . $lat . ', using default');
        $lat = SkyDefaults::DEFAULT_LATITUDE;
    }
    
    if (!is_finite($lon) || $lon < -180 || $lon > 180) {
        error_log('Invalid longitude provided: ' . $lon . ', using default');
        $lon = SkyDefaults::DEFAULT_LONGITUDE;
    }
    
    return [$lat, $lon];
}

try {
    [$latitude, $longitude] = getValidatedCoordinates();
    $config = new SkyConfig($latitude, $longitude);
    $generator = new SkyGradientGenerator($config);
    
    [$gradient, $topVec, $bottomVec] = $generator->generate();
    $sunElevation = $generator->getSunElevationSafe();
    
    // Sanitize RGB values
    $topVec = sanitizeRGBValues($topVec);
    $bottomVec = sanitizeRGBValues($bottomVec);
    
    $top = "rgb({$topVec[0]}, {$topVec[1]}, {$topVec[2]})";
    $bottom = "rgb({$bottomVec[0]}, {$bottomVec[1]}, {$bottomVec[2]})";
    $gradient = sanitizeCSSValue($gradient);
    
} catch (Exception $e) {
    // Fallback values on any error using centralized defaults
    error_log('Index page error: ' . $e->getMessage());
    $gradient = SkyDefaults::FALLBACK_GRADIENT;
    $fallbackColor = SkyDefaults::FALLBACK_COLOR;
    $top = "rgb({$fallbackColor[0]}, {$fallbackColor[1]}, {$fallbackColor[2]})";
    $bottom = $top;
    $topVec = $fallbackColor;
    $bottomVec = $fallbackColor;
    $sunElevation = SkyDefaults::FALLBACK_SUN_ELEVATION;
    // Set a fallback config for the HTML output
    $config = (object)['latitude' => SkyDefaults::DEFAULT_LATITUDE, 'longitude' => SkyDefaults::DEFAULT_LONGITUDE];
}

?><!DOCTYPE html>
<html lang="en" style="height: 100%; background: <?php echo htmlspecialchars($gradient, ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Refresh" content="60">
    <meta name="theme-color" content="<?php echo htmlspecialchars($top, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="darkreader-lock">
    <meta name="description" content="The current sky at location (<?php echo htmlspecialchars($config->latitude ?? 0, ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars($config->longitude ?? 0, ENT_QUOTES, 'UTF-8'); ?>), rendered as a CSS gradient. Refreshes every minute using the meta http-equiv='Refresh' tag.">
    <title>Sky Gradient at <?php echo htmlspecialchars($config->latitude ?? 0, ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars($config->longitude ?? 0, ENT_QUOTES, 'UTF-8'); ?></title>
</head>
<body style="margin: 0; padding: 0; height: 100vh;">

<header style="background-color: <?php echo htmlspecialchars($top, ENT_QUOTES, 'UTF-8'); ?>;">

</header>

<div style="height: 100%; background: <?php echo htmlspecialchars($gradient, ENT_QUOTES, 'UTF-8'); ?>">
    Sky Gradient at <?php echo htmlspecialchars($config->latitude ?? 0, ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars($config->longitude ?? 0, ENT_QUOTES, 'UTF-8'); ?> <br>
    Sun Elevation: <?php echo htmlspecialchars(number_format($sunElevation, 6), ENT_QUOTES, 'UTF-8'); ?> rad <br>
</div>

<footer style="background-color: <?php echo htmlspecialchars($bottom, ENT_QUOTES, 'UTF-8'); ?>;">

</footer>
</body>
</html>