<?php
/**
 * Sky Gradient - CSS Renderer
 * PHP port of the original JavaScript/TypeScript implementation by 
 * Daniel Lazaro (https://github.com/dnlzro/horizon) 
 * who has deployed it at: https://sky.dlazaro.ca
 * Renders the current sky as a CSS gradient based on atmospheric physics
 */

// Fixed location:
define('LATITUDE', 51.285335);
define('LONGITUDE', 9.787075-100);

// Physical constants and coefficients
define('PI', M_PI);

// Coefficients of media components (m^-1)
define('RAYLEIGH_SCATTER', [5.802e-6, 13.558e-6, 33.1e-6]);
define('MIE_SCATTER', 3.996e-6);
define('MIE_ABSORB', 4.44e-6);
define('OZONE_ABSORB', [0.65e-6, 1.881e-6, 0.085e-6]);

// Altitude density distribution metrics
define('RAYLEIGH_SCALE_HEIGHT', 8e3);
define('MIE_SCALE_HEIGHT', 1.2e3);

// Additional parameters
define('GROUND_RADIUS', 6_360e3);
define('TOP_RADIUS', 6_460e3);
define('SUN_INTENSITY', 1.0);

// Rendering
define('SAMPLES', 32);
define('FOV_DEG', 75);

// Post-processing
define('EXPOSURE', 25.0);
define('GAMMA', 2.2);
define('SUNSET_BIAS_STRENGTH', 0.1);

/**
 * Vector math utilities
 */
function clamp($x, $min, $max) {
    return max($min, min($max, $x));
}

function dot($v1, $v2) {
    return $v1[0] * $v2[0] + $v1[1] * $v2[1] + $v1[2] * $v2[2];
}

function vectorLength($v) {
    if (!is_array($v) || count($v) !== 3) {
        throw new InvalidArgumentException('Vector must be an array of 3 elements');
    }
    return sqrt($v[0] * $v[0] + $v[1] * $v[1] + $v[2] * $v[2]);
}

function normalize($v) {
    $l = vectorLength($v);
    if ($l == 0.0) {
        return [0.0, 0.0, 0.0]; // Return zero vector for zero-length input
    }
    return [$v[0] / $l, $v[1] / $l, $v[2] / $l];
}

function vectorAdd($v1, $v2) {
    if (!is_array($v1) || !is_array($v2) || count($v1) !== 3 || count($v2) !== 3) {
        throw new InvalidArgumentException('Vectors must be arrays of 3 elements');
    }
    return [$v1[0] + $v2[0], $v1[1] + $v2[1], $v1[2] + $v2[2]];
}

function vectorScale($v, $s) {
    if (!is_array($v) || count($v) !== 3) {
        throw new InvalidArgumentException('Vector must be an array of 3 elements');
    }
    if (!is_numeric($s)) {
        throw new InvalidArgumentException('Scalar must be numeric');
    }
    return [$v[0] * $s, $v[1] * $s, $v[2] * $s];
}

function vectorExp($v) {
    if (!is_array($v) || count($v) !== 3) {
        throw new InvalidArgumentException('Vector must be an array of 3 elements');
    }
    return [exp($v[0]), exp($v[1]), exp($v[2])];
}

/**
 * Calculate sun position using simplified solar position algorithm
 * Returns solar elevation angle in radians
 */
function calculateSunPosition($latitude, $longitude, $timestamp = null) {
    if ($timestamp === null) {
        $timestamp = time();
    }
    
    $date = getdate($timestamp);
    $year = $date['year'];
    $month = $date['mon'];
    $day = $date['mday'];
    $hour = $date['hours'];
    $minute = $date['minutes'];
    $second = $date['seconds'];
    
    // Convert to Julian day
    if ($month <= 2) {
        $year -= 1;
        $month += 12;
    }
    $a = floor($year / 100);
    $b = 2 - $a + floor($a / 4);
    $jd = floor(365.25 * ($year + 4716)) + floor(30.6001 * ($month + 1)) + $day + $b - 1524.5;
    
    // Add time of day
    $timeOfDay = ($hour + $minute / 60.0 + $second / 3600.0) / 24.0;
    $jd += $timeOfDay;
    
    // Calculate centuries since J2000.0
    $t = ($jd - 2451545.0) / 36525.0;
    
    // Solar longitude (degrees)
    $l0 = fmod(280.46646 + $t * (36000.76983 + $t * 0.0003032), 360);
    
    // Mean anomaly (degrees)
    $m = deg2rad(357.52911 + $t * (35999.05029 - 0.0001537 * $t));
    
    // Sun's equation of center
    $c = sin($m) * (1.914602 - $t * (0.004817 + 0.000014 * $t)) +
         sin(2 * $m) * (0.019993 - 0.000101 * $t) +
         sin(3 * $m) * 0.000289;
    
    // True longitude of sun (degrees)
    $sunLon = $l0 + $c;
    
    // Obliquity of ecliptic (degrees)
    $obliq = 23.439291 - $t * (0.0130042 + $t * (0.00000016 - $t * 0.000000504));
    
    // Solar declination (radians)
    $declination = asin(sin(deg2rad($obliq)) * sin(deg2rad($sunLon)));
    
    // Hour angle (radians)
    $longitude_rad = deg2rad($longitude);
    $hourAngle = deg2rad(15 * ($timeOfDay * 24 - 12)) + $longitude_rad;
    
    // Solar elevation (radians)
    $latitude_rad = deg2rad($latitude);
    $elevation = asin(sin($latitude_rad) * sin($declination) + 
                     cos($latitude_rad) * cos($declination) * cos($hourAngle));
    
    return $elevation;
}

/**
 * ACES tonemapper (Knarkowicz)
 */
function aces($color) {
    $result = [];
    for ($i = 0; $i < 3; $i++) {
        $c = $color[$i];
        $n = $c * (2.51 * $c + 0.03);
        $d = $c * (2.43 * $c + 0.59) + 0.14;
        $result[$i] = max(0, min(1, $n / $d));
    }
    return $result;
}

/**
 * Enhance sunset hues
 */
function applySunsetBias($color) {
    $r = $color[0];
    $g = $color[1];
    $b = $color[2];
    
    // Relative luminance (sRGB)
    $lum = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    
    // Weight is higher for darker sky, lower midday
    $w = 1.0 / (1.0 + 2.0 * $lum);
    $k = SUNSET_BIAS_STRENGTH;
    $rb = 1.0 + 0.5 * $k * $w; // boost red
    $gb = 1.0 - 0.5 * $k * $w; // suppress green
    $bb = 1.0 + 1.0 * $k * $w; // boost blue
    
    return [max(0, $r * $rb), max(0, $g * $gb), max(0, $b * $bb)];
}

/**
 * Rayleigh phase function
 */
function rayleighPhase($angle) {
    return (3 * (1 + cos($angle) * cos($angle))) / (16 * PI);
}

/**
 * Mie phase function
 */
function miePhase($angle) {
    $g = 0.8;
    $scale = 3 / (8 * PI);
    $num = (1 - $g * $g) * (1 + cos($angle) * cos($angle));
    $denom = (2 + $g * $g) * pow((1 + $g * $g - 2 * $g * cos($angle)), 3/2);
    return ($scale * $num) / $denom;
}

/**
 * Intersect ray with sphere
 */
function intersectSphere($p, $d, $r) {
    $m = $p;
    $b = dot($m, $d);
    $c = dot($m, $m) - $r * $r;
    $discr = $b * $b - $c;
    
    if ($discr < 0) {
        return null; // Ray misses sphere
    }
    
    $t = -$b - sqrt($discr);
    if ($t < 0) {
        return -$b + sqrt($discr); // Ray inside sphere
    }
    return $t;
}

/**
 * Compute transmittance through atmosphere
 */
function computeTransmittance($height, $angle) {
    
    $rayOrigin = [0, GROUND_RADIUS + $height, 0];
    $rayDirection = [sin($angle), cos($angle), 0];
    
    $distance = intersectSphere($rayOrigin, $rayDirection, TOP_RADIUS);
    if ($distance === null) {
        return [1, 1, 1];
    }
    
    $segmentLength = $distance / SAMPLES;
    $t = 0.5 * $segmentLength;
    
    $odRayleigh = 0;
    $odMie = 0;
    $odOzone = 0;
    
    for ($i = 0; $i < SAMPLES; $i++) {
        $pos = vectorAdd($rayOrigin, vectorScale($rayDirection, $t));
        $h = vectorLength($pos) - GROUND_RADIUS;
        
        $dR = exp(-$h / RAYLEIGH_SCALE_HEIGHT);
        $dM = exp(-$h / MIE_SCALE_HEIGHT);
        
        $odRayleigh += $dR * $segmentLength;
        
        // Simple ozone layer
        $ozoneDensity = 1.0 - min(abs($h - 25e3) / 15e3, 1.0);
        $odOzone += $ozoneDensity * $segmentLength;
        
        $odMie += $dM * $segmentLength;
        $t += $segmentLength;
    }
    
    $rayleighScatter = RAYLEIGH_SCATTER;
    $ozoneAbsorb = OZONE_ABSORB;
    
    $tauR = [
        $rayleighScatter[0] * $odRayleigh,
        $rayleighScatter[1] * $odRayleigh,
        $rayleighScatter[2] * $odRayleigh
    ];
    $tauM = [MIE_ABSORB * $odMie, MIE_ABSORB * $odMie, MIE_ABSORB * $odMie];
    $tauO = [
        $ozoneAbsorb[0] * $odOzone,
        $ozoneAbsorb[1] * $odOzone,
        $ozoneAbsorb[2] * $odOzone
    ];
    
    $tau = [
        -($tauR[0] + $tauM[0] + $tauO[0]),
        -($tauR[1] + $tauM[1] + $tauO[1]),
        -($tauR[2] + $tauM[2] + $tauO[2])
    ];
    
    return vectorExp($tau);
}

/**
 * Render sky gradient based on solar elevation
 */
function renderGradient($altitude) {
    if (!is_numeric($altitude)) {
        throw new InvalidArgumentException('Altitude must be numeric');
    }
    
    $cameraPosition = [0, GROUND_RADIUS, 0];
    $sunDirection = normalize([cos($altitude), sin($altitude), 0]);
    
    $focalZ = 1.0 / tan((FOV_DEG * 0.5 * PI) / 180.0);
    
    $stops = [];
    
    for ($i = 0; $i < SAMPLES; $i++) {
        $s = $i / (SAMPLES - 1);
        $viewDirection = normalize([0, $s, $focalZ]);
        
        $inscattered = [0, 0, 0];
        
        $tExitTop = intersectSphere($cameraPosition, $viewDirection, TOP_RADIUS);
        if ($tExitTop !== null && $tExitTop > 0) {
            $rayOrigin = $cameraPosition;
            $segmentLength = $tExitTop / SAMPLES;
            $tRay = $segmentLength * 0.5;
            
            $rayOriginRadius = vectorLength($rayOrigin);
            $isRayPointingDownwardAtStart = dot($rayOrigin, $viewDirection) / $rayOriginRadius < 0.0;
            $startHeight = $rayOriginRadius - GROUND_RADIUS;
            $startRayCos = clamp(dot(vectorScale($rayOrigin, 1.0 / $rayOriginRadius), $viewDirection), -1, 1);
            $startRayAngle = acos(abs($startRayCos));
            $transmittanceCameraToSpace = computeTransmittance($startHeight, $startRayAngle);
            
            for ($j = 0; $j < SAMPLES; $j++) {
                $samplePos = vectorAdd($rayOrigin, vectorScale($viewDirection, $tRay));
                $sampleRadius = vectorLength($samplePos);
                $upUnit = vectorScale($samplePos, 1.0 / $sampleRadius);
                $sampleHeight = $sampleRadius - GROUND_RADIUS;
                
                $viewCos = clamp(dot($upUnit, $viewDirection), -1, 1);
                $sunCos = clamp(dot($upUnit, $sunDirection), -1, 1);
                $viewAngle = acos(abs($viewCos));
                $sunAngle = acos($sunCos);
                
                $transmittanceToSpace = computeTransmittance($sampleHeight, $viewAngle);
                $transmittanceCameraToSample = [0, 0, 0];
                
                for ($k = 0; $k < 3; $k++) {
                    $transmittanceCameraToSample[$k] = $isRayPointingDownwardAtStart
                        ? $transmittanceToSpace[$k] / $transmittanceCameraToSpace[$k]
                        : $transmittanceCameraToSpace[$k] / $transmittanceToSpace[$k];
                }
                
                $transmittanceLight = computeTransmittance($sampleHeight, $sunAngle);
                
                $opticalDensityRay = exp(-$sampleHeight / RAYLEIGH_SCALE_HEIGHT);
                $opticalDensityMie = exp(-$sampleHeight / MIE_SCALE_HEIGHT);
                $sunViewCos = clamp(dot($sunDirection, $viewDirection), -1, 1);
                $sunViewAngle = acos($sunViewCos);
                $phaseR = rayleighPhase($sunViewAngle);
                $phaseM = miePhase($sunViewAngle);
                
                $rayleighScatter = RAYLEIGH_SCATTER;
                $scatteredRgb = [0, 0, 0];
                for ($k = 0; $k < 3; $k++) {
                    $rayleighTerm = $rayleighScatter[$k] * $opticalDensityRay * $phaseR;
                    $mieTerm = MIE_SCATTER * $opticalDensityMie * $phaseM;
                    $scatteredRgb[$k] = $transmittanceLight[$k] * ($rayleighTerm + $mieTerm);
                }
                
                for ($k = 0; $k < 3; $k++) {
                    $inscattered[$k] += $transmittanceCameraToSample[$k] * $scatteredRgb[$k] * $segmentLength;
                }
                $tRay += $segmentLength;
            }
            
            for ($k = 0; $k < 3; $k++) {
                $inscattered[$k] *= SUN_INTENSITY;
            }
        }
        
        // Post-process
        $color = $inscattered;
        $color = vectorScale($color, EXPOSURE);
        $color = applySunsetBias($color);
        $color = aces($color);
        $color = [pow($color[0], 1.0 / GAMMA), pow($color[1], 1.0 / GAMMA), pow($color[2], 1.0 / GAMMA)];
        $rgb = [
            round(clamp($color[0], 0, 1) * 255),
            round(clamp($color[1], 0, 1) * 255),
            round(clamp($color[2], 0, 1) * 255)
        ];
        
        $percent = (1 - $s) * 100;
        $stops[] = ['percent' => $percent, 'rgb' => $rgb];
    }
    
    // Sort stops and create CSS gradient
    usort($stops, function($a, $b) {
        return $a['percent'] <=> $b['percent'];
    });
    
    $colorStops = [];
    foreach ($stops as $stop) {
        $rgb = $stop['rgb'];
        $percent = round($stop['percent'] * 100) / 100;
        $colorStops[] = "rgb({$rgb[0]}, {$rgb[1]}, {$rgb[2]}) {$percent}%";
    }
    
    $gradient = "linear-gradient(to bottom, " . implode(", ", $colorStops) . ")";
    $topColor = $stops[0]['rgb'];
    $bottomColor = $stops[count($stops) - 1]['rgb'];
    
    return [$gradient, $topColor, $bottomColor];
}

// Main execution
try {
    $sunElevation = calculateSunPosition(LATITUDE, LONGITUDE);
    list($gradient, $topVec, $bottomVec) = renderGradient($sunElevation);
} catch (Exception $e) {
    // Fallback to simple gradient on error
    error_log('Sky gradient error: ' . $e->getMessage());
    $gradient = 'linear-gradient(to bottom, #87CEEB 0%, #FFA500 100%)';
    $topVec = [135, 206, 235];
    $bottomVec = [255, 165, 0];
}

$top = "rgb({$topVec[0]}, {$topVec[1]}, {$topVec[2]})";
$bottom = "rgb({$bottomVec[0]}, {$bottomVec[1]}, {$bottomVec[2]})";

?><!DOCTYPE html>
<html lang="en" style="height: 100%; background: <?php echo htmlspecialchars($gradient); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Refresh" content="60">
    <meta name="theme-color" content="<?php echo htmlspecialchars($top); ?>">
    <meta name="darkreader-lock">
    <meta name="description" content="The current sky at Kassel, Germany (<?php echo LATITUDE; ?>, <?php echo LONGITUDE; ?>), rendered as a CSS gradient. Refreshes every minute using the meta http-equiv='Refresh' tag.">
    <title>Horizon at <?php echo LATITUDE; ?>, <?php echo LONGITUDE; ?></title>
</head>
<body style="margin: 0; padding: 0; height: 100vh;">

<header style="background-color: <?php echo htmlspecialchars($top); ?>;">

</header>

<div style="height: 100%; background: <?php echo htmlspecialchars($gradient); ?>">
    Horizon at <?php echo LATITUDE; ?>, <?php echo LONGITUDE; ?> <br>
    Sun Elevation: <?php echo htmlspecialchars($sunElevation); ?> rad <br>
</div>

<footer style="background-color: <?php echo htmlspecialchars($bottom); ?>;">

</footer>
</body>
</html>